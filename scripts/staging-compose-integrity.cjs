#!/usr/bin/env node
'use strict';
// Bind a private staging rollout to the reviewed immutable Compose definition.
// Source-only check; never parses credentials, changes Docker or invokes network.
const fs=require('node:fs');
const path=require('node:path');
const {createHash}=require('node:crypto');
const {ancestorSnapshot,assertAncestorsUnchanged,ancestorFingerprint}=require('./staging-path-integrity.cjs');
const MAX_BYTES=1024*1024;
const MIN_BYTES=40;

function capture(file) {
  if(typeof file!=='string' || !path.isAbsolute(file) ||
     typeof fs.constants.O_NOFOLLOW!=='number' ||
     typeof fs.constants.O_NONBLOCK!=='number') {
    throw Error('Staging Compose file path cannot be securely inspected');
  }
  const ancestors=ancestorSnapshot(file);
  let fd;
  try {
    fd=fs.openSync(file,fs.constants.O_RDONLY|fs.constants.O_NOFOLLOW|
      fs.constants.O_NONBLOCK|(fs.constants.O_CLOEXEC||0));
  }catch{
    throw Error('Staging Compose definition cannot be securely opened');
  }
  try {
    const before=fs.fstatSync(fd);
    if(!before.isFile() || before.nlink!==1 || before.size<MIN_BYTES ||
       before.size>MAX_BYTES || (before.mode&0o022)!==0 ||
       (before.mode&0o400)===0) {
      throw Error('Staging Compose definition file type, size or permissions invalid');
    }
    const content=Buffer.alloc(MAX_BYTES+1);
    try {
      let count=0;
      while(count<content.length){
        const n=fs.readSync(fd,content,count,content.length-count,null);
        if(n===0)break;
        count+=n;
      }
      const after=fs.fstatSync(fd);
      if(count!==before.size || after.size!==before.size ||
         after.dev!==before.dev || after.ino!==before.ino ||
         after.mtimeMs!==before.mtimeMs || after.ctimeMs!==before.ctimeMs ||
         (after.mode&0o022)!==0 || after.nlink!==1) {
        throw Error('Staging Compose definition changed during inspection');
      }
      assertAncestorsUnchanged(ancestors);
      return Object.freeze({size:count,dev:before.dev,ino:before.ino,
        digest:createHash('sha256').update(content.subarray(0,count)).digest('hex'),
        ancestorDigest:ancestorFingerprint(ancestors)});
    }finally{content.fill(0);}
  }finally{fs.closeSync(fd);}
}
function assertUnchanged(file,baseline){
  if(!baseline || typeof baseline!=='object' ||
      typeof baseline.digest!=='string' || !/^[a-f0-9]{64}$/.test(baseline.digest) ||
      !/^[a-f0-9]{64}$/.test(baseline.ancestorDigest||'')){
    throw Error('Missing reviewed staging Compose identity');
  }
  const now=capture(file);
  if(now.size!==baseline.size || now.dev!==baseline.dev ||
     now.ino!==baseline.ino || now.digest!==baseline.digest ||
     now.ancestorDigest!==baseline.ancestorDigest){
    throw Error('Staging Compose definition changed after review');
  }
  return true;
}
module.exports={MAX_BYTES,MIN_BYTES,capture,assertUnchanged};
