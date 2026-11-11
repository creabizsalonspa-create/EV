'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const cp = require('node:child_process');
const SRC = path.join(__dirname,'../scripts/staging-image-build');
const SHA='f'.repeat(40);
function fixture({dirty=false,sha=SHA}={}){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'ev-builder-'));
  const scripts=path.join(root,'scripts'),bin=path.join(root,'bin');
  fs.mkdirSync(scripts);fs.mkdirSync(bin);fs.mkdirSync(path.join(root,'infra/staging'),{recursive:true});
  fs.copyFileSync(SRC,path.join(scripts,'staging-image-build'));
  fs.writeFileSync(path.join(scripts,'staging-image-check'),'#!/bin/sh\necho checked > "$EV_TEST_CHECK_LOG"\n',{mode:0o755});
  const git='#!/bin/sh\ncase "$1" in\n rev-parse) echo "$EV_TEST_SHA";;\n status) if [ "$EV_TEST_DIRTY" = 1 ];then echo " M scripts/staging-image-build";fi;;\n *) exit 2;;\nesac\n';
  fs.writeFileSync(path.join(bin,'git'),git,{mode:0o755});
  fs.writeFileSync(path.join(bin,'docker'),'#!/bin/sh\nprintf "%s\\n" "$@" > "$EV_TEST_DOCKER_LOG"\n',{mode:0o755});
  const log=path.join(root,'docker.log'), checkLog=path.join(root,'check.log');
  const env={...process.env,PATH:bin+':'+process.env.PATH,EV_TEST_DOCKER_LOG:log,EV_TEST_CHECK_LOG:checkLog,EV_TEST_SHA:sha,EV_TEST_DIRTY:dirty?'1':'0',BUILDX_CONFIG:path.join(root,'buildx'),EV_BUILD_CA_BUNDLE:'/dev/null'};
  return {root,log,checkLog,env,run:(tag='creabiz-ev-business:review')=>cp.spawnSync('bash',[path.join(scripts,'staging-image-build'),tag],{env,encoding:'utf8'}),done:()=>fs.rmSync(root,{force:true,recursive:true})};
}
test('build binds clean exact 40-hex checkout to immutable OCI label metadata',()=>{
  const f=fixture();try {
    const r=f.run();assert.equal(r.status,0,r.stderr);
    const lines=fs.readFileSync(f.log,'utf8').trim().split('\n');
    assert.ok(lines.includes('org.opencontainers.image.revision='+SHA));
    assert.ok(lines.includes('org.opencontainers.image.source=https://github.com/creabizsalonspa-create/EV'));
    assert.ok(lines.includes('com.creabiz.ev.staging.review=true'));
    assert.ok(lines.includes('build'));
    assert.ok(fs.existsSync(f.checkLog));
  }finally{f.done();}
});
test('dirty tracked checkout is refused before docker is invoked',()=>{
  const f=fixture({dirty:true});try {assert.equal(f.run().status,2);assert.equal(fs.existsSync(f.log),false);}finally{f.done();}
});
test('invalid commit digest is refused before build',()=>{
  for(const sha of ['','short', 'Z'.repeat(40)]) {
    const f=fixture({sha});try {assert.equal(f.run().status,2);assert.equal(fs.existsSync(f.log),false);}finally{f.done();}
  }
});
test('image tag shell and injected flags are refused',()=>{
  for (const tag of ['not-creabiz:latest','creabiz-ev-business:','creabiz-ev-business:review;rm -rf /','creabiz-ev-business:review\n--privileged']) {
    const f=fixture();try {assert.equal(f.run(tag).status,2);assert.equal(fs.existsSync(f.log),false);}finally{f.done();}
  }
});

test('builder status request includes untracked source changes',()=>{
  const file=fs.readFileSync(SRC,'utf8');
  assert.match(file,/git status --porcelain --untracked-files=normal/);
});

test('CI Docker-exclusion canaries are the only exceptional Git-ignored paths',()=>{
  const repo = path.resolve(__dirname, '..');
  const canaries = [
    'services/business-api/database/ev-image-guard.sqlite',
    'services/business-api/database/ev-image-guard.sqlite3',
    'services/business-api/database/ev-image-guard.sqlite-wal',
    'services/business-api/database/ev-image-guard.sqlite3-journal',
    'services/business-api/.env.image-canary',
  ];
  for (const candidate of canaries) {
    const result = cp.spawnSync('git', ['check-ignore', '-q', '--', candidate],
      {cwd: repo, encoding:'utf8'});
    assert.equal(result.status, 0, 'CI canary must be ignored by Git: '+candidate);
  }
  // Database/.gitignore already has an inherited *.sqlite* rule, so a
  // filename ending in .sqlite-backup is NOT a useful negative control.
  // Confirm unrelated PHP and env files remain visible instead.
  for (const candidate of [
    'services/business-api/database/Agent10Unexpected.php',
    'services/business-api/.env.image-canary-backup',
    'services/business-api/app/Agent10Untracked.php',
  ]) {
    const result = cp.spawnSync('git', ['check-ignore', '-q', '--', candidate],
      {cwd: repo, encoding:'utf8'});
    assert.equal(result.status, 1, 'Unexpected source must remain visible to git status: '+candidate);
  }
  // Require precisely five explicit canary rules at the repository root.
  // This catches accidental broadening even where an older nested ignore
  // would make git check-ignore -q pass for unrelated SQLite filenames.
  const rootRules = fs.readFileSync(path.join(repo,'.gitignore'),'utf8')
    .split(/\r?\n/).filter(line =>
      line.startsWith('/services/business-api/database/ev-image-guard.') ||
      line.startsWith('/services/business-api/.env.image-canary'));
  assert.deepEqual(rootRules, canaries.map(candidate => '/'+candidate));
  const dockerignore = fs.readFileSync(path.join(repo,'.dockerignore'),'utf8');
  for (const required of ['**/.env.*','**/*.sqlite','**/*.sqlite3',
    '**/*.sqlite-*','**/*.sqlite3-*']) {
    assert.ok(dockerignore.split(/\r?\n/).includes(required),
      'Docker context must exclude CI canaries with rule '+required);
  }
});
