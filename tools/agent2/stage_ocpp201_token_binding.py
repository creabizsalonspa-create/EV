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
    helper=Path(__file__).resolve().parents[2]/'integrations/hasone-express/ocppStartAuthorization.mjs'
    out=path.parent/'ocppStartAuthorization.mjs'
    resource=helper.parent/'ocppResourceScope.mjs'
    resource_out=path.parent/'ocppResourceScope.mjs'
    if not helper.is_file() or not resource.is_file() or out.exists() or resource_out.exists():
        raise RuntimeError('Required single-use authorization helper missing or already staged')
    data=path.read_text(encoding='utf8')
    data=once(data,'const maxWh = 1e12;',
      'import {claimOcppStartToken,hashOcppToken,sameActiveOcpp16Retry} from "./ocppStartAuthorization.mjs";\nconst maxWh = 1e12;',
      'token helper import')
    data=once(data,
        '''        lastSeqNo:data.seqNo,tariff:await tariff(chargerId),status:"active",''',
        '''        lastSeqNo:data.seqNo,tariff:await tariff(chargerId),status:"active",
        idTokenHash:(await import("node:crypto")).createHash("sha256")
          .update(data.idToken.idToken).digest("hex"),''',
        '2.0.1 token evidence')
    marker='''  async function open16(chargerId,data) {'''
    consumption='''  // Transaction start consumes the scoped credential atomically.
  // An OCPP Authorize preflight remains read-only and does not call this.
  async function consumeToken(chargerId,value,resourceScope) {
    return (await claimOcppStartToken(tokens,chargerId,value,new Date(),resourceScope)).accepted;
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
    if(typeof data.idTag!=="string" || data.idTag.length<8 || data.idTag.length>20)
      return {idTagInfo:{status:"Invalid"}};
    const idTagHash=hashOcppToken(data.idTag);
    // A retried StartTransaction may have lost its original response.
    // Return the existing active transaction only for the same charger,
    // connector, token hash, and exact initial meter reading.
    const prior=await sessions.findOne({
      chargerId,protocol:"ocpp1.6",idTagHash,
      connectorId:data.connectorId,meterStartWh:Math.round(data.meterStart),
      status:"active"
    });
    if(prior && sameActiveOcpp16Retry(prior,{
      chargerId,tokenHash:idTagHash,connectorId:data.connectorId,meterStart:data.meterStart
    }))return {transactionId:prior.transactionId,idTagInfo:{status:"Accepted"}};
    if(!await consumeToken(chargerId,data.idTag,{
      protocol:"ocpp1.6",connectorId:data.connectorId,evseId:null
    }))
      return {idTagInfo:{status:"Invalid"}};
    const counter='''
    data=once(data,previous,replacement,'1.6 start consumption and safe retry')
    data=once(data,
      '''      status:"active",idTagHash:(await import("node:crypto")).createHash("sha256").update(data.idTag).digest("hex"),''',
      '''      status:"active",idTagHash,''',
      '1.6 reuse computed hash')
    data=once(data,
      '''const valid=data.idToken?.idToken && await validToken(chargerId,data.idToken.idToken);''',
      '''if(!Number.isInteger(data.evse?.id)||data.evse.id<1||data.evse.id>64 ||
         !Number.isInteger(data.evse?.connectorId)||data.evse.connectorId<1||data.evse.connectorId>64)
        return {idTokenInfo:{status:"Invalid"}};
      const valid=data.idToken?.idToken && await consumeToken(chargerId,data.idToken.idToken,{
        protocol:"ocpp2.0.1",connectorId:data.evse.connectorId,evseId:data.evse.id
      });''',
      '2.0.1 consume at start')
    data=once(data,
      '''    if(previous && (previous.lastSeqNo??-1)>=data.seqNo)return {};''',
      '''    if(previous && data.evse && (data.evse.id!==previous.evseId ||
        (data.evse.connectorId!==undefined && data.evse.connectorId!==previous.connectorId)))
      throw new Error("EVSE/connector does not match the source transaction");
    if(previous && (previous.lastSeqNo??-1)>=data.seqNo)return {};''',
      '2.0.1 resource consistency before replay')
    data=once(data,
      '''        await updateMeter(id,payload.transactionId,readMeter16(payload.meterValue));return {};''',
      '''        if(payload.connectorId!==undefined){
          const linked=await sessions.findOne({chargerId:id,protocol:"ocpp1.6",transactionId:payload.transactionId});
          if(linked && payload.connectorId!==linked.connectorId)
            throw new Error("Connector does not match the source transaction");
        }
        await updateMeter(id,payload.transactionId,readMeter16(payload.meterValue));return {};''',
      '1.6 explicit meter connector consistency')
    path.write_text(data,encoding='utf8')
    out.write_text(helper.read_text(encoding='utf8'),encoding='utf8')
    resource_out.write_text(resource.read_text(encoding='utf8'),encoding='utf8')
    print('STAGED_OCPP_START_TOKENS_ATOMIC_SINGLE_USE; live gateway unchanged')

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: stage_ocpp201_token_binding.py STAGED_HANDLERS_FILE')
    patch(Path(sys.argv[1]))

