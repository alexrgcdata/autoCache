# autoCache

autoCache keeps selected FileMaker data in a MySQL or MariaDB database that a website can read locally. FileMaker remains the source. A React administration workspace lets you choose tables and fields, save mapping drafts, import records, explicitly activate a mapping, and search the cached values.

I built this to explore a practical alternative to making a FileMaker request for every website read. A source change sends a notification containing record IDs. PHP authenticates that notification, reads the current selected values through FileMaker OData, and updates the website cache. This is one-way current-state caching; it does not write website changes back to FileMaker or preserve every intermediate version of a record.

Created by [Alex Seidler](https://alexseidler.dev), in collaboration with [RGC Data Solutions](https://rgcdata.com). Existing `rgcCache` PHP functions, database prefixes and FileMaker script names remain for compatibility.

## Status and downloads

**Community preview v0.3.1.** The source builds and the included offline tests pass. A connected FileMaker/MySQL installation, real change delivery and recovery still need acceptance testing on the intended environment before production use.

- [Published prerelease and deployment ZIP](https://github.com/alexrgcdata/autoCache/releases/tag/v0.3.1): prebuilt web assets, PHP backend, configuration template and setup instructions.
- [Original v0.3.1 source](https://github.com/alexrgcdata/autoCache/tree/v0.3.1): the fixed published snapshot.
- This branch: the same original product with subsequent documentation and repository housekeeping. The existing release tag and ZIP have not been replaced. See [verification notes](RELEASE-CHECKS.md).

## Try the fictional demo

Open `DEMO.html` in a browser and choose **Demo login**. It uses fictional in-memory records, resets when refreshed and does not connect to FileMaker. The separate `online-demo/` folder is the static version intended for a web host; see [demo instructions](FTP-UPLOAD.md).

To run the sample UI from source, use Node 22.18+ or Node 24 and npm:

```sh
cd admin
npm ci
npm run dev -- --port 5186
```

Open `http://127.0.0.1:5186/?demo=1`. The Vite server does not supply a PHP backend. The pinned development dependencies have known audit findings; review them before exposing a development server. The October 30 production-dependency audit reported no vulnerabilities. See [verification notes](RELEASE-CHECKS.md) for the scope.

## Install a connected test instance

You need PHP 8.3+ with PDO MySQL, cURL, DOM/XML, JSON and sessions, a separate MySQL/MariaDB cache database, and a FileMaker Server file with OData available to a scoped read-only account. Node is only needed to rebuild the UI, not to host the prebuilt application.

1. Follow [DEPLOYMENT.md](DEPLOYMENT.md) to expose only `public/`. Keep `private/` and setup tools outside the web root, and configure HTTPS and API routing.
2. Copy `private/config.example.php` to a private `config.php`. Set the cache database, FileMaker OData connection, webhook token and administrator password hash. Follow [INSTALLATION.md](INSTALLATION.md) for the database check and new test-database installation commands.
3. Sign in to the web administrator, select explicit tables and fields, choose a stable unique key or ROWID fallback, and save a draft. Pause relevant source writers while importing, reviewing and explicitly activating it.
4. Manually enter the three `filemaker/*.txt` listings in Script Workspace: **rgcCache Settings**, **rgcCache Capture Transaction**, and **rgcCache Send Pending**. These are readable listings, not directly importable XML or a compiled add-on. Configure the endpoint, private token and exact file name.
5. Wire Capture Transaction into the intended OnWindowTransaction workflow with the native transaction JSON. Instrument other write paths explicitly. Direct Data API/OData writes need their own notifications; installing the three scripts alone does not observe every change.
6. On disposable data, verify create, update, key change, delete, duplicate delivery and interrupted delivery. Confirm actual cached values and identities, then complete the [acceptance checklist](REVIEW.md).

The website reads the cache through the server-side functions in `private/src/reader.php`. Your website still needs its own end-user authorization. The cache administrator is not a public data browser.

## Delivery and recovery limits

FileMaker sends notifications synchronously with Insert from URL, so delivery can delay its script or UI. The pending queue is held in session variables and is lost when that session closes. Run **Send Pending** to retry retained batches using their existing delivery IDs and bodies; do not rerun Capture for the same transaction. The PHP inbox can retry only work it actually received. A durable FileMaker outbox and scheduled reconciliation are not implemented.

The active mapping limits cached tables and fields. Changing a mapping requires a new draft, import and explicit activation. A health response does not establish complete imports, successful delivery or fresh data. This preview offers no automatic-everywhere detection, no-stale guarantee, latency guarantee, two-way sync, image/container caching, or before/after audit history.

## Build and check

From `admin/`:

```sh
npm ci
npm test
npm run build
npm run build:demo
```

From the repository root with a supported PHP CLI:

```sh
php tests/run.php
php tests/admin.php
php tests/contact.php
php tests/odata-adapter.php
php tests/admin-records.php
```

The record-search suite requires PDO SQLite and uses an in-memory database. The other suites use fixtures or mocked services; they do not certify a live FileMaker/MySQL deployment or send real email.

For editable source and packaging instructions, see [PROJECT-GUIDE.md](PROJECT-GUIDE.md). Packaging creates a new dated folder; it does not publish a release. Keep a modified build distinct from the fixed v0.3.1 download.

Read [SECURITY.md](SECURITY.md) before connecting private data. Third-party license notices are preserved; no project-wide software license has been selected. See [CREDITS.md](CREDITS.md).
