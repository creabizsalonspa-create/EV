/* Same-origin read-only unified catalog. Source data is always rendered as text. */
(() => {
 const text=(tag,en,ar)=>{const n=document.createElement(tag);if(tag==='label'){n.append(text('span',en,ar));return n;}n.dataset.en=en;n.dataset.ar=ar;n.textContent=document.documentElement.lang==='ar'?ar:en;return n;};
 const button=(en,ar,action)=>{const n=text('button',en,ar);n.type='button';n.onclick=action;return n;};
 function install(root,api,onCatalog){
  let advancedQuery={},viewportQuery={},generation=0,page=1,query='',scope='all',country='',connector='',mapRows=[],mapPublished=false,recentStatus='',maxSourceAge='',savedOnly=false,customer=false,owner=null,favoriteWriter=null,writing=false,uncertain=false,recentPresets=[];
  const form=document.createElement('form'),input=document.createElement('input'),source=document.createElement('select'),plug=document.createElement('select'),state=document.createElement('p'),list=document.createElement('div'),nav=document.createElement('nav');
  input.maxLength=80;input.name='unified-query';
  const field=text('label','Name, address or operator','الاسم أو العنوان أو المشغل');field.append(input);form.append(field);
  for(const [value,en,ar] of [['all','All published sources','كل المصادر المنشورة'],['catalog','Platform catalog','كتالوج المنصة'],['partner','Authorized partner reports','تقارير الشركاء المصرح لهم']]){const option=text('option',en,ar);option.value=value;source.append(option);}
  const sourceLabel=text('label','Source','المصدر');sourceLabel.append(source);form.append(sourceLabel);
  for(const value of ['', 'CCS2','Type2','CHAdeMO']){const option=text('option',value||'All connectors',value||'كل الموصلات');option.value=value;plug.append(option);}
  const market=document.createElement('select');market.name='country';
  for(const [value,en,ar] of [['','No country filter (available records)','بدون تصفية حسب الدولة (السجلات المتاحة)'],['AE','United Arab Emirates','الإمارات العربية المتحدة'],['US','United States','الولايات المتحدة'],['CA','Canada','كندا']]){const option=text('option',en,ar);option.value=value;market.append(option);}
  const marketLabel=text('label','Country (supported markets)','الدولة (الأسواق المدعومة)');marketLabel.append(market);form.append(marketLabel);
  const connectorLabel=text('label','Connector','الموصل');connectorLabel.append(plug);form.append(connectorLabel);
  const savedField=text('label','Saved places only','الأماكن المحفوظة فقط'),savedInput=document.createElement('input');savedInput.type='checkbox';savedField.append(savedInput);savedField.hidden=true;form.append(savedField);
  const report=document.createElement('select');report.name='recent_source_status';
  for(const value of ['',...(window.EvConnectorFreshness?.statuses||['Available','Charging','Reserved','Faulted','Unavailable'])]){const option=text('option',value||'All states',value?(window.EvConnectorFreshness?.label(value,true)||value):'كل الحالات');option.value=value;report.append(option);}
  const reportLabel=text('label','Simulated source state at search','حالة مصدر المحاكاة عند البحث');reportLabel.append(report);form.append(reportLabel);
  const ageFilter=document.createElement('select');ageFilter.name='max_source_age_hours';
  for(const [value,en,ar] of [['','All report ages','كل أعمار التقارير'],['1','Last hour','آخر ساعة'],['24','Last 24 hours','آخر 24 ساعة'],['168','Last 7 days','آخر 7 أيام']]){const option=text('option',en,ar);option.value=value;ageFilter.append(option);}
  const ageLabel=text('label','Partner source updates within (platform results unchanged)','تحديثات مصدر الشريك خلال (نتائج المنصة دون تغيير)');ageLabel.append(ageFilter);form.append(ageLabel);
  form.append(text('p','Canonical connector reports within five minutes at search only; excludes partner reports. Physical availability is unverified. Search again to recheck.','تقارير موصلات المنصة خلال خمس دقائق عند البحث فقط؛ باستثناء تقارير الشركاء. التوفر الفعلي غير مؤكد. أعد البحث للتحقق.'));
  const advanced=document.createElement('details'),advancedInputs={};advanced.append(text('summary','Advanced filters','مرشحات متقدمة'));
  for(const [key,en,ar,limit] of [['operator','Operator label','اسم المشغل',100],['address','Address or city','العنوان أو المدينة',150],['access','Platform access description','وصف الوصول في المنصة',150],['min_power_kw','Minimum published station rating (kW)','أقل قدرة منشورة للمحطة (كيلوواط)',4],['max_power_kw','Maximum published station rating (kW)','أعلى قدرة منشورة للمحطة (كيلوواط)',4],['max_rate_per_kwh','Maximum simulated energy rate per kWh','أقصى سعر طاقة محاكاة لكل كيلوواط ساعة',10],['price_currency','Rate currency (for example AED)','عملة السعر (مثلاً AED)',3]]){
   const field=text('label',en,ar),control=document.createElement('input');control.name=key;control.maxLength=limit;field.append(control);advancedInputs[key]=control;advanced.append(field);
  }
  advanced.append(text('p','Operator and address match published labels. Access, power and price filters exclude partner reports. Published station kW is a catalog rating, not measured live power. Price matches current simulated energy rates only, excluding tax; it is not a total-cost quote. Enter both rate and currency.','يطابق المشغل والعنوان الأوصاف المنشورة. تستثني مرشحات الوصول والقدرة والسعر تقارير الشركاء. قدرة المحطة المنشورة وصف كتالوجي وليست قدرة فعلية مقاسة. يطابق السعر أسعار الطاقة المحاكاة الحالية فقط دون الضريبة؛ وليس عرضاً للتكلفة الإجمالية. أدخل السعر والعملة معاً.'));form.append(advanced);
  const normalizeRate=value=>value.replace(/[٠-٩۰-۹]/g,c=>String(c.charCodeAt(0)-(c>='۰'?0x6f0:0x660))).replace(/٫/g,'.');
  const submit=text('button','Search all sources','البحث في كل المصادر');submit.type='submit';form.append(submit);state.setAttribute('role','status');
  // Deliberately private to this UI instance: never localStorage, cookies,
  // analytics, browser history or a background server request.
  const recentPanel=document.createElement('section'),recentList=document.createElement('div');
  recentPanel.id='unified-recent-presets';
  recentPanel.hidden=true;
  const recentHeading=text('h4','Recent filters (this session only)','مرشحات حديثة (هذه الجلسة فقط)');
  const clearRecent=button('Clear recent filters','مسح المرشحات الحديثة',()=>{
    recentPresets=[];renderRecentPresets();
  });
  recentPanel.append(recentHeading,recentList,clearRecent);
  function renderRecentPresets(){
    recentPanel.hidden=recentPresets.length===0;
    recentList.replaceChildren();
    for(const [i,record] of recentPresets.entries()){
      const scopes={all:['All published sources','كل المصادر المنشورة'],catalog:['Platform catalog','كتالوج المنصة'],partner:['Partner reports','تقارير الشركاء']};
      const sourceLabel=scopes[record.scope]||scopes.all;
      const displayEn=[record.q||'All locations',record.country||'all countries',sourceLabel[0]].join(' · ').slice(0,120);
      const displayAr=[record.q||'كل المواقع',record.country||'كل الدول',sourceLabel[1]].join(' · ').slice(0,120);
      const restore=button('Restore filters: '+displayEn,'استعادة المرشحات: '+displayAr,()=>{
        if(writing||submit.disabled)return;
        // Discarded actor/history callbacks cannot repopulate a new form.
        if(!recentPresets.includes(record))return;
        input.value=record.q;source.value=record.scope;market.value=record.country;
        plug.value=record.connector;report.value=record.recentStatus;
        ageFilter.value=record.maxSourceAge;savedInput.checked=customer&&record.savedOnly;
        for(const [key,control] of Object.entries(advancedInputs)){
          control.value=record.advanced[key]||'';
        }
        // Restoring a prior draft is NOT a search: a second, explicit
        // Search action is always required before any network request.
        state.replaceChildren(text('span',
          'Filters restored locally. Press Search to submit.',
          'استُعيدت المرشحات محلياً. اضغط بحث لإرسالها.'));
      });
      restore.dataset.recentIndex=String(i);recentList.append(restore);
    }
  }
  function rememberSuccessfulSearch(record){
    if(!record)return;
    const fingerprint=JSON.stringify(record);
    recentPresets=[record,...recentPresets.filter(other=>JSON.stringify(other)!==fingerprint)].slice(0,5);
    renderRecentPresets();
  }
  root.append(text('h3','Search platform and partner locations','البحث في مواقع المنصة والشركاء'),text('p','One search of published records. Partner reports are unverified and cannot authorize booking, charging or payment. External discovery providers require their separate consent.','بحث موحد في السجلات المنشورة. تقارير الشركاء غير مؤكدة ولا تسمح بالحجز أو الشحن أو الدفع. يتطلب مزودو الاكتشاف الخارجيون موافقة منفصلة.'),form,state,list,nav);
  // Only this UI instance can revoke its published map. A retired listener
  // cannot erase a newer view's map when the screen is mounted twice.
  const mapPublication = {};
  function revokePublishedMap(){
   if(!mapPublished)return;
   mapPublished=false;
   window.dispatchEvent(new CustomEvent('unified-map-clear',{detail:mapPublication}));
  }
  const mapButton=button('Map these loaded results','عرض هذه النتائج المحملة على الخريطة',()=>{
   if(mapButton.disabled||mapRows.length===0||
      document.visibilityState==='hidden')return;
   mapPublished=true;
   const published=new CustomEvent('unified-map-data',{detail:mapRows});
   published.evUnifiedPublication=mapPublication;
   window.dispatchEvent(published);
   document.getElementById('discovery-map')?.scrollIntoView({block:'start'});
  });mapButton.disabled=true;root.append(mapButton,recentPanel);
  // All source entry points belong to discovery, but a licensed provider
  // is a separate consented lookup, not part of the published SQL page.
  const licensedEntry=text('div','','');
  function renderLicensedEntry(){
   licensedEntry.replaceChildren();
   if(owner===null)return;
   const epoch=generation,who=owner;
   const sourceStatus=text('p','','');sourceStatus.setAttribute('role','status');
   const open=button('Open Charge Map — separate licensed search','خريطة الشحن المفتوحة — بحث مرخص منفصل',()=>{
    if(epoch!==generation||who!==owner||writing||submit.disabled||document.visibilityState==='hidden')return;
    const panel=document.getElementById('open-charge-map-discovery');
    if(!panel||panel.hidden){
     sourceStatus.replaceChildren(text('span','Licensed source is not available. Published search remains available.','المصدر المرخص غير متاح. يبقى البحث في السجلات المنشورة متاحاً.'));
     return;
    }
    // Do not transfer the query, coordinates, filters, consent or identity.
    // The existing provider form owns its independent consent and limits.
    panel.tabIndex=-1;
    panel.focus?.({preventScroll:true});
    panel.scrollIntoView?.({block:'start'});
   });
   open.dataset.discoverySource='open_charge_map';
   licensedEntry.append(text('p','Published results and licensed reports are separate. Opening the licensed source does not submit a search or share your current filters.','النتائج المنشورة والتقارير المرخصة منفصلة. فتح المصدر المرخص لا يرسل بحثاً أو يشارك مرشحاتك الحالية.'),open,sourceStatus);
  }
  root.append(licensedEntry);
  // Missing source metadata must never become a bookable catalog action.
  // Location identity remains visible as read-only evidence.
  const catalogMetadataComplete=row=>row&&row.station&&
    typeof row.station.name==='string'&&row.station.name.trim()!==''&&
    ['SIMULATED','DISCOVERY_ONLY'].includes(row.station.evidence)&&
    ['CCS2','Type2','CHAdeMO'].includes(row.station.connector_type)&&
    Number.isSafeInteger(row.station.power_kw)&&
    row.station.power_kw>=1&&row.station.power_kw<=1000&&
    (row.station.evidence!=='DISCOVERY_ONLY'||row.station.reservable!==true)&&
    (row.station.distance_km===null||row.station.distance_km===undefined||
      (typeof row.station.distance_km==='number'&&
       Number.isFinite(row.station.distance_km)&&row.station.distance_km>=0))&&
    (row.station.timezone===null||row.station.timezone===undefined||
      (typeof row.station.timezone==='string'&&row.station.timezone.trim()!==''))&&
    (row.station.reservation_policy===null||row.station.reservation_policy===undefined||
      typeof row.station.reservation_policy==='string');
  async function load(target,remember=false){
   if(writing)return;
   const historySnapshot=remember?{q:query,scope,country,connector,recentStatus,maxSourceAge,savedOnly,advanced:{...advancedQuery}}:null;
   uncertain=false;const turn=++generation;revokePublishedMap();mapRows=[];mapButton.disabled=true;list.replaceChildren();nav.replaceChildren();state.replaceChildren(text('span','Searching…','جارٍ البحث…'));submit.disabled=true;
   try{
    const data=await api('search?'+new URLSearchParams({...advancedQuery,...viewportQuery,q:query,scope,...(country?{country}:{}),connector,page:String(target),saved_only:savedOnly?'1':'0',recent_source_status:recentStatus,...(maxSourceAge?{max_source_age_hours:maxSourceAge}:{})}));if(turn!==generation)return;
    if(!data||!Array.isArray(data.data)||data.data.length>25||
       !Number.isSafeInteger(data.current_page)||data.current_page!==target||
       !Number.isSafeInteger(data.last_page)||data.last_page<1||data.last_page>1000000||
       !Number.isSafeInteger(data.total)||data.total<0||data.total<data.data.length||
       (data.current_page>data.last_page&&data.data.length>0))
      throw Error('Unverified catalog page');
    // Validate unique typed record identities before rendering any card
    // or authorizing the map. A failed page must never reveal partial records.
    const keys=new Set();
    for(const row of data.data){
      if(!row||typeof row!=='object'||Array.isArray(row)||
         typeof row.key!=='string'||keys.has(row.key))
        throw Error('Invalid catalog identity');
      keys.add(row.key);
    }
    page=data.current_page;state.replaceChildren(text('span',`${data.total} published results`,`${data.total} نتيجة منشورة`));
    for(const row of data.data){
     const card=document.createElement('article');card.dataset.discoveryKey=row.key;
     let allowedFavorite=false;
     if(row.kind==='catalog'&&row.station&&Number.isSafeInteger(row.station.id)&&row.station.id>0&&row.key==='catalog:'+row.station.id){
      card.dataset.stationId=String(row.station.id);
      const name=typeof row.station.name==='string'&&row.station.name.trim()?row.station.name:'Unnamed platform station';
      const evidence=typeof row.station.evidence==='string'&&row.station.evidence.trim()?row.station.evidence:'unknown';
      card.append(text('h4',name,name),text('p','Platform catalog · '+evidence,'كتالوج المنصة · '+evidence));
      if(catalogMetadataComplete(row)){
       allowedFavorite=true;
       card.append(button('View station and booking options','عرض المحطة وخيارات الحجز',()=>onCatalog(row.station.id)));
      }else{
       card.append(text('p','Published station details incomplete. Booking and saved-place actions are unavailable until catalog metadata is corrected.',
         'بيانات المحطة المنشورة غير مكتملة. لا تتوفر إجراءات الحجز أو الحفظ حتى تصحيح بيانات الكتالوج.'));
      }
     }else if(row.kind==='partner'&&Number.isSafeInteger(row.record_id)&&row.record_id>0&&row.key==='partner:'+row.record_id&&row.location&&row.reservable===false&&row.remote_control===false&&row.availability==='unknown'){
      const partnerSource=row.evidence==='PARTNER_REPORTED_UNVERIFIED'&&
        row.location.availability==='unknown'&&row.location.reservable===false&&
        row.location.remote_control===false;
      allowedFavorite=partnerSource;
      card.dataset.partnerLocationId=String(row.record_id);const l=row.location;
      const safe=value=>typeof value==='string'&&value.trim()?value.trim().slice(0,250):'';
      const providedName=safe(l.name)||safe(l.id);
      const nameEn=providedName||'Partner record #'+row.record_id;
      const nameAr=providedName||'سجل الشريك #'+row.record_id;
      const provider=safe(row.provider_label);
      const address=[l.address,l.city,l.country].map(safe).filter(Boolean).join(' · ');
      const lat=l.latitude===null||l.latitude===undefined||String(l.latitude).trim()===''?NaN:Number(l.latitude);
      const lng=l.longitude===null||l.longitude===undefined||String(l.longitude).trim()===''?NaN:Number(l.longitude);
      const canMap=Number.isFinite(lat)&&Number.isFinite(lng)&&Math.abs(lat)<=85.05112878&&Math.abs(lng)<=180;
      card.append(
       text('h4',nameEn,nameAr),
       text('p',provider||'Provider label unavailable (unverified)',provider||'اسم مقدم الخدمة غير متاح (غير مؤكد)'),
       text('p',address||'Address details unavailable',address||'تفاصيل العنوان غير متاحة'),
       text('p','Partner reported · availability unknown · no booking or remote control','تقرير شريك · التوفر غير معروف · لا حجز أو تحكم عن بُعد'),
      );
      if(!canMap)card.append(text('p','Map coordinates unavailable; this record can only be inspected in the list.','إحداثيات الخريطة غير متاحة؛ يمكن عرض هذا السجل في القائمة فقط.'));
      card.append(text('p','Reported source time: '+String(row.source_updated_at||'unknown'),'وقت المصدر المبلغ عنه: '+String(row.source_updated_at||'غير معروف')));
      const age=row.source_timestamp_evidence==='PARTNER_REPORTED_ONLY'&&row.source_clock_evidence==='PARTNER_REPORTED_TIME'&&Number.isSafeInteger(row.reported_source_age_minutes)&&row.reported_source_age_minutes>=0?row.reported_source_age_minutes:null;
      card.append(text('p',age===null?'Reported catalog age unknown or partner clock ahead':`Reported catalog age at search: ${age} min${age>=1440?' · over 24 hours old':''}`,age===null?'عمر الكتالوج المبلغ عنه غير معروف أو ساعة الشريك متقدمة':`عمر الكتالوج المبلغ عنه عند البحث: ${age} دقيقة${age>=1440?' · أقدم من 24 ساعة':''}`));
      card.append(text('p',window.EvPartnerCatalogPolicy?.text(row.catalog_age_policy,false)||'Catalog-age policy unavailable.',window.EvPartnerCatalogPolicy?.text(row.catalog_age_policy,true)||'سياسة عمر الكتالوج غير متاحة.'));
      card.append(text('p','Catalog timestamps do not verify physical availability. Search again to recheck.','أوقات الكتالوج لا تؤكد التوفر الفعلي. أعد البحث للتحقق.'));

     }else continue;
     if(customer&&allowedFavorite&&typeof favoriteWriter==='function'){
      const saved=row.is_favorite===true;
      const star=button(saved?'Remove saved place':'Save place',saved?'إزالة المكان المحفوظ':'حفظ المكان',async()=>{
       if(writing||uncertain||turn!==generation)return;
       const who=owner;writing=true;submit.disabled=true;star.disabled=true;
       try{
        const id=row.kind==='catalog'?row.station.id:row.record_id;
        const receipt=await favoriteWriter(row.kind,id,!saved);
        if(turn!==generation||who!==owner)return;
        if(receipt!==!saved)throw Error('Uncertain preference');
        writing=false;await load(1);
       }catch{
        if(turn===generation&&who===owner){uncertain=true;state.replaceChildren(text('span','Saved-state outcome unknown. Search again to refresh before retrying.','نتيجة الحفظ غير معروفة. أعد البحث لتحديث الحالة قبل المحاولة.'));}
       }finally{if(turn===generation){writing=false;submit.disabled=false;}}
      });star.dataset.favoriteKind=row.kind;card.append(star);
     }
     list.append(card);
     // Do not send a rejected identity, guessed partner status, or incomplete
     // catalog metadata to the separate map event consumer.
     if(row.kind==='catalog' ? catalogMetadataComplete(row) :
        allowedFavorite&&row.location_available!==false)mapRows.push(row);
    }
    mapButton.disabled=mapRows.length===0;
    if(page>1)nav.append(button('Previous','السابق',()=>load(page-1)));
    nav.append(text('span',`Page ${page} / ${data.last_page}`,`الصفحة ${page} / ${data.last_page}`));
    if(page<data.last_page)nav.append(button('Next','التالي',()=>load(page+1)));
    rememberSuccessfulSearch(historySnapshot);
   }catch{if(turn===generation){
     mapRows=[];mapButton.disabled=true;list.replaceChildren();nav.replaceChildren();
     state.replaceChildren(text('span','Search failed. Submit again to retry.','تعذر البحث. أعد الإرسال للمحاولة.'));
    }}
   finally{if(turn===generation){submit.disabled=false;renderLicensedEntry();}}
  }
  form.onsubmit=e=>{e.preventDefault();if(writing)return;advancedQuery={};viewportQuery={};for(const [key,control] of Object.entries(advancedInputs)){let value=control.value.trim();if(key==='price_currency')value=value.toUpperCase();if(['max_rate_per_kwh','min_power_kw','max_power_kw'].includes(key))value=normalizeRate(value);if(value)advancedQuery[key]=value;}query=input.value.trim();scope=source.value;country=market.value;connector=plug.value;recentStatus=report.value;maxSourceAge=ageFilter.value;savedOnly=customer&&savedInput.checked;load(1,true);};
  // Map movement does not make a request. Only the user's explicit map-view
  // search event supplies transient bounds to this existing authenticated API.
  window.addEventListener('unified-viewport-search',event=>{
   const raw=event.detail;
   if(!raw||writing)return;
   const keys=['south','north','west','east'];
   const values=keys.map(key=>Number(raw[key]));
   const [south,north,west,east]=values;
   if(!values.every(Number.isFinite)||south < -90||north > 90||
      south >= north||west < -180||west > 180||east < -180||east > 180||
      west === east)return;
   viewportQuery=Object.fromEntries(keys.map((key,i)=>[key,values[i].toFixed(6)]));
   const panel=root.closest?.('details');
   if(panel)panel.open=true;
   load(1);
   root.scrollIntoView?.({block:'start'});
  });
  // Location queries and result sets stay in memory; there is no automatic lookup.
  const controller={
   setContext(context){
    if(owner!==context.owner||customer!==(context.customer===true))this.clear();
    owner=context.owner;customer=context.customer===true;
    favoriteWriter=context.favorite;savedField.hidden=!customer;renderLicensedEntry();
   },
   clear(){
    revokePublishedMap();
    recentPresets=[];renderRecentPresets();advancedQuery={};viewportQuery={};
    for(const control of Object.values(advancedInputs))control.value='';
    generation++;writing=false;uncertain=false;scope='all';source.value='all';
    connector='';plug.value='';recentStatus='';report.value='';maxSourceAge='';
    ageFilter.value='';savedOnly=false;savedInput.checked=false;mapRows=[];
    mapButton.disabled=true;query='';input.value='';country='';market.value='';
    list.replaceChildren();nav.replaceChildren();state.replaceChildren();
    submit.disabled=false;renderLicensedEntry();
   }
  };
  // BFCache navigation and hidden tabs cannot retain a previous customer's
  // search history, typed coordinates, saved filter or published map pins.
  window.addEventListener('pagehide',()=>controller.clear());
  document.addEventListener?.('visibilitychange',()=>{
   if(document.visibilityState==='hidden')controller.clear();
  });
  return controller;
 }
 window.EvUnifiedDiscovery={install};
 window.addEventListener('discovery-api-ready',event=>{const root=document.getElementById('unified-discovery');if(root)window.EvUnifiedDiscovery.active=install(root,event.detail,id=>window.dispatchEvent(new CustomEvent('unified-catalog-open',{detail:id})));});
})();
