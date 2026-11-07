#!/usr/bin/env python3
"""Install private read-only source history into an isolated Express copy."""
from pathlib import Path
import sys
def patch(root):
    root=root.resolve()
    if str(root)=="/home/hasio/ev-deployment" or str(root).startswith("/home/hasio/ev-deployment/"):
        raise RuntimeError("Refusing production source")
    customer=root/"src/routes/routes.js"
    admin=root/"src/routes/adminRoutes.js"
    target=root/"src/controllers/ocppHistory.js"
    session=root/"src/models/ChargingSessionModel.js"
    helper=root/"src/utils/ocppHistory.mjs"
    base=Path(__file__).resolve().parents[2]/"integrations/hasone-express"
    if target.exists() or helper.exists():
        raise RuntimeError("History already staged")
    c=customer.read_text()
    a=admin.read_text()
    session_text=session.read_text()
    model_marker='const ChargingSessionModel = mongoose.model("ChargingSession", ChargingSessionSchema);'
    if session_text.count(model_marker)!=1: raise RuntimeError("History session model anchor changed")
    session_text=session_text.replace(model_marker,'''ChargingSessionSchema.index({userId:1,sourceType:1,_id:-1},{name:"history_owner_source_cursor"});
ChargingSessionSchema.index({sourceType:1,_id:-1},{name:"history_source_cursor"});
'''+model_marker)
    marker="const router = express.Router();"
    if c.count(marker)!=1 or a.count(marker)!=1:
        raise RuntimeError("History router anchors changed")
    c=c.replace(marker,'''import {CustomerOcppHistory,CustomerOcppHistoryDetail} from "../controllers/ocppHistory.js";
'''+marker+'''
router.get("/ChargingSession/history", AuthVerification, CustomerOcppHistory);
router.get("/ChargingSession/history/:id", AuthVerification, CustomerOcppHistoryDetail);
''')
    a=a.replace(marker,'''import {AdminOcppHistory,AdminOcppHistoryDetail} from "../controllers/ocppHistory.js";
'''+marker+'''
router.get("/admin/ocpp/charging-history", AdminAuth, AdminOcppHistory);
router.get("/admin/ocpp/charging-history/:id", AdminAuth, AdminOcppHistoryDetail);
''')
    source=(base/"ocppHistoryController.js").read_text()
    shared=(base/"ocppHistory.mjs").read_text()
    customer.write_text(c)
    admin.write_text(a)
    target.write_text(source)
    helper.write_text(shared)
    session.write_text(session_text)
    print("STAGED_SOURCE_CHARGING_HISTORY; production unchanged")
if __name__=="__main__":
    if len(sys.argv)!=2: raise SystemExit("Pass isolated source root")
    patch(Path(sys.argv[1]))
