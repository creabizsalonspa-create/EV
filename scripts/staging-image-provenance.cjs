#!/usr/bin/env node
'use strict';
// Agent 10. Read-only, local Docker image/source identity evidence; no deployments.
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { parseSnapshot, checkSnapshot, localDockerSnapshot } = require('./staging-runtime-gate.cjs');

const SHA = /^[a-f0-9]{40}$/;
const IMAGE_ID = /^sha256:[a-f0-9]{64}$/;
const SERVICES = ['application', 'worker', 'scheduler'];
const SOURCE = 'https://github.com/creabizsalonspa-create/EV';
const MAX = 4 * 1024 * 1024;

function parseArgs(args) {
  const required = ['--source-sha'];
  if (args.length === 3 && args[0] === '--live' && args[1] === '--source-sha' && SHA.test(args[2])) {
    return { mode: 'live', source_sha: args[2] };
  }
  if (args.length === 6 && args[0] === '--source-sha' && SHA.test(args[1]) &&
    args[2] === '--image-snapshot' && args[3] && !args[3].startsWith('--') &&
    args[4] === '--containers-snapshot' && args[5] && !args[5].startsWith('--')) {
    return {mode: 'snapshot', source_sha:args[1], image:args[3], containers:args[5]};
  }
  throw Error('Usage: --live --source-sha SHA | --source-sha SHA --image-snapshot PRIVATE.json --containers-snapshot PRIVATE.json');
}
function readPrivate(file) {
  const st = fs.lstatSync(file);
  const parent = fs.lstatSync(path.dirname(path.resolve(file)));
  if (!st.isFile() || st.isSymbolicLink() || st.size === 0 || st.size > MAX ||
    !parent.isDirectory() || parent.isSymbolicLink() ||
    (process.platform !== 'win32' && ((st.mode & 0o077) !== 0 || (parent.mode & 0o077) !== 0))) {
    throw Error('Only small private, regular snapshot files in owner-only directories permitted');
  }
  return fs.readFileSync(file,'utf8');
}
function parseInspect(content) {
  if (typeof content !== 'string' || !content || Buffer.byteLength(content) > MAX) throw Error('Invalid inspect snapshot size');
  const data = JSON.parse(content);
  if (!Array.isArray(data) || !data.length || data.length > 3 || data.some(x => !x || typeof x !== 'object' || Array.isArray(x))) {
    throw Error('Invalid inspect snapshot records');
  }
  return data;
}
function validateImageContainers(images, containers, source_sha) {
  const errors = [];
  if (!SHA.test(source_sha) || images.length !== 1 || containers.length !== 3) return {ok:false,errors:['Incomplete source/image/container evidence']};
  const image = images[0];
  if (!IMAGE_ID.test(image.Id)) errors.push('Invalid immutable image ID');
  if (image.Config?.Labels?.['org.opencontainers.image.revision'] !== source_sha ||
      image.Config?.Labels?.['org.opencontainers.image.source'] !== SOURCE) {
    errors.push('Image revision/source label does not match reviewed checkout');
  }
  if (!image.Config?.Labels || image.Config.Labels['com.creabiz.ev.staging.review'] !== 'true') {
    errors.push('Image was not marked as a review artifact');
  }
  const seen = new Set();
  const ids = new Set();
  for (const ctr of containers) {
    const service = ctr.Config?.Labels?.['com.docker.compose.service'];
    if (!SERVICES.includes(service) || seen.has(service)) errors.push('Missing, duplicate or extra application role');
    seen.add(service);
    if (ctr.Config?.Labels?.['com.docker.compose.project'] !== 'ev-staging') errors.push('Container project does not match staging');
    if (ctr.State?.Running !== true || ctr.State?.Status !== 'running') errors.push('Stopped or unready staging application role');
    if (!IMAGE_ID.test(ctr.Image) || ctr.Image !== image.Id) errors.push('Running container image differs from reviewed image ID');
    if (!/^[a-f0-9]{64}$/.test(ctr.Id) || ids.has(ctr.Id)) errors.push('Duplicate/invalid immutable container ID');
    ids.add(ctr.Id);
    if (!/^creabiz-ev-business:[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$/.test(ctr.Config?.Image || '')) errors.push('Unrecognized staging image reference');
  }
  if (seen.size !== 3) errors.push('Expected three application roles');
  if (new Set(containers.map(c => c.Config?.Image)).size !== 1) errors.push('Application roles use different image tags');
  return {ok:errors.length===0,errors,source_sha,image_id:errors.length ? undefined : image.Id};
}
function localInspect(source_sha, {run=execFileSync, getCompose=localDockerSnapshot} = {}) {
  const env = process.env;
  const options = {encoding:'utf8',timeout:10000,maxBuffer:MAX};
  const before = parseSnapshot(getCompose(env,run));
  if (!checkSnapshot(before).ok) throw Error('Staging runtime gate failed');
  const selected = SERVICES.map(s => before.find(x=>x.Service===s)?.ID);
  if (selected.some(id=>!id || !/^[a-f0-9]{12,64}$/.test(id)) || new Set(selected).size!==3) {
    throw Error('Invalid Compose container IDs');
  }
  const containers = parseInspect(String(run('docker',['container','inspect',...selected],options)));
  const expectedRef=before.find(x=>x.Service==='application')?.Image;
  if (containers.length!==selected.length ||
      containers.some((c,i)=>typeof c.Id!=='string' ||
        !/^[a-f0-9]{64}$/.test(c.Id) || !c.Id.startsWith(selected[i]) ||
        c.Config?.Labels?.['com.docker.compose.service']!==SERVICES[i] ||
        c.Config?.Image!==expectedRef)) {
    throw Error('Compose/container identity, service role or tag mismatch');
  }
  if (!IMAGE_ID.test(containers[0].Image)) throw Error('Container image ID missing');
  const images = parseInspect(String(run('docker',['image','inspect',containers[0].Image],options)));
  // Detect any deployment race between the two observations.
  const after = parseSnapshot(getCompose(env,run));
  const protectedRoles=[...SERVICES,'database','gateway'];
  if (!checkSnapshot(after).ok || protectedRoles.some(s=>
    after.find(x=>x.Service===s)?.ID!==before.find(x=>x.Service===s)?.ID)) {
    throw Error('Staging containers changed during read-only inspection');
  }
  const proof=validateImageContainers(images,containers,source_sha);
  if (!proof.ok) return proof;
  // Keep a precise, private identity snapshot so a separate HTTPS probe can
  // prove that *the same* five staging containers survived its entire window.
  const runtime_services=Object.fromEntries(protectedRoles.map(s=>
    [s,before.find(x=>x.Service===s)?.ID]));
  if (Object.values(runtime_services).some(id=>typeof id!=='string' ||
      !/^[a-f0-9]{12,64}$/.test(id)) ||
      new Set(Object.values(runtime_services)).size!==5) {
    throw Error('Invalid staging container identities');
  }
  return {...proof,runtime_services};
}
function main(args=process.argv.slice(2), deps={}) {
  try {
    const config=parseArgs(args);
    const result=config.mode==='live'?localInspect(config.source_sha,deps):validateImageContainers(
      parseInspect(readPrivate(config.image)),parseInspect(readPrivate(config.containers)),config.source_sha);
    if (!result.ok) {
      process.stderr.write('FAIL: staging container image IDs, source labels or service roles do not match. No secrets disclosed.\n');
      return 1;
    }
    process.stdout.write('PASS: staging app, worker and scheduler run the same locally labeled image ID at the requested source SHA. Labels are self-declared and not cryptographic release attestation.\n');
    return 0;
  } catch {
    process.stderr.write('FAIL: private staging image evidence invalid, stale, unsafe or unavailable. No data changed.\n');
    return 2;
  }
}
if(require.main===module) process.exitCode=main();
module.exports={parseArgs,readPrivate,parseInspect,validateImageContainers,localInspect,main};
