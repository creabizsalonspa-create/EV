/* Same-origin read-only unified catalog. Source data is always rendered as text. */
(() => {
 const text=(tag,en,ar)=>{const n=document.createElement(tag);n.dataset.en=en;n.dataset.ar=ar;n.textContent=document.documentElement.lang==='ar'?ar:en;return n;};
 const button=(en,ar,action)=>{const n=text('button',en,ar);n.type='button';n.onclick=action;return n;};
 function install(root,api,onCatalog){
  let generation=0,page=1,query='',scope='all',connector='',mapRows=[],savedOnly=false,customer=false,owner=null,favoriteWriter=null,writing=false,uncertain=false;
  const form=document.createElement('form'),input=document.createElement('input'),source=document.createElement('select'),plug=document.createElement('select'),state=text('p','',''),list=document.createElement('div'),nav=document.createElement('nav');
  input.maxLength=80;input.name='unified-query';
  const field=text('label','Name, address or operator','الاسم أو العنوان أو المشغل');field.append(input);form.append(field);
  for(const [value,en,ar] of [['all','All published sources','كل المصادر المنشورة'],['catalog','Platform catalog','كتالوج المنصة'],['partner','Authorized partner reports','تقارير الشركاء المصرح لهم']]){const option=text('option',en,ar);option.value=value;source.append(option);}
  const sourceLabel=text('label','Source','المصدر');sourceLabel.append(source);form.append(sourceLabel);
  for(const value of ['', 'CCS2','Type2','CHAdeMO']){const option=text('option',value||'All connectors',value||'كل الموصلات');option.value=value;plug.append(option);}
  const connectorLabel=text('label','Connector','الموصل');connectorLabel.append(plug);form.append(connectorLabel);
  const savedField=text('label','Saved places only','الأماكن المحفوظة فقط'),savedInput=document.createElement('input');savedInput.type='checkbox';savedField.append(savedInput);savedField.hidden=true;form.append(savedField);
  const submit=text('button','Search all sources','البحث في كل المصادر');submit.type='submit';form.append(submit);state.setAttribute('role','status');
  root.append(text('h3','Search platform and partner locations','البحث في مواقع المنصة والشركاء'),text('p','One search of published records. Partner reports are unverified and cannot authorize booking, charging or payment. External discovery providers require their separate consent.','بحث موحد في السجلات المنشورة. تقارير الشركاء غير مؤكدة ولا تسمح بالحجز أو الشحن أو الدفع. يتطلب مزودو الاكتشاف الخارجيون موافقة منفصلة.'),form,state,list,nav);
  const mapButton=button('Map these loaded results','عرض هذه النتائج المحملة على الخريطة',()=>{
   window.dispatchEvent(new CustomEvent('unified-map-data',{detail:mapRows}));
   document.getElementById('discovery-map')?.scrollIntoView({block:'start'});
  });mapButton.disabled=true;root.append(mapButton);
  async function load(target){
   if(writing)return;
   uncertain=false;const turn=++generation;mapRows=[];mapButton.disabled=true;list.replaceChildren();nav.replaceChildren();state.textContent=document.documentElement.lang==='ar'?'جارٍ البحث…':'Searching…';submit.disabled=true;
   try{
    const data=await api('search?'+new URLSearchParams({q:query,scope,connector,page:String(target),saved_only:savedOnly?'1':'0'}));if(turn!==generation)return;
    if(!Array.isArray(data.data)||!Number.isInteger(data.current_page)||!Number.isInteger(data.last_page)||!Number.isInteger(data.total))throw Error('Invalid catalog response');
    mapRows=data.data;mapButton.disabled=mapRows.length===0;
    page=data.current_page;state.replaceChildren(text('span',`${data.total} published results`,`${data.total} نتيجة منشورة`));
    for(const row of data.data){
     const card=document.createElement('article');card.dataset.discoveryKey=row.key;
     if(row.kind==='catalog'&&row.station&&Number.isSafeInteger(row.station.id)&&row.station.id>0&&row.key==='catalog:'+row.station.id){
      card.dataset.stationId=String(row.station.id);card.append(text('h4',row.station.name,row.station.name),text('p','Platform catalog · '+row.station.evidence,'كتالوج المنصة · '+row.station.evidence));
      card.append(button('View station and booking options','عرض المحطة وخيارات الحجز',()=>onCatalog(row.station.id)));
     }else if(row.kind==='partner'&&Number.isSafeInteger(row.record_id)&&row.record_id>0&&row.key==='partner:'+row.record_id&&row.location&&row.reservable===false&&row.remote_control===false&&row.availability==='unknown'){
      card.dataset.partnerLocationId=String(row.record_id);const l=row.location;card.append(text('h4',String(l.name||l.id||''),String(l.name||l.id||'')),text('p',String(row.provider_label||''),String(row.provider_label||'')),text('p',[l.address,l.city,l.country].filter(Boolean).join(' · '),[l.address,l.city,l.country].filter(Boolean).join(' · ')),text('p','Partner reported · availability unknown · no booking or remote control','تقرير شريك · التوفر غير معروف · لا حجز أو تحكم عن بُعد'));
      card.append(text('p','Reported source time: '+String(row.source_updated_at||'unknown'),'وقت المصدر المبلغ عنه: '+String(row.source_updated_at||'غير معروف')));
     }else continue;
     if(customer&&typeof favoriteWriter==='function'){
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
    }
    if(page>1)nav.append(button('Previous','السابق',()=>load(page-1)));
    nav.append(text('span',`Page ${page} / ${data.last_page}`,`الصفحة ${page} / ${data.last_page}`));
    if(page<data.last_page)nav.append(button('Next','التالي',()=>load(page+1)));
   }catch{if(turn===generation)state.replaceChildren(text('span','Search failed. Submit again to retry.','تعذر البحث. أعد الإرسال للمحاولة.'));}
   finally{if(turn===generation)submit.disabled=false;}
  }
  form.onsubmit=e=>{e.preventDefault();if(writing)return;query=input.value.trim();scope=source.value;connector=plug.value;savedOnly=customer&&savedInput.checked;load(1);};
  // Location queries and result sets stay in memory; there is no automatic lookup.
  return {setContext(context){if(owner!==context.owner)this.clear();owner=context.owner;customer=context.customer===true;favoriteWriter=context.favorite;savedField.hidden=!customer;},clear(){generation++;writing=false;uncertain=false;savedOnly=false;savedInput.checked=false;mapRows=[];mapButton.disabled=true;query='';input.value='';list.replaceChildren();nav.replaceChildren();state.replaceChildren();submit.disabled=false;}};
 }
 window.EvUnifiedDiscovery={install};
 window.addEventListener('discovery-api-ready',event=>{const root=document.getElementById('unified-discovery');if(root)window.EvUnifiedDiscovery.active=install(root,event.detail,id=>window.dispatchEvent(new CustomEvent('unified-catalog-open',{detail:id})));});
})();
