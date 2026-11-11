"use client";

import {useCallback,useEffect,useMemo,useState} from "react";
import {RefreshCw,ShieldCheck,Zap,ReceiptText,AlertCircle,ExternalLink,Search} from "lucide-react";
import Link from "next/link";
import api from "@/lib/api";
import {useTheme} from "@/lib/ThemeContext";

type OcppRow={
  bookingId:string;
  sourceSessionId:string;
  chargerId:string;
  protocol:"ocpp1.6"|"ocpp2.0.1"|string;
  meterVerified:boolean;
  sessionEnded:boolean;
  energyKwh:number|null;
  calculatedUnpaidAed:number|null;
  billingStatus:string;
  paymentStatus:string;
};
type Report={
  currency:"AED";
  billingMode:string;
  count:number;
  meterVerifiedCount:number;
  totalCalculatedUnpaidAed:number;
  totalCollectedAed:number;
  entries:OcppRow[];
};
const empty:Report={currency:"AED",billingMode:"calculation_only_no_collection",count:0,
  meterVerifiedCount:0,totalCalculatedUnpaidAed:0,totalCollectedAed:0,entries:[]};
const aed=(value:number|null|undefined)=>typeof value==="number" && Number.isFinite(value)
  ? "AED "+value.toFixed(2) : "—";
const compact=(value:string)=>value && value.length>17
  ? value.slice(0,8)+"…"+value.slice(-6):value||"—";

