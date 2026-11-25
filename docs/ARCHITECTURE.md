# Architecture — WM Edu Privacy Portal
`wm-edu-privacy-portal` · version `1.1.2` from the plugin header · WordPress 6.4+ · PHP 8.1+ · text domain `wm-edu-privacy-portal`

Measured against the source code in this repository — every figure below comes from the code, not from any document.

---

## What runs where

```
WM_Edu_Consent_Types     what can be consented to: slug, label, wording (its SHA-256 is the scope hash)
                           + a text archive, so an old entry's wording survives an edit

WM_Edu_Guardians         pupil ID  <->  HMAC of a guardian address; the only check on who may answer

WM_Edu_Consent_Form      [wm_edu_consent_form]: request -> e-mailed one-time link -> POST records
WM_Edu_Consent_Requests    the decision; receipt mail -> revocation link -> POST records REVOKED
                           rate limit per IP and per address, retention of request data (daily event)

WM_Edu_Consent_Vault     the hash-chained register, schema 3
                           every append: named lock -> recorded tip -> row + tip in ONE transaction
                           read by [wm_edu_consent_gate] and by the admin screen

WM_Edu_Admin             five pages behind manage_edu_consents (settings behind manage_options)
WM_Edu_Access            who may look up, who may tokenize; two settings restore public access

WM_Edu_PII_Tokenizer     German dates, class identifiers, caller-supplied names
                           POST wm-edu/v1/tokenize · /detokenize   (authenticated)
                           wp edu tokenize · a demo shortcode

WM_Edu_Keys              one key per purpose, derived from wp_salt('auth')
WM_Edu_Ecosystem         wm_edu_ready action · Block Bindings wm-edu/status · GenerateBlocks {{wm_edu_status}}
WM_Edu_Spoke_Adapter     added through the wm_register_suite_module filter
```

Plain classes under `includes/` (`class-edu-*.php`) — no namespace, no autoloader.

---

## Wiring — what to touch for a given change

| Change | Files, in order | Trap |
|---|---|---|
| Add a token type | `WM_Edu_PII_Tokenizer` → the route schema → assertion + mutation | the routes return **plain text**: check type and UTF-8, escape on output, and do **not** HTML-sanitise what you return |
| Change a signed field | `row_signature()` → **raise the schema version** → keep verifying older rows (boundary option for schema 1, `row_version` for schema 2) → add the upgrade step to `install()` | `dbDelta()` never drops a column default. Changing a signed field without the version bump makes every stored row fail verification |
| Add a table | `WM_Edu_Consent_Vault::all_table_sql()` → one `dbDelta()` call → the schema version | a table created outside `all_table_sql()` never reaches a site that upgrades instead of activating |
| Write a consent from new code | `WM_Edu_Consent_Vault::append_consent()` with a `source` | never insert into the table directly: the lock, the recorded tip and the transaction are what keep the chain honest |
| Add a key | `WM_Edu_Keys`, one key per purpose, derived from `wp_salt('auth')` | never a literal and never the raw `AUTH_KEY` — only `legacy_signature_key()` touches it, to verify schema-1 rows |
| Add an admin action | `process_*()` (no output, returns a notice code) → `handle_*()` (capability + nonce + redirect) → a code in `notice()` | the redirect carries the **code**, never submitted text; an unknown code prints nothing |
| Add an icon | inline SVG with `aria-hidden="true"` | **no emoji**; PHP output carries no `style=` and no colour (groups 8, 16, 17), and the sheet keeps colours and dimensions on custom-property lines (group 9) |

---

## Fragile spots

