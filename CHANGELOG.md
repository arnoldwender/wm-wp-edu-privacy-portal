# Changelog — WM Edu Privacy Portal

All notable changes to **WM Edu Privacy Portal** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Switched GenerateBlocks 2.x Dynamic Tag hook to `generateblocks_dynamic_tag_replacement` (`mixed` safety) and standardized spoke registration on `wm_register_suite_module`.
- Implemented `WM_Edu_Spoke_Adapter::get_settings_url()` pointing to `admin.php?page=wm-edu-consents-settings`, added `Tested up to: 7.1` in main plugin header, synchronized docs/catalogs, and recomputed `bom.json`.

## [1.2.1] - 2026-11-21

### Fixed

- The result line of the tokenizer demo shortcode («Pseudonymisierter Text (%d Datum oder Klassenbezeichnung ersetzt)») repeated one form for several numbers in Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak and Slovenian (12 catalogs), for example Czech «nahrazeno 2 údajů» and Polish «zastąpiono 2 dat»; each number now gets the form its language uses (Czech «nahrazeny 2 údaje», Polish «zastąpiono 2 daty»). The plugin's code is unchanged.

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.
- The German description of the second screenshot (`README.de.md`) still called the text archive "unveränderlich"; it now says what the English one has said since 1.1.5.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.6] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-edu-privacy-portal/`, `https://github.com/arnoldwender/wm-wp-edu-privacy-portal`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [1.1.5] - 2026-11-19

### Changed
- Reword screenshot #2 caption in `README.md` and `readme.txt` to avoid absolute-claim phrasing (`CLAIM-06` UWG compliance check).
- Regenerated visual banners (`772x250`, `1544x500`), updated release metadata, and refreshed `bom.json` to `1.1.5`.

---

## [1.1.4] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-3.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-edu-privacy-portal/` in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`.

### Changed
- Archived superseded early mockups and banners non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata and SBOM (`bom.json`) to `1.1.4`.

---

## [1.1.3] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-edu-privacy-portal/` and align release metadata at `v1.1.3`.

---

## [1.1.2] - 2026-11-18

### Fixed

- **Multisite network activation and new-subsite provisioning (`wm-edu-privacy-portal.php`, `WM_Edu_Consent_Vault::install()`).** Hooked `wp_initialize_site` (`wm_edu_on_initialize_site`) so newly created multisite subsites run `WM_Edu_Consent_Vault::install()` inside `switch_to_blog()` / `try { ... } finally { restore_current_blog(); }` when the plugin is network-activated; updated `WM_Edu_Consent_Vault::install( bool $network_wide = false )` to iterate across all network sites on network-wide activation and guarded the `wp-admin/includes/upgrade.php` require in `create_tables()` with `file_exists()` and `function_exists( 'dbDelta' )`.
- **Non-frontend option autoload hygiene (`WM_Edu_Consent_Vault::install()`).** Passed explicit `$autoload = false` when writing `wm_edu_schema_version` alongside `wm_edu_legacy_row_boundary`, `wm_edu_chain_tip`, and `wm_edu_delete_data_on_uninstall` so non-frontend options stay out of `alloptions`.

---

## [1.1.1] - 2026-11-18

### Added

- **MySQL 8.0 CI integration matrix (`.github/workflows/ci.yml`).** Added `mysql-integration` (`mysql:8.0`) job verifying `GET_LOCK()`, InnoDB transactions, and schema constraints against a real MySQL 8.0 instance alongside the standalone PHP test matrix.

---

## [1.1.0] - 2026-11-16

### Added — ecosystem bridges, opt-in uninstall, translations and SBOM (2026-11-16)

- **WordPress 6.5+ Block Bindings and GenerateBlocks 2.x Dynamic Tag (`WM_Edu_Ecosystem`).** Registers the Block Bindings source `wm-edu/status` and the GenerateBlocks 2.x Dynamic Tag `{{wm_edu_status}}`, fires `wm_edu_ready` on `init`, and exposes the `wm_edu_status_value`, `wm_edu_export_csv_output` and `wm_edu_export_csv_stream` filters.
- **Opt-in data deletion on uninstall (`wm_edu_delete_data_on_uninstall`).** `uninstall.php` now preserves all plugin options, capabilities and consent ledger tables by default unless an administrator explicitly enables `wm_edu_delete_data_on_uninstall` on the settings screen (registered via `register_setting`).
- **Translations, CycloneDX 1.6 SBOM and Playground Blueprint.** Compiled 29 translated `.po`/`.mo` catalogs in `languages/`, added `bom.json` (CycloneDX 1.6 with SHA-256 of `wm-edu-privacy-portal.php`), `.wordpress-org/blueprints/blueprint.json`, and registered `[wm_edu_consent_form]` with its literal tag for static audit tooling.

