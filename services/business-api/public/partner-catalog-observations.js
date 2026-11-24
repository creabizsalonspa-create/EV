/* Stored catalog counts only; no provider fetch, booking, command or billing. */
window.EvPartnerCatalogObservations=(()=>{
 const viewers=new WeakMap();
 const text=(tag,en,ar=en)=>{const n=document.createElement(tag);n.dataset.en=en;n.dataset.ar=ar;n.textContent=document.documentElement.lang==='ar'?ar:en;return n;};
 const raw=(tag,value)=>{const n=document.createElement(tag);n.textContent=String(value);return n;};
 const ageLabels={within_1_hour:['Reported/received within1 hour','أُبلغ/استُلم خلال ساعة'],within_24_hours:['Over1 hour, within24 hours','أقدم من ساعة وحتى24 ساعة'],within_7_days:['Over24 hours, within7 days','أقدم من24 ساعة وحتى7 أيام'],older_than_7_days:['Over7 days','أقدم من7 أيام'],unknown:['Unknown timestamp','وقت غير معروف'],clock_ahead:['Clock ahead of snapshot','الساعة متقدمة على اللقطة']};
 const labels={stored_publish_true:['Stored publish flag: true','علامة النشر المخزنة: نعم'],stored_publish_false:['Stored publish flag: false','علامة النشر المخزنة: لا'],stored_publish_unknown:['Unknown publish flag','علامة النشر غير معروفة'],coordinates_usable:['Coordinates usable for a map (accuracy unverified)','إحداثيات قابلة للعرض (الدقة غير متحققة)'],coordinates_unknown_or_invalid:['Missing/invalid coordinates','إحداثيات مفقودة/غير صالحة'],connector_list_present:['Reported connector list present','قائمة موصلات مبلّغ عنها موجودة'],connector_list_empty:['Reported connector list empty','قائمة الموصلات المبلّغ عنها فارغة'],connector_list_unknown:['Reported connector list unknown','قائمة الموصلات المبلّغ عنها غير معروفة'],current_version_retired:['Current tariff version withdrawn','نسخة التعرفة الحالية مسحوبة']};
 const markets={AE:['UAE (partner-reported location)','الإمارات (موقع مبلّغ عنه من الشريك)'],US:['US (partner-reported location)','الولايات المتحدة (موقع مبلّغ عنه من الشريك)'],CA:['Canada (partner-reported location)','كندا (موقع مبلّغ عنه من الشريك)'],other_or_unknown:['Other or unknown reported location','موقع مبلّغ عنه في دولة أخرى أو غير معروف']};
 const count=n=>Number.isSafeInteger(n)&&n>=0&&n<=10000;
 function valid(d,id){
  if(d?.scope!=='stored_partner_catalog_observations'||d.provider?.id!==id||typeof d.provider.name!=='string'||typeof d.provider.authorized!=='boolean'||typeof d.as_of!=='string'||d.record_limit!==10000)return false;
  if(!['all','locations','tariffs'].includes(d.module_filter)||d.market_classification!=='PARTNER_REPORTED_LOCATION_COUNTRY_ONLY'||d.coverage_exhaustive!==false||!['receipt_refreshes_source_age','live_availability_verified','physical_charging_verified','remote_control','billing_authority'].every(k=>d[k]===false))return false;
  const expected=d.module_filter==='all'?['locations','tariffs']:[d.module_filter];if(!d.modules||Object.keys(d.modules).length!==expected.length)return false;
  if(expected.reduce((total,key)=>total+(count(d.modules[key]?.total)?d.modules[key].total:10001),0)>d.record_limit)return false;
  return expected.every(key=>{const m=d.modules[key];if(!m||!count(m.total))return false;
   if(!['reported_age','receipt_age'].every(kind=>m[kind]&&Object.keys(m[kind]).length===6&&Object.keys(ageLabels).every(k=>count(m[kind][k]))&&Object.values(m[kind]).reduce((n,v)=>n+v,0)===m.total))return false;
   const metrics=key==='locations'?Object.keys(labels).filter(k=>k!=='current_version_retired'):['current_version_retired'];if(!metrics.every(k=>count(m[k])&&m[k]<=m.total))return false;
   if(key==='locations'&&[['stored_publish_true','stored_publish_false','stored_publish_unknown'],['coordinates_usable','coordinates_unknown_or_invalid'],['connector_list_present','connector_list_empty','connector_list_unknown']].some(keys=>keys.reduce((n,k)=>n+m[k],0)!==m.total))return false;
   if(key==='locations'){
    const published=m.published_by_reported_market,usable=m.published_with_usable_coordinates_by_reported_market;
    const exactMarkets=map=>map&&Object.keys(map).length===4&&Object.keys(markets).every(k=>count(map[k]));
    if(!exactMarkets(published)||!exactMarkets(usable))return false;
    const sum=map=>Object.values(map).reduce((n,v)=>n+v,0);
    if(sum(published)!==m.stored_publish_true||sum(usable)>m.coordinates_usable||Object.keys(markets).some(k=>usable[k]>published[k]))return false;
   }
   return Object.keys(m).length===metrics.length+(key==='locations'?5:3);
  });
 }
 const qualityCount=n=>Number.isSafeInteger(n)&&n>=0&&n<=1000000;
 function validQuality(d,id){
  if(d?.scope!=='stored_partner_catalog_quality_history'||d.provider?.id!==id||typeof d.provider.name!=='string'||typeof d.provider.authorized!=='boolean'||d.period!=='hour_utc'||d.sample_limit!==30||d.retention_hours!==168||d.evidence!=='STORED_PARTNER_CATALOG_TIMESTAMPS_ONLY'||d.sample_completeness!=='STORED_SYNC_AND_OPTIONAL_PERIODIC_SNAPSHOTS'||!['source_covers_whole_network','live_availability_verified','physical_charging_verified','remote_control','billing_authority'].every(k=>d[k]===false)||!Array.isArray(d.samples)||d.samples.length>30||d.count!==d.samples.length)return false;
  let previous=null;
  const metrics=['published_count','within_policy_count','stale_count','unknown_age_count','future_clock_count'];
  const timestamp=/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;
  const strictInstant=v=>typeof v==='string'&&timestamp.test(v)&&Number.isFinite(Date.parse(v))&&new Date(Date.parse(v)).toISOString().slice(0,23)===v.slice(0,23);
  for(const row of d.samples){
   if(!row||typeof row!=='object'||!strictInstant(row.observed_hour)||!strictInstant(row.sampled_at))return false;
   const hour=Date.parse(row.observed_hour),at=Date.parse(row.sampled_at);
   if(!Number.isFinite(hour)||!Number.isFinite(at)||!row.observed_hour.endsWith(':00:00.000000Z')||at<hour||at>=hour+3600000||(previous!==null&&hour>=previous))return false;
   previous=hour;
   if(['within_policy','attention','empty'].includes(row.state)){
    if(!Number.isSafeInteger(row.max_age_seconds)||row.max_age_seconds<60||row.max_age_seconds>604800||!metrics.every(k=>qualityCount(row[k]))||row.within_policy_count+row.stale_count+row.unknown_age_count!==row.published_count||row.future_clock_count>row.unknown_age_count||row.state!==(row.published_count===0?'empty':(row.stale_count+row.unknown_age_count>0?'attention':'within_policy')))return false;
   }else if(!['unavailable','policy_unavailable'].includes(row.state)||!metrics.every(k=>row[k]===null))return false;
  }
  return true;
 }
 function open({host,providerId,api,current}){
  viewers.get(host)?.();let sequence=0,request=null,observer=null;
  const root=document.createElement('section'),select=document.createElement('select'),read=text('button','Read stored catalog observations','قراءة ملاحظات الكتالوج المخزنة'),quality=text('button','Read source quality history','قراءة سجل جودة المصدر'),output=document.createElement('div');
  const label=text('label','Catalog module','وحدة الكتالوج');label.append(select);
  for(const [value,en,ar]of[['all','Locations and tariffs','المواقع والتعرفة'],['locations','Locations','المواقع'],['tariffs','Tariffs','التعرفة']]){const option=text('option',en,ar);option.value=value;select.append(option);}
  read.type='button';quality.type='button';
  function erase(){sequence++;request?.abort();request=null;output.replaceChildren();read.disabled=false;quality.disabled=false;select.disabled=false;}
  const hidden=()=>{if(document.hidden)erase();};document.addEventListener('visibilitychange',hidden);
  window.addEventListener('pagehide',erase);
  const cleanup=()=>{observer?.disconnect();erase();document.removeEventListener('visibilitychange',hidden);window.removeEventListener('pagehide',erase);};viewers.set(host,cleanup);
  const active=n=>n===sequence&&!document.hidden&&current()&&root.isConnected;
  read.onclick=async()=>{if(document.hidden||!current())return;erase();const n=sequence,controller=new AbortController();request=controller;read.disabled=true;quality.disabled=true;select.disabled=true;const module=select.value;
   try{const d=await api(`ocpi/providers/${providerId}/catalog-observations?module=${module}`,'GET',undefined,controller.signal);if(!active(n))return;if(!valid(d,providerId)||d.module_filter!==module)throw Error('Invalid observation contract');
    output.append(raw('h4',d.provider.name),raw('p',d.as_of));if(!d.provider.authorized)output.append(text('p','Partner authorization is revoked. These are private historical records.','تصريح الشريك ملغى. هذه سجلات تاريخية خاصة.'));
    for(const [key,m]of Object.entries(d.modules)){const card=document.createElement('article');card.append(text('h4',key==='locations'?'Stored locations':'Stored tariffs',key==='locations'?'المواقع المخزنة':'التعرفة المخزنة'),text('p',`Records: ${m.total}`,`السجلات: ${m.total}`));
     for(const [kind,en,ar]of[['reported_age','Partner-reported timestamp age','عمر الوقت الذي أبلغ عنه الشريك'],['receipt_age','Local receipt timestamp age','عمر وقت الاستلام المحلي']]){card.append(text('h5',en,ar));for(const [bucket,names]of Object.entries(ageLabels)){const p=text('p',...names);p.append(raw('span',`: ${m[kind][bucket]}`));card.append(p);}}
     for(const [metric,names]of Object.entries(labels)){if(!(metric in m))continue;const p=text('p',...names);p.append(raw('span',`: ${m[metric]}`));card.append(p);}
     if(key==='locations'){
      for(const [facet,en,ar]of [['published_by_reported_market','Stored publish=true partner records by reported market (not full network coverage)','السجلات المخزنة بعلامة نشر صحيحة حسب الدولة المبلّغ عنها (ليست تغطية الشبكة كاملة)'],['published_with_usable_coordinates_by_reported_market','Published records with usable map coordinates by reported market (accuracy unverified)','السجلات المنشورة بإحداثيات قابلة للعرض حسب الدولة (الدقة غير متحققة)']]){
       card.append(text('h5',en,ar));for(const [market,names]of Object.entries(markets)){const p=text('p',...names);p.append(raw('span',`: ${m[facet][market]}`));card.append(p);}
      }
     }output.append(card);
    }
   }catch(error){if(active(n))output.replaceChildren(text('p','Observations unavailable. Check access or select one module, then read again. No partial counts are shown.','الملاحظات غير متاحة. تحقق من الصلاحية أو اختر وحدة واحدة ثم اقرأ مجدداً. لا تُعرض أعداد جزئية.'));}
   finally{if(active(n)){read.disabled=false;quality.disabled=false;select.disabled=false;}}
  };
  quality.onclick=async()=>{
   if(document.hidden||!current())return;
   erase();
   const n=sequence,controller=new AbortController();request=controller;
   read.disabled=true;quality.disabled=true;select.disabled=true;
   try{
    const d=await api(`ocpi/providers/${providerId}/catalog-quality-history`,'GET',undefined,controller.signal);
    if(!active(n))return;
    if(!validQuality(d,providerId))throw Error('Invalid quality history evidence');
    output.append(text('h4','Stored hourly partner source quality (not live availability)','سجل جودة مصدر الشريك بالساعة (ليس توفراً حياً)'));
    if(!d.samples.length)output.append(text('p','No changed location syncs recorded; there is no coverage conclusion.','لا توجد عمليات مزامنة مواقع متغيرة مسجلة؛ لا توجد نتيجة عن التغطية.'));
    for(const entry of d.samples){
     const item=document.createElement('article');
     item.append(raw('p',entry.observed_hour),text('p',
       `Reported-source state: ${entry.state}`,`حالة بيانات المصدر المبلّغ عنها: ${entry.state}`));
     if(entry.published_count!==null){
      item.append(text('p',
       `Stored published: ${entry.published_count} · Within age policy: ${entry.within_policy_count} · Stale: ${entry.stale_count} · Unknown: ${entry.unknown_age_count} · Future clock: ${entry.future_clock_count}`,
       `التقارير المنشورة: ${entry.published_count} · ضمن سياسة العمر: ${entry.within_policy_count} · قديمة: ${entry.stale_count} · غير معروفة: ${entry.unknown_age_count} · ساعة مستقبلية: ${entry.future_clock_count}`));
     }
     output.append(item);
    }
   }catch(error){if(active(n))output.replaceChildren(text('p','Quality history unavailable. Refresh explicitly; no partial samples are shown.','سجل جودة المصدر غير متاح. أعد القراءة يدوياً؛ لا تُعرض عينات جزئية.'));}
   finally{if(active(n)){read.disabled=false;quality.disabled=false;select.disabled=false;}}
  };
  select.onchange=erase;
  root.append(text('p','Stored partner reports only. Age buckets describe observations, not the configured catalog policy. A recent receipt does not refresh an old source report. Publish flags, coordinate usability and connector lists do not prove geographic accuracy, live availability or charging compatibility. No upstream request, booking, command or payment is sent.','تقارير شريك مخزنة فقط. فئات العمر تصف الملاحظات وليست قرار سياسة الكتالوج المضبوطة. الاستلام الحديث لا يجعل تقرير المصدر القديم حديثاً. علامات النشر وصلاحية عرض الإحداثيات وقوائم الموصلات لا تثبت دقة الموقع أو التوفر الحي أو توافق الشحن. لا يُرسل طلب للشريك أو حجز أو أمر أو دفع.'),label,read,quality,output);host.replaceChildren(root);observer=new MutationObserver(()=>{if(!root.isConnected)cleanup();});observer.observe(document.body,{childList:true,subtree:true});
 }
 return{open};
})();
