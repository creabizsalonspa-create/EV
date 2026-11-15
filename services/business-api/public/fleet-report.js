/* Private owner/Admin report; no payment or charger operations. */
window.EvFleetReport = (() => {
    let serial=0, actor=null, api=null, host=null;
    const roots=new Set();
    const text=(tag,en,ar=en)=>{const n=document.createElement(tag);n.dataset.en=en;n.dataset.ar=ar;n.textContent=document.documentElement.lang==='ar'?ar:en;return n;};
    const raw=(tag,value)=>{const n=document.createElement(tag);n.textContent=String(value);return n;};
    function clear(){serial++;for(const root of roots)root.replaceChildren();roots.clear();}
    function mount(root,id){
        for(const old of roots)if(!old.isConnected)roots.delete(old);
        roots.add(root);root.dataset.fleetReportId=String(id);const identity=serial;
        const form=document.createElement('form'), from=document.createElement('input'), through=document.createElement('input'), center=document.createElement('input');
        for(const [field,en,ar]of[[from,'From local date','من التاريخ المحلي'],[through,'Through local date','حتى التاريخ المحلي'],[center,'Cost center ID (optional)','معرف مركز التكلفة (اختياري)']]){const label=text('label',en,ar);label.append(field);form.append(label);}
        from.type=through.type='date';from.required=through.required=true;center.type='number';center.min='1';
        const now=new Date().toISOString().slice(0,10);from.value=now.slice(0,8)+'01';through.value=now;
        const button=text('button','Read fleet report','قراءة تقرير الأسطول');button.type='submit';form.append(button);
        const output=document.createElement('div');root.append(text('p','Approved claims explicitly shared with this fleet only. Amounts are recorded claims, not balances, payments, refunds or proof of physical charging. Inventory counts are current and cover all cost centers. Dates use the fleet timezone.','المطالبات المعتمدة التي تمت مشاركتها صراحة مع الأسطول فقط. المبالغ مطالبات مسجلة وليست أرصدة أو مدفوعات أو مبالغ مستردة أو دليلاً على الشحن الفعلي. أعداد المخزون حالية وتشمل جميع مراكز التكلفة. التواريخ حسب منطقة الأسطول الزمنية.'),form,output);
        let request=0;
        form.onsubmit=async e=>{e.preventDefault();const run=++request;output.replaceChildren();button.disabled=true;
            const query=new URLSearchParams({from:from.value,through:through.value});if(center.value)query.set('cost_center_id',center.value);
            try{const d=await api(`fleets/${id}/report?${query}`);
                if(identity!==serial||run!==request||document.hidden||!root.isConnected)return;
                output.append(raw('h4',d.fleet.name),raw('p',`${d.period.from} — ${d.period.through} · ${d.period.timezone} · ${d.as_of}`));
                const labels={active_members:['Active members','الأعضاء النشطون'],invited_members:['Pending invitations','الدعوات المعلقة'],assigned_vehicles:['Assigned vehicles','المركبات المسندة'],cost_centers:['Cost centers','مراكز التكلفة']};
                for(const [key,pair]of Object.entries(labels)){const line=text('p',...pair);line.append(raw('span',`: ${d.current_inventory[key]}`));output.append(line);}
                output.append(text('p',`Approved claims: ${d.approved_claims}`,`المطالبات المعتمدة: ${d.approved_claims}`));
                for(const g of d.groups){const amount=String(g.amount_minor).padStart(g.minor_units+1,'0');const formatted=g.minor_units?amount.slice(0,-g.minor_units)+'.'+amount.slice(-g.minor_units):amount;output.append(raw('p',`${g.code} · ${g.name} · ${g.currency} ${formatted} · ${g.claims}`));}
                const csv=text('button','Download private CSV snapshot','تنزيل لقطة CSV خاصة');csv.type='button';csv.onclick=()=>{if(identity!==serial||document.hidden)return;const cell=v=>'"'+(/^[\s\uFEFF]*[=+@-]/u.test(String(v))?"'":'')+String(v).replaceAll('"','""')+'"';const rows=[['Fleet',d.fleet.name,d.as_of],['Approved claims submitted',d.period.from,d.period.through,d.period.timezone],['Recorded claims only; not payment, balance or physical charging proof'],...Object.entries(d.current_inventory),['Cost center','Historic name','Currency','Minor units','Claims','Amount minor'],...d.groups.map(g=>[g.code,g.name,g.currency,g.minor_units,g.claims,g.amount_minor])];const url=URL.createObjectURL(new Blob([rows.map(r=>r.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='fleet-report.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),0);};output.append(csv);
            }catch(error){if(identity===serial&&run===request&&!document.hidden)output.replaceChildren(text('p','Report unavailable. Check access, dates and cost center, then read again.','التقرير غير متاح. تحقق من الصلاحية والتواريخ ومركز التكلفة ثم اقرأ مجدداً.'));}finally{if(identity===serial&&run===request)button.disabled=false;}
        };
    }
    function setActor(current,request){clear();actor=current;api=request;host=document.getElementById('fleet-admin-report');if(!host||actor?.role!=='admin')return;roots.add(host);const id=document.createElement('input');id.type='number';id.min='1';const label=text('label','Fleet ID','معرف الأسطول');label.append(id);const open=text('button','Open fleet reporting','فتح تقارير الأسطول');open.type='button';const slot=document.createElement('div');open.onclick=()=>{slot.replaceChildren();if(/^[1-9][0-9]{0,17}$/.test(id.value))mount(slot,id.value);};host.append(label,open,slot);}
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)return;const mounts=[...roots].filter(r=>r.isConnected&&r.dataset.fleetReportId).map(r=>[r,r.dataset.fleetReportId]);setActor(actor,api);for(const [root,id]of mounts){if(root.isConnected)mount(root,id);}});window.addEventListener('pagehide',clear);
    return {setActor,mount};
})();
