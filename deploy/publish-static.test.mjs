import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { publishStatic } from './publish-static.mjs';

async function fixture(base = '/') {
  const root = await mkdtemp(join(tmpdir(), 've360-release-'));
  await mkdir(join(root, 'dist/assets'), { recursive: true });
  await mkdir(join(root, 'dist/images'));
  await mkdir(join(root, 'www/assets'), { recursive: true });
  await writeFile(join(root, 'www/index.html'), 'old index');
  await writeFile(join(root, 'www/assets/old.js'), 'old browser asset');
  await writeFile(join(root, 'dist/index.html'), `<script src="${base}assets/new.js"></script>`);
  await writeFile(join(root, 'dist/assets/new.js'), 've-auth-story auth-editorial.webp');
  await writeFile(join(root, 'dist/images/auth-editorial.webp'), 'test image');
  return root;
}
test('publishes complete build, preserves old assets and backs up served files', async () => {
  const root = await fixture();
  try {
    const result = await publishStatic(root, '/');
    assert.equal(await readFile(join(root, 'www/index.html'), 'utf8'), '<script src="/assets/new.js"></script>');
    assert.equal(await readFile(join(result.backup, 'www/index.html'), 'utf8'), 'old index');
    assert.equal(await readFile(join(root, 'www/assets/old.js'), 'utf8'), 'old browser asset');
    assert.equal(await readFile(join(root, 'www/images/auth-editorial.webp'), 'utf8'), 'test image');
  } finally { await rm(root, { recursive: true, force: true }); }
});
test('wrong base and stale builds fail before modifying live index', async () => {
  const root = await fixture('/pulse-ui/');
  try {
    await assert.rejects(publishStatic(root, '/'), /base mismatch/);
    assert.equal(await readFile(join(root, 'www/index.html'), 'utf8'), 'old index');
    await writeFile(join(root, 'dist/assets/new.js'), 'old app');
    await assert.rejects(publishStatic(root, '/pulse-ui/'), /current authentication/);
    assert.equal(await readFile(join(root, 'www/index.html'), 'utf8'), 'old index');
  } finally { await rm(root, { recursive: true, force: true }); }
});
