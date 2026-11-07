#!/usr/bin/env python3
"""Stage accepted-meter receipt metadata and reject coerced/oversized samples."""
from pathlib import Path
import sys


def once(text, before, after):
    if text.count(before) != 1:
        raise RuntimeError('Meter receipt source anchor changed')
    return text.replace(before, after)


def patch(path):
    path = path.resolve()
    production = Path('/home/hasio/ev-deployment')
    if path == production or production in path.parents:
        raise RuntimeError('Refusing deployed gateway source')
    text = path.read_text()
    if 'lastMeterReceivedAt' in text or 'claimOcppStartToken' not in text:
        raise RuntimeError('Already staged or scoped authorization prerequisite missing')
    text = once(text, '  const value=Number(sample.value);', '''  const raw=sample.value;
  if(typeof raw!=="number" && (typeof raw!=="string" || raw.length>64 ||
      !/^[+-]?(?:\\d+(?:\\.\\d*)?|\\.\\d+)(?:[eE][+-]?\\d+)?$/.test(raw.trim())))return null;
  const value=Number(raw);''')
    text = once(text, '  return Math.round(value*multiplier);',
                '  const converted=Math.round(value*multiplier);\n  return converted<=maxWh?converted:null;')
    text = once(text, '      status:"active",idTagHash,',
                '      status:"active",idTagHash,lastMeterReceivedAt:new Date(),')
    text = once(text, 'meterLastWh:value,updatedAt:new Date(),...invoiceFor(updated)',
                'meterLastWh:value,updatedAt:new Date(),lastMeterReceivedAt:new Date(),...invoiceFor(updated)')
    text = once(text, 'data.meterStop<0)return {};', 'data.meterStop<0||data.meterStop>maxWh)return {};')
    text = once(text, '      status:"ended",stopReason:String(data.reason||"Unknown").slice(0,60),',
                '      status:"ended",lastMeterReceivedAt:new Date(),stopReason:String(data.reason||"Unknown").slice(0,60),')
    text = once(text, '        connectorId:data.evse?.connectorId??null,evseId:data.evse?.id??null,',
                '        connectorId:data.evse?.connectorId??null,evseId:data.evse?.id??null,\n        lastMeterReceivedAt:meter===null?null:new Date(),')
    text = once(text, '    const updated={...previous,lastSeqNo:data.seqNo,meterLastWh:safeValue,',
                '''    const received=value!==null && value===safeValue;
    const updated={...previous,lastSeqNo:data.seqNo,meterLastWh:safeValue,
      lastMeterReceivedAt:received?new Date():previous.lastMeterReceivedAt??null,''')
    text = once(text, '      lastSeqNo:updated.lastSeqNo,meterLastWh:updated.meterLastWh,',
                '      lastSeqNo:updated.lastSeqNo,meterLastWh:updated.meterLastWh,lastMeterReceivedAt:updated.lastMeterReceivedAt,')
    path.write_text(text)
    print('STAGED_ACCEPTED_METER_RECEIPTS; production unchanged')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Pass isolated scoped OCPP handler file')
    patch(Path(sys.argv[1]))
