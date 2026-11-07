"use client";

import {useCallback,useEffect,useRef,useState} from "react";
import api from "@/lib/api";
import {useTheme} from "@/lib/ThemeContext";
import {CheckCircle2,ClipboardCopy,Eye,EyeOff,Link2,LockKeyhole,RefreshCw,X,Zap} from "lucide-react";

type BookingRef={
  _id:string;stationName?:string;stationId?:string|{_id:string};
  status:string;isPaid:boolean;hasArrived?:boolean;
};

type OpState={
  bookingId:string;stationId:string;bookingStatus:string;
  bookingPrepaymentRecorded:boolean;arrivalRecorded:boolean;
  approvedChargerId:string|null;selectedChargerId:string|null;
  approvedChargers:Array<{chargerId:string;protocol:string;online:boolean}>;
  requiresChargerSelection:boolean;authorizationIssued:boolean;
  tokenIssuedAt:string|null;tokenExpiresAt:string|null;
  sourceSessionId:string|null;sourceTransactionId:string|null;
  sourceVerified:boolean;meterVerified:boolean;sourceEnded:boolean;
  energyKwh:number|null;calculatedUnpaidAed:number|null;
  paymentStatus:string|null;canIssueToken:boolean;canReissueToken:boolean;
  canBindSource:boolean;canFinalizeSource:boolean;
  needsPaymentReconciliation:boolean;
};
type StartExceptionReport={
  scanned:number;hasMore:boolean;nextCursor:string|null;
  orphanCount:number;matchedSourceCount:number;withinGracePeriodCount:number;
  readOnly:boolean;exceptions:Array<{
    chargerId:string;bookingId:string|null;consumedAt:string;
    issue:string;action:string;credentialRestored:boolean
  }>;
};
type SourceSession={
  id:string;chargerId:string;protocol:string;transactionId:string|number;
  status:string;energyKwh:number|null;paymentStatus:string;
};
type IssueResponse={status:string;data?:{
  oneTimeToken:string;chargerId:string;expiresAt:string;
}};
const isHex24=(value:string)=>/^[a-f0-9]{24}$/i.test(value);
const statusText=(value:boolean,positive:string,negative:string)=>value?positive:negative;

function errorText(error:unknown){
  const reason=error as {response?:{data?:{message?:string}}};
  return reason?.response?.data?.message||"Request could not be completed. Refresh and check authorization.";
}

