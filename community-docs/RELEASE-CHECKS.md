# Release checks · October 27, 2026

- 14 frontend tests passed, including key selection/fallback, immutable mapping state, sample import/activation, content search beyond page one, no-network sample behavior, POST search privacy and CSRF requests.
- TypeScript production build passed. Standalone build explicitly replaces Node environment variables with production constants; packaging rejects unresolved environment references.
- PHP 8.4.25: routing/source URL, administrator session, contact transport and OData adapter fixture checks passed. The new record-search suite exercised authentication/CSRF, prepared queries, active scope, field projection, names/emails/numbers, literal wildcards, exact large IDs and oversized rows using SQLite in memory. No real mail, FileMaker or external database was contacted.
- Edge browser: the static online-demo build was served at `/autocache-demo/` to test subdirectory assets and its content policy. Demo login, schema search, key choice, draft save, import, activation, content search and record details worked. The new autoCache charcoal/orange/violet theme and personal/RGC service callout were visually inspected.
- Responsive checks used measured CSS viewports of 390, 767 and 1440 pixels. No document-level horizontal overflow was observed; wide records scroll inside their table. Schema and field panes stay bounded. Record details receive keyboard focus and become visible when opened. This is Chromium testing, not a physical Safari/iOS check.
- Standalone file built with embedded CSS/JS and a content policy denying network requests. The browser tool refused local file URLs; **double-click DEMO.html verification remains a recipient check**. This restriction was not bypassed.
- Release files come from an explicit allow-list. Local config, runtime data, logs and node_modules are excluded. Pattern checks and per-file SHA256 hashes are included; this is not a comprehensive secret/security audit.

Connected fresh installation, MySQL migrations on a clean server, source import/activation and hosted business-key lifecycle were not exercised in this pass. See REVIEW.md for the acceptance checklist. No upload, email or deployment was performed.

## Original product source review: October 30, 2026

This branch preserves the original v0.3.1 application. Subsequent README/setup guidance and Git ignore rules do not change the application or its three supplied FileMaker listings. The published `v0.3.1` tag and deployment ZIP remain the original release. The branch manifest is updated for branch documentation; use the manifest shipped with a downloaded release to verify that release.

The October 30 local audit passed 14 frontend tests, TypeScript production and demo builds, all five included PHP suites on PHP 8.4.25, and syntax checks for 26 PHP files. Record-search checks used PDO SQLite in memory; OData checks used fixtures and contact checks used a mock transport. These are not live FileMaker/MySQL acceptance tests. Interactive browser QA was not repeated in this audit; the earlier browser evidence above remains historical.

At the time of that audit, `npm audit --omit=dev` reported no vulnerabilities. The full audit reported two high-severity development dependency packages, Vite 8.3.0 and source-map-js. These remain pinned to preserve the original application snapshot. Review and address them in a separately tested maintenance change before relying on the development toolchain beyond local evaluation. This finding does not establish that the prebuilt PHP/static deployment has the same exposure.

Public repository, prerelease page, deployment ZIP and pinned original-source ZIP returned HTTP 200 on October 30. Download accessibility does not prove a usable connected installation. No new release artifact, hosted deployment, real database operation or FileMaker write was performed for this review.

Remaining acceptance work: fresh installation on supported PHP/MySQL or MariaDB, actual OData access and field projection, import/activation with paused writers, every relevant FileMaker write path, create/update/key-change/delete identity behavior, duplicate and interrupted delivery, lost-session reconciliation and host-specific SQL time limits. No production readiness, automatic freshness or recovery guarantee is made.
