#!/usr/bin/env python3
"""Stage protocol-qualified connector report receipts; no live deployment."""
from pathlib import Path
import sys


def patch(path):
    path=path.resolve();production=Path('/home/hasio/ev-deployment')
    if path==production or production in path.parents:raise RuntimeError('Refusing deployed gateway')
    text=path.read_text()
    if 'connectorId:connector,receivedAt:' in text or 'lastMeterReceivedAt' not in text:
        raise RuntimeError('Already staged or accepted-meter receipt prerequisite missing')
    before='''      await db.collection("ocpp_chargers").updateOne({chargerId:id},{$set:{
        ["connectors."+evse+"_"+connector]:{status,updatedAt:date(payload.timestamp)},'''
    after='''      if(!["ocpp1.6","ocpp2.0.1"].includes(protocol)||
         (protocol==="ocpp2.0.1"&&(evse<1||connector<1)))
        throw new Error("Invalid connector status scope");
      await db.collection("ocpp_chargers").updateOne({chargerId:id},{$set:{
        ["connectors."+evse+"_"+connector]:{status,protocol,evseId:protocol==="ocpp1.6"?null:evse,
          connectorId:connector,receivedAt:new Date(),updatedAt:date(payload.timestamp)},'''
    if text.count(before)!=1:raise RuntimeError('Connector receipt source anchor changed')
    text=text.replace(before,after)
    for old,new in [
      ('status:"active",idTagHash,lastMeterReceivedAt:new Date(),',
       'status:"active",idTagHash,startedReceivedAt:new Date(),lastMeterReceivedAt:new Date(),'),
      ('lastMeterReceivedAt:meter===null?null:new Date(),',
       'startedReceivedAt:new Date(),lastMeterReceivedAt:meter===null?null:new Date(),')]:
        if text.count(old)!=1:raise RuntimeError('Accepted source-start receipt anchor changed')
        text=text.replace(old,new)
    path.write_text(text)
    print('STAGED_QUALIFIED_CONNECTOR_RECEIPTS; no availability, commands or payment authority added')


if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Pass isolated gateway handler')
    patch(Path(sys.argv[1]))
