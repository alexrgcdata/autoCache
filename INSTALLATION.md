# Install a test instance

## 1. Place files and configure privately

Use PHP 8.3+ with PDO MySQL, cURL, DOM/XML, JSON and sessions, MySQL/MariaDB, and a FileMaker server with OData available for the intended file. Verify the server/account privileges against your FileMaker version. Use a read-only source account scoped to the tables you intend to cache.

Place `private/` outside the web document root. Serve **only** `public/`. The packaged `public/index.php` assumes `private/` is its sibling. If your host separates these folders differently, change that one require path to the correct server path. Never solve a path error by uploading private configuration into a public folder.

The included Apache `.htaccess` routes API paths and preserves Authorization; configure equivalent routing on other servers. Static `/admin/` assets must be served as files. No hosted Node process, scheduled task or daemon is required.

Copy `private/config.example.php` to `private/config.php` on the private server. Set:

| Setting | Meaning |
| --- | --- |
| `base_path` | URL mount, e.g. `/autocache`; use an empty string when public/ is the domain root |
| `installation_id` | Initial scope identifier; mapping lifecycle manages draft scopes |
| `webhook_token` | Secret shared with FileMaker; generate privately with `php scripts/token.php` |
| `admin.username`, `admin.password_hash` | Your own admin identity; create hash interactively with `php scripts/admin-password.php` |
| `database` | Cache database host/name/user/password and unique table prefix |
| `filemaker.file` | Exact file name without `.fmp12` |
| `filemaker.odata_url` | HTTPS OData file URL with encoded file name |
| `filemaker.user`, `filemaker.password` | Private source account |
| `filemaker.tables` | Bootstrap/discovery input; runtime authorization comes from the active saved mapping |

For multiple sites, use distinct private configuration, tokens, admin session names and database/prefix isolation. This package does not promise safe multi-tenancy inside one shared administrator session.

## 2. Install and create a mapping

From the package root on the server, run `php scripts/database.php check`. For a **new dedicated test database**, run `php scripts/database.php install`, then check again. These commands create/check prefixed cache tables; they do not import FileMaker records. Back up existing deployments and review migrations before upgrades.

Open `<base_path>/admin/`. Sign in with the credentials you configured. Discover metadata, select tables/fields and a key for each table, then save a uniquely named draft. The demo is separately available at `<base_path>/admin/?demo=1` and never saves to the server.

## 3. Import and activate explicitly

Open **Import** in the React administrator and choose the saved draft. Confirm all relevant source writers are paused, then Start import. The UI sends bounded steps until completion; Pause after this batch stops after the current request. Check saved progress reloads server state. On completion, review and explicitly activate. Keep writers paused through activation and reconciliation, then resume them. Switching tabs stops further browser-issued steps after the current request; server-committed progress remains.

For custom clients, these are the same protected API operations. Retain the administrator session cookie. Login at `POST <base_path>/api/v1/admin/login`; its response contains `csrf_token`. Send it as `X-CSRF-Token` with JSON mutations and record searches. Do not place credentials in URLs or saved public examples.

| Operation | Method/path after `/api/v1/admin/` | Body/query |
| --- | --- | --- |
| Login | POST `login` | `username`, `password` |
| List mappings | GET `mappings` | None |
| Start draft import | POST `import/start` | `id`: saved draft name, `source_writes_paused`: true |
| Advance bounded import | POST `import/step` | `id`: same draft name |
| Inspect progress | GET `import/status?id=...` | URL-encoded draft name |
| Activate ready draft | POST `mapping/activate` | `id`: ready draft name |
| Search active cached records | POST `records/query` | `table`, `query` (up to 160 bytes), `after` (record ID string, initially `"0"`) |

Pause all writers to the relevant source data **before** asserting `source_writes_paused`. Continue bounded steps until the import reports ready; inspect failures and completeness before activation. Keep writers paused through activation. Do not use the bootstrap CLI to activate a different saved draft accidentally. Reconcile any changes missed during maintenance before resuming normal work.

## 4. Wire FileMaker notifications

The three `filemaker/*.txt` files are instructions to enter in Script Workspace. They are not directly executable script imports or a `.fmaddon`. Set the HTTPS endpoint, private token and exact file name in **rgcCache Settings**. Use **rgcCache Capture Transaction** for the file's OnWindowTransaction workflow, passing the native transaction JSON, then use **rgcCache Send Pending** to retry retained deliveries. Verify availability and parameter setup for your FileMaker version.

The endpoint is `POST <base_path>/api/v1/sync`, with `Content-Type: application/json`, `Authorization: Bearer <private token>` and `Idempotency-Key: <stable delivery UUID>`. The existing native-shaped body is `{ "YOUR_FILE_NAME": { "Contacts": [["Modified", "123", ""]] } }`. Operations are `New`, `Modified`, `Deleted`; IDs stay strings. A retry must retain the same UUID and body. Timestamp/newValue/oldValue are not part of this existing contract.

The sender uses Insert from URL with time limits. It can delay the FileMaker script/UI while sending; it is not an invisible asynchronous watcher. Its queue lives in session variables and is lost when the session closes. The PHP inbox can retry only deliveries it actually received. Direct Data API/OData writes need explicit notifications; this trigger does not provide universal coverage.

Installing these listings does not wire the trigger or instrument other writers automatically. Inventory the write paths your solution uses, including client edits, scripted edits, imports, server scripts and API integrations. Verify which paths produce the expected transaction JSON in your FileMaker version and explicitly notify the others. Do not claim a path is covered until a disposable change reaches the cache with the expected values.

When a delivery fails, preserve the session and inspect the reported result before making more test edits. Retry with **rgcCache Send Pending**, retaining the saved delivery UUID and body. Do not rerun Capture for the same transaction or clear its pending variable to hide the error. If the session or a notification is lost, reconcile the source and cache; PHP cannot recover an event that never reached its inbox. This preview does not include an automated reconciliation job.

## 5. Read and verify

The administrator's **Cached records** tab searches selected field values across the chosen active table, with 25-record pages and read-only details. It does not query live FileMaker. Search is case-insensitive and treats wildcard characters literally. Empty search browses by numeric internal ID. Legacy all-fields mappings must be converted to explicit selections before content search; at most 512 selected fields are searched. Large scans may time out; this is not a full-text index.

Your server-side website can use `private/src/reader.php`: `rgcCache_cachedRecord` reads by internal record ID and `rgcCache_cachedRecordByPrimaryKey` reads by configured business key. Supply PDO/config on the server and enforce your own end-user authorization. Do not expose a raw cache browser publicly; use online-demo for public demonstrations.

On a disposable source record, verify create, update, key change, delete, duplicate delivery and retry after interruption. Confirm cached values and identities, not just HTTP success. Test ROWID fallback separately. Test all write channels your integration uses. The `health` route only proves routing, not source connectivity, import completeness or freshness.

Before production: finish REVIEW.md's deployment checklist, arrange backups and recovery, and record the versions tested. Full rebuild/purge-all UI, durable source outbox, before/after history and scheduled reconciliation remain future work.
