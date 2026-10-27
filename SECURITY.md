# Security and privacy boundaries

This is a description of protections and remaining responsibilities, not a certification or independent security audit.

## Public demo

`online-demo/` contains static assets only. It has a sample-only entry point, fictional record fixtures, no configured credentials, no API connection and no persistent data storage. Demo login opens in-memory state; it is not an account on the connected server. The content policy denies connections, framing (when HTTP headers are applied), form submissions and external scripts. Direct author/partner links remain ordinary navigation.

Do not expose a real connected administrator using a shared demo password. The connected build retains authentication; publishing the static demo does not weaken it.

## Connected admin

- Username/password verification uses a server-side hash. Sessions use cookie-only, HttpOnly and SameSite Strict settings; HTTPS enables Secure cookies.
- Mapping changes, imports, activation and record search require a valid session and CSRF token. There is no credential in an exported mapping.
- Configuration stays in private/config.php outside the document root. FileMaker access should use a dedicated read-only account with the minimum privileges.
- Record browsing reads the active mapping and verifies its imported installation/configuration. The client cannot select a different installation/file or a table outside that mapping.
- Search text and JSON paths are prepared parameters. `%`, `_` and `=` are literal search characters. Search matches selected field values, including numeric values, rather than raw JSON keys.
- Queries return at most 25 records plus lookahead, with keyset pagination, a 160-byte query limit and support for 1–512 explicit fields. Three-second SQL time limits are requested on MySQL/MariaDB; verify your server version honors them. No expensive total-count query is run.
- Browser responses are limited to approximately 2 MB of source payloads per page; individual payloads above 1 MB are represented by a notice. JSON encoding can add overhead. IDs stay strings to preserve precision.
- API responses are JSON, no-store and nosniff. React renders record values as text; no record HTML is injected. Record searches use POST bodies to avoid putting search terms into URL/access-log query strings. Infrastructure may still log request bodies; configure it appropriately.
- The old contact endpoint has no default recipient/sender. Without explicit private mail settings, it fails closed. The UI uses direct email links instead.

## Your hosting responsibilities

Use HTTPS, a non-public private directory, strong unique administrator credentials, backups, least-privilege database grants and restricted admin access. Add login rate limiting at the reverse proxy/host; application-level login throttling and automatic session expiry hardening are not implemented in this release. Configure trusted proxy headers correctly. Do not give website visitors cache-admin credentials. The website consuming cached data must enforce its own authorization.

Substring search scans selected field values; large tables can time out. It is not a full-text search index. No scan result proves the source is fresh. A schema suggestion is not proof that a primary key is unique. The native sender is session-local and not a durable source outbox. See INSTALLATION.md for import/recovery and write-channel coverage.

## Verification

Automated checks cover missing login/CSRF, input limits, scope isolation, prepared-query behavior, literal wildcards, large record IDs, selected-field projection, oversized responses and offline sample isolation. SQL behavior was exercised in SQLite with a compatibility helper; MySQL/MariaDB-specific timeouts and the connected deployment need acceptance testing. No production account, database or source record was changed in this pass.
