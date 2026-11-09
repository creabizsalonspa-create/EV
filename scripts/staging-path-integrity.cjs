#!/usr/bin/env node
'use strict';
// Source-only path provenance. Node's O_NOFOLLOW protects the leaf but not
// symlinked ancestor directories; require every ancestor to remain a real dir.
const fs=require('node:fs');
const path=require('node:path');
const {createHash}=require('node:crypto');
function snapshotFromParent(start) {
  const chain=[];
  let dir=start;
  for (;;) {
    let st;
    try { st=fs.lstatSync(dir); } catch {
      throw Error('Staging input ancestor inaccessible');
    }
    if (!st.isDirectory() || st.isSymbolicLink()) {
      throw Error('Staging input ancestor unsafe parent: must be a real directory');
    }
    chain.push(Object.freeze({path:dir,dev:st.dev,ino:st.ino}));
    const parent=path.dirname(dir);
    if (parent===dir) break;
    dir=parent;
  }
  return Object.freeze(chain);
}
function ancestorSnapshot(file) {
  if (typeof file!=='string' || !path.isAbsolute(file)) {
    throw Error('Staging input path must be absolute');
  }
  return snapshotFromParent(path.dirname(file));
}
function assertAncestorsUnchanged(chain) {
  if (!Array.isArray(chain) || chain.length===0) {
    throw Error('Missing staging input ancestor provenance');
  }
  const current=snapshotFromParent(chain[0].path);
  if (current.length!==chain.length || current.some((item,i)=>
      item.path!==chain[i].path || item.dev!==chain[i].dev ||
      item.ino!==chain[i].ino)) {
    throw Error('Staging input ancestor directory changed during inspection');
  }
  return true;
}
// Retain directory lineage across separate baseline/rollout inspections
// without placing absolute filesystem paths into serialized evidence.
function ancestorFingerprint(chain) {
  if (!Array.isArray(chain) || chain.length === 0) {
    throw Error('Missing staging ancestor lineage for fingerprint');
  }
  return createHash('sha256').update(JSON.stringify(chain)).digest('hex');
}
module.exports={ancestorSnapshot,assertAncestorsUnchanged,ancestorFingerprint};
