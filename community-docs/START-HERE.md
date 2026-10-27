# autoCache · Community preview 0.3.1

An editable FileMaker → PHP → MySQL/MariaDB cache, with a React mapping workspace.
Created by [Alex Seidler](https://alexseidler.dev), in collaboration with RGC Data Solutions.

## Try it in one minute

1. Open **DEMO.html** in your browser. Choose **Demo login**. No installation, account, password or network connection is needed.
2. Open **Fields**, choose a table, and select its **Primary key field**. Choose **ROWID fallback** if there is no suitable business key. Required fields stay checked. Expand the nearby identity help for examples.
3. Review the complete schema tree or selection JSON, then save a named draft. Open **Import**, confirm the sample maintenance step, import and activate. In **Cached records**, search `person77@` and open the result. Refreshing resets this fictional workspace.

The demo exercises the editor. It does not connect to FileMaker, import records, or prove a live cache is fresh. The included live admin remains authenticated; Demo login does not bypass it.

## What is included

- `DEMO.html`: standalone, offline React demo with networking blocked by its content policy.
- `online-demo/`: **the folder to publish by FTP**. Static sample-only build; see FTP-UPLOAD.md.
- `public/`: prebuilt admin and PHP API entry point. This is the **only** directory to expose to the web.
- `private/`: PHP cache engine, SQL migrations, ODataCWP library and an empty configuration template.
- `admin/`: editable React/TypeScript source and build tools. Node is only needed to rebuild the UI.
- `filemaker/`: three Script Workspace listings; not a compiled FileMaker add-on.
- `scripts/`: configuration helpers and server-side operations.
- `tests/`: selected offline checks; `SHA256SUMS.txt`: hashes of the packaged files.
- `INSTALLATION.md`, `PROJECT-GUIDE.md`, `AGENTIC_HANDOFF.md`, `REVIEW.md`: setup, editing and verification guidance.

## Before connecting a real database

Read **INSTALLATION.md**. Use a disposable test FileMaker file and a separate cache database first. Set connection details privately; no populated configuration is included. Use separate installations/configuration/database prefixes for separate sites. This release has no multi-site switcher or shared-tenant authorization system.

The engine fetches the current selected fields after a change notification. It is not an audit log, a two-way sync service, or a universal zero-script watcher. Direct Data API/OData writes need a separate notification path. FileMaker delivery is synchronous and its pending queue is session memory; see the limits below and in the handoff.

## Release boundary

This is a community **preview** for review and learning. Field selection, schema tree, draft saving, resumable import controls, explicit activation and read-only cached-record content search are implemented. The public demo exercises the flow using fictional data. Hosted business-key create/update/key-change/delete, SQL timeout behavior and failure recovery must still be verified on the intended installation before production use. Nothing has been deployed by creating this package. Images, cached containers and notification screens are outside this release.

For a quick review, start with DEMO.html, then REVIEW.md. For edits, start with PROJECT-GUIDE.md.