export default function OcppBookingWorkflow({
  booking,onUpdated
}:{
  booking:BookingRef;onUpdated:()=>void|Promise<void>
}){
  const {theme}=useTheme();
  const dark=theme==="dark";
  const [open,setOpen]=useState(false);
  const [loading,setLoading]=useState(false);
  const [working,setWorking]=useState(false);
  const [error,setError]=useState("");
  const [message,setMessage]=useState("");
  const [state,setState]=useState<OpState|null>(null);
  const [sessions,setSessions]=useState<SourceSession[]>([]);
  const [chosen,setChosen]=useState("");
  const [chargerChoice,setChargerChoice]=useState("");
  const [refreshOnClose,setRefreshOnClose]=useState(false);
  const [startAudit,setStartAudit]=useState<StartExceptionReport|null>(null);
  const [auditLoading,setAuditLoading]=useState(false);
  const auditVersion=useRef(0);
  const [secret,setSecret]=useState("");
  const [showSecret,setShowSecret]=useState(false);

  const read=useCallback(async()=>{
    if(!isHex24(booking._id))throw new Error("Invalid booking ID");
    const response=await api.get("/admin/bookings/"+booking._id+"/ocpp/operations");
    if(response.data?.status!=="Success" || !response.data?.data)
      throw new Error("Invalid Admin booking state");
    const current=response.data.data as OpState;
    setState(current);
    const available=Array.isArray(current.approvedChargers)?current.approvedChargers:[];
    setChargerChoice(previous=>current.selectedChargerId||
      (available.some(c=>c.chargerId===previous)?previous:
       available.length===1?available[0].chargerId:""));
    if(current.stationId && isHex24(current.stationId)){
      const sessionsResponse=await api.get(
        "/admin/stations/"+current.stationId+"/ocpp/booking-sources");
      const list=sessionsResponse.data?.data?.sessions;
      const loaded=Array.isArray(list)?list as SourceSession[]:[];
      setSessions(loaded);
      setChosen(previous=>loaded.some(x=>x.id===previous)?previous:
        current.sourceSessionId||"");
    }else{
      setSessions([]);
      setChosen("");
    }
  },[booking._id]);

  useEffect(()=>{
    if(!open)return;
    let active=true;
    setLoading(true);
    setError("");
    // No charger control, token issuance or money operation occurs on mount.
    read().catch(e=>{if(active)setError(errorText(e));})
      .finally(()=>{if(active)setLoading(false);});
    return ()=>{active=false;};
  },[open,read]);

  function close(){
    if(working)return;
    setOpen(false);
    setSecret("");
    setShowSecret(false);
    setChosen("");
    setChargerChoice("");
    setMessage("");
    setError("");
    setState(null);
    setStartAudit(null);
    auditVersion.current++;
    setAuditLoading(false);
    if(refreshOnClose)void onUpdated();
    setRefreshOnClose(false);
  }

  useEffect(()=>{
    if(!open)return;
    const visibility=()=>{
      if(document.visibilityState==="hidden"){
        setSecret("");
        setShowSecret(false);
      }
    };
    const esc=(event:KeyboardEvent)=>{if(event.key==="Escape" && !working)close();};
    document.addEventListener("visibilitychange",visibility);
    window.addEventListener("keydown",esc);
    return ()=>{
      document.removeEventListener("visibilitychange",visibility);
      window.removeEventListener("keydown",esc);
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  },[open,working]);

  async function perform(
    path:"issue-token"|"reissue-token"|"bind-source"|"finalize-source",
    body:Record<string,string>={}
  ){
    if(working || !state)return;
    setWorking(true);
    setError("");
    setMessage("");
    try{
      const payload=(path==="issue-token"||path==="reissue-token")
        ?{...body,chargerId:chargerChoice}:body;
      const response=await api.post("/admin/bookings/"+booking._id+"/ocpp/"+path,payload);
      if(response.data?.status!=="Success")throw new Error("Operation was not accepted");
      if(path==="issue-token"||path==="reissue-token"){
        const result=response.data as IssueResponse;
        if(typeof result.data?.oneTimeToken!=="string" ||
          !/^[a-f0-9]{48}$/.test(result.data.oneTimeToken)){
          throw new Error("Authorization was accepted, but token delivery was incomplete. Contact your administrator.");
        }
        setSecret(result.data.oneTimeToken);
        setShowSecret(false);
        setMessage(path==="reissue-token"
          ?"Expired unused token replaced. Copy the new credential securely now."
          :"A one-time token was issued. Copy it securely now; it will not be shown after closing.");
      }else if(path==="bind-source"){
        setMessage("Verified OCPP transaction linked to this booking.");
      }else{
        setMessage("Booking finalized from verified OCPP stop. Charging charge remains unpaid.");
      }
      await read();
      // Reloading the bookings table here would unmount this dialog and
      // lose the one-time authorization token before Admin can copy it.
      if(path==="finalize-source")setRefreshOnClose(true);
    }catch(e){setError(errorText(e));}
    finally{setWorking(false);}
  }

  async function auditStartExceptions(append=false){
    if(!state || !isHex24(state.stationId) || auditLoading || working)return;
    const cursor=append?startAudit?.nextCursor:null;
    if(append && (!cursor || !isHex24(cursor)))return;
    const version=++auditVersion.current;
    setAuditLoading(true);
    setError("");
    try{
      const response=await api.get(
        "/admin/stations/"+state.stationId+"/ocpp/start-exceptions"+
        (cursor?"?cursor="+encodeURIComponent(cursor):""));
      const report=response.data?.data as StartExceptionReport|undefined;
      if(response.data?.status!=="Success" || !report ||
         report.readOnly!==true || !Array.isArray(report.exceptions) ||
         !Number.isInteger(report.scanned) || report.scanned<0 ||
         typeof report.hasMore!=="boolean" ||
         (report.hasMore && (!report.nextCursor || !isHex24(report.nextCursor) ||
           report.nextCursor===cursor))){
        throw new Error("Invalid OCPP start exception response");
      }
      if(version!==auditVersion.current)return;
      setStartAudit(previous=>append && previous?{
        ...report,
        scanned:previous.scanned+report.scanned,
        orphanCount:previous.orphanCount+report.orphanCount,
        matchedSourceCount:previous.matchedSourceCount+report.matchedSourceCount,
        withinGracePeriodCount:previous.withinGracePeriodCount+report.withinGracePeriodCount,
        exceptions:[...previous.exceptions,...report.exceptions]
      }:report);
    }catch(error){if(version===auditVersion.current)setError(errorText(error));}
    finally{if(version===auditVersion.current)setAuditLoading(false);}
  }

  async function copySecret(){
    if(!secret)return;
    try{
      await navigator.clipboard.writeText(secret);
      setMessage("Token copied. Use only with the intended authorized charger.");
    }catch{
      setError("Clipboard access was blocked. Reveal and copy the token manually.");
      setShowSecret(true);
    }
  }

  const surface=dark?"border-slate-700 bg-[#1E2A3A]":"border-slate-200 bg-white";
  const muted=dark?"text-slate-400":"text-slate-500";
  const inputClass="w-full rounded-lg border p-2.5 text-sm "+(dark?
    "bg-slate-900 border-slate-600 text-white":"bg-white border-slate-300");
  const canOperate=state && !loading && !working;

  return (
    <>
      <button
        type="button" onClick={()=>setOpen(true)}
        className="inline-flex items-center gap-1.5 rounded-lg border border-sky-300 px-3 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-slate-700"
        aria-label={"OCPP operations for booking "+booking._id}>
        <Zap size={14}/> OCPP
      </button>
      {open&&(
        <div className="fixed inset-0 z-[90] flex items-center justify-center bg-black/65 p-3 sm:p-6"
             role="dialog" aria-modal="true" aria-label="Booking OCPP operations">
          <div className={"w-full max-w-2xl max-h-[92vh] overflow-y-auto rounded-2xl border shadow-xl "+surface}>
            <div className={"sticky top-0 z-10 flex items-center justify-between gap-4 border-b p-4 "+surface}>
              <div className="min-w-0">
                <h2 className="text-lg font-bold">Booking OCPP Operations</h2>
                <p className={"text-xs mt-1 truncate "+muted} title={booking._id}>
                  {booking.stationName||"Charging station"} · {booking._id}
                </p>
              </div>
              <div className="flex gap-2">
                <button aria-label="Refresh OCPP status" disabled={loading||working}
                  onClick={()=>{setLoading(true);read().catch(e=>setError(errorText(e))).finally(()=>setLoading(false));}}
                  className="rounded-lg border p-2 disabled:opacity-40"><RefreshCw size={16}/></button>
                <button aria-label="Close OCPP operations" disabled={working}
                  onClick={close} className="rounded-lg border p-2 disabled:opacity-40"><X size={16}/></button>
              </div>
            </div>

            <div className="p-4 sm:p-5 space-y-5">
              <div className={"rounded-lg border p-3 text-xs leading-relaxed "+(
                dark?"bg-amber-950/20 border-amber-600/40 text-amber-200":
                "bg-amber-50 border-amber-200 text-amber-900")}>
                This workflow issues authorization tokens and links verified source records. It does not remotely start or stop chargers. Metered energy charges remain <strong>not collected</strong> until separately reconciled.
              </div>
              {error&&<p className="rounded-lg border border-red-300 p-3 text-sm text-red-600" role="alert">{error}</p>}
              {message&&<p className="rounded-lg border border-sky-300 p-3 text-sm text-sky-600" role="status">{message}</p>}
              {loading&&!state&&<p className={muted}>Reading authorized booking and charger status…</p>}
              {state&&(
                <>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    {[
                      ["Booking",state.bookingStatus],
                      ["Booking paid",statusText(state.bookingPrepaymentRecorded,"Recorded","Not recorded")],
                      ["Arrival",statusText(state.arrivalRecorded,"Confirmed","Pending")],
                      ["OCPP source",statusText(state.sourceVerified,"Verified","Not verified")]
                    ].map(([label,value])=>
                      <div key={label} className={"border rounded-lg p-3 "+surface}>
                        <div className={"text-xs "+muted}>{label}</div>
                        <div className="text-sm font-semibold mt-1">{value}</div>
                      </div>)}
                  </div>

                  <section className={"space-y-3 rounded-xl border p-4 "+surface}>
                    <h3 className="font-bold flex items-center gap-2"><LockKeyhole size={16}/> 1. Authorize charger token</h3>
                    <p className={"text-xs "+muted}>
                      Approved charger: <strong>{state.approvedChargerId||
                        (state.approvedChargers.length>1?"Select below":"No approved charger")}</strong>.
                      A paid, arrived Upcoming booking and approved charger are required.
                    </p>
                    {state.approvedChargers.length>0&&(
                      <label className="block text-xs font-semibold">Booking charger
                        <select aria-label="Select booking charger" className={inputClass+" mt-1"}
                          value={chargerChoice} onChange={e=>setChargerChoice(e.target.value)}
                          disabled={state.authorizationIssued||working}>
                          <option value="">Choose approved charger</option>
                          {state.approvedChargers.map(c=>
                            <option key={c.chargerId} value={c.chargerId}>
                              {c.chargerId} · {c.protocol} · {c.online?"Online":"Offline"}
                            </option>)}
                        </select>
                      </label>
                    )}
                    {state.authorizationIssued&&
                      <p className="text-sm text-sky-600">Token already issued.
                        {state.tokenExpiresAt?" Expires "+new Date(state.tokenExpiresAt).toLocaleString():""}
                      </p>}
                    <button type="button"
                      disabled={!canOperate||!state.canIssueToken||!chargerChoice}
                      onClick={()=>void perform("issue-token")}
                      className="rounded-lg bg-sky-600 px-4 py-2.5 font-semibold text-white text-sm disabled:opacity-40">
                      {working?"Working…":"Issue one-time authorization token"}
                    </button>
                    {state.authorizationIssued&&(
                      <div className="space-y-2">
                        <button type="button" disabled={!canOperate||!state.canReissueToken}
                          onClick={()=>void perform("reissue-token")}
                          className="rounded-lg border border-sky-400 px-4 py-2.5 font-semibold text-sky-600 text-sm disabled:opacity-40">
                          Reissue expired unused token
                        </button>
                        <p className={"text-xs "+muted}>
                          Renewal is allowed only after expiry and a safety grace period,
                          with no consumed credential or OCPP charging transaction.
                        </p>
                      </div>
                    )}
                    {secret&&(
                      <div className="space-y-2 rounded-lg border border-amber-300 p-3">
                        <p className="text-xs text-amber-600 font-bold">Sensitive one-time token — not saved in browser storage</p>
                        <div className="flex gap-2 items-center">
                          <code className={"flex-1 min-w-0 break-all text-xs "+(dark?"text-white":"text-slate-800")}>
                            {showSecret?secret:"••••••••••••••••••••••••"}
                          </code>
                          <button aria-label={showSecret?"Hide token":"Reveal token"} onClick={()=>setShowSecret(v=>!v)}
                            className="rounded-lg border p-2">{showSecret?<EyeOff size={17}/>:<Eye size={17}/>}</button>
                          <button aria-label="Copy one-time token" onClick={()=>void copySecret()}
                            className="rounded-lg border p-2"><ClipboardCopy size={17}/></button>
                        </div>
                      </div>
                    )}
                  </section>

                  <section className={"space-y-3 rounded-xl border p-4 "+surface}>
                    <h3 className="font-bold flex items-center gap-2"><Link2 size={16}/> 2. Bind verified OCPP transaction</h3>
                    <p className={"text-xs "+muted}>Select an OCPP source from the approved booking station.
                      The backend verifies charger, booking token and meter evidence before binding.</p>
                    <label className="block text-xs font-semibold">Source session
                      <select aria-label="Select source transaction" className={inputClass+" mt-1"} value={chosen}
                        onChange={e=>setChosen(e.target.value)} disabled={!!state.sourceSessionId||working}>
                        <option value="">Select recorded OCPP transaction</option>
                        {sessions.filter(x=>isHex24(x.id)&&
                          x.chargerId===(state.selectedChargerId||chargerChoice)).map(x=>(
                          <option key={x.id} value={x.id}>
                            {x.chargerId} · {x.protocol} · {String(x.transactionId)} · {x.status} · {x.energyKwh??"?"} kWh
                          </option>
                        ))}
                      </select>
                    </label>
                    {state.sourceSessionId&&
                      <p className="text-xs text-sky-600 break-all">
                        Linked source: {state.sourceSessionId}
                      </p>}
                    <button type="button"
                      disabled={!canOperate||!state.canBindSource||!isHex24(chosen)}
                      onClick={()=>void perform("bind-source",{sourceSessionId:chosen})}
                      className="rounded-lg bg-sky-600 px-4 py-2.5 font-semibold text-white text-sm disabled:opacity-40">
                      Bind verified source
                    </button>
                  </section>

                  <section className={"space-y-3 rounded-xl border p-4 "+surface}>
                    <h3 className="font-bold flex items-center gap-2"><RefreshCw size={16}/> Charging start diagnostics</h3>
                    <p className={"text-xs "+muted}>
                      Check for consumed charger tokens with no matching OCPP transaction.
                      This is read-only. Credentials will never be restored automatically.
                    </p>
                    <button type="button" disabled={working||auditLoading}
                      onClick={()=>void auditStartExceptions()}
                      className="rounded-lg border border-sky-300 px-4 py-2 text-sm font-semibold text-sky-700 disabled:opacity-40">
                      {auditLoading?"Checking…":"Check interrupted starts"}
                    </button>
                    {startAudit&&(
                      <div className="space-y-2" role="status">
                        <p className="text-xs">
                          Records checked: <strong>{startAudit.scanned}</strong>.
                          Transactions matched: <strong>{startAudit.matchedSourceCount}</strong>.
                          Grace period: <strong>{startAudit.withinGracePeriodCount}</strong>.
                          Investigation required: <strong>{startAudit.orphanCount}</strong>.
                        </p>
                        {startAudit.hasMore?(
                          <button type="button" disabled={working||auditLoading}
                            onClick={()=>void auditStartExceptions(true)}
                            className="rounded-lg border px-3 py-2 text-xs font-semibold disabled:opacity-40">
                            {auditLoading?"Loading…":"Load earlier records"}
                          </button>
                        ):<p className={"text-xs "+muted}>End of available records. Refresh to check for new activity.</p>}
                        {startAudit.exceptions
                          .filter(x=>x.chargerId===state.selectedChargerId||!state.selectedChargerId)
                          .map((x,i)=>(
                            <div key={x.chargerId+"-"+x.consumedAt+"-"+i}
                              className="rounded-lg border border-amber-300 p-3 text-xs">
                              <strong>{x.chargerId}</strong> — consumed {new Date(x.consumedAt).toLocaleString()}.
                              <p className="mt-1">{x.action}</p>
                            </div>
                          ))}
                      </div>
                    )}
                  </section>

                  <section className={"space-y-3 rounded-xl border p-4 "+surface}>
                    <h3 className="font-bold flex items-center gap-2"><CheckCircle2 size={16}/> 3. Finalize confirmed charging</h3>
                    <div className="grid grid-cols-2 gap-3 text-xs">
                      <div>Meter: <strong>{state.meterVerified?"Verified":"Pending"}</strong></div>
                      <div>OCPP stop: <strong>{state.sourceEnded?"Confirmed":"Pending"}</strong></div>
                      <div>Energy: <strong>{state.energyKwh===null?"—":state.energyKwh+" kWh"}</strong></div>
                      <div>Calculated unpaid: <strong>{state.calculatedUnpaidAed===null?"—":"AED "+state.calculatedUnpaidAed.toFixed(2)}</strong></div>
                    </div>
                    <button type="button" disabled={!canOperate||!state.canFinalizeSource}
                      onClick={()=>void perform("finalize-source")}
                      className="rounded-lg bg-sky-600 px-4 py-2.5 font-semibold text-white text-sm disabled:opacity-40">
                      Finalize verified booking
                    </button>
                    {state.needsPaymentReconciliation&&
                      <p className="text-xs text-amber-600">Metered amount is calculated but remains unpaid. A separate approved settlement is required.</p>}
                  </section>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
