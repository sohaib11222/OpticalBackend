import { cp, lstat, mkdir, readFile, readdir, rename, writeFile, copyFile } from 'node:fs/promises';
import { resolve, join, dirname, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

// Explicit release step for the existing Nginx www roots. Never empties the
// served directory: retained hashed assets also support already-open browsers.
export async function publishStatic(project, expectedBase) {
  if (!['/', '/pulse-ui/'].includes(expectedBase)) throw new Error('Expected base must be / or /pulse-ui/.');
  project = resolve(project);
  const source = join(project, 'dist');
  const target = join(project, 'www');
  for (const path of [project, source, target]) {
    const stat = await lstat(path).catch(error => { if (error.code !== 'ENOENT') throw error; return null; });
    if (stat?.isSymbolicLink()) throw new Error(`Refusing symlink path: ${path}`);
  }
  const files = [];
  async function inspect(folder) {
    for (const entry of await readdir(folder, { withFileTypes: true })) {
      const path = join(folder, entry.name);
      if (entry.isSymbolicLink() || entry.name.startsWith('.')) throw new Error(`Unexpected hidden file or symlink: ${path}`);
      if (entry.isDirectory()) await inspect(path);
      else if (entry.isFile()) files.push(relative(source, path));
      else throw new Error(`Unsupported build entry: ${path}`);
    }
  }
  await inspect(source);
  const html = await readFile(join(source, 'index.html'), 'utf8');
  const assets = [...html.matchAll(/(?:src|href)=["']([^"']+\.(?:js|css))["']/g)]
    .map(match => match[1]).filter(url => !/^https?:\/\//.test(url));
  if (!assets.some(url => url.endsWith('.js'))) throw new Error('Build has no JavaScript entry.');
  for (const asset of assets) {
    if (!asset.startsWith(`${expectedBase}assets/`) || asset.includes('..') || asset.includes('\\')) {
      throw new Error(`Build base mismatch: ${asset}; expected ${expectedBase}assets/. Rebuild with the correct base.`);
    }
    await lstat(join(source, asset.slice(expectedBase.length)));
  }
  await lstat(join(source, 'images', 'auth-editorial.webp'));
  const js = await Promise.all(assets.filter(url => url.endsWith('.js')).map(url => readFile(join(source, url.slice(expectedBase.length)), 'utf8')));
  if (!js.some(code => code.includes('ve-auth-story') && code.includes('auth-editorial.webp'))) {
    throw new Error('Build does not contain the current authentication layout. Pull matching source/contracts and rebuild.');
  }
  // Validate every destination ancestor before writing any served file.
  for (const file of files) {
    let path = target;
    for (const segment of file.split(sep)) {
      path = join(path, segment);
      const stat = await lstat(path).catch(error => { if (error.code !== 'ENOENT') throw error; return null; });
      if (stat?.isSymbolicLink()) throw new Error(`Refusing destination symlink: ${path}`);
    }
  }
  const release = new Date().toISOString().replace(/[:.]/g, '-') + '-' + process.pid;
  const backup = join(project, '.deployment-backups', release);
  const backupStat = await lstat(join(project, '.deployment-backups')).catch(error => { if (error.code !== 'ENOENT') throw error; return null; });
  if (backupStat?.isSymbolicLink()) throw new Error('Refusing backup directory symlink.');
  await mkdir(backup, { recursive: true });
  const previous = await lstat(target).catch(error => { if (error.code !== 'ENOENT') throw error; return null; });
  if (previous) await cp(target, join(backup, 'www'), { recursive: true, dereference: false });
  await mkdir(target, { recursive: true });
  for (const file of files.filter(file => file !== 'index.html')) {
    await mkdir(dirname(join(target, file)), { recursive: true });
    await copyFile(join(source, file), join(target, file));
  }
  const temporaryIndex = join(target, `index-${release}.tmp`);
  await writeFile(temporaryIndex, html, { flag: 'wx', mode: 0o644 });
  await rename(temporaryIndex, join(target, 'index.html'));
  return { target, backup, files: files.length, indexSha256: createHash('sha256').update(html).digest('hex') };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const portal = process.argv[2];
  if (!['frontend', 'admin'].includes(portal)) {
    console.error('Usage: node backend/deploy/publish-static.mjs frontend|admin [--base=/]');
    process.exitCode = 1;
  } else {
    const base = process.argv[3]?.replace(/^--base=/, '') || '/';
    const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
    try { console.log(JSON.stringify(await publishStatic(join(root, portal), base), null, 2)); }
    catch (error) { console.error(error.message); process.exitCode = 1; }
  }
}
