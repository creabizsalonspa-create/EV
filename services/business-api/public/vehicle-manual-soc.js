/* Customer self-reports only. Never infer SOC from phone/device or trigger charging. */
(function () {
  "use strict";
  const tr = (en, ar, tag = "span") => {
    const el = document.createElement(tag);
    el.dataset.en=en;el.dataset.ar=ar;
    el.textContent=document.documentElement.lang==="ar"?ar:en;
    return el;
  };
  const ar = () => document.documentElement.lang==="ar";
  const button = (en,ar,action) => {
    const el=document.createElement("button");
    el.type="button";el.append(tr(en,ar));el.onclick=action;
    return el;
  };
  function create({root,api,actor,epoch}) {
    let account=null, session=0, generation=0;
    let chosen=null, latest=null, history=null, page=1, pending=null;
    const alive = token=>account===actor()&&session===epoch()&&generation===token;
    function clear() {
      generation++;account=null;chosen=latest=history=pending=null;page=1;root.replaceChildren();
    }
    function status(msg) {
      let node=root.querySelector("[data-manual-soc-status]");
      if (!node) {
        node=document.createElement("p");
        node.dataset.manualSocStatus="yes";node.setAttribute("role","status");
        node.setAttribute("aria-live","polite");root.prepend(node);
      }
      node.textContent=msg;
    }
    function setActor(user) {
      if (user?.role!=="customer") {clear();return;}
      if (account===user)return;
      clear();account=user;session=epoch();
    }
    function renderGarage(vehicles) {
      if (account?.role!=="customer"||actor()!==account)return;
      generation++;chosen=latest=history=pending=null;page=1;
      root.replaceChildren(tr("Manual battery reports","تقارير البطارية اليدوية","h3"),
        tr("Battery values are your own estimates, never measured by the phone, vehicle API or charger. Reports are not used to start/stop charging, authorize reservations or calculate invoices.",
        "قيم البطارية تقديرات منك وليست قياسات الهاتف أو واجهة المركبة أو الشاحن. لا تستخدم التقارير لبدء أو إيقاف الشحن أو السماح بالحجز أو احتساب الفواتير.","p"));
      if (!vehicles.length)root.append(tr("Add a vehicle before reporting battery state.",
        "أضف مركبة أولاً لتقديم تقرير عن البطارية.","p"));
      for (const vehicle of vehicles)
        root.append(button("Report battery for "+vehicle.name,"تسجيل بطارية "+vehicle.name,
          ()=>open(vehicle)));
    }
    async function open(vehicle) {
      if (actor()?.role!=="customer"||!account)return;
      const token=++generation;
      chosen=vehicle;latest=history=pending=null;page=1;
      status(ar()?"جارٍ تحميل بيانات البطارية...":"Loading manual battery reports…");
      try {
        const response=await api("vehicles/"+vehicle.id+"/manual-soc");
        if(!alive(token))return;
        latest=response;
        await loadHistory(1);
      }catch(e){if(alive(token))status(String(e.message||e));}
    }
    async function loadHistory(target=page) {
      if(!chosen||!account)return;
      const token=++generation;
      page=target;
      try {
        const result=await api("vehicles/"+chosen.id+"/manual-soc/history?page="+page);
        if(!alive(token))return;
        history=result;render();
      }catch(e){if(alive(token))status(String(e.message||e));}
    }
    function render() {
      root.querySelector("[data-manual-soc-panel]")?.remove();
      if (!chosen||!latest||!history)return;
      const panel=document.createElement("section");
      panel.dataset.manualSocPanel="yes";
      panel.append(tr("Manual report · "+chosen.name,"تقرير يدوي · "+chosen.name,"h4"));
      panel.append(tr("Source: CUSTOMER_MANUAL_SELF_REPORT (unverified).",
        "المصدر: تقرير يدوي من العميل (غير متحقق منه).","p"));
      panel.append(tr(latest.battery_percent===null?"No report yet.":
        "Latest self-reported battery: "+latest.battery_percent+"% · "+(latest.reported_at||""),
        latest.battery_percent===null?"لا يوجد تقرير بعد.":
        "آخر تقدير للبطارية: "+latest.battery_percent+"٪ · "+(latest.reported_at||""),"p"));
      panel.append(tr("Battery may have changed since the report. Not an actual measurement.",
        "قد تكون حالة البطارية تغيرت منذ التقرير. هذه ليست قراءة فعلية.","p"));
      const form=document.createElement("form");
      form.className="vehicle-manual-soc-form";
      const field=document.createElement("input");
      field.type="number";field.min="0";field.max="100";field.step="1";field.required=true;
      field.name="battery_percent";field.setAttribute("aria-label","Self-reported battery % / تقدير البطارية");
      const label=document.createElement("label");
      label.append(tr("My battery estimate (0–100%)","تقديري للبطارية (٠–١٠٠٪)"),field);
      form.append(label);
      const submit=document.createElement("button");submit.type="submit";
      submit.append(tr("Save manual report","حفظ التقرير اليدوي"));form.append(submit);
      const retry=button("Retry the same report","إعادة إرسال نفس التقرير",()=>submitReport());
      retry.disabled=pending===null;
      form.append(retry);
      form.append(button("Clear pending after reviewing history","مسح المعلق بعد مراجعة السجل",
        ()=>{pending=null;render();status("");}));
      form.onsubmit=event=>{
        event.preventDefault();
        if(pending)return submitReport();
        const number=Number(field.value);
        if(!Number.isInteger(number)||number<0||number>100) {
          status(ar()?"اختر نسبة بين 0 و100.":"Choose 0–100%.");return;
        }
        if(!crypto?.randomUUID){status("Secure operation identity unavailable.");return;}
        pending={
          operation_key:crypto.randomUUID(),
          expected_revision:latest.revision,
          battery_percent:number,
        };
        submitReport();
      };
      panel.append(form);
      panel.append(tr("Previous manual reports","التقارير اليدوية السابقة","h4"));
      if(!history.data?.length)panel.append(tr("No manual report history yet.",
        "لا يوجد سجل تقارير يدوي بعد.","p"));
      const list=document.createElement("ul");
      for(const item of history.data||[])list.append(tr(
        item.battery_percent+"% · "+item.reported_at+" · Revision "+item.revision,
        item.battery_percent+"٪ · "+item.reported_at+" · النسخة "+item.revision,"li"));
      panel.append(list);
      if(page>1)panel.append(button("Previous","السابق",()=>loadHistory(page-1)));
      if(page<history.last_page)panel.append(button("Next","التالي",()=>loadHistory(page+1)));
      panel.append(button("Refresh source and history","تحديث المصدر والسجل",()=>open(chosen)));
      panel.append(button("Close","إغلاق",()=>panel.remove()));
      root.append(panel);
    }
    async function submitReport() {
      if(!pending||!chosen||!latest||!account)return;
      const token=generation, request=Object.freeze({...pending}), vehicleId=chosen.id;
      status(ar()?"جارٍ إرسال التقرير..." : "Saving self-reported battery…");
      try {
        const result=await api("vehicles/"+vehicleId+"/manual-soc","POST",request);
        if(!alive(token))return;
        pending=null;
        // A replay can refer to an old report. Always reload current state.
        latest=await api("vehicles/"+vehicleId+"/manual-soc");
        if(!alive(token))return;
        await loadHistory(1);
        if(alive(generation))status(ar()?"تم حفظ التقرير اليدوي." : "Manual report saved.");
      }catch(e) {
        if(!alive(token))return;
        status(ar()?"تعذرت معرفة النتيجة. راجع السجل أو أعد نفس التقرير." :
          "Result uncertain. Check history or retry the identical report. "+String(e.message||e));
        render();
      }
    }
    return {clear,setActor,renderGarage,open,loadHistory};
  }
  window.VehicleManualSoc={create};
})();
