// Run through admin's package:community command. Only explicitly listed source
// trees are eligible; never enumerate private/config.php or runtime directories.
import { readFile, writeFile, mkdir, readdir, lstat, copyFile } from 'node:fs/promises';
import { dirname, resolve, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const { version } = JSON.parse(await readFile(join(root, 'admin/package.json'), 'utf8'));
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const output = join(root, 'release', `autoCache-community-${version}-${stamp}`);
await mkdir(output, { recursive: true });
const forbiddenNames = /^(?:config(?:\..+)?\.php|\.env(?:\..*)?|node_modules|runtime|\.git|release|\.release-build)$/i;
async function copy(source, destination = source) {
  const from = join(root, source), to = join(output, destination);
  const stat = await lstat(from);
  if (stat.isSymbolicLink()) throw new Error(`Refusing symlink: ${source}`);
  if (forbiddenNames.test(source.split(/[\\/]/).at(-1)) && source !== 'private/config.example.php') throw new Error(`Forbidden release path: ${source}`);
  if (stat.isDirectory()) {
    await mkdir(to, { recursive: true });
    for (const entry of await readdir(from)) await copy(join(source, entry), join(destination, entry));
  } else {
    await mkdir(dirname(to), { recursive: true });
    await copyFile(from, to);
  }
}
for (const path of ['public/admin', 'private/src', 'private/migrations', 'private/vendor', 'private/config.example.php', 'admin/src', 'admin/tests', 'admin/package.json', 'admin/package-lock.json', 'admin/index.html', 'admin/tsconfig.json', 'admin/vite.config.ts', 'admin/vite.demo.config.ts', 'filemaker']) await copy(path);
for (const name of ['admin-password.php', 'database.php', 'import.php', 'mapping.php', 'source.php', 'token.php', 'package-community.mjs', 'package-deployment.mjs']) await copy(`scripts/${name}`);
for (const name of ['run.php', 'contact.php', 'admin.php', 'admin-records.php', 'odata-adapter.php']) await copy(`tests/${name}`);
await copy('community-docs');
for (const name of await readdir(join(root, 'community-docs'))) await copy(`community-docs/${name}`, name);
// A portable default: only public/ is a document root. The working checkout's
// host-specific bootstrap is intentionally never copied into a community bundle.
await writeFile(join(output, 'public/index.php'), "<?php\ndeclare(strict_types=1);\nrequire_once dirname(__DIR__) . '/private/src/http.php';\nrgcCache_run();\n");
await writeFile(join(output, 'public/.htaccess'), 'Options -Indexes -MultiViews\nDirectoryIndex index.php\nRewriteEngine On\nRewriteCond %{HTTP:Authorization} .\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^ index.php [L,QSA]\n');
await writeFile(join(output, '.gitignore'), '/private/config.php\n/private/config.*.php\n!/private/config.example.php\n/admin/node_modules/\n/.release-build/\n/release/\n*.log\n');
let settings = await readFile(join(output, 'filemaker/01-Settings.txt'), 'utf8');
settings = settings.replace('http://127.0.0.1:8080/rgccache/api/v1', 'https://YOUR-SERVER/autocache/api/v1').replace('"CWP Snippets"', '"YOUR_FILE_NAME"').replace('"rgcCache_Test"', '"YOUR_TABLE_NAME"');
await writeFile(join(output, 'filemaker/01-Settings.txt'), settings);
const js = (await readFile(join(root, '.release-build/demo/demo.js'), 'utf8')).replace(/<\/script/gi, '<\\/script');
if (/process\.env\./.test(js)) throw new Error('Offline bundle contains an unresolved Node environment variable');
const css = (await readFile(join(root, '.release-build/demo/demo.css'), 'utf8')).replace(/<\/style/gi, '<\\/style');
await writeFile(join(output, 'DEMO.html'), `<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; base-uri 'none'; form-action 'none'"><title>autoCache · Community demo</title><style>${css}</style></head><body><div id="root"></div><script>${js}</script></body></html>`);
// This is the public FTP demo: no PHP, configured accounts or live API adapter.
const demo = join(output, 'online-demo');
await mkdir(join(demo, 'assets'), { recursive: true });
await copyFile(join(root, '.release-build/demo/demo.js'), join(demo, 'assets/app.js'));
await copyFile(join(root, '.release-build/demo/demo.css'), join(demo, 'assets/app.css'));
await writeFile(join(demo, 'index.html'), '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'self\'; style-src \'self\'; img-src data:; connect-src \'none\'; base-uri \'none\'; form-action \'none\'"><title>autoCache · Community demo</title><link rel="stylesheet" href="./assets/app.css"><script defer src="./assets/app.js"></script></head><body><div id="root"></div></body></html>');
await writeFile(join(demo, '.htaccess'), 'Options -Indexes\nDirectoryIndex index.html\n<IfModule mod_headers.c>\nHeader always set X-Content-Type-Options "nosniff"\nHeader always set X-Frame-Options "DENY"\nHeader always set Referrer-Policy "no-referrer"\nHeader always set Content-Security-Policy "default-src \'none\'; script-src \'self\'; style-src \'self\'; img-src data:; connect-src \'none\'; base-uri \'none\'; form-action \'none\'; frame-ancestors \'none\'"\n</IfModule>\n');
// Retain dependency notices even though the runtime ships as compiled assets.
await mkdir(join(output, 'licenses'), { recursive: true });
for (const name of ['react', 'react-dom', 'scheduler']) {
  await copy(`admin/node_modules/${name}/LICENSE`, `licenses/${name}-LICENSE.txt`);
  await copy(`admin/node_modules/${name}/LICENSE`, `online-demo/licenses/${name}-LICENSE.txt`);
}
await writeFile(join(demo, 'CREDITS.txt'), 'autoCache — created by Alex Seidler (https://alexseidler.dev), in collaboration with RGC Data Solutions (https://rgcdata.com).\nReact, React DOM and Scheduler license notices are in licenses/.\n');
const manifest = [];
async function verify(directory) {
  for (const name of (await readdir(directory)).sort()) {
    const path = join(directory, name), stat = await lstat(path);
    if (stat.isDirectory()) { await verify(path); continue; }
    const rel = relative(output, path).replaceAll('\\', '/');
    if (/^(private\/config\.php|.*\.(log|sql\.gz|fmp12|zip)|.*\/runtime\/)/i.test(rel)) throw new Error(`Unexpected release file: ${rel}`);
    const bytes = await readFile(path);
    // Public templates/tests may contain synthetic placeholders, but never live
    // deployment URLs, passwords supplied for reference review or private keys.
    const text = bytes.toString('utf8');
    if (/staging\.fullaccess\.us|-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----/i.test(text) || text.includes(['admin', '123'].join(''))) throw new Error(`Release scan rejected ${rel}`);
    manifest.push(`${createHash('sha256').update(bytes).digest('hex')}  ${rel}`);
  }
}
await verify(output);
await writeFile(join(output, 'SHA256SUMS.txt'), manifest.join('\n') + '\n');
console.log(`Community folder: ${output}\nVerified ${manifest.length} allow-listed files. No local configuration or runtime data copied.`);
