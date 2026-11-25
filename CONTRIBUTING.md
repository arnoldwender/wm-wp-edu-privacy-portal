# Contributing to WM Edu Privacy Portal

Thank you for your interest in contributing to **WM Edu Privacy Portal**, part of the WM Suite plugin suite.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-edu-privacy-portal/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Development Standards

1. **PHP 8.1+ Strict Typing:** Every PHP file must start with `declare(strict_types=1);`.
2. **Zero-CDN Architecture:** No external resources (CDNs, Google Fonts, external telemetry) may be loaded at runtime.
3. **Consent chain integrity:** every append holds the named database lock, links to the recorded chain tip (a signed option, not the last row) and writes row and tip in one transaction; rows carry an HMAC-SHA256 signature over every stored field, the key fingerprint, the source, the acting user and the reason. `verify_chain()` must keep detecting an edited row, a changed key, a missing table, a failed read and a register that does not end at its tip (test groups 4, 5 and 14). Keys come from `WM_Edu_Keys`, derived from `wp_salt( 'auth' )`; never from the source or the raw `AUTH_KEY`.
4. **The default is never "all good":** a missing status, a missing table, a chain that does not verify or a tip that is missing never reads as a consent or as healthy, and nothing repairs a chain to make it verify.
5. **Consent is recorded on POST, for registered pairs only,** and the form answers the same whether or not a pair is registered. Recorded consents are never deleted; retention applies to request data.
6. **Docs measured against the code:** `README.md` and `DEVELOPERS.md` describe what the plugin registers. A new route, command, shortcode, table or setting is documented in the same pull request; nothing is documented that does not exist, and no legal conformity is claimed.
7. **Accessibility:** text at least 4.5:1, field borders and buttons at least 3:1, 48px targets, a visible focus outline (test group 9 computes the ratios from `assets/css/wm-edu-portal.css`); colours and dimensions only as custom properties; icons are inline SVG, never emoji; every field has a label, and errors are linked to the field they belong to.
8. **Tests:** a fix comes with an assertion that fails without it and an entry in `tests/mutations.php`.

---

## Development Workflow

```bash
# 1. Clone the repository
git clone https://github.com/arnoldwender/wm-wp-edu-privacy-portal.git
cd wm-edu-privacy-portal

# 2. Run the standalone suite (no WordPress needed; pdo_sqlite required) and the mutation check
php tests/test-suite.php
bash tests/run-mutations.sh

# 3. Plugin Check before a pull request (the plugin-check plugin, in a WordPress install)
wp plugin check wm-edu-privacy-portal
```

---

## Commit Conventions

- Use the format `[Feature] ...`, `[Fix] ...`, `[Security] ...`, `[Docs] ...`.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
