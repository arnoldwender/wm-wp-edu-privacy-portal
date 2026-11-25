<?php
/**
 * WM Edu Privacy Portal — pseudonymisation of pupil data in text
 *
 * Replaces caller-supplied pupil names, German dates and school class identifiers with keyed placeholder tokens, and
 * puts the originals back into a text that carries those tokens. Other personal data (names without a list, addresses,
 * e-mail addresses, grades) is not detected, and the token map makes the replacement reversible: this is
 * pseudonymisation, not anonymisation.
 *
 * @package WM_Edu_Privacy_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Edu_PII_Tokenizer {

	/**
	 * Prefixes a cell a spreadsheet would read as a formula with an apostrophe (CWE-1236). The characters are the ones
	 * OWASP lists for CSV injection: = + - @, tab (0x09), carriage return (0x0D) and line feed (0x0A); the line feed was
	 * missing until 2026-09-17, when the CSV export of the admin screen became the first caller.
	 */
	public static function sanitize_formula( string $text ): string {
		if ( '' !== $text && in_array( $text[0], [ '=', '+', '-', '@', "\t", "\r", "\n" ], true ) ) {
			return "'" . $text;
		}
		return $text;
	}

	/**
	 * Tokenize text containing pupil data.
	 *
	 * @param string                  $text           Raw input text.
	 * @param array<array-key, mixed> $known_students Map of pupil name => token; a token that is not a non-empty string
	 *                                                is replaced by a generated one.
	 * @return array{sanitized_text: string, token_map: array<string, string>, detections: int}
	 * @throws RuntimeException When a pattern fails (for example on text that is not valid UTF-8): a half-replaced text
	 *                          never leaves this method.
	 */
	public static function tokenize( string $text, array $known_students = [] ): array {
		$token_map  = [];
		$detections = 0;
		$key        = WM_Edu_Keys::derive( WM_Edu_Keys::TOKENIZER );

		// 1. Names, longest first and through placeholders. "Max" must not replace the first half of "Max Müller" (the
		// surname stayed in clear until 2026-09-15), a token is never read as a regex replacement ($0, \1), and a
		// shorter name never matches inside a token that was already put in.
		$names = [];
		foreach ( $known_students as $name => $assigned ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$token   = is_string( $assigned ) && '' !== trim( $assigned ) ? $assigned : '[[STUDENT_' . strtoupper( substr( hash_hmac( 'sha256', $name, $key ), 0, 8 ) ) . ']]';
			$names[] = [ $name, $token ];
		}
		usort( $names, static fn( array $a, array $b ): int => strlen( $b[0] ) <=> strlen( $a[0] ) );

		[ $open, $close ] = self::placeholder_delimiters( $text );
		$placeholders     = [];
		foreach ( $names as $i => [ $name, $token ] ) {
			$placeholder = $open . $i . $close;
			$count       = 0;
			$text        = self::checked( preg_replace_callback( '/\b' . preg_quote( $name, '/' ) . '\b/iu', static fn(): string => $placeholder, $text, -1, $count ) );
			if ( $count > 0 ) {
				$placeholders[ $placeholder ] = $token;
				$token_map[ $token ]          = $name;
				$detections                  += $count;
			}
		}

		// 2. German dates (15.03.2012 or 15. März 2012). Every such date is replaced, not only dates of birth.
		$dob_pattern = '/\b(\d{1,2}\.\s*(?:Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember|\d{1,2}\.)\s*\d{4})\b/iu';
		$text        = self::checked(
			preg_replace_callback(
				$dob_pattern,
				static function ( array $m ) use ( &$token_map, &$detections, $key ): string {
					$token               = '[[DOB_' . strtoupper( substr( hash_hmac( 'sha256', $m[1], $key ), 0, 8 ) ) . ']]';
					$token_map[ $token ] = $m[1];
					++$detections;
					return $token;
				},
				$text
			)
		);

		// 3. School class identifiers (Klasse 9b, Kl. 7c).
		$class_pattern = '/\b(?:Klasse|Kl\.)\s*([0-9]{1,2}[a-zA-Z]?)\b/iu';
		$text          = self::checked(
			preg_replace_callback(
				$class_pattern,
				static function ( array $m ) use ( &$token_map, &$detections, $key ): string {
					$token               = '[[CLASS_' . strtoupper( substr( hash_hmac( 'sha256', $m[0], $key ), 0, 6 ) ) . ']]';
					$token_map[ $token ] = $m[0];
					++$detections;
					return $token;
				},
				$text
			)
		);

		$text = strtr( $text, $placeholders );

		return [
			'sanitized_text' => $text,
			'token_map'      => $token_map,
			'detections'     => $detections,
		];
	}

	/**
	 * Restore original values into a text using the token map, in one pass: strtr() tries the longest token first and
	 * never scans a replacement again. Until 2026-09-15 each token was replaced in map order, so with the tokens S1 and
	 * S10 the text "S10" became "Anna0". Entries with an empty token or an original that is not a string are ignored.
	 *
	 * @param array<array-key, mixed> $token_map Map of token => original value.
	 */
	public static function detokenize( string $tokenized_text, array $token_map ): string {
		$pairs = [];
		foreach ( $token_map as $token => $original ) {
			$token = (string) $token;
			if ( '' !== $token && is_string( $original ) ) {
				$pairs[ $token ] = $original;
			}
		}
		return [] === $pairs ? $tokenized_text : strtr( $tokenized_text, $pairs );
	}

	/**
	 * preg_* functions return null on a PCRE error.
	 */
	private static function checked( ?string $result ): string {
		if ( null === $result ) {
			throw new RuntimeException( 'Pseudonymisierung abgebrochen: ' . esc_html( preg_last_error_msg() ) );
		}
		return $result;
	}

	/**
	 * Two private-use characters that do not occur in the text; they mark where a name was found until every pattern
	 * has run.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function placeholder_delimiters( string $text ): array {
		for ( $cp = 0xE000; $cp < 0xF8FE; $cp += 2 ) {
			$open  = self::utf8_char( $cp );
			$close = self::utf8_char( $cp + 1 );
			if ( ! str_contains( $text, $open ) && ! str_contains( $text, $close ) ) {
				return [ $open, $close ];
			}
		}
		throw new RuntimeException( 'Pseudonymisierung abgebrochen: kein freies Platzhalterzeichen.' );
	}

	/**
	 * UTF-8 encoding of a code point between U+0800 and U+FFFF (three bytes), without mbstring.
	 */
	private static function utf8_char( int $cp ): string {
		return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
}
