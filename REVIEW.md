# Review receipt · 2026-10-27

## What changed

This pass preserves the original treeview while introducing the **autoCache** name and a charcoal/orange/violet palette with no green. The workflow focuses on fields, explicit identity choices, initial import and searchable cached records. A separate data manager was inspected as a workflow reference; its design, business data and credentials are not included. A visible service callout promotes custom integration work with RGC Data and links to Alex's personal site/email.

## Verification

- Frontend: 14 unit tests passed, including multiple key choices, ROWID fallback, locked identity fields, immutable changes, import/activation sequencing, pagination, content search and sample network isolation.
- Production React/TypeScript and standalone demo builds completed.
- Browser: password-free login, top navigation, primary-key selection, draft save, demo import, explicit activation and content search verified. Searching person77@ finds a record beyond the first page and opens its chosen primary key and fields. Draft saving alone leaves the active sample mapping unchanged.
- Additional packaging/browser/PHP checks are recorded in RELEASE-CHECKS.md when available.

This receipt is scoped to this release preparation. It is not proof of live source/cache synchronization or a security audit.

## Review it yourself

1. Open DEMO.html and click Demo login without entering credentials.
2. On Fields, choose Contacts. Select ID; both ID and ROWID stay checked. Choose another eligible field, then return to ROWID fallback. Check the JSON mirrors the chosen identity. Search and expand the complete schema.
3. Save a named draft. Confirm the active mapping remains sample_active and refreshing resets all demo changes. Export contains mapping fields only.
4. Import the saved demo draft, then activate it. Search Cached records by name/email/number, page forward/back and open a row. Try no-match searches and clear the search.
5. Try narrow and wide windows, tree collapse, identity help, Security and Help. Read FTP-UPLOAD.md before publishing the static walkthrough; INSTALLATION.md covers the separate connected deployment.

## Before production claims

- Fresh install with only public/ exposed; private config and source inaccessible over HTTP.
- Metadata discovery with the actual FileMaker version/account.
- Unique/non-empty business-key validation, import, activation, create/update/key-change/delete and ROWID fallback.
- Duplicate delivery, failed source read, interrupted delivery, retry, session close and recovery.
- Direct API writes and bulk operations accounted for; paused-writer procedure tested.
- Hosting rate limits, backups, authenticated admin access and public cache-reader authorization reviewed.

These connected acceptance checks have not been performed by this UI packaging pass.
