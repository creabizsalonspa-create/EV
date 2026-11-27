/* Private owner/Admin report; no payment or charger operations. */
window.EvFleetReport = (() => {
    let serial=0, actor=null, api=null, host=null;
    const roots=new Set();
    const text=(tag,en,ar=en)=>{const n=document.createElement(tag);n.dataset.en=en;n.dataset.ar=ar;n.textContent=document.documentElement.lang==='ar'?ar:en;return n;};
    const raw=(tag,value)=>{const n=document.createElement(tag);n.textContent=String(value);return n;};
    function clear(){serial++;for(const root of roots)root.replaceChildren();roots.clear();}
    function verified(d,id,query){
        const count=v=>Number.isSafeInteger(v)&&v>=0;
        const keys=['active_members','invited_members','assigned_vehicles','cost_centers'];
        if(!d||d.basis!=='approved_claims_submitted_in_period'||d.physical_charging_verified!==false||
            !d.fleet||!count(d.fleet.id)||d.fleet.id===0||d.fleet.id!==Number(id)||typeof d.fleet.name!=='string'||
            !d.period||d.period.from!==query.get('from')||d.period.through!==query.get('through')||
            d.period.cost_center_id!==(query.has('cost_center_id')?Number(query.get('cost_center_id')):null)||
            typeof d.period.timezone!=='string'||!Number.isFinite(Date.parse(d.as_of))||
            !d.current_inventory||!keys.every(k=>count(d.current_inventory[k]))||
            !count(d.approved_claims)||d.approved_claims>2500||!Array.isArray(d.groups)||d.groups.length>2500)
            throw Error('Invalid fleet report scope.');
        let claims=0;const groups=new Set();
        for(const g of d.groups){
            if(!g||!count(g.cost_center_id)||g.cost_center_id===0||
                (query.has('cost_center_id')&&g.cost_center_id!==Number(query.get('cost_center_id')))||
                typeof g.code!=='string'||typeof g.name!=='string'||typeof g.currency!=='string'||
                !/^[A-Z]{3}$/.test(g.currency)||!Number.isInteger(g.minor_units)||g.minor_units<0||g.minor_units>3||
                typeof g.amount_minor!=='string'||!/^(0|[1-9][0-9]{0,22})$/.test(g.amount_minor)||
                !count(g.claims)||g.claims===0||g.claims>d.approved_claims-claims)
                throw Error('Invalid fleet report group.');
            const key=JSON.stringify([g.cost_center_id,g.code,g.name,g.currency,g.minor_units]);
            if(groups.has(key))throw Error('Duplicate fleet report group.');
            groups.add(key);claims+=g.claims;
        }
        if(claims!==d.approved_claims)throw Error('Invalid fleet report total.');
    }
    function mount(root,id){
        for(const old of roots)if(!old.isConnected)roots.delete(old);
        roots.add(root);root.dataset.fleetReportId=String(id);const identity=serial;
        const form=document.createElement('form'), from=document.createElement('input'), through=document.createElement('input'), center=document.createElement('input');
        for(const [field,en,ar]of[[from,'From local date','من التاريخ المحلي'],[through,'Through local date','حتى التاريخ المحلي'],[center,'Cost center ID (optional)','معرف مركز التكلفة (اختياري)']]){const label=document.createElement('label');label.append(text('span',en,ar),field);form.append(label);}
        from.type=through.type='date';from.required=through.required=true;center.type='number';center.min='1';
        const now=new Date().toISOString().slice(0,10);from.value=now.slice(0,8)+'01';through.value=now;
        const button=text('button','Read fleet report','قراءة تقرير الأسطول');button.type='submit';form.append(button);
        const output=document.createElement('div');root.append(text('p','Approved claims explicitly shared with this fleet only. Amounts are recorded claims, not balances, payments, refunds or proof of physical charging. Inventory counts are current and cover all cost centers. Dates use the fleet timezone.','المطالبات المعتمدة التي تمت مشاركتها صراحة مع الأسطول فقط. المبالغ مطالبات مسجلة وليست أرصدة أو مدفوعات أو مبالغ مستردة أو دليلاً على الشحن الفعلي. أعداد المخزون حالية وتشمل جميع مراكز التكلفة. التواريخ حسب منطقة الأسطول الزمنية.'),form,output);
        let request=0;
        form.addEventListener('input',()=>{request++;output.replaceChildren();button.disabled=false;});
        form.onsubmit=async e=>{e.preventDefault();if(identity!==serial||document.hidden||!form.isConnected||button.disabled)return;const run=++request;output.replaceChildren();button.disabled=true;
            const query=new URLSearchParams({from:from.value,through:through.value});if(center.value)query.set('cost_center_id',center.value);
            try{const d=await api(`fleets/${id}/report?${query}`);
                if(identity!==serial||run!==request||document.hidden||!root.isConnected)return;
                verified(d,id,query);
                output.append(raw('h4',d.fleet.name),raw('p',`${d.period.from} — ${d.period.through} · ${d.period.timezone} · ${d.as_of}`));
                const labels={active_members:['Active members','الأعضاء النشطون'],invited_members:['Pending invitations','الدعوات المعلقة'],assigned_vehicles:['Assigned vehicles','المركبات المسندة'],cost_centers:['Cost centers','مراكز التكلفة']};
                for(const [key,pair]of Object.entries(labels)){const line=text('p',...pair);line.append(raw('span',`: ${d.current_inventory[key]}`));output.append(line);}
                output.append(text('p',`Approved claims: ${d.approved_claims}`,`المطالبات المعتمدة: ${d.approved_claims}`));
                for(const g of d.groups){const amount=String(g.amount_minor).padStart(g.minor_units+1,'0');const formatted=g.minor_units?amount.slice(0,-g.minor_units)+'.'+amount.slice(-g.minor_units):amount;output.append(raw('p',`${g.code} · ${g.name} · ${g.currency} ${formatted} · ${g.claims}`));}
                const csv=text('button','Download private CSV snapshot','تنزيل لقطة CSV خاصة');csv.type='button';csv.onclick=()=>{if(identity!==serial||run!==request||document.hidden||!csv.isConnected)return;const cell=v=>'"'+(/^[\p{Z}\p{Cc}\p{Cf}]*[=+@-]/u.test(String(v))?"'":'')+String(v).replaceAll('"','""')+'"';const rows=[['Fleet',d.fleet.name,d.as_of],['Approved claims submitted',d.period.from,d.period.through,d.period.timezone],['Recorded claims only; not payment, balance or physical charging proof'],...['active_members','invited_members','assigned_vehicles','cost_centers'].map(k=>[k,d.current_inventory[k]]),['Cost center','Historic name','Currency','Minor units','Claims','Amount minor'],...d.groups.map(g=>[g.code,g.name,g.currency,g.minor_units,g.claims,g.amount_minor])];const url=URL.createObjectURL(new Blob([rows.map(r=>r.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='fleet-report.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),0);};output.append(csv);
            }catch(error){if(identity===serial&&run===request&&!document.hidden)output.replaceChildren(text('p','Report unavailable. Check access, dates and cost center, then read again.','التقرير غير متاح. تحقق من الصلاحية والتواريخ ومركز التكلفة ثم اقرأ مجدداً.'));}finally{if(identity===serial&&run===request)button.disabled=false;}
        };
    }
    function verifyDirectory(d,page,filters){
        const positive=v=>Number.isSafeInteger(v)&&v>0;
        const count=v=>Number.isSafeInteger(v)&&v>=0;
        const invalid=()=>{throw Error('Invalid fleet directory scope.');};
        if(!d||!positive(page)||page>1000000||d.current_page!==page||d.per_page!==25||
            !count(d.total)||d.last_page!==Math.max(1,Math.ceil(d.total/25))||
            !Array.isArray(d.data)||d.data.length!==Math.min(25,Math.max(0,d.total-(page-1)*25)))invalid();
        let previous=0;
        for(const fleet of d.data){
            if(!fleet||!positive(fleet.id)||fleet.id<=previous||typeof fleet.name!=='string'||
                !fleet.name.length||typeof fleet.country!=='string'||!/^[A-Z]{2}$/.test(fleet.country)||
                (filters.country&&fleet.country!==filters.country)||
                !fleet.inventory||!['active_members','assigned_vehicles','cost_centers'].every(k=>count(fleet.inventory[k]))||
                (fleet.owner!==null&&(!fleet.owner||!positive(fleet.owner.id)||
                    typeof fleet.owner.name!=='string'||typeof fleet.owner.email!=='string'))||
                (filters.owner_id&&fleet.owner?.id!==Number(filters.owner_id)))invalid();
            previous=fleet.id;
        }
        return d;
    }
    function setActor(current,request){
        clear();actor=current;api=request;host=document.getElementById('fleet-admin-report');
        if(!host||actor?.role!=='admin')return;
        roots.add(host);
        const target=host, identity=serial, form=document.createElement('form'), name=document.createElement('input'), country=document.createElement('input');
        name.type='search';name.maxLength=80;country.maxLength=2;
        const ownerId=document.createElement('input');ownerId.type='number';ownerId.min='1';ownerId.step='1';
        for(const [input,en,ar]of[[name,'Fleet name (literal search)','اسم الأسطول (بحث حرفي)'],[country,'Country code (optional)','رمز الدولة (اختياري)'],[ownerId,'Owner account ID (optional)','معرف مالك الأسطول (اختياري)']]){const label=document.createElement('label');label.append(text('span',en,ar),input);form.append(label);}
        const search=text('button','Search fleets','بحث الأساطيل');search.type='submit';form.append(search);
        const rows=document.createElement('div'), slot=document.createElement('div');slot.dataset.fleetReportSlot='';
        let turn=0;
        const openFleet=id=>{if(identity!==serial||document.hidden||!target.isConnected)return;slot.replaceChildren();mount(slot,id);};
        form.addEventListener('input',()=>{
            turn++;
            rows.replaceChildren();
            slot.replaceChildren();
            search.disabled=false;
        });
        async function load(page=1,applied=null){
            if(identity!==serial||document.hidden)return;
            const run=++turn, filters=applied||{q:name.value.trim(),country:country.value.trim().toUpperCase(),include:'owner_inventory'};
            if(!applied&&ownerId.value.trim())filters.owner_id=ownerId.value.trim();
            const params=new URLSearchParams({...filters,page:String(page)});
            rows.replaceChildren();slot.replaceChildren();search.disabled=true;
            try{
                const d=await api(`admin/fleets?${params}`);
                if(identity!==serial||run!==turn||document.hidden||!target.isConnected)return;
                verifyDirectory(d,page,filters);
                rows.append(text('p',`Fleet results: ${d.total}`,`نتائج الأساطيل: ${d.total}`));
                for(const fleet of d.data){const card=document.createElement('article'),open=raw('button',fleet.name);open.type='button';open.onclick=()=>{if(run===turn&&open.isConnected)openFleet(fleet.id);};card.append(open,raw('p',`${fleet.country} · #${fleet.id}`));
                    if(fleet.owner){const owner=text('p','Fleet owner','مالك الأسطول');owner.append(raw('span',`: ${fleet.owner.name} · ${fleet.owner.email} · #${fleet.owner.id}`));card.append(owner);}
                    if(fleet.inventory){const counts=text('p','Active members / vehicles / cost centers','الأعضاء النشطون / المركبات / مراكز التكلفة');counts.append(raw('span',`: ${fleet.inventory.active_members} / ${fleet.inventory.assigned_vehicles} / ${fleet.inventory.cost_centers}`));card.append(counts);}
                    rows.append(card);}
                for(const [enabled,pageNumber,en,ar]of[[d.current_page>1,d.current_page-1,'Previous','السابق'],[d.current_page<d.last_page,d.current_page+1,'Next','التالي']]){if(!enabled)continue;const button=text('button',en,ar);button.type='button';button.onclick=()=>{if(run===turn&&button.isConnected)load(pageNumber,filters);};rows.append(button);}
            }catch(error){if(identity===serial&&run===turn&&!document.hidden)rows.replaceChildren(text('p','Fleet directory unavailable. Check filters and current access, then search again.','دليل الأساطيل غير متاح. تحقق من المرشحات والصلاحية الحالية ثم ابحث مجدداً.'));}
            finally{if(identity===serial&&run===turn)search.disabled=false;}
        }
        form.onsubmit=e=>{e.preventDefault();load();};
        const id=document.createElement('input');id.type='number';id.min='1';const label=document.createElement('label');label.append(text('span','Fleet ID','معرف الأسطول'),id);
        const open=text('button','Open fleet reporting','فتح تقارير الأسطول');open.type='button';open.onclick=()=>{if(/^[1-9][0-9]{0,17}$/.test(id.value))openFleet(id.value);};
        target.append(form,rows,label,open,slot);
    }
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)return;const mounts=[...roots].filter(r=>r.isConnected&&r.dataset.fleetReportId).map(r=>[r,r.dataset.fleetReportId]);setActor(actor,api);for(const [root,id]of mounts){if(root.isConnected)mount(root,id);}});window.addEventListener('pagehide',clear);
    return {setActor,mount};
})();
