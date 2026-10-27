# Deploy autoCache

The connected application is PHP + MySQL/MariaDB with a prebuilt React administrator. No Node service or GitHub checkout is required on the server.

## Choose the correct package

- **autoCache-deployment**: the actual connected application, with public assets, private PHP engine, empty configuration template, setup scripts, FileMaker script listings and installation/security documentation. This is not the public demo.
- **online-demo**: fictional browser-only walkthrough with Demo login. It cannot update a real cache.
- **autoCache-community**: full editable source and developer/agent documentation. Keep this outside the public website directory.

## FTP layout

Keep the deployment folder outside the web document root and point the domain/subdomain document root at its `public/` directory when your hosting panel supports that. Set `base_path` to an empty string for a dedicated domain/subdomain.

For a shared-host subfolder installation:

```text
account/
  autoCache-private/             <- contents of package private/; outside public_html
  autoCache-tools/scripts/       <- package scripts/, for private setup only
  public_html/autocache/         <- contents of package public/
    index.php
    .htaccess
    admin/
```

The setup scripts assume `private/` is their sibling. For command-line setup, retain the package layout in a private directory, or adjust your private layout so that relationship is preserved. Do not copy setup scripts into public_html.

If you split `public/` from `private/`, edit the single require path in the uploaded index.php to the absolute server path of `autoCache-private/src/http.php`. Preserve the `rgcCache_run()` call: it is a compatibility identifier, not the displayed product name. Set private configuration `base_path` to `/autocache` for the example above. Existing installations should retain their actual URL mount.

Upload the entire public/admin asset set together, including dotfiles. Do not overwrite an existing private/config.php with the example. Never place private/, source ZIPs, SQL backups or source tooling under public_html.

## Configure and verify

1. Follow INSTALLATION.md for server extensions, private credentials, admin password hash, database setup and FileMaker notification scripts. Use separate tokens/configuration/database scope per site.
2. Open `/autocache/admin/` (or `/admin/` on a dedicated domain). Connected access must require your configured administrator credentials. Demo login belongs only to the sample walkthrough.
3. Verify HTTPS, Authorization forwarding, rewrite rules, session cookies, host login rate limiting and denial of private files. Test a draft import and explicit activation against disposable data, then create/update/delete notification behavior and primary-key changes before production rollout.

The public name is **autoCache**. Existing PHP function names, database table prefixes and FileMaker script names remain compatible. Packaging and local checks are not proof of a successful hosted FileMaker/MySQL installation. No hosting deployment is performed by the package builder.
