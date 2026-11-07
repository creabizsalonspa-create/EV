#!/usr/bin/env python3
"""Stage OCPP2.0.1 transaction identity and atomic single-use start credentials.

Authorize is read-only; actual StartTransaction/TransactionEvent Started atomically
consumes an issued token. The original live gateway is NEVER changed.
"""
from pathlib import Path
import sys

def once(data,before,after,name):
    if data.count(before)!=1:raise RuntimeError(name+' source shape mismatch')
    return data.replace(before,after)

def patch(path):
    path=path.resolve()
    if path==Path('/home/hasio/ev-deployment') or str(path).startswith('/home/hasio/ev-deployment/'):
        raise RuntimeError('Never modify the deployed OCPP gateway')
    if not path.is_file():raise RuntimeError('Staged ocpp-handlers.mjs not found')
    data=path.read_text(encoding='utf8')
    data=once(data,
        '''        lastSeqNo:data.seqNo,tariff:await tariff(chargerId),status:"active",''',
        '''        lastSeqNo:data.seqNo,tariff:await tariff(chargerId),status:"active",
        idTokenHash:(await import("node:crypto")).createHash("sha256")
          .update(data.idToken.idToken).digest("hex"),''',
        '2.0.1 token evidence')
    marker='''  async function open16(chargerId,data) {'''
    consumption='''  // One booking credential can start at most one physical transaction.
  // The authorization preflight (Authorize) is read-only. The actual start
  // atomically consumes the token so concurrent starts cannot both succeed.
  async function consumeToken(chargerId,value) {
    if(typeof value!=="string"||value.length<8||value.length>64)return false;
    const tokenHash=(await import("node:crypto")).createHash("sha256")
      .update(value).digest("hex");
    const used=await tokens.findOneAndUpdate({
      chargerId,tokenHash,enabled:true,consumedAt:{$exists:false},
      expiresAt:{$gt:new Date()}
    },{$set:{enabled:false,consumedAt:new Date()}},{returnDocument:"after"});
    return Boolean(used);
  }
'''
    data=once(data,marker,consumption+marker,'consume function insertion')
    previous='''  async function open16(chargerId,data) {
    if(!await validToken(chargerId,data.idTag))return {idTagInfo:{status:"Invalid"}};
    if(!Number.isInteger(data.connectorId)||data.connectorId<1||data.connectorId>64 ||
      !Number.isFinite(data.meterStart)||data.meterStart<0||data.meterStart>maxWh)
      throw new Error("Invalid StartTransaction readings or connector");
    const counter='''
    replacement='''  async function open16(chargerId,data) {
    if(!Number.isInteger(data.connectorId)||data.connectorId<1||data.connectorId>64 ||
      !Number.isFinite(data.meterStart)||data.meterStart<0||data.meterStart>maxWh)
      throw new Error("Invalid StartTransaction readings or connector");
    if(typeof data.idTag!=="string" || data.idTag.length<8 || data.idTag.length>64)
      return {idTagInfo:{status:"Invalid"}};
    const idTagHash=(await import("node:crypto")).createHash("sha256")
      .update(data.idTag).digest("hex");
    // A retried StartTransaction may have lost its original response.
    // Return the existing active transaction only for the same charger,
    // connector, token hash, and exact initial meter reading.
    const prior=await sessions.findOne({
      chargerId,protocol:"ocpp1.6",idTagHash,
      connectorId:data.connectorId,meterStartWh:Math.round(data.meterStart),
      status:"active"
    });
    if(prior)return {transactionId:prior.transactionId,idTagInfo:{status:"Accepted"}};
    if(!await consumeToken(chargerId,data.idTag))
      return {idTagInfo:{status:"Invalid"}};
    const counter='''
    data=once(data,previous,replacement,'1.6 start consumption and safe retry')
    data=once(data,
      '''      status:"active",idTagHash:(await import("node:crypto")).createHash("sha256").update(data.idTag).digest("hex"),''',
      '''      status:"active",idTagHash,''',
      '1.6 reuse computed hash')
    data=once(data,
      '''const valid=data.idToken?.idToken && await validToken(chargerId,data.idToken.idToken);''',
      '''const valid=data.idToken?.idToken && await consumeToken(chargerId,data.idToken.idToken);''',
      '2.0.1 consume at start')
    path.write_text(data,encoding='utf8')
    print('STAGED_OCPP_START_TOKENS_ATOMIC_SINGLE_USE; live gateway unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_ocpp201_token_binding.py STAGED_HANDLERS_FILE')
    patch(Path(sys.argv[1]))