export default function OcppSessionsPage(){
  const {theme}=useTheme();
  const dark=theme==="dark";
  const [report,setReport]=useState<Report>(empty);
  const [loading,setLoading]=useState(true);
  const [error,setError]=useState("");
  const [filter,setFilter]=useState("");
  const [lastFetched,setLastFetched]=useState<string|null>(null);

  const refresh=useCallback(async()=>{
    setLoading(true);
    try{
      const response=await api.get("/admin/ocpp/booking-sessions",{params:{limit:100}});
      const payload=response.data?.data as Report|undefined;
      if(response.data?.status!=="Success" || !payload || !Array.isArray(payload.entries) ||
         payload.currency!=="AED" || payload.billingMode!=="calculation_only_no_collection"){
        throw new Error("Unexpected source-report response");
      }
      setReport(payload);
      setLastFetched(new Date().toLocaleString());
      setError("");
    }catch(err:unknown){
      const e=err as {response?:{data?:{message?:string}}};
      setError(e?.response?.data?.message||"Unable to load verified charging sessions. Try refreshing.");
    }finally{setLoading(false);}
  },[]);
  useEffect(()=>{void refresh();},[refresh]);

  const rows=useMemo(()=>{
    const term=filter.trim().toLowerCase();
    return report.entries.filter(s=>!term ||
      [s.bookingId,s.sourceSessionId,s.chargerId,s.protocol,s.billingStatus]
        .some(v=>(v||"").toLowerCase().includes(term)));
  },[report.entries,filter]);
  const totalKwh=report.entries.reduce((sum,x)=>sum+
    (typeof x.energyKwh==="number" && Number.isFinite(x.energyKwh)?x.energyKwh:0),0);
  const shell=dark?"border-slate-700 bg-[#1E2A3A]":"border-slate-200 bg-white";
  const muted=dark?"text-slate-400":"text-slate-500";
  const tableHead=dark?"bg-slate-900/50 text-slate-300":"bg-slate-50 text-slate-600";
  const tableLine=dark?"border-slate-700":"border-slate-100";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className={"text-xs font-bold uppercase tracking-wider "+(dark?"text-sky-400":"text-sky-700")}>Source-backed operations</div>
          <h1 className="text-2xl sm:text-3xl font-bold mt-1">OCPP Session Report</h1>
          <p className={"text-sm mt-2 max-w-3xl "+muted}>
            Source-verified charging from approved OCPP 1.6 and 2.0.1 transactions. These amounts are calculated, not collected.
          </p>
        </div>
        <button onClick={()=>void refresh()} disabled={loading}
          className="inline-flex items-center gap-2 rounded-lg bg-sky-600 hover:bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">
          <RefreshCw size={16} className={loading?"animate-spin":""}/>{loading?"Refreshing…":"Refresh report"}
        </button>
      </div>

      <div className={"flex items-start gap-3 rounded-xl border px-4 py-3 "+(dark?"bg-amber-950/20 border-amber-700/40 text-amber-200":"bg-amber-50 border-amber-200 text-amber-900")}>
        <AlertCircle size={19} className="shrink-0 mt-0.5"/>
        <div className="text-sm leading-relaxed"><strong>Financial status: calculation only.</strong> The OCPP gateway does not capture card payments. Do not treat estimated charging charges as collected revenue or vendor earnings. Booking prepayments and metered charges require finance reconciliation before collection.</div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        {[
          {label:"Source-linked sessions",value:String(report.count),Icon:ShieldCheck,detail:"Approved booking ↔ OCPP transaction"},
          {label:"Verified energy",value:totalKwh.toFixed(2)+" kWh",Icon:Zap,detail:report.meterVerifiedCount+" meter-verified sessions"},
          {label:"Calculated, unpaid",value:aed(report.totalCalculatedUnpaidAed),Icon:ReceiptText,detail:"Metering and tariff calculations"},
          {label:"Collected from OCPP",value:aed(report.totalCollectedAed),Icon:AlertCircle,detail:"No payment captures verified"},
        ].map(({label,value,Icon,detail})=>(
          <div key={label} className={"border rounded-xl p-4 shadow-sm "+shell}>
            <div className={"flex items-center justify-between "+muted}><span className="text-xs font-semibold">{label}</span><Icon size={18}/></div>
            <div className="font-bold text-xl sm:text-2xl mt-3 tabular-nums">{value}</div>
            <div className={"text-xs mt-2 "+muted}>{detail}</div>
          </div>
        ))}
      </div>

      <div className={"rounded-xl border overflow-hidden "+shell}>
        <div className="flex flex-wrap justify-between gap-4 p-4 sm:p-5">
          <div>
            <h2 className="font-bold text-lg">Verified linked transactions</h2>
            <p className={"text-xs mt-1 "+muted}>{lastFetched?"Last refreshed "+lastFetched:"Awaiting report"}
              {" · "}Up to 100 recent linked sessions
            </p>
          </div>
          <label className={"inline-flex items-center gap-2 rounded-lg border px-3 py-2 "+(dark?"border-slate-600":"border-slate-200")}>
            <Search size={15} className={muted}/>
            <input aria-label="Search sessions" placeholder="Search booking or charger"
              value={filter} onChange={e=>setFilter(e.target.value)}
              className={"bg-transparent outline-none text-sm w-52 max-w-full "+(dark?"placeholder:text-slate-500":"placeholder:text-slate-400")}/>
          </label>
        </div>
        {error?<div role="alert" className="m-4 rounded-lg border border-red-400/30 p-4 text-red-500 text-sm">{error}</div>:null}
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm">
            <thead className={tableHead}>
              <tr>{["Booking","Charger","Protocol","State","Energy","Calculated unpaid","Payment"].map(h=>
                <th key={h} className="text-left px-4 py-3 text-xs font-semibold">{h}</th>)}</tr>
            </thead>
            <tbody>
              {rows.map(s=>(
                <tr key={s.sourceSessionId} className={"border-t "+tableLine}>
                  <td className="px-4 py-3 font-mono text-xs" title={s.bookingId}>{compact(s.bookingId)}</td>
                  <td className="px-4 py-3 font-medium">{s.chargerId}</td>
                  <td className="px-4 py-3 text-xs">{s.protocol}</td>
                  <td className="px-4 py-3">
                    <span className={"inline-block rounded-full px-2 py-1 text-xs font-semibold "+(
                      s.sessionEnded?(dark?"bg-sky-900/50 text-sky-300":"bg-sky-100 text-sky-800"):
                      dark?"bg-amber-900/50 text-amber-300":"bg-amber-100 text-amber-800")}>
                      {s.sessionEnded?"Ended":"Active"}
                    </span>
                  </td>
                  <td className="px-4 py-3 tabular-nums">{s.meterVerified && s.energyKwh!==null?s.energyKwh.toFixed(2)+" kWh":"Unverified"}</td>
                  <td className="px-4 py-3 font-semibold tabular-nums">{aed(s.calculatedUnpaidAed)}</td>
                  <td className="px-4 py-3 text-amber-600 font-semibold text-xs">Not collected</td>
                </tr>
              ))}
            </tbody>
          </table>
          {!loading && !error && rows.length===0?(
            <div className={"p-10 text-center text-sm "+muted}>
              {filter?"No matching transactions.":"No source-linked OCPP sessions yet. An approved charger transaction must be bound to a paid and arrived booking."}
            </div>):null}
          {loading && !report.entries.length?<div className={"p-10 text-center text-sm "+muted}>Loading source sessions…</div>:null}
        </div>
      </div>

      <p className={"text-xs flex flex-wrap items-center gap-1 "+muted}>
        <ShieldCheck size={14}/> Authorization and OCPP source evidence are required.
        This view is read-only. <Link href="/bookings" className="text-sky-600 hover:underline inline-flex items-center gap-1 ml-1">View booking history <ExternalLink size={12}/></Link>
      </p>
    </div>
  );
}
