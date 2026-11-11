"""Synthetic source-contract tests for the isolated connector receipt patcher.

These do not replace the proprietary Express/Mongo/OCPP integration acceptance.
"""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "agent2_connector_receipts", ROOT / "tools/agent2/stage_ocpp_connector_receipts.py"
)
patcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)

# Minimal old-source anchors and the observed StatusNotification branch.
# Authentication/session creation are deliberately outside this fixture's scope.
SOURCE = '''const idTagHash="synthetic";
export const sample16=()=>({status:"active",idTagHash,lastMeterReceivedAt:new Date(),});
export const sample201=meter=>({lastMeterReceivedAt:meter===null?null:new Date(),});
export async function report(db,id,protocol,payload){
 const action="StatusNotification",date=value=>new Date(value);
    if(action==="StatusNotification"){
      const connector=protocol==="ocpp1.6"?payload.connectorId:payload.connectorId;
      const evse=protocol==="ocpp1.6"?0:payload.evseId;
      const status=protocol==="ocpp1.6"?payload.status:payload.connectorStatus;
      const allowed=protocol==="ocpp1.6"?
        ["Available","Preparing","Charging","SuspendedEV","SuspendedEVSE","Finishing","Reserved","Unavailable","Faulted"]:
        ["Available","Occupied","Reserved","Unavailable","Faulted"];
      if(!Number.isInteger(connector)||connector<0||connector>64||
        !Number.isInteger(evse)||evse<0||evse>64 ||!allowed.includes(status))
        throw new Error("Invalid connector status");
      await db.collection("ocpp_chargers").updateOne({chargerId:id},{$set:{
        ["connectors."+evse+"_"+connector]:{status,updatedAt:date(payload.timestamp)},
        lastSeenAt:new Date()
      }});
      return {};
    }
}
'''


class ConnectorReceiptPatchTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="agent2-receipt-contract-")
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / "gateway.mjs"
        self.path.write_text(SOURCE)

    def node(self, code):
        subprocess.run(
            ["node", "--input-type=module", "-e",
             "import assert from 'node:assert/strict';import * as gateway from "
             + repr(self.path.as_uri()) + ";" + code],
            check=True, capture_output=True, text=True,
        )

    def test_received_metadata_and_start_receipts_are_server_times(self):
        patcher.patch(self.path)
        self.node('''
let result;const db={collection:()=>({updateOne:async(_q,u)=>{result=u.$set;}})};
for(const protocol of ['ocpp1.6','ocpp2.0.1']){
 const before=Date.now();
 await gateway.report(db,'synthetic-charger',protocol,{connectorId:2,evseId:3,
  status:'Faulted',connectorStatus:'Faulted',timestamp:'2020-01-01T00:00:00Z'});
 const row=result['connectors.'+(protocol==='ocpp1.6'?'0_2':'3_2')];
 assert.equal(row.protocol,protocol);assert.equal(row.connectorId,2);
 assert.equal(row.evseId,protocol==='ocpp1.6'?null:3);
 assert.ok(row.receivedAt instanceof Date);assert.ok(row.receivedAt.valueOf()>=before);
 assert.ok(row.receivedAt.valueOf()<=Date.now());assert.equal(row.updatedAt.getUTCFullYear(),2020);
}
assert.ok(gateway.sample16().startedReceivedAt instanceof Date);
assert.ok(gateway.sample201(null).startedReceivedAt instanceof Date);
assert.equal(gateway.sample201(null).lastMeterReceivedAt,null);
''')

    def test_ocpp201_zero_scope_and_unknown_protocol_cannot_write(self):
        patcher.patch(self.path)
        self.node('''
let writes=0;const db={collection:()=>({updateOne:async()=>{writes++;}})};
for(const p of [{connectorId:0,evseId:1},{connectorId:1,evseId:0}])
 await assert.rejects(()=>gateway.report(db,'synthetic','ocpp2.0.1',{
  ...p,connectorStatus:'Faulted',timestamp:'2020-01-01T00:00:00Z'}));
await assert.rejects(()=>gateway.report(db,'synthetic','unsupported',{
 connectorId:1,evseId:1,connectorStatus:'Faulted',timestamp:'2020-01-01T00:00:00Z'}));
assert.equal(writes,0);
await gateway.report(db,'synthetic','ocpp1.6',{
 connectorId:0,status:'Faulted',timestamp:'2020-01-01T00:00:00Z'});
assert.equal(writes,1);
''')

    def test_repeat_refuses_without_changing_the_staged_file(self):
        patcher.patch(self.path)
        expected = self.path.read_bytes()
        with self.assertRaises(RuntimeError):
            patcher.patch(self.path)
        self.assertEqual(expected, self.path.read_bytes())

    def test_late_source_anchor_failure_is_atomic(self):
        changed = SOURCE.replace(
            "lastMeterReceivedAt:meter===null?null:new Date(),", "meterReceiptChanged:null,"
        )
        self.path.write_text(changed)
        with self.assertRaises(RuntimeError):
            patcher.patch(self.path)
        self.assertEqual(changed, self.path.read_text())

    def test_missing_accepted_meter_prerequisite_is_atomic(self):
        changed = SOURCE.replace("lastMeterReceivedAt", "legacyMeterTime")
        self.path.write_text(changed)
        with self.assertRaises(RuntimeError):
            patcher.patch(self.path)
        self.assertEqual(changed, self.path.read_text())

    def test_deployed_path_is_refused_before_any_read_or_write(self):
        with self.assertRaisesRegex(RuntimeError, "Refusing deployed"):
            patcher.patch(Path("/home/hasio/ev-deployment/ocpp-gateway-next/ocpp-handlers.mjs"))


if __name__ == "__main__":
    unittest.main()
