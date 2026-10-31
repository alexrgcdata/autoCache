# Agent handoff · autoCache community 0.3.1

## Current checkpoint: October 30, 2026

Canonical checkout: `C:\Users\agsei\Desktop\Projects\autoCache`, branch `main`. This existing `AGENTIC_HANDOFF.md` is the root handoff (the requested lowercase spelling resolves to the same file on Windows). Keep the original standalone product separate from later company integrations. Portfolio code and deployment remain owned by PROJECT MANAGER; this product chat must not edit that checkout.

The latest completed product-documentation checkpoint before this handoff update is [8e497e0025fba8ee1e84308bbdbe6d73885506c5](https://github.com/alexrgcdata/autoCache/commit/8e497e0025fba8ee1e84308bbdbe6d73885506c5), pushed to [main](https://github.com/alexrgcdata/autoCache/tree/main). It rewrote the README, clarified manual script installation and delivery recovery, added private-export ignore rules, synchronized community documentation and updated the branch manifest. Use Git history for the commit containing this handoff update; it does not introduce another product version.

The fixed [v0.3.1 prerelease](https://github.com/alexrgcdata/autoCache/releases/tag/v0.3.1) remains at [239162594141bad087a947b35f08f0aebfe7658f](https://github.com/alexrgcdata/autoCache/commit/239162594141bad087a947b35f08f0aebfe7658f). [Deployment ZIP](https://github.com/alexrgcdata/autoCache/releases/download/v0.3.1/autoCache-deployment-0.3.1.zip) and [original source ZIP](https://github.com/alexrgcdata/autoCache/archive/239162594141bad087a947b35f08f0aebfe7658f.zip) endpoints returned HTTP 200 during the October 30 audit. Runtime source, prebuilt assets and all three supplied script listings remain unchanged from that original commit. No release tag or ZIP was replaced, relabeled or newly published. Current branch documentation differs from the fixed release; use each artifact's own manifest.

### Verification already completed

October 30 checks passed: 14 frontend tests, TypeScript production build, standalone demo build, all five included PHP suites using PHP 8.4.25, syntax checks for 26 PHP files, all 105 branch-manifest entries, README local links and matching root/community documentation copies. PHP record-search tests used SQLite in memory; OData checks used fixtures and contact checks used a mock transport. Earlier responsive browser evidence is recorded in RELEASE-CHECKS.md, not repeated during this source-documentation pass.

The production-dependency audit reported zero vulnerabilities; the full audit flagged two high-severity development dependency packages, Vite 8.3.0 and source-map-js. Those pins were left unchanged in the original-product documentation pass. XAMPP PHP 8.0 is unsupported; the documented minimum is PHP 8.3. No live FileMaker/MySQL acceptance, production database operations or hosting deployment was performed.

### Remaining limits and next steps

The honest status remains **Community preview v0.3.1**. Fresh installation, actual OData connectivity, import/activation, every relevant source write path, create/update/key-change/delete identity behavior, duplicate and interrupted delivery, lost-session reconciliation and MySQL/MariaDB SQL time limits require an explicitly authorized disposable test environment. Do not start those operations without one.

The three FileMaker scripts require manual installation and wiring. Direct Data API/OData writes need explicit notification instrumentation. Sending is synchronous; pending source deliveries live in session memory and disappear when the session closes. PHP can retry only notifications it received. There is no freshness guarantee, universal automatic observation, durable source outbox, two-way sync, image/container cache or historical before/after log.

Private current-database script preservation/comparison is separate and incomplete. Preserve existing local exports without publishing, committing, packaging or logging their contents. Do not replace the original supplied scripts with current private variants. No project-wide software license has been selected; preserve visibility, credit and dependency licenses.

The next documentation-only action is complete once this handoff and its manifest are pushed. Do not repeat unchanged installs, tests or builds for this handoff. Future maintenance should first scope dependency updates in a separate tested change, or obtain an authorized test environment for connected acceptance. Keep one worker on this product, do not create duplicate servers/watchers or persistent monitors, and request a heavy-check slot from PROJECT MANAGER before expensive work. No heavy checks were needed on resume, so no RAM/CPU attribution is claimed.


## Start here

This is the standalone **autoCache** PHP/MySQL cache plus React administrator. It is not a marketing website, OpenBridge/FM Explorer, or the separate FileMaker Change Bridge webhook scaffold. Confirm the current folder contains `private/src/sync.php` and `admin/src/App.tsx` before editing. Read START-HERE.md, PROJECT-GUIDE.md, INSTALLATION.md and REVIEW.md.

User intent: a useful, editable free community preview with its own charcoal/orange/violet personality and existing treeview, approachable primary-key selection, password-free isolated demo, clear Alex Seidler and RGC Data Solutions credit, and practical handoff documentation.

Latest direction: product name **autoCache**; no green. Keep the charcoal/orange/violet theme and the visible custom-integration service callout for both RGC Data and Alex. The public repository is https://github.com/alexrgcdata/autoCache. Keep its app link visible. Use the autoCache-deployment package for the connected application: only public/ belongs in the web root. The separate online-demo folder is sample-only. Read DEPLOYMENT.md before FTP. The canonical sanitized public source is the autoCache checkout; rgcCache is the older local working folder. PHP/database identifiers deliberately remain compatible. Do not reintroduce images, cached containers or notification panels in this pass.

## Implemented in this pass

- Fields, Import, Cached records, Security, Help and Connection top navigation. Original palette and treeview preserved. Tall bounded field/table lists; no image/container or notification screens.
- Primary-key dropdown, optional metadata suggestion, multiple eligible choices, explicit ROWID fallback, identity locking and identity-change warning.
- Preserved field/table selection, search, tree, JSON export, named drafts, confirmation, sessions and CSRF.
- Demo login without username/password, in-memory fictional data, production `?demo=1` entry and standalone `DEMO.html` with network-denying policy.
- Separate author/partner credits and direct email link. Legacy server contact transport has no default recipient/sender; unconfigured installs cannot send to a previous owner.
- Versioned allow-list packaging with generic bootstrap, blank config, license notices and SHA256 manifest.
- Searchable complete schema tree shows included/excluded fields. Identity help explains internal ROWID vs a user-selected unique business key.
- Import UI drives existing protected start/step/status/activate APIs, with maintenance acknowledgment, resumable batches and separate activation confirmation.
- Cached-record browser searches selected values across the active table; POST + session + CSRF, prepared JSON paths/patterns, keyset pagination, query and payload bounds, stale-response guards and escaped detail text.
- Separate static online-demo folder for public FTP. No real credentials or PHP services are shipped in that upload folder.

## Architecture and invariants

PHP handles webhook auth, durable received inbox work, bounded OData reads, mapping lifecycle and MySQL cache writes. React edits drafts, drives protected import/activation and browses cached values; it never edits source records. Source FileMaker data is read-only to the engine. The native notification contains internal record IDs, not arbitrary website business keys. The optional identity index supports business-key reads/deletes. The active mapping determines allowed tables/fields; configuration is not a runtime authorization bypass.

Never mutate an active mapping in place. Create a draft, pause writers, import, explicitly activate. Keep internal IDs as strings. Never silently choose keys or claim uniqueness based on metadata. Preserve unknown legacy key choices with warnings. Keep credentials in private config, never source examples, browser state, mappings or output logs.

Demo calls the sample API only. Do not wire the demo to PHP, persist demo tokens, or remove authentication from live endpoints. Do not expose the package root as the web root. Do not upload `private/` under public_html. Do not publish history/diagnostics/client data from a working checkout.

## Verification and what is unfinished

See REVIEW.md for the current run evidence. Frontend builds and offline tests do not prove a connected cache lifecycle. No real database/source writes, hosting deployment or email transmission were part of this release preparation.

Remaining priorities:

1. Validate the packaged fresh-install path and keyed lifecycle against an explicitly authorized disposable FileMaker/MySQL environment; preserve evidence without records/secrets.
2. Verify new search SQL/time-limit behavior on MySQL/MariaDB and exercise import interruption, stale sessions and activation recovery in a connected disposable installation. SQLite tests prove query intent/scope but not vendor-specific timeout support.
3. Design a durable source outbox and explicit notification support for non-trigger write channels; reconcile lost events.
4. Add true cache/queue health only when the API exposes verifiable observations.
5. Consider multi-site orchestration through adapters; current site separation is per installation.

Old/new value history, purge-all action, unattended polling, a compiled add-on and universal global observation are not implemented. The FileMaker sender is synchronous and its pending queue is session-local. Do not market it as zero-scripting or guaranteed lossless replication.

## Commands

From `admin/`: `npm ci`, `npm test`, `npm run build`, `npm run package:community`.
From root with PHP: `php tests/run.php`, `php tests/admin.php`, `php tests/contact.php`, `php tests/odata-adapter.php`. These selected checks use fixtures/mocks; contact tests do not send real mail.

Record-search checks: `php -d extension=pdo_sqlite tests/admin-records.php` (or normal PHP when PDO SQLite is already enabled). These use a fresh in-memory database and do not load private/config.php. Frontend tests include the demo import/activation sequence and full-content search beyond the first page. Add tests alongside actual behavior changes; do not count fixture checks as hosted FileMaker proof.

Do not run install/import scripts, live tests, deployments or production operations without the user's specified target and authorization. Preserve existing configured private files without reading or copying their values into a handoff. Release packaging must remain allow-listed.

## Copy-ready restart prompt

Continue autoCache community preview in this folder. Read AGENTIC_HANDOFF.md, PROJECT-GUIDE.md and REVIEW.md. Preserve the existing PHP cache engine and React treeview. Keep Demo login isolated and credentials private. Review the documented gaps and implement the requested next feature with relevant tests. Do not deploy, send email, or write to live FileMaker/MySQL without explicit authorization. Report verified behavior and remaining limits separately.
