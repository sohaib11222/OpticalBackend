import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {verifyPublic} from './release-portal.mjs';
for (const portal of ['frontend','admin']) test(portal + ' verifies actual bytes and rejects stale served index/assets',async()=>{
  const dir=await mkdtemp(join(tmpdir(),'ve360-verify-'));
  await mkdir(join(dir,'dist/assets'),{recursive:true});
  await writeFile(join(dir,'dist/index.html'),'new index');
  await writeFile(join(dir,'dist/assets/app.js'),'new script');
  let stale='';
  const server=createServer((req,res)=>res.end(req.url.includes('/assets/') ? (stale==='asset'?'old script':'new script') : (stale==='index'?'old index':'new index')));
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  try {
    const url='http://127.0.0.1:'+server.address().port;
    assert.equal((await verifyPublic(dir,url)).verifiedFiles,2);
    stale='asset';await assert.rejects(verifyPublic(dir,url),/differs/);
    stale='index';await assert.rejects(verifyPublic(dir,url),/differs/);
  } finally { await new Promise(resolve=>server.close(resolve));await rm(dir,{recursive:true,force:true}); }
});
