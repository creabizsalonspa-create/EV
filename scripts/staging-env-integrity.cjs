#!/usr/bin/env node
'use strict';
// Source-only guard for an isolated staging release. Never exposes or writes
// private .env content, never mutates Docker and never connects to a service.
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');

const MAX_ENV_BYTES = 128 * 1024;
const MIN_ENV_BYTES = 20;

function capture(file) {
  if (typeof file !== 'string' || !path.isAbsolute(file) ||
      typeof fs.constants.O_NOFOLLOW !== 'number' ||
      typeof fs.constants.O_NONBLOCK !== 'number') {
    throw Error('Private staging environment cannot be securely reviewed');
  }
  let fd;
  try {
    fd = fs.openSync(file, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW |
      fs.constants.O_NONBLOCK | (fs.constants.O_CLOEXEC || 0));
  } catch {
    throw Error('Private staging environment cannot be securely opened');
  }
  const buffer = Buffer.alloc(MAX_ENV_BYTES + 1);
  try {
    const before = fs.fstatSync(fd);
    if (!before.isFile() || before.size < MIN_ENV_BYTES ||
        before.size > MAX_ENV_BYTES || (before.mode & 0o077) !== 0 ||
        before.nlink !== 1) {
      throw Error('Private staging environment file type, access or size invalid');
    }
    let count=0;
    while(count < buffer.length) {
      const read = fs.readSync(fd, buffer, count, buffer.length-count, null);
      if(read===0)break;
      count += read;
    }
    const after = fs.fstatSync(fd);
    if (count !== before.size || before.size !== after.size ||
        before.dev !== after.dev || before.ino !== after.ino ||
        before.mtimeMs !== after.mtimeMs || before.ctimeMs !== after.ctimeMs ||
        (after.mode & 0o077) !== 0) {
      throw Error('Private staging environment changed during review');
    }
    return Object.freeze({digest: createHash('sha256').update(buffer.subarray(0,count)).digest('hex'),
      size:count, dev:before.dev, ino:before.ino});
  } finally {
    buffer.fill(0);
    fs.closeSync(fd);
  }
}

function assertUnchanged(file, baseline) {
  if (!baseline || typeof baseline !== 'object' ||
      !/^[a-f0-9]{64}$/.test(baseline.digest||'')) {
    throw Error('Missing reviewed private staging environment identity');
  }
  const latest = capture(file);
  if (latest.digest !== baseline.digest || latest.size !== baseline.size ||
      latest.dev !== baseline.dev || latest.ino !== baseline.ino) {
    throw Error('Private staging environment changed since preflight');
  }
  return true;
}
module.exports={MAX_ENV_BYTES,MIN_ENV_BYTES,capture,assertUnchanged};