### Added — the catalog freshness gate (2026-09-20)

- `tests/test-pot-freshness.php` and two CI steps on PHP 8.3: the gate extracts the translatable strings from the source and fails if any of them lacks an entry in the `.pot`. A string with no catalog entry cannot be translated by anyone into any language — the translator never sees it, nothing reports an error, and the interface simply stays German.
- **This catalog was already current** — 172 translatable strings, 172 entries, nothing missing and nothing stale — and that is the reason to add the gate rather than a reason to skip it. Fifteen of the fleet's twenty-four plugins had drifted, one of them down to a single entry still shared with the code, and not one of them had a check that could notice. Being correct today is not a mechanism.
- The gate takes its definition of "fresh" from `wp i18n make-pot` rather than a regex of its own, reads the text domain from the plugin header instead of holding a second copy, treats 0 extracted strings as a failure rather than a pass, ignores the plugin-header fields `make-pot` adds (nobody translates an author name or a URL), requires wp-cli with no escape hatch, and runs its own self-test before judging the plugin.
### Added (2026-09-17, consent recording and access)

Until this entry the consent table had no writer: on a real install the lookup answered "KEIN NACHWEIS" for every pupil. The features below fill that gap and close the two doors that were open. Every item has assertions in `tests/test-suite.php` (groups 14 to 18) and entries in `tests/mutations.php`.

- **Consent types with their exact wording.** A slug (stored with every entry), a label, a description, the wording and an active flag; the SHA-256 of the wording is the scope hash, and every wording a type ever had is archived under its hash, so an old entry can still be shown with the text it was given. Types are never deleted, only deactivated.
- **Guardian registry.** Pairs of pupil ID and guardian address, imported on the admin screen. Only an HMAC of the lower-cased address is stored, so the registry can confirm an address and not disclose one.
- **Consent form for guardians, `[wm_edu_consent_form]`.** Pupil ID, address, a decision per active type and a confirmation that the wording was read. The answer is the same whether or not the pair is registered; only a registered pair gets an e-mail with a one-time link (48 hours by default). The decision is recorded when the link's page is submitted with the read box ticked — never on the GET that opens it — and the receipt mail carries a revocation link that records `REVOKED`, also only on POST. Submissions are processed on `template_redirect` and answered with a 303, so a second render of the shortcode cannot mail or record twice. Rate limit per client IP and per address per hour, a honeypot field, no-cache and `Referrer-Policy: no-referrer` on pages that carry a token, HTTP 503 with `Retry-After` when the register is locked.
- **Admin screen** behind the new capability `manage_edu_consents` (granted to administrators on install; the settings need `manage_options`): current state per pupil and type, history of one pupil, manual entry with its reason, revocation of an entry on file, consent types, guardian registry, CSV export and the state of the signature chain on every page. Every action posts to `admin-post.php` with a nonce and is answered with a redirect carrying a notice code, never submitted text. The export quotes every cell, doubles quotes, carries a byte order mark and neutralises formula characters (`sanitize_formula()` had no caller and now covers the line feed OWASP lists as well).
- **Retention.** A daily event deletes the data of requests that were never confirmed once the configured period after their expiry has passed, deletes closed requests and releases interrupted ones; recorded consents are never deleted, on uninstall no more than anywhere else. `wp edu cleanup` applies it by hand. The event is scheduled on `plugins_loaded`, not only on activation, so an update of an active plugin gets it too.
- **Settings** (one option): public access to the lookup and to the tokenizer (both off), roles for the lookup, link validity, retention period and the two rate limits. Every read applies the limits again.

### Changed (2026-09-17)

