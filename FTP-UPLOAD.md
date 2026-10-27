# Publish the community demo by FTP

## Upload only online-demo

The `online-demo/` folder is the public walkthrough. Upload its **contents** to your chosen website folder, such as `public_html/autocache/`. Keep `assets/` beside `index.html`, and include `.htaccess` if your host uses Apache/LiteSpeed. Open the resulting HTTPS URL and choose **Demo login**. There are no shared passwords or real accounts to configure. For the actual connected application, use DEPLOYMENT.md instead.

This upload contains a static React demo with fictional records. Its entry point never constructs the live PHP API client. Its content policy blocks all API/network connections, and a refresh resets the workspace. It supports the entire sample sequence: choose fields and identity, save a draft, import, activate, search records and open details.

Relative asset URLs support installation in a subdirectory. No Node service, PHP runtime, database or FileMaker access is needed for this demo. When updating it, replace index.html and both assets together; refresh the page after upload. Verify your host applies the supplied content policy and headers. On non-Apache servers, configure equivalent headers if needed.

## Do not upload the source package as a website

Do not upload `private/`, `admin/`, `scripts/`, `tests/`, the package ZIP or a configured working checkout into the public demo folder. `public/` is the separate **connected** administration/API build; it is not the public demo. Hosting it requires the private installation steps and real administrator access described in INSTALLATION.md.

The standalone DEMO.html is an alternative for offline review. The online-demo folder uses separate assets and a stricter script policy, so it is the preferred public upload.

## Check after upload

1. Open the page in a private browser window. Demo login must work without credentials.
2. Save a draft, import and activate it; search `person77@` in Contacts and open record 77.
3. Refresh: sample_active returns and your temporary draft disappears.
4. Check the browser console for missing assets or policy errors. No API request should be made. The demo should work even when no PHP backend exists at that address.

No FTP deployment is performed by the package builder. You choose the target and upload it yourself.
