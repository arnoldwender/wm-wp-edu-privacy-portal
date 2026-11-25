# Security Policy — WM Edu Privacy Portal

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.2.x` (`1.2.1`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-edu-privacy-portal/security/advisories/new](https://github.com/arnoldwender/wm-wp-edu-privacy-portal/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to Include

1. Reproduction steps or a proof of concept.
2. Affected component: the consent form and its links, the consent register and chain, the lookup, the admin screen and its export, `wm-edu/v1/tokenize` or `/detokenize`, the `wp edu` commands or the hub adapter.
3. Potential impact (for example a consent recorded for a pupil without the registered guardian, a forged row that still verifies, a way to make the lookup show a consent, re-identification of a tokenized text beyond what is described above, markup injected into a page).

Reports are acknowledged and handled as quickly as possible; no response time is guaranteed.
