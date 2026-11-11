"use client";
import {useCallback,useEffect,useRef,useState} from "react";
import api from "@/lib/api";
import {useTheme} from "@/lib/ThemeContext";
import {validOcppHistoryPage,validOcppHistoryRow} from "./ocppHistoryEvidence.mjs";

type Row={
 sessionId:string;bookingId:string;stationName:string;chargerId:string|null;
 chargerEnabled:boolean;protocol:string|null;state:"active"|"ended"|"unverified";
 sourceVerified:boolean;meterVerified:boolean;reviewReason:string|null;
 bookingPrepaymentRecorded:boolean;startedAt:string|null;endedAt:string|null;
 durationSeconds:number|null;energyKwh:number|null;meterStartWh:number|null;
 meterLastWh:number|null;calculatedUnpaidAed:number|null;billingStatus:string;
 paymentStatus:string;transactionId:string|null;readOnly:boolean;
};
type Page={entries:Row[];scanned:number;unavailableCount:number;hasMore:boolean;
 nextCursor:string|null;currency:string;billingMode:string;readOnly:boolean};
const id=(s:string)=>/^[a-f0-9]{24}$/i.test(s);
const adminIdentity=()=>{try{return window.localStorage.getItem("admin_token")||"";}catch{return "";}};
export default function OcppHistoryPage(){
 const {theme}=useTheme();
 const dark=theme==="dark";
 const [arabic,setArabic]=useState(false);
 const t=(en:string,ar:string)=>arabic?ar:en;
 const [page,setPage]=useState<Page|null>(null);
 const [cursors,setCursors]=useState<Array<string|null>>([null]);
 const [index,setIndex]=useState(0);
 const [loading,setLoading]=useState(false);
 const [error,setError]=useState("");
 const [detail,setDetail]=useState<Row|null>(null);
 const [detailLoading,setDetailLoading]=useState(false);
 const version=useRef(0),detailVersion=useRef(0);
 const owner=useRef("");
 const [reload,setReload]=useState(0);
 const clearPrivate=useCallback(()=>{
   version.current++;detailVersion.current++;setPage(null);setDetail(null);
   setLoading(false);setDetailLoading(false);setError("");
 },[]);
 const identityChanged=useCallback(()=>{
   const current=adminIdentity();
   if(current===owner.current)return;
   owner.current=current;clearPrivate();setCursors([null]);setIndex(0);setReload(v=>v+1);
 },[clearPrivate]);
 useEffect(()=>{
   owner.current=adminIdentity();
   window.addEventListener("storage",identityChanged);window.addEventListener("focus",identityChanged);
   return ()=>{window.removeEventListener("storage",identityChanged);window.removeEventListener("focus",identityChanged);};
 },[identityChanged]);
 useEffect(()=>{
   const request=++version.current;
   const identity=adminIdentity();owner.current=identity;
   const accept=()=>{
     if(identity!==adminIdentity()){identityChanged();return false;}
     return !!identity&&request===version.current;
   };
   setLoading(true);setError("");setPage(null);setDetail(null);setDetailLoading(false);detailVersion.current++;
   if(!identity){setLoading(false);return;}
   const cursor=cursors[index];
   api.get("/admin/ocpp/charging-history",{params:{limit:25,...(cursor?{cursor}:{})}})
     .then(response=>{
       const data=response.data?.data as Page;
       if(!validOcppHistoryPage(response.data,{cursor,limit:25}))
         throw Error("Invalid history response");
       if(accept())setPage(data);
     }).catch(()=>{
       if(accept())setError("history");
     }).finally(()=>{if(accept())setLoading(false);});
   return ()=>{version.current++;};
 },[cursors,index,reload,identityChanged]);
 useEffect(()=>()=>{detailVersion.current++;},[]);
 async function inspect(selected:Row){
   const sessionId=selected.sessionId;
   if(!id(sessionId))return;
   const identity=adminIdentity(),listVersion=version.current;
   if(!identity||identity!==owner.current){identityChanged();return;}
   const request=++detailVersion.current;
   const accept=()=>{
     if(identity!==adminIdentity()){identityChanged();return false;}
     return request===detailVersion.current&&listVersion===version.current;
   };
   setDetail(null);setDetailLoading(true);setError("");
   try{
     const response=await api.get("/admin/ocpp/charging-history/"+sessionId);
     const row=response.data?.data as Row;
     if(response.data?.status!=="Success" ||
        !validOcppHistoryRow(row,{sessionId,bookingId:selected.bookingId}))throw Error("Invalid source detail");
     if(accept())setDetail(row);
   }catch{if(accept())setError("detail");}
   finally{if(accept())setDetailLoading(false);}
 }
 const shell=dark?"border-slate-700 bg-[#1E2A3A]":"border-slate-200 bg-white";
 const number=(value:number|null,suffix="")=>value===null?"—":
   new Intl.NumberFormat(arabic?"ar-AE":"en-AE",{maximumFractionDigits:3}).format(value)+suffix;
 const state=(row:Row)=>row.state==="ended"?t("Ended","منتهية"):
   row.state==="active"?t("Active source","مصدر نشط"):t("Needs review","تحتاج مراجعة");
 return <div dir={arabic?"rtl":"ltr"} className="min-w-0 space-y-5">
   <div className={"sticky top-20 lg:top-2 z-20 rounded-xl border p-3 flex flex-wrap items-center justify-between gap-3 "+shell}>
     <h1 className="text-2xl font-bold">{t("Charging source history","سجل مصادر الشحن")}</h1>
     <div className="flex gap-2">
       <button className="rounded border px-3 py-2" onClick={()=>setArabic(v=>!v)}
         aria-label="Change history language">{arabic?"English":"العربية"}</button>
       <button className="rounded border px-3 py-2" disabled={loading}
         onClick={()=>{clearPrivate();setCursors([null]);setIndex(0);setReload(v=>v+1);}}>
         {t("Refresh history","تحديث السجل")}</button>
     </div>
   </div>
   <p className="rounded border border-amber-400 p-3 text-sm">
     {t("Source history is read-only. Calculated energy charges remain unpaid. A recorded booking prepayment is separate from metered charging settlement.",
       "هذا السجل للقراءة فقط. رسوم الطاقة المحسوبة غير محصلة. دفعة الحجز المسجلة منفصلة عن تسوية رسوم الشحن.")}
   </p>
   {error&&<p role="alert" className="rounded border border-red-400 p-3 text-sm">
     {t("Unable to load source history. Retry the page or inspect the record again.",
       "تعذر تحميل سجل المصدر. أعد تحميل الصفحة أو فحص السجل.")}</p>}
   {loading&&<p role="status">{t("Loading source history…","جارٍ تحميل سجل المصدر…")}</p>}
   {page&&<div className={"max-w-full rounded-xl border overflow-x-auto "+shell}>
     <table className="w-full min-w-[850px] text-sm"><thead>
       <tr>{[t("Station / booking","المحطة / الحجز"),t("Charger","الشاحن"),
         t("Source state","حالة المصدر"),t("Energy","الطاقة"),t("Calculated unpaid","المبلغ غير المحصل"),
         t("Inspect","الفحص")].map(label=><th key={label} className="p-3 text-start">{label}</th>)}</tr>
     </thead><tbody>
       {page.entries.map(row=><tr key={row.sessionId} data-booking-id={row.bookingId}
         className="border-t">
         <td className="p-3">{row.stationName||"—"}<div className="text-xs font-mono">{row.bookingId}</div></td>
         <td className="p-3">{row.chargerId||"—"}<div className="text-xs">
           {row.chargerEnabled?t("Enabled now","مفعّل الآن"):t("Disabled or unavailable now","معطّل أو غير متاح الآن")}</div></td>
         <td className="p-3">{state(row)}</td>
         <td className="p-3">{number(row.energyKwh," kWh")}</td>
         <td className="p-3">{number(row.calculatedUnpaidAed," AED")}</td>
         <td className="p-3"><button aria-label={"Inspect history "+row.sessionId}
           className="rounded border px-3 py-2" disabled={loading||detailLoading}
           onClick={()=>void inspect(row)}>{t("Inspect evidence","فحص الأدلة")}</button></td>
       </tr>)}
     </tbody></table>
     {page.entries.length===0&&!loading&&<p className="p-5">{t("No history records on this page.","لا توجد سجلات في هذه الصفحة.")}</p>}
     <p className="px-3 py-2 text-xs">{t("Records checked on this page: ","السجلات المفحوصة في الصفحة: ")}
       {page.scanned}{page.unavailableCount>0&&t(" · Some links are unavailable for review."," · بعض الروابط غير متاحة للمراجعة.")}</p>
   </div>}
   <div className="flex items-center gap-3">
     <button className="rounded border px-3 py-2" disabled={loading||index===0}
       onClick={()=>setIndex(v=>v-1)}>{t("Previous page","الصفحة السابقة")}</button>
     <span>{t("Page ","صفحة ")}{index+1}</span>
     <button className="rounded border px-3 py-2" disabled={loading||!page?.hasMore}
       onClick={()=>{if(page?.nextCursor){setCursors(old=>[...old.slice(0,index+1),page.nextCursor]);setIndex(v=>v+1);}}}>
       {t("Next page","الصفحة التالية")}</button>
     {error&&<button className="rounded border px-3 py-2" onClick={()=>setReload(v=>v+1)}>
       {t("Retry page","إعادة المحاولة")}</button>}
   </div>
   {detailLoading&&<p role="status">{t("Inspecting source…","جارٍ فحص المصدر…")}</p>}
   {detail&&<section aria-label="Source evidence details" className={"rounded-xl border p-4 space-y-3 "+shell}>
     <div className="flex justify-between gap-3"><h2 className="font-bold">{t("Source evidence","أدلة المصدر")}</h2>
       <button aria-label="Close source evidence" onClick={()=>{detailVersion.current++;setDetail(null);setDetailLoading(false);}}>
         {t("Close","إغلاق")}</button></div>
     <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
       {[
         [t("State","الحالة"),state(detail)],
         [t("Protocol / transaction","البروتوكول / المعاملة"),(detail.protocol||"—")+" / "+(detail.transactionId||"—")],
         [t("Started","البداية"),detail.startedAt||"—"],[t("Ended","النهاية"),detail.endedAt||"—"],
         [t("Duration in seconds","المدة بالثواني"),number(detail.durationSeconds)],
         [t("Meter start / last (Wh)","قراءة العداد الأولى / الأخيرة"),number(detail.meterStartWh)+" / "+number(detail.meterLastWh)],
         [t("Verified energy","الطاقة المتحقق منها"),number(detail.energyKwh," kWh")],
         [t("Calculated unpaid","المبلغ غير المحصل"),number(detail.calculatedUnpaidAed," AED")],
         [t("Payment","الدفع"),t("Not collected","غير محصل")],
         [t("Booking prepayment recorded","دفعة الحجز المسجلة"),detail.bookingPrepaymentRecorded?t("Yes","نعم"):t("No","لا")],
         [t("Evidence review","مراجعة الأدلة"),detail.reviewReason?t("Verification required; inspect source records.","يلزم التحقق من سجلات المصدر."):t("Source and meter verified","تم التحقق من المصدر والعداد")]
       ].map(([label,value])=><div key={label}><dt className="opacity-70">{label}</dt><dd className="break-all font-semibold">{value}</dd></div>)}
     </dl>
   </section>}
 </div>;
}
