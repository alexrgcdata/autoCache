# Editing autoCache

The public product is now **autoCache**, using charcoal surfaces, warm orange primary controls and restrained violet help accents. `admin/src/autocache-theme.css` owns the semantic palette. Legacy PHP function names, database prefixes and FileMaker script names remain `rgcCache_*` for compatibility; do not rename stored tables during a branding change.

## Files you will change most often

| File | Responsibility |
| --- | --- |
| `admin/src/App.tsx` | Login, navigation, editor state, draft saving and footer credits |
| `admin/src/WorkspacePages.tsx` | Overview and learning screens |
| `admin/src/IdentityPicker.tsx` | Primary-key dropdown, metadata suggestions and ROWID fallback |
| `admin/src/SchemaTree.tsx` | Searchable complete schema with selected-field highlighting |
| `admin/src/ImportPage.tsx` | Resumable import, progress and deliberate activation |
| `admin/src/RecordsPage.tsx` | Debounced content search, pagination and readable record details |
| `admin/src/SecurityPage.tsx` | Security/privacy guidance with no invented audit score |
| `admin/src/model.ts` | Required fields, eligible keys, validation and mapping comparison |
| `admin/src/styles.css` | Original charcoal/orange/violet design and shared controls |
| `admin/src/community.css` | Community layout, navigation, responsive panels and key picker |
| `admin/src/fields-workspace.css` | Fields-first top navigation, tall bounded tables and newer screens |
| `admin/src/autocache-theme.css` | Current product colors, readable contrast, logo and services callout styling |
| `admin/src/api.ts` | Same-origin PHP session API; CSRF headers and error handling |
| `admin/src/sample.ts` | Fictional in-memory demo API; never add real connection details |
| `admin/src/main.tsx` | Live app, or isolated sample when `?demo=1` is present |
| `admin/src/demo.tsx` | Offline sample-only entry point |
| `private/config.example.php` | Empty installation settings; copy privately to config.php |
| `private/src/source.php` | OData metadata and source reads |
| `private/src/identity.php` | Business-key extraction and identity index |
| `private/src/sync.php` | Notification inbox, processing and retry behavior |
| `private/src/mapping.php` | Draft/active mapping lifecycle and scope |
| `private/src/import.php` | Initial import with progress tracking |
| `private/src/reader.php` | PHP website-side cached reads |
| `private/src/admin.php` | Authenticated administration operations |
| `private/src/admin-records.php` | Authenticated active-scope search, safe SQL parameters and response bounds |
| `scripts/package-community.mjs` | Allow-listed, credential-free release builder |

## Rebuild and package

Use a current Node version supporting TypeScript stripping (Node 22.18+ or Node 24 LTS). In `admin/`, run `npm ci`, then `npm test` and `npm run build`. The build replaces `public/admin/`; keep handwritten code in `admin/src/`.

`npm run dev -- --port 5186` starts the local editor. Open `http://127.0.0.1:5186/?demo=1`. The development server does not proxy a PHP backend; use the demo for local UI work.

`npm run package:community` tests and builds both entries, then creates a dated folder inside `release/`. Existing releases are never overwritten. Edit documentation in `community-docs/`; the builder copies it to the package root too. Zip the resulting folder if needed. Never zip the working checkout wholesale.

Publish only `online-demo/` for the public demo (FTP-UPLOAD.md). It uses the sample-only build with separate assets and a network-denying content policy. The connected `public/admin/` retains real session authentication. The two builds share visual components, not live state or credentials.

The builder intentionally replaces the working checkout's deployment-specific PHP entry point with a portable sibling-private default, and replaces the FileMaker settings sample with generic placeholders. It includes only empty configuration, source, selected tests, compiled assets and documentation. It excludes runtime data, local secrets, dependencies, diagnostics and historical internal notes. It includes third-party license notices.

## Identity rules

A notification identifies a FileMaker internal record ID. A business key is an optional second lookup identity, not a replacement for that source ID. The dropdown lists supported scalar fields, excluding binary, global and calculation fields. Multiple choices may appear; a metadata suggestion is never applied automatically. Choose one field whose values are stable, unique and non-empty. Composite keys are not assembled.

Switching to a key locks that field into the selection. Switching back to ROWID removes the business-key setting but preserves the old field as ordinary selected data. Existing unfamiliar keys are shown with a warning rather than silently discarded. Key changes require a new draft, import and activation. The editor cannot establish uniqueness by reading schema alone.

## How data moves

FileMaker transaction → JSON notification with record IDs → authenticated PHP inbox → bounded OData reads → MySQL cache → website's authorized PHP reader.

The notification is not the cached record. It carries operations and IDs; PHP obtains current selected values from FileMaker. All identity strings must stay strings across JS/PHP boundaries. An older/newer field comparison is not implemented; intermediate changes can be coalesced by current-state reads.

Keep credentials out of React, mappings and exported JSON. Keep your website's authentication separate from cache administration. Add a host adapter to integrate another app; do not copy its accounts or business rules into this engine.
