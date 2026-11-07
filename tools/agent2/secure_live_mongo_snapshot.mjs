#!/usr/bin/env node
/**
 * Agent 2: encrypted, read-only Mongo snapshot on the owner's authorized server.
 * The backup is kept on the host; do not upload customer data or credentials to GitHub.
 *
 * From the deployed Express project directory:
 *   node --env-file=.env /path/to/secure_live_mongo_snapshot.mjs --run
 *
 * This script NEVER restarts processes, changes the database, or issues OCPP commands.
 */
import {spawnSync} from 'node:child_process';
import {randomBytes,createHash} from 'node:crypto';
import {mkdtempSync,mkdirSync,writeFileSync,readFileSync,statSync,rmSync,chmodSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const root='/home/hasio/ev-deployment/backups';
const supplied=process.env.MONGO_URL;
if (process.argv[2] !== '--run') {
 console.log('Read-only snapshot utility. Run with --run from the authorized Express project with --env-file=.env');
 process.exit(0);
}
if (!supplied || !/^mongodb(\+srv)?:\/\//.test(supplied)) {
 console.error('No valid MONGO_URL found in process environment.'); process.exit(2);
}
let dbName;
try { dbName=decodeURIComponent(new URL(supplied).pathname.slice(1)); }
catch { console.error('Malformed MongoDB URL'); process.exit(2); }
if (!/^[a-zA-Z0-9_-]{1,64}$/.test(dbName) || ['admin','local','config'].includes(dbName)) {
 console.error('Refusing to back up an unspecified or administrative database'); process.exit(2);
}
mkdirSync(root,{recursive:true,mode:0o700});
const temp=mkdtempSync(join(tmpdir(),'agent2-mongo-cfg-'));
const output=mkdtempSync(join(root,'agent2-mongo-snapshot-'));
chmodSync(output,0o700);
const config=join(temp,'mongo.yaml'), key=join(output,'backup-key.txt');
const archive=join(output,'mongo.archive.gz.enc'), manifest=join(output,'backup-verification.json');
writeFileSync(config,'uri: '+JSON.stringify(supplied)+'\n',{mode:0o600});
writeFileSync(key,randomBytes(48).toString('hex')+'\n',{mode:0o600});
const env={...process.env,AGENT2_CONFIG:config,AGENT2_DB:dbName,AGENT2_KEY:key,AGENT2_OUTPUT:archive};
function run(command){
 const p=spawnSync('bash',['-o','pipefail','-c',command],{
  env,stdio:['ignore','pipe','pipe'],timeout:120000,maxBuffer:1024*256,
 });
 if(p.status!==0 || p.error) throw new Error('Encrypted backup or archive validation failed (process exit '+p.status+')');
 return (p.stdout??Buffer.alloc(0)).toString('utf8').trim();
}
let complete=false;
try {
 run('mongodump --config="$AGENT2_CONFIG" --db="$AGENT2_DB" --archive --gzip | openssl enc -aes-256-cbc -salt -pbkdf2 -pass file:"$AGENT2_KEY" -out "$AGENT2_OUTPUT"');
 chmodSync(archive,0o600);
 const bytes=Number(run('openssl enc -d -aes-256-cbc -pbkdf2 -pass file:"$AGENT2_KEY" -in "$AGENT2_OUTPUT" | wc -c'));
 if(!Number.isFinite(bytes)||bytes<100) throw new Error('Snapshot unexpectedly small');
 // Mongo's --dryRun validates archive structure without applying any writes.
 run('openssl enc -d -aes-256-cbc -pbkdf2 -pass file:"$AGENT2_KEY" -in "$AGENT2_OUTPUT" | mongorestore --config="$AGENT2_CONFIG" --archive --gzip --dryRun >/dev/null');
 const encrypted=readFileSync(archive);
 const record={
  mode:'encrypted-on-host-only',
  createdUtc:new Date().toISOString(),
  archivedBytes:bytes,
  encryptedBytes:statSync(archive).size,
  ciphertextSha256:createHash('sha256').update(encrypted).digest('hex'),
  archiveDecrypted:true,
  mongorestoreDryRunPassed:true,
  containsProductionData:true,
  databaseWasModified:false,
  offsiteRecoveryCopyVerified:false,
 };
 writeFileSync(manifest,JSON.stringify(record,null,2)+'\n',{mode:0o600});
 complete=true;
 console.log(JSON.stringify({snapshotDirectory:output,...record}));
} catch(err) {
 console.error('Snapshot not accepted: '+err.message);
 process.exitCode=1;
} finally {
 rmSync(temp,{recursive:true,force:true});
 if(!complete) rmSync(output,{recursive:true,force:true});
}
