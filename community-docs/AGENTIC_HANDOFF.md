# Agent handoff · autoCache community 0.3.1

## Start here

This is the standalone **autoCache** PHP/MySQL cache plus React administrator. It is not a marketing website, OpenBridge/FM Explorer, or the separate FileMaker Change Bridge webhook scaffold. Confirm the current folder contains `private/src/sync.php` and `admin/src/App.tsx` before editing. Read START-HERE.md, PROJECT-GUIDE.md, INSTALLATION.md and REVIEW.md.

User intent: a useful, editable free community preview with its own charcoal/orange/violet personality and existing treeview, approachable primary-key selection, password-free isolated demo, clear Alex Seidler and RGC Data Solutions credit, and practical handoff documentation.

Latest direction: product name **autoCache**; no green. Keep the charcoal/orange/violet theme and the visible custom-integration service callout for both RGC Data and Alex. The public repository is https://github.com/alexrgcdata/autoCache. Keep its app link visible. Use the autoCache-deployment package for the connected application: only public/ belongs in the web root. The separate online-demo folder is sample-only. Read DEPLOYMENT.md before FTP. The working checkout remains named rgcCache, and PHP/database identifiers deliberately remain compatible. Do not reintroduce images, cached containers or notification panels in this pass.

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