- **The consent register is written under a named lock and anchored to a recorded tip.** Two consents written at the same moment used to read the same chain tip and fork the chain. Every append now takes `GET_LOCK()` for this database and table (5 seconds; `0` or NULL refuses the write with `wm_edu_chain_busy`, status 503), links to the recorded tip — a signed option — instead of the last row in the table, and writes the row and the new tip in one transaction. `verify_chain()` reports a register that does not end at its tip, and a tip that is missing or unsigned is never re-signed by a later write, so deleting the newest rows can no longer be laundered by the next consent. On a database without named locks (the SQLite Database Integration plugin answers `GET_LOCK()` with the string `1=1`) every consent write is refused.
- **Schema 3** adds `source`, `actor_id`, `note` and `row_version` to the register and signs them: an entry says where it came from, who made it and why, and editing any of those breaks the chain. Schema-2 rows keep verifying with their own payload, the upgrade records the tip from the table, and three assertions of the suite moved from schema `2` to `3`.
- **The lookup, the tokenizer routes and the demo shortcode are no longer public.** `[wm_edu_consent_gate]` needs `manage_edu_consents` or a role named in the settings and verifies a nonce for logged-in users; the REST routes need an authenticated user with a capability that defaults to `edit_posts` and is filterable through `wm_edu_tokenizer_capability` (401 without a login, 403 without the capability); the demo asks for the same capability, because it is the third door to the same tokenizer. **This changes what existing integrations see**: the settings `public_gate` and `public_tokenizer`, both off by default, restore the earlier behaviour.
- `[wm_edu_consent_gate]` takes a `type` attribute and then answers for that consent type instead of the newest entry of any.
- `wp edu status` prints the recorded tip with its row count and whether its signature verifies. The hub adapter no longer says the register has no writer and reports the active consent types and the requests still waiting for a confirmation.
- `uninstall.php` also deletes the settings option, the capability and the retention event; it keeps the register, the recorded tip, the consent types, their text archive and the guardian registry.

### The version this file opened on matched 1.0.0 before the 1.1.0 release (D15, 2026-09-17)

- **At that point the plugin was 1.0.0, and the changelog opened on 1.0.0 too.** This file used to open on an unreleased `[1.1.0]`, a release that `git tag` (0) and `gh release list` (0) both denied at the time. That early entry moved **verbatim and complete** to an appendix at the foot of this file: nothing was deleted, because a changelog is a record and the record is that those entries were written. Moved by the release-hygiene script, which refuses to write if a single non-blank line would be lost.

### Fixed (2026-09-15, reader pass)

The REST, ledger, key, gate and shortcode items were reproduced before the change on a local WordPress install (WordPress 7.1, PHP 8.3) and on a remote staging install (WordPress 7.1, PHP 8.4) with a probe whose writes were rolled back. Every item has an assertion in `tests/test-suite.php` and an entry in `tests/mutations.php`.

