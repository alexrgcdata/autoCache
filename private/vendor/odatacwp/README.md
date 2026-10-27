# Vendored ODataCWP

Selected source: `FMMeets.com/server/lib/ODataCWP.php`

- Upstream version: `1.1.0-fmmeets.1`
- Patched version: `1.1.0-fmmeets.1-rgccache.1`
- Imported: September 18, 2026
- Upstream SHA-256 at import: `B83852ECBE4BC5EE4EB68C0B9ACF3C3ED7E852C1BD88995A5B7D0816F62D7225`
- Current patched SHA-256: `0DCC462AEEE8290733CAF4D942748A6CEBE44046CB2A7487B6300D4D42AB4AC4`
- License: MIT; see `LICENSE`

This is the standalone base class used by FMMeets. The FMMeets-specific
`IdODataClient` subclass was deliberately not imported because it imposes an
ID-only schema policy and parses XML metadata. rgcCache constructs this class
through `private/src/odata.php`, keeps configuration in rgcCache, and uses raw
JSON metadata requests.

Local rgcCache patch:

- `getMetadata()` and its `getLayoutMetadata()` alias request JSON CSDL and
  return decoded JSON rather than requesting a raw XML document.

Do not edit this vendored file silently. Record any local patch here and add a
regression test in `tests/odata-adapter.php`.
