# WM Edu Privacy Portal — Developer Manual

> Everything below is measured against the code in this repository (2026-09-17). Until 2026-09-17 nothing in the plugin wrote a consent, there was no admin screen, and the lookup and the tokenizer routes were public.

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-edu-privacy-portal.php` | Header, constants (`WM_EDU_PORTAL_VERSION` / `_FILE` / `_PATH`), `require_once` of the classes, shortcode, form and REST init, the admin screen inside `is_admin()`, CLI file when `WP_CLI` is defined, text domain on `init`, schema check and cron sync on `plugins_loaded`, hub registration on `plugins_loaded` (20), multisite subsite provisioning on `wp_initialize_site`, activation hook `WM_Edu_Consent_Vault::install()`, deactivation hook `WM_Edu_Consent_Requests::unschedule()`. |
| `includes/class-edu-keys.php` | `WM_Edu_Keys`: `derive( $purpose )` (`tokenizer`, `consent-signature`, `chain-tip`, `guardian-email`, `rate-limit`), `signature_key_id()`, `legacy_signature_key()`. |
| `includes/class-edu-settings.php` | `WM_Edu_Settings`: one option, defaults, limits, `all()`, `get()`, `sanitize()`, `save()`. |
| `includes/class-edu-access.php` | `WM_Edu_Access`: the capability, `grant_capability()`, `can_manage()`, `can_use_gate()`, `can_use_tokenizer()`, `tokenizer_capability()`, `rest_tokenizer_permission()`. |
| `includes/class-edu-pii-tokenizer.php` | `tokenize()`, `detokenize()`, `sanitize_formula()` (used by the CSV export). |
| `includes/class-edu-consent-vault.php` | Schema and ledger: `all_table_sql()`, `install()` / `maybe_upgrade()`, `lock_name()`, `recorded_tip()`, `tip_signature()`, `current_chain_tip()`, `row_signature()`, `record_consent()` / `append_consent()`, `row()`, `all_rows()`, `rows_for_student()`, `current_entries()`, `consent_state()`, `has_valid_consent()`, `verify_chain()`. |
| `includes/class-edu-consent-types.php` | `WM_Edu_Consent_Types`: the two tables, `save()`, `all()`, `active()`, `get()`, `get_by_slug()`, `normalize_slug()`, `normalize_text()`, `scope_hash()`, `text_for_scope()`. |
| `includes/class-edu-guardians.php` | `WM_Edu_Guardians`: registry table, `matches()`, `add()`, `import()`, `remove()`, `remove_student()`, `students()`, `email_hmac()`, `normalize_student()`, `normalize_email()`. |
| `includes/class-edu-consent-requests.php` | `WM_Edu_Consent_Requests`: request table, `rate_limited()`, `client_ip()`, `submit()`, `confirmable()`, `revocable()`, `decisions()`, `confirm()`, `revoke()`, `granted_rows()`, `cleanup()`, `sync_schedule()`, `unschedule()`. |
| `includes/class-edu-consent-form.php` | `WM_Edu_Consent_Form`: `[wm_edu_consent_form]`, `handle_request()` on `template_redirect`, `validate()`, the three views. |
| `includes/class-edu-shortcodes.php` | `[wm_edu_pii_sanitizer]`, `[wm_edu_consent_gate]`, `icon()` (inline SVG), `enqueue_style()`. |
| `includes/class-edu-admin.php` | `WM_Edu_Admin`: menu, five pages, `register_settings()`, `process_record()`, `process_revoke()`, `process_type()`, `process_guardian()`, `process_settings()`, `csv()`, the `admin_post_` handlers. |
| `includes/class-edu-ecosystem.php` | `WM_Edu_Ecosystem`: `wm_edu_ready` action, WordPress 6.5+ Block Bindings source `wm-edu/status`, GenerateBlocks 2.x Dynamic Tag `{{wm_edu_status}}`, `wm_edu_status_value` filter. |
| `includes/class-edu-rest-controller.php` | Two routes under `wm-edu/v1` with argument schemas, validators and the access permission callback. |
| `includes/class-edu-cli.php` | `WM_Edu_CLI` (`status`, `tokenize`, `cleanup`) and `WP_CLI::add_command( 'edu', … )`. |
| `includes/class-edu-spoke-adapter.php` | WM Suite Hub contract; returns early when the hub's interface does not exist. |
| `assets/css/wm-edu-portal.css` | Sheet of the three forms; every colour and dimension is a custom property on `.wm-edu-box`. |
| `languages/` | Template and 29 translated `.po` / `.mo` catalogs. |
| `bom.json` | CycloneDX 1.6 software bill of materials (`GPL-2.0-or-later`, SHA-256 of `wm-edu-privacy-portal.php`). |
| `.wordpress-org/blueprints/blueprint.json` | WordPress Playground blueprint landing on `/wp-admin/admin.php?page=wm-edu-consents`. |
| `uninstall.php` | Clears scheduled hooks; preserves all options, capabilities and tables by default unless `wm_edu_delete_data_on_uninstall` is explicitly enabled. |
| `tests/` | `test-suite.php` (SQLite), `mutations.php` and `run-mutations.sh`, `wp-runtime-edu.php`, `fixtures/hub-interface-stub.php`. |

---

## 2. Keys

`WM_Edu_Keys::derive( $purpose )` is `hash_hmac( 'sha256', 'wm-edu-privacy-portal|' . $purpose, wp_salt( 'auth' ) )`. Purposes: `tokenizer` (token hex), `consent-signature` (row signatures), `chain-tip` (the recorded tip), `guardian-email` (the registry's address HMAC), `rate-limit` (transient keys of the form). `signature_key_id()` is the first 16 hex characters of an HMAC of a fixed label under the signing key. The writer's IP is blinded with `hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) )`. `legacy_signature_key()` (`AUTH_KEY` when set, else `wp_salt( 'auth' )`) only verifies schema-1 rows.

`wp_salt( 'auth' )` is `AUTH_KEY . AUTH_SALT` from `wp-config.php`, or a value WordPress generated and stored in the database. Changing it changes every derived key: rows report a changed key and the guardian registry stops matching.

---

## 3. Tables (schema 3)

`{prefix}wm_edu_parent_consents` — `id`, `student_token` VARCHAR(64), `parent_email` VARCHAR(190), `consent_type` VARCHAR(50), `scope_hash` VARCHAR(64), `granted_status` VARCHAR(20) **without default**, `ip_hmac` VARCHAR(64), `key_id` VARCHAR(16) default `''`, `prev_hash`, `signature_hash`, `created_at`, `source` VARCHAR(20) default `''`, `actor_id` BIGINT default 0, `note` VARCHAR(500) default `''`, `row_version` VARCHAR(4) default `''`; keys on `student_token`, `consent_type`, `signature_hash`.

`{prefix}wm_edu_consent_types` — `id`, `slug` VARCHAR(50) unique, `label`, `description`, `scope_hash`, `active`, `created_at`, `updated_at`.
`{prefix}wm_edu_consent_texts` — `scope_hash` (primary key), `consent_text` LONGTEXT, `created_at`.
`{prefix}wm_edu_guardians` — `id`, `student_token`, `email_hmac`, `created_at`.
`{prefix}wm_edu_consent_requests` — `id`, `confirm_hash`, `revoke_hash`, `status` (`pending`, `confirming`, `confirmed`, `revoking`, `revoked`), `student_token`, `parent_email`, `decisions` (JSON), `consent_ids` (JSON), `created_at`, `expires_at`, `updated_at`.

`install( bool $network_wide = false )` iterates all network sites (`switch_to_blog()` / `restore_current_blog()` inside `try/finally`) when `$network_wide` is true on multisite, and per site reads `MAX(id)` of an existing consent table, runs one `dbDelta()` with every CREATE TABLE (guarding the `upgrade.php` require with `file_exists()` and `function_exists( 'dbDelta' )`), drops the `granted_status` default on an existing table (`dbDelta()` never drops one), records `wm_edu_legacy_row_boundary` (`autoload = false`; an existing value is kept), writes the recorded tip (`autoload = false`) when none exists, grants the capability and sets `wm_edu_schema_version` to `3` (`autoload = false`). Any step that fails leaves the version unset, and the next load tries again. `maybe_upgrade()` on `plugins_loaded` runs `install()` whenever the stored version is not `3`, and `wm_edu_on_initialize_site()` on `wp_initialize_site` calls `install()` on new multisite subsites when network-activated.

---

## 4. Writing the chain

`append_consent( array $data ): int|WP_Error` — `record_consent()` is the same call with `false` instead of the error. Required: `granted_status` exactly `GRANTED`, `REVOKED` or `DENIED`, `student_token` (≤64), `parent_email` (valid, ≤190), `consent_type` (≤50), `scope_hash` (64 hex), `ip_address` (a valid IP). Optional: `source` (one of `direct`, `parent_confirmation`, `parent_revocation`, `admin_entry`, `admin_revocation`), `actor_id` (int ≥ 0), `note` (≤500); the two admin sources need an actor and a note.

Then: `GET_LOCK( <per database and table>, 5 )` — `1` is the lock, `0` and NULL are not, and neither writes (`wm_edu_chain_busy`, status 503) — `START TRANSACTION`, insert, `update_option` of the tip, `COMMIT`. The row's `prev_hash` is the **recorded tip**, not the last row of the table; when the tip is missing or its signature does not verify, the row links to the table's end and no tip is written, so a later write never blesses a register that does not verify. Error codes: `wm_edu_consent_invalid` (400), `wm_edu_consent_table_missing` (503), `wm_edu_chain_busy` (503), `wm_edu_consent_not_written` (500).

`row_signature()` picks its payload by the row's own `row_version`: `'3'` signs `["v3", key_id, student_token, parent_email, consent_type, scope_hash, granted_status, ip_hmac, created_at, prev_hash, source, actor_id, note]`, anything else the v2 list without the last three. Both are JSON, so a `|` inside a field cannot move a boundary.

`verify_chain()` returns `valid`, `rows`, `checked`, `legacy_rows`, `broken_at_id`, `error`, `missing_table`, `key_changed`, `tip_mismatch`. It walks the rows by id (schema-1 rows up to the boundary with their old payload and key; a row without `key_id` after it is invalid; an unknown `row_version` is invalid) and then compares the recorded tip: missing, unsigned or not at the end of the table is not valid. The error texts are German and untranslated (the method can run before `init`).

---

## 5. The guardian flow

1. `WM_Edu_Consent_Form::handle_request()` runs on `template_redirect`, never while the shortcode renders, and answers a submission with a 303 to the same page (`?wm_edu_status=requested|confirmed|revoked`).
2. `rate_limited( $ip, $email )` counts every submission per client IP and per address in a fixed hourly window (transients keyed by HMAC).
3. `validate()` checks the pupil ID, the address, at least one decision among the active types and the read box.
4. `submit()` answers `unregistered` without doing anything when `WM_Edu_Guardians::matches()` is false; otherwise it stores a pending request (only `hash( 'sha256', $token )`) and mails the link. A mail that cannot be sent deletes the request.
5. `confirm( $token, $ip, $page )` → `confirmed` | `invalid` | `busy` | `error`. It claims the request (`UPDATE … WHERE status = 'pending'`), appends one row per decision with `source = parent_confirmation`, keeps the ids it already recorded when a write fails (a retry records only the rest), then stores `confirmed`, a revocation hash for the granted ids, clears pupil ID and address, and mails the receipt.
6. `revoke( $token, $ip )` → `revoked` | `invalid` | `busy` | `error`. Same claim, one `REVOKED` row per granted id with `source = parent_revocation`, address and scope copied from the row it revokes.
7. `cleanup( $now )` deletes pending requests whose link expired more than `retention_days` ago, deletes closed requests (revoked, or confirmed without a revocation link) that old, and releases claims older than an hour. The ledger is never touched.

---

## 6. Shortcodes

| Tag | Who sees it | Behaviour |
| --- | --- | --- |
| `[wm_edu_consent_form]` | everybody | request form, confirmation page (`?wm_edu_confirm=…`), revocation page (`?wm_edu_revoke=…`), result messages |
| `[wm_edu_consent_gate]` | `manage_edu_consents`, a role in `gate_roles`, or everybody while `public_gate` is on | looks up `consent_state()` for the pupil ID, for the type in the `type` attribute when given; nonce for logged-in users |
| `[wm_edu_pii_sanitizer]` | the tokenizer capability, or everybody while `public_tokenizer` is on | runs `tokenize()` without names on the posted text |

All three accept `''` as attributes (WordPress before 6.5 passes it), enqueue the style `wm-edu-portal` and carry no inline style; icons are inline SVG with `aria-hidden="true"`.

---

## 7. REST

| Method | Route | Permission | Arguments | Response |
| --- | --- | --- | --- | --- |
| `POST` | `/wp-json/wm-edu/v1/tokenize` | `WM_Edu_Access::rest_tokenizer_permission` | `text` (string, required, valid UTF-8), `known_students` (object of strings) | `200 { success, data: { sanitized_text, token_map, detections }, timestamp }`; 401/403; 400 `rest_invalid_param`; 500 `wm_edu_tokenize_failed` |
| `POST` | `/wp-json/wm-edu/v1/detokenize` | same | `text`, `token_map` | `200 { success, data: { restored_text }, timestamp }` |

Core validates the arguments **before** the permission callback, so a request with a malformed body is answered 400 without the guard ever running. A cookie-authenticated request needs the `wp_rest` nonce in `X-WP-Nonce`; without it WordPress sets the current user to 0 and the routes answer 401. An application password needs no nonce.

---

## 8. WP-CLI

```bash
wp edu status                                            # version, rows, verified, schema-1 rows, chain tip, recorded tip; exit 1 when not valid
wp edu tokenize "Aus der Kl. 7c, geboren am 15.03.2012." # dates and class identifiers only
wp edu cleanup                                           # applies the retention period to request data
```

---

## 9. Hooks, options and settings

| Hook | Where | Effect |
| --- | --- | --- |
| shortcodes registered at load | `WM_Edu_Shortcodes::init()`, `WM_Edu_Consent_Form::init()` | the three shortcodes |
| `template_redirect` | `WM_Edu_Consent_Form::init()` | processes a submission once, sends no-cache headers on token pages |
| `rest_api_init` | `WM_Edu_REST_Controller::init()` | the two routes |
| `init` | main file | `load_plugin_textdomain( 'wm-edu-privacy-portal', false, '<plugin dir>/languages' )` |
| `init` (20) | `WM_Edu_Ecosystem::init()` | registers Block Bindings `wm-edu/status`, GenerateBlocks Dynamic Tag `{{wm_edu_status}}`, and fires `wm_edu_ready` |
| `generateblocks_dynamic_tag_output` | `WM_Edu_Ecosystem::init()` | resolves `{{wm_edu_status}}` fallback output |
| `plugins_loaded` (10) | main file | `WM_Edu_Consent_Vault::maybe_upgrade()`, `WM_Edu_Consent_Requests::sync_schedule()` |
| `wm_edu_retention_cleanup` | main file | `WM_Edu_Consent_Requests::cleanup()` (daily) |
| `plugins_loaded` (20) | main file | adds `WM_Edu_Spoke_Adapter` to `wm_register_suite_module` when the hub's interface exists |
| `admin_menu`, `admin_init`, `admin_post_wm_edu_*` | `WM_Edu_Admin::init()` (inside `is_admin()`) | the five pages, `register_setting()` for `wm_edu_delete_data_on_uninstall`, and the six handlers |
| `register_activation_hook` / `register_deactivation_hook` | main file | `install( $network_wide )` / `unschedule()` |
| `wp_initialize_site` | main file | `wm_edu_on_initialize_site()` provisions new multisite subsites when network-activated |

**Action the plugin fires:** `wm_edu_ready` (plugin version). **Filters the plugin offers:** `wm_edu_tokenizer_capability` (string, default `edit_posts`), `wm_edu_can_close_response` (bool), `wm_edu_status_value` (string, args), `wm_edu_export_csv_output` (string), `wm_edu_export_csv_stream` (string). **Options it writes:** `wm_edu_schema_version` (`autoload = false`), `wm_edu_legacy_row_boundary` (`autoload = false`), `wm_edu_chain_tip` (`autoload = false`), `wm_edu_settings`, `wm_edu_delete_data_on_uninstall` (default `'0'`, `autoload = false`). **Settings:** `public_gate`, `public_tokenizer` (both false), `gate_roles` (empty), `confirm_hours` (48, 1–720), `retention_days` (7, 0–365), `rate_ip_per_hour` (10, 1–1000), `rate_email_per_hour` (3, 1–100). Every read applies the limits again, so a value edited in the database cannot open access or stretch a link's life.

---

## 10. Known drift (documented, not fixed)

1. A database without MySQL named locks refuses every consent write with 503; the SQLite Database Integration plugin is such a database (its `GET_LOCK()` returns the string `1=1`).
2. The recorded tip is an option: whoever can delete rows can delete it too, and the register then stays "tip missing" until a backup restores it. Nothing repairs a chain.
3. The registry is the only check on who may answer for a pupil, and the mail is flushed on `shutdown` after closing the response where supported (`fastcgi_finish_request` / `litespeed_finish_request`, or an immediate single cron event otherwise).
4. A changed auth salt makes rows report a changed key and the registry stop matching; nothing re-signs or re-imports.
5. `uninstall.php` preserves all options, the ledger, the tip, the types, the texts and the registry by default unless `wm_edu_delete_data_on_uninstall` is explicitly enabled on the settings screen.
6. The date pattern matches every German date; names need a caller-supplied list; two pupils with the same name get the same generated token; no other personal data is detected.

---

## 11. WM Suite Hub spoke

`WM_Edu_Spoke_Adapter`: slug `wm-edu-privacy-portal`, version from the constant, health `ERROR` when `verify_chain()` is not valid and `HEALTHY` otherwise, a component entry with the SHA-256 of the main file and `GPL-2.0-or-later`, settings URL `admin.php?page=wm-edu-consents-settings`, telemetry `ledger_rows`, `ledger_rows_verified`, `ledger_legacy_rows`, `chain_valid`, `missing_table`, `consent_types_active`, `requests_pending`; action `self_test` (the tokenizer on `Max Müller aus Klasse 9b, geboren am 15.03.2012` plus chain verification). Every other action returns `success: false`.

---

## 12. Tests

```bash
php tests/test-suite.php     # 18 groups on an in-memory SQLite $wpdb built from the plugin's own CREATE TABLE statements; needs pdo_sqlite
bash tests/run-mutations.sh  # every entry of tests/mutations.php undone once; the suite must report a failure each time
wp eval-file wp-content/plugins/wm-edu-privacy-portal/tests/wp-runtime-edu.php --user=1   # in WordPress, rolled back, counts compared
```

Run WordPress Plugin Check (`wp plugin check wm-edu-privacy-portal`) on the packaged distribution directory, exercise `tests/wp-runtime-edu.php` on a WordPress install, and keep `tests/test-pot-freshness.php` green whenever translatable strings change.

---

## 13. Security disclosure

See [SECURITY.md](SECURITY.md).
