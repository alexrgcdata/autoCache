# autoCache

A FileMaker → PHP → MySQL/MariaDB cache with a React administration workspace.

Created by [Alex Seidler](https://alexseidler.dev), in collaboration with [RGC Data Solutions](https://rgcdata.com). Need custom FileMaker, web or mobile integration? [Talk to Alex](mailto:hello@alexseidler.dev) or [RGC Data](https://rgcdata.com).

## Get started

- [Deploy the connected application](DEPLOYMENT.md): PHP/MySQL configuration, prebuilt React admin and private server files.
- [Install and connect FileMaker](INSTALLATION.md).
- [Try the sample workspace](START-HERE.md): online-demo/ is a separate fictional-data walkthrough.
- [Understand and edit the files](PROJECT-GUIDE.md).
- [Agent handoff](AGENTIC_HANDOFF.md).
- [Security boundaries](SECURITY.md) and [verification limits](RELEASE-CHECKS.md).

The public product is **autoCache**. Legacy PHP function names, database prefixes and FileMaker script names remain compatible.

## Build

From admin/, run npm ci, then npm run package:community. This runs tests and builds the connected UI and isolated demo into a sanitized, dated release folder. From the repository root, run node scripts/package-deployment.mjs followed by that community folder name to produce the runtime deployment folder.

Serve only public/ for the connected app. Keep private configuration and setup tools outside the web root. Node is needed only to rebuild, not to host the application.

## Release status

Community preview 0.3.1. Primary-key/ROWID selection, schema tree, resumable import, explicit activation and cached-record content search are implemented. Connected FileMaker/MySQL lifecycle acceptance remains necessary before production use. No universal zero-script watcher, durable FileMaker outbox, image handling or historical before/after audit log is promised.

See [credits and licensing status](CREDITS.md). Third-party notices are preserved; no project-wide open-source license has yet been selected.
