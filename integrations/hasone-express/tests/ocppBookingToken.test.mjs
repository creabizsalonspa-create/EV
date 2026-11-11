import test from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {createOcppBookingToken} from '../ocppBookingToken.mjs';
import {hashOcppToken,claimOcppStartToken} from '../ocppStartAuthorization.mjs';

test('booking credentials fit the 20-character OCPP 1.6 wire field',()=>{
 for(let i=0;i<32;i++){
   const token=createOcppBookingToken();
   assert.equal(token.length,20);
   assert.match(token,/^[0-9A-F]{20}$/);
 }
});
test('short wire credentials retain full SHA-256 storage identity',()=>{
 const token=createOcppBookingToken();
 const digest=hashOcppToken(token);
 assert.equal(digest,createHash('sha256').update(token).digest('hex'));
 assert.match(digest,/^[a-f0-9]{64}$/);
 assert.notEqual(token,digest);
});
test('protocol-sized credential still uses atomic charger-scoped single-use claim',async()=>{
 const token=createOcppBookingToken();
 const digest=hashOcppToken(token);
 let consumed=false;
 const table={async findOneAndUpdate(query,update){
   assert.equal(query.chargerId,'charger-A');
   assert.equal(query.tokenHash,digest);
   assert.equal(query.enabled,true);
   assert.deepEqual(query.consumedAt,{$exists:false});
   assert.equal(update.$set.enabled,false);
   assert.ok(update.$set.consumedAt instanceof Date);
   assert.ok(Object.hasOwn(update.$unset,'expiresAt'));
   if(consumed)return null;
   consumed=true;return {chargerId:query.chargerId,tokenHash:digest,...update.$set};
 }};
 assert.equal((await claimOcppStartToken(table,'charger-A',token)).accepted,true);
 assert.equal((await claimOcppStartToken(table,'charger-A',token)).accepted,false);
});

