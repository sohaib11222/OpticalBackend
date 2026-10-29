import { execFileSync } from 'node:child_process';
import { readFile, readdir, writeFile, lstat } from 'node:fs/promises';
import { resolve, join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { publishStatic } from './publish-static.mjs';

const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const git = (cwd, ...args) => execFileSync('git', ['-C', cwd, ...args], {encoding:'utf8'}).trim();
export async function verifyPublic(project, origin, base = '/') {
  const root = new URL(origin);
  if (root.protocol !== 'https:' && !['127.0.0.1','localhost'].includes(root.hostname)) throw new Error('Public verification requires HTTPS.');
  const files = [];
  async function visit(path, prefix = '') {
    for (const entry of await readdir(path, {withFileTypes:true})) {
      const name = prefix + entry.name;
      if (entry.isDirectory()) await visit(join(path, entry.name), name + '/');
      else if (entry.isFile()) files.push(name);
      else throw new Error('Unsupported build entry: ' + name);
    }
  }
  await visit(join(project, 'dist'));
  for (const name of files) {
    const url = new URL(base + name, root);
    url.searchParams.set('release_check', Date.now().toString());
    const response = await fetch(url, {headers:{'Cache-Control':'no-cache'}, redirect:'error', signal:AbortSignal.timeout(30000)});
    if (!response.ok) throw new Error(`Public file ${name}: HTTP ${response.status}`);
    if (hash(Buffer.from(await response.arrayBuffer())) !== hash(await readFile(join(project, 'dist', name)))) {
      throw new Error(`Public file differs: ${name}. Check Nginx root/CDN; rebuilding is not the remedy.`);
    }
  }
  // Verify ordinary SPA navigation too, without cache-busting.
  for (const route of ['', 'auth/login', 'auth/sign-in']) {
    const response = await fetch(new URL(base + route, root), {redirect:'error', signal:AbortSignal.timeout(30000)});
    if (!response.ok || hash(Buffer.from(await response.arrayBuffer())) !== hash(await readFile(join(project,'dist/index.html')))) throw new Error('Public SPA route serves a different index: ' + route);
  }
  return {verifiedFiles:files.length, origin:root.origin, base};
}

async function checkedCheckout(path) {
  if (resolve(git(path,'rev-parse','--show-toplevel')) !== resolve(path)) throw new Error('Expected an independent repository at ' + path);
  if (git(path,'status','--porcelain')) throw new Error('Checkout has changes: ' + path + '. Preserve/review them before release; do not reset server configuration.');
  if (git(path,'branch','--show-current') !== 'main') throw new Error('Expected main branch at ' + path);
  git(path,'fetch','origin');
  if (git(path,'rev-list','--count','origin/main..HEAD') !== '0') throw new Error('Server-only commits at ' + path + '. Review and reconcile them in the source repository first.');
  git(path,'merge','--ff-only','origin/main');
  return git(path,'rev-parse','HEAD');
}

async function release(portal, origin) {
  if (!['frontend','admin'].includes(portal)) throw new Error('Usage: node deploy/release-portal.mjs frontend|admin https://public-host [--verify-only]');
  const publicUrl = new URL(origin);
  if (publicUrl.protocol !== 'https:' || publicUrl.username || publicUrl.password || publicUrl.pathname !== '/' || publicUrl.search || publicUrl.hash) throw new Error('Supply the public HTTPS origin only, without credentials or a path.');
  const workspace = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
  const project = join(workspace, portal);
  if (process.argv.includes('--verify-only')) return verifyPublic(project, origin);
  for (const folder of [project, join(workspace,'contracts')]) if ((await lstat(folder)).isSymbolicLink()) throw new Error('Refusing symlink checkout: ' + folder);
  const commits = {portal: await checkedCheckout(project), contracts: await checkedCheckout(join(workspace,'contracts'))};
  // Verify the shared composer exists before modifying the served release.
  await readFile(join(workspace,'contracts/operations/MessageWizard.tsx'));
  const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  execFileSync(npm,['ci'],{cwd:project,stdio:'inherit'});
  execFileSync(npm,['run','build'],{cwd:project,stdio:'inherit',env:{...process.env,VITE_APP_BASE_PATH:'/'}});
  const index = await readFile(join(project,'dist/index.html'),'utf8');
  const manifest = {portal, commits, builtAt:new Date().toISOString(), base:'/', indexSha256:hash(index)};
  await writeFile(join(project,'dist/release.json'),JSON.stringify(manifest,null,2));
  const result = await publishStatic(project,'/');
  console.log(JSON.stringify({published:result,release:manifest},null,2));
  return verifyPublic(project,origin);
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  release(process.argv[2],process.argv[3]).then(result=>console.log(JSON.stringify(result,null,2))).catch(error=>{console.error(error.message);process.exitCode=1;});
}