1. **The default is never "all good".** A missing or unknown status is refused, not stored as `GRANTED`. A missing table, a failed read, or a register that does not end at its recorded tip is not a valid chain. While `verify_chain()` is not valid the lookup shows no consent and health is `ERROR` — never the other way round, and nothing repairs a chain.
2. **A consent is recorded on POST only**, and only for a pupil ID and address that are registered together. The form's answer is identical either way; a message, a status code or a redirect that differs would turn it into a directory of pupils.
3. **The recorded tip is the anchor.** An append links to it, not to the last row; a tip that is missing or unsigned is reported and is never re-signed by a later write. The tip lives in `wp_options`, so whoever can delete rows can delete it too — that is a known limit, not a bug to paper over.
4. **A database without MySQL named locks refuses every consent write** (the SQLite integration answers `GET_LOCK()` with `1=1`). Refusing is the design: an unserialised append forks the chain and the site then accuses itself of tampering.
5. **Keys come from `WM_Edu_Keys`** — see the wiring table. A changed auth salt breaks row verification *and* the guardian registry.
6. **Recorded consents are evidence:** never deleted by retention or by the admin screen, and preserved by `uninstall.php` unless `wm_edu_delete_data_on_uninstall` is explicitly enabled on the settings screen. Retention is about request data only.
7. **Chain errors and WP-CLI output stay untranslated German** on purpose: they can run before `init`, where a translation call would fail.
8. **Catalog freshness:** keep `languages/wm-edu-privacy-portal.pot` and the 29 `.po`/`.mo` files in sync with `tests/test-pot-freshness.php`.
9. **No conformity claims.** The plugin makes no DSGVO, school-act, FERPA, COPPA or GoBD assessment, does not say whether a recorded consent is valid, and **does not fully anonymise**.
10. `tests/test-suite.php` needs `pdo_sqlite`; it builds its `$wpdb` from the plugin's own CREATE TABLE statements, so the tables the tests use cannot drift from the schema.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-edu-privacy-portal --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**17 PHP files, 4084 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 187 |
| `includes` | 15 | 3897 |

---

## Classes

| Class | File |
|---|---|
| `WM_Edu_Access` | [`includes/class-edu-access.php`](../includes/class-edu-access.php) |
| `WM_Edu_Admin` | [`includes/class-edu-admin.php`](../includes/class-edu-admin.php) |
| `WM_Edu_CLI` | [`includes/class-edu-cli.php`](../includes/class-edu-cli.php) |
| `WM_Edu_Consent_Form` | [`includes/class-edu-consent-form.php`](../includes/class-edu-consent-form.php) |
| `WM_Edu_Consent_Requests` | [`includes/class-edu-consent-requests.php`](../includes/class-edu-consent-requests.php) |
| `WM_Edu_Consent_Types` | [`includes/class-edu-consent-types.php`](../includes/class-edu-consent-types.php) |
| `WM_Edu_Consent_Vault` | [`includes/class-edu-consent-vault.php`](../includes/class-edu-consent-vault.php) |
| `WM_Edu_Ecosystem` | [`includes/class-edu-ecosystem.php`](../includes/class-edu-ecosystem.php) |
| `WM_Edu_Guardians` | [`includes/class-edu-guardians.php`](../includes/class-edu-guardians.php) |
| `WM_Edu_Keys` | [`includes/class-edu-keys.php`](../includes/class-edu-keys.php) |
| `WM_Edu_PII_Tokenizer` | [`includes/class-edu-pii-tokenizer.php`](../includes/class-edu-pii-tokenizer.php) |
| `WM_Edu_REST_Controller` | [`includes/class-edu-rest-controller.php`](../includes/class-edu-rest-controller.php) |
| `WM_Edu_Settings` | [`includes/class-edu-settings.php`](../includes/class-edu-settings.php) |
| `WM_Edu_Shortcodes` | [`includes/class-edu-shortcodes.php`](../includes/class-edu-shortcodes.php) |
| `WM_Edu_Spoke_Adapter` | [`includes/class-edu-spoke-adapter.php`](../includes/class-edu-spoke-adapter.php) |

---

## Entry points

**REST routes:** `/detokenize`, `/tokenize`

**WP-CLI:** `edu`

**Shortcodes:** `wm_edu_consent_form`, `wm_edu_consent_gate`, `wm_edu_pii_sanitizer`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_edu_delete_data_on_uninstall` | `includes/class-edu-admin.php` | `includes/class-edu-settings.php`, `uninstall.php` |

Every key has exactly one writing layer and at least one reader.


---

## Gates

- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-pot-freshness.php`
- `tests/test-suite.php`
- `tests/wp-runtime-edu.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

