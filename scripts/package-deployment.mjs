// Package only an already sanitized community release, never the working private folder.
import { cp, mkdir, readFile, readdir, lstat, writeFile } from 'node:fs/promises';
import { resolve, dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const name = process.argv[2];
if (!/^autoCache-community-[0-9A-Za-z.-]+$/.test(name ?? '')) throw new Error('Pass a generated community folder name, not a path.');
const source = join(root, 'release', name);
const target = join(root, 'release', name.replace('community', 'deployment'));
// Verify the sanitized source before copying it.
const entries = (await readFile(join(source, 'SHA256SUMS.txt'), 'utf8')).trim().split('\n');
const approved = new Set();
for (const line of entries) {
  const match = /^([a-f0-9]{64})  (.+)$/.exec(line.trim());
  if (!match || match[2].includes('..') || match[2].startsWith('/') || match[2].includes('\\')) throw new Error('Invalid manifest entry');
  const hash = createHash('sha256').update(await readFile(join(source, match[2]))).digest('hex');
  if (hash !== match[1]) throw new Error(`Manifest mismatch: ${match[2]}`);
  approved.add(match[2]);
}
await mkdir(target); // Refuse to overwrite any existing release.
const manifest = [];
async function copy(path) {
  const item = join(source, path), stat = await lstat(item);
  if (stat.isSymbolicLink()) throw new Error('Symlinks are not permitted');
  if (stat.isDirectory()) {
    await mkdir(join(target, path), { recursive: true });
    for (const child of await readdir(item)) await copy(join(path, child));
    return;
  }
  const rel = path.replaceAll('\\', '/');
  if (!approved.has(rel) || /(^|\/)config\.php$/.test(rel)) throw new Error(`Unapproved deployment file: ${rel}`);
  await mkdir(dirname(join(target, path)), { recursive: true });
  await cp(item, join(target, path));
  manifest.push(`${createHash('sha256').update(await readFile(item)).digest('hex')}  ${relative(target, join(target, path)).replaceAll('\\', '/')}`);
}
for (const path of ['public', 'private', 'filemaker', 'licenses', 'DEPLOYMENT.md', 'INSTALLATION.md', 'SECURITY.md', 'CREDITS.md']) await copy(path);
for (const name of ['admin-password.php', 'database.php', 'import.php', 'mapping.php', 'source.php', 'token.php']) await copy(`scripts/${name}`);
await writeFile(join(target, 'SHA256SUMS.txt'), manifest.sort().join('\n') + '\n');
console.log(`Deployment folder: ${target}\nVerified ${manifest.length} runtime and setup files. Upload only public/ to the web root.`);