- **The consent gate honoured rows that do not verify.** `has_valid_consent()` read the status of the newest row and checked neither its signature nor the chain: a row inserted by other means with `signature_hash = 'not-a-signature'` answered "GÜLTIGE EINWILLIGUNG". The new `consent_state()` answers `unverifiable` while `verify_chain()` is not valid, and the gate prints "REGISTER NICHT PRÜFBAR". Tokens and types are compared exactly in PHP, because MySQL's default collation would also match other case or accent spellings.
- **`record_consent()` stored a grant by default.** A missing, lower-case or unknown status was stored as `GRANTED`, the column defaulted to `GRANTED`, and a missing IP, type or scope became `127.0.0.1`, `EXCURSION` and a fixed hash. It now returns `false` unless the status is exactly `GRANTED`, `REVOKED` or `DENIED` and a token, an e-mail, a type, a 64-hex scope hash and a valid IP are given within the column lengths. The column has no default in schema 2, applied by `install()` with `ALTER TABLE … DROP DEFAULT` because `dbDelta()` never drops one.
- **A missing table read as a valid, empty chain.** Health `HEALTHY`, telemetry `chain_valid: YES`, a successful `self_test` and `wp edu status` all reported success without a table (a multisite subsite, a copy deployed without activation). `verify_chain()` now reports `missing_table` and a failed read as not valid, health is `ERROR`, `wp edu status` exits 1, and `maybe_upgrade()` on `plugins_loaded` creates the table on any site whose schema version is not 2.
- **Keys.** The consent signature and the tokenizer used the raw `AUTH_KEY` constant whenever it was set, so a site that kept the `wp-config-sample.php` phrase (which core's `wp_salt()` ignores) produced tokens and signatures anyone could compute, and the public `/tokenize` route computed HMACs under the signing key. Each purpose now has its own key derived from `wp_salt( 'auth' )` (`WM_Edu_Keys`); generated tokens change once. The 2026-09-14 entry below says "All three now use `wp_salt( 'auth' )`"; that was true only for the IP hash.
- **Signature format and key rotation.** Schema-2 signatures also cover `ip_hmac` and a fingerprint of the signing key (`key_id`) and encode the fields as a JSON list, so a `|` inside a field cannot move a boundary. A changed auth salt is reported as a changed key instead of tampering. Rows written before schema 2 keep verifying with their old payload and key up to the id stored in `wm_edu_legacy_row_boundary`; a row without `key_id` after that id does not verify.
- **The "consents" number** was the count of rows verified before the first break (0 once the first row had been edited) and included revocations. Telemetry reports `ledger_rows`, `ledger_rows_verified`, `ledger_legacy_rows`, `chain_valid` and `missing_table`; the literal `status: ACTIVE` is gone.
- **REST routes answered malformed input with a fatal TypeError** (HTTP 500): `known_students` as a JSON list, a number as a token, a number or a list as `token_map`. Both routes declare their arguments (`text` required and valid UTF-8; the maps objects of strings, a JSON list refused) and answer 400; the handlers check again. A request without `text` answered 200 with an empty result; it answers 400.
- **The routes changed the text beyond the replaced data.** `sanitize_text_field()` removed line breaks and `%ad` and turned `<3` into `&lt;3`. The text is now checked for type and UTF-8 only; the response is JSON, and a client that shows it as HTML escapes it.
- **Overlapping names left surnames in clear.** With `Max` and `Max Müller`, "Max Müller" became "[[STUDENT_…]] Müller". Names are replaced longest first through private-use placeholders, so tokens are also put in literally (a `$0` in a token put the name back) and a shorter name never matches inside a token. `detokenize()` replaces in one pass: with `S1` and `S10`, "S10" no longer becomes "Anna0". A pattern that fails throws instead of returning a half-replaced text.
- **Both shortcodes threw a fatal TypeError on WordPress 6.4** when used without attributes: before 6.5 core passes `''` (in 6.4 `shortcode_parse_atts()` returns `ltrim( $text )` when nothing parses). The callbacks accept `array|string`.
- **Contrast.** Button text measured 4.10:1 and 3.77:1 and the field border 2.36:1. The inline styles moved to `assets/css/wm-edu-portal.css` with custom properties: buttons `#0369a1` and `#047857`, field border `#94a3b8`, 48px targets, a visible focus outline; the suite computes every ratio from the sheet.
- **The gate's service field was never read** and named a Land platform ("SchulCloud NRW / Lernplattform"); it is gone, and the gate says that it answers for any consent type. The demo button said "Anonymisieren" for a reversible pseudonymisation, and the texts sent users to WP-CLI for name lists, which `wp edu tokenize` does not take.
- **Translations were never loaded** and the catalogs did not contain the code's strings. `load_plugin_textdomain()` runs on `init`, the user-facing strings go through gettext, and the template and the 29 catalogs are generated from the code, without the double-encoded UTF-8. None of them is translated yet.
- **`uninstall.php`** deleted an option and a transient that nothing wrote; it deletes the two options the plugin writes and still keeps the consent table (plan D7).

### Documentation (2026-09-15)

- `README.md`, `README.de.md`, `README.es.md`, `DEVELOPERS.md`, `readme.txt`, `SECURITY.md`, `CONTRIBUTING.md`, `llms.txt`, `public/humans.txt` and `CLAUDE.md` follow the code above; `AGENTS.md` is a copy of `CLAUDE.md` again (it still described a `/src/` folder and stylesheets that did not exist). `public/humans.txt` still said "Founder & CEO" (the 2026-09-14 entry below says that line had changed) and listed Gutenberg blocks, React, a transaction-safe data layer and conformity with BFSG, WCAG 2.2 AA, DSGVO and TDDDG; none of that is in the plugin. `SECURITY.md` promised response times and cited CRA Art. 14 and BSI guidelines without a process behind them. The banner and the mockup in `assets/images/` are no longer embedded and are left out of the package.
- The 2026-09-14 entry quoted the gate as «kein aktiver Nachweis»; the gate prints "KEIN NACHWEIS: … kein aktiver Einwilligungsnachweis …".

### Changed (2026-09-15)

- `tests/test-suite.php` runs on an in-memory SQLite `$wpdb` built from `table_sql()`; `tests/run-mutations.sh` undoes each correction of `tests/mutations.php` and requires the suite to fail; `tests/wp-runtime-edu.php` checks routes, schema, ledger, gate and shortcodes inside WordPress and rolls back. CI runs the PHP syntax check, the suite with its results line, the mutation check and the static audit on PHP 8.1, 8.2 and 8.3; the SBOM step, which could only print a warning, is gone.

### Documentation (2026-09-14)

- `README.md`, `README.de.md`, `README.es.md`, the 24 translated READMEs, `DEVELOPERS.md`, `CLAUDE.md`, `llms.txt`, `CONTRIBUTING.md`, `SECURITY.md`, `readme.txt` and the header description describe what the plugin ships: a tokenizer for dates of birth, class identifiers and caller-supplied names (two public routes, one command, a demo shortcode), a hash-chained consent table read by `[wm_edu_consent_gate]`, and a hub adapter. Until now they promised consent forms in an admin menu, `wp edupriv status|verify|export`, REST routes `/status` `/submit` `/export`, a token dictionary table, PDF/CSV GoBD exports, a `wm_edu_parent_consent_recorded` action and FERPA/COPPA/DSGVO guarantees; none of those exist. The docs now say plainly that nothing in the plugin writes a consent, that the gate and `/tokenize` are public, and that three replaced data types are not full anonymisation. The translated READMEs are generated from `README.md` by the fleet script `wp-plugin-fleet-translations-sync.py`.
- The entries **[1.1.0]** and **[1.0.0]** below list features that never shipped as described; they are kept as the record of what was written at the time, each such line marked. The plugin header stays at 1.0.0.

### Fixed (2026-09-14)

- **The hub adapter confirmed every action and reported zero consents.** `execute_action()` returned "executed successfully" for any action name; it now implements `self_test` (tokenizer on a known sentence plus chain verification) and reports unknown actions as failures. Telemetry reported a literal `consents: 0`; it reads the row count and chain state from the ledger. `get_settings_url()` pointed at `admin.php?page=wm-edu-portal`, a page nothing registers; it returns `null`. Test group 10.
- **Frontend claims:** the sanitizer called itself "DSGVO Art. 25 & BbgSchulG / SchulG NRW konform"; the gate headed itself "(DSGVO Art. 8 & Schulrecht)", promised a "SHA-256 Prüfsumme" it does not show and called a table row "ein aktiver Nachweis nach DSGVO Art. 8". The texts now say what is computed or looked up. Four emoji became inline SVG icons. `wp edu status` printed a hardcoded version, "DSGVO Art. 6(1)(e) & Art. 9" and "GÜLTIG (Revisionssicher)"; it prints the version constant and the chain state. Test group 11.

### Security (2026-09-14)

- **No more literal keys (HIGH, fleet audit 2026-09-13 §6.2 #24):** parent IPs were blinded with `hash_hmac( 'sha256', $ip, 'daily_ip_salt' )` — a key anyone could read in the source, so an IPv4 address was recoverable by brute force — and the signature and tokenizer fell back to `'wm_edu_consent_salt_2026'` / `'wm_edu_default_salt'` when `AUTH_KEY` was empty. All three now use `wp_salt( 'auth' )`. Existing `ip_hmac` values were computed with the old literal and will not match the new derivation; they remain valid as chain content (the signature does not cover them) but cannot be re-derived. Tests: group 8.

### Fixed (2026-09-14, gate and demo)

- **The parent consent gate consults the ledger (HIGH, §6.2 #22):** `[wm_edu_consent_gate]` printed «GÜLTIGE EINWILLIGUNG» for any string typed into it. It now asks `WM_Edu_Consent_Vault::has_valid_consent()` — the latest entry for the token must be `GRANTED`; a later `REVOKED`/`DENIED` entry wins — and says «kein aktiver Nachweis» otherwise. Tests: group 9.
- **The PII sanitizer demo stops pretending (§6.2 #23):** the shortcode only knew `'Max Müller'`; any other pupil's name passed through under a green «PII geschützt» counter. The hardcoded map is gone, dates of birth and class identifiers are still masked, and the result box says that names are only pseudonymised when the school's list is passed as `known_students` (REST/CLI).

### Changed (2026-09-14)

- **Plugin Check on the dist view (2026-09-14):** `readme.txt` added. Without it the checker read `README.md` as the readme and flagged the missing stable tag, tested version and licence line. The new file mirrors the plugin header (Stable tag 1.0.0, Requires PHP 8.1, GPLv2 or later) and describes only what the code registers; `Tested up to: 7.1` is backed by the local WordPress runtime pass.

---

## [1.0.0] — 2026-08-25

Date as written at the time; it predates the repository's first commit.

### Initial Enterprise Release

- Core architectural implementation for EdTech & School Privacy.
- WP-CLI management commands under `wp edu`.
- Revisionssicheres GoBD cryptographic ledger and telemetry hooks. — *a hash-chained consent table exists; nothing writes to it and no GoBD assessment is made*
- Strict compliance with DSGVO Art. 6(1)(e), Art. 8, Art. 9, SchulG NRW / Schulgesetze der Länder & GoBD. — *no such assessment is made by the code*

---

## Appendix — the entry written for 1.1.0 on 2026-08-29, never released

> **Historical record from 2026-09-17 (before the real 1.1.0 release above).** Measured 2026-09-17: `git tag` -> 0, `gh release list` -> 0, and `wm-edu-privacy-portal.php`, `readme.txt`, `SECURITY.md`, `includes/class-edu-spoke-adapter.php` carried **1.0.0** at that time. The entry below is kept verbatim as the record of what was written on 2026-08-29.

Date as written at the time; the first commit of this repository is from 2026-08-28 and there are no tags.

### Added

- **24 Official European Union Languages:** Full binary `.mo` and source `.po` translation packages in `languages/` (`bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el_GR`, `en_GB`, `es_ES`, `et_EE`, `fi_FI`, `fr_FR`, `ga_IE`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv_LV`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`). — *the catalogs held four words each*
- **Developer Documentation Suite:** Created `DEVELOPERS.md` with complete architecture diagrams, hook references, database schema, and REST API guides. — *the manual described routes, a table and an action that never shipped; rewritten from the code on 2026-09-14*
- **Decoupled REST API Controller:** Registered namespace `/wp-json/wm-edu/v1` with strict nonce and permission validation. — *the namespace exists with two public routes and no nonce*
- **Zero-CDN Frontend Shortcode:** Implemented `[wm_edu_pii_sanitizer]` with inline SVG icons and CSS design tokens. — *the shortcode existed; it used emoji and inline hex styles, no tokens*
- **CycloneDX v1.6 SBOM Engine:** Dynamic SHA-256 entry file integrity hashing and spoke adapter integration for **WM Suite Hub**. — *a component entry for the hub with the SHA-256 of the main file; no CycloneDX document and no schema validation*
- **Enterprise Showcase Assets:** High-fidelity 16:9 marketing banner and cockpit showcase mockups conditioned on real screenshot topology. — *the cockpit mockup shows a screen the plugin does not have*
- **Clean Uninstallation Lifecycle:** Comprehensive `uninstall.php` handler for clean database and transient cache purging. — *it deleted an option and a transient nothing wrote and kept the consent table*

### Security

- **CWE-1236 Mitigation:** Active sanitization against formula injection attacks on all CSV exports and dynamic text inputs. — *never shipped: there is no CSV export; `sanitize_formula()` has no caller*
- **Constant-Time Cryptography:** Enforced `hash_equals()` verification on all HMAC-SHA256 signature chains. — *applies to `verify_chain()`*
- **SQL Parameterization:** Refactored all queries to use strict `$wpdb->prepare()` with parameterized placeholders. — *partly at the time: the consent lookup was prepared, the chain tip and chain walk interpolated the table name; all are prepared since 2026-09-15*

### Quality & Tests

- Expanded standalone deterministic test suite (`tests/test-suite.php`) with a green pass rate and zero external dependencies.
