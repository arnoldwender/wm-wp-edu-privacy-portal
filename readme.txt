=== WM Edu Privacy Portal ===
Contributors: arnoldwender
Tags: education, privacy, consent, tokenization, dsgvo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Records parental consent in a hash-chained register through a form confirmed by e-mail, and looks it up for the school.

== Description ==

* **Consent form:** the shortcode `[wm_edu_consent_form]` asks guardians for the pupil ID the school gave them, their e-mail address and a decision per consent type. The answer is the same whether or not the pair is registered; only a registered pair receives a one-time link, and the decision is recorded when that link's page is submitted, never when it is opened. The receipt mail carries a revocation link. Submissions are rate-limited per IP and per address.
* **Consent register:** a hash-chained table. Every entry holds a database lock, links to the recorded chain tip and is written with it in one transaction; entries carry where they came from, who made them and why. Entries are never deleted during normal operation, and uninstalling preserves all options and register tables by default unless an administrator explicitly opts into `wm_edu_delete_data_on_uninstall`.
* **Admin screen:** current state per pupil and consent type, history, manual entry with its reason, revocation, consent types with their exact wording, the guardian registry, CSV export and the state of the signature chain. Behind the capability `manage_edu_consents`; the settings need `manage_options`.
* **Consent lookup:** the shortcode `[wm_edu_consent_gate]` answers for one pupil ID, optionally for one consent type, and shows nothing while the chain does not verify. It is not public; a setting restores the earlier public behaviour.
* **Block Bindings & GenerateBlocks:** registers the WordPress 6.5+ Block Bindings source `wm-edu/status`, the GenerateBlocks 2.x Dynamic Tag `{{wm_edu_status}}`, and the `wm_edu_ready` action.
* **Tokenizer:** German dates and class identifiers are replaced by placeholders such as `[[DOB_…]]` and `[[CLASS_…]]`; pupils' names only when the school's list is passed as `known_students`. The REST routes `wm-edu/v1/tokenize` and `/detokenize` and the demo shortcode need a logged-in user with a capability (`edit_posts` by default, filterable), because computing the token of a guessed name or date re-identifies a text. Replacing these three kinds of data is not full anonymisation.
* **WP-CLI:** `wp edu status` (exit 1 when the chain does not verify), `wp edu tokenize "<text>"` and `wp edu cleanup` (retention of request data).

Zero-CDN: nothing is loaded from another host. The plugin makes no assessment under the GDPR, the German school acts, FERPA or COPPA.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-edu-privacy-portal/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. School consent register overview behind manage_edu_consents with hash-chain integrity check
2. Consent types administration archiving versioned consent texts by SHA-256 scope hash
3. Guardian consent request and lookup shortcodes ([wm_edu_consent_form] and [wm_edu_consent_gate])

== Changelog ==


= 1.2.1 =
* Fixed: The result line of the tokenizer demo shortcode («Pseudonymisierter Text (%d Datum oder Klassenbezeichnung ersetzt)») repeated one form for several numbers in Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak and Slovenian (12 catalogs), for example Czech «nahrazeno 2 údajů» and Polish «zastąpiono 2 dat»; each number now gets the form its language uses (Czech «nahrazeny 2 údaje», Polish «zastąpiono 2 daty»). The plugin's code is unchanged.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.6 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-edu-privacy-portal` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.5 =
* Reword screenshot #2 description to neutral phrasing (UWG compliance check) and regenerate release metadata and visual banner for v1.1.5.

= 1.1.4 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..3.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-edu-privacy-portal/`).

= 1.1.3 =
* Point Plugin URI to the official plugin page.

= 1.1.2 =
* Multisite provisioning: hooked `wp_initialize_site` for network-activated subsites (`switch_to_blog` / `restore_current_blog` inside `try/finally`), added `$network_wide` support in `WM_Edu_Consent_Vault::install()`, and guarded the `dbDelta()` require cleanly.
* Autoload hygiene: stored `wm_edu_schema_version` with explicit `$autoload = false` alongside `wm_edu_delete_data_on_uninstall`, `wm_edu_legacy_row_boundary` and `wm_edu_chain_tip`.

= 1.1.1 =
* Added real `mysql:8.0` integration matrix (`mysql-integration`) to GitHub Actions CI verifying `GET_LOCK()`, InnoDB transactions, and schema constraints against a live MySQL 8.0 engine.

= 1.1.0 =
* Ecosystem bridges: WordPress 6.5+ Block Bindings source `wm-edu/status`, GenerateBlocks 2.x Dynamic Tag `{{wm_edu_status}}`, `wm_edu_ready` action, `wm_edu_status_value`, and CSV export filters `wm_edu_export_csv_output` and `wm_edu_export_csv_stream`.
* Uninstallation: preserves options and consent ledger data by default; explicit opt-in setting `wm_edu_delete_data_on_uninstall` on the settings screen removes plugin options, capabilities and tables when enabled.
* Translations: complete `.po`/`.mo` catalogs across 29 locales, CycloneDX 1.6 `bom.json`, and WordPress Playground blueprint.
* Consent form for guardians with confirmation by e-mail, one-time links, revocation links, rate limit and retention of request data.
* Consent types with their exact wording (its SHA-256 is the scope hash) and an archive of every wording a type ever had.
* Guardian registry: only an HMAC of the address is stored, and the form answers the same whether or not a pair is registered.
* Admin screen: state per pupil and type, history, manual entries and revocations signed with the acting user and a reason, CSV export, chain verification, settings.
* Consent register: named database lock per append, links to a signed recorded tip, row and tip in one transaction, schema 3 signs source, actor and reason.
* Access: the lookup, the tokenizer routes and the demo are no longer public; settings restore the earlier behaviour.
* Earlier in this release: consent gate shows no consent while the chain does not verify; no GRANTED default; keys derived per purpose from wp_salt( 'auth' ); REST arguments validated; overlapping names; shortcodes on WordPress 6.4; contrast of the forms; translations loaded and catalogs generated from the code.

= 1.0.0 =
* Initial release. (An earlier edition of this file listed a 1.1.0 with a REST controller, 24 language packs and an uninstall handler; the header never carried 1.1.0.)

== Upgrade Notice ==

= 1.2.1 =
Translation fix only: plural forms in 10 languages. No code, data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; until both are updated the plugin keeps working on its own but is not listed in the hub cockpit.
