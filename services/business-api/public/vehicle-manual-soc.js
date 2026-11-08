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
    let chosen=null, latest=null, history=null, page=1, pending=null, submitting=false, submitAttempt=0;
    const alive = token=>account===actor()&&session===epoch()&&generation===token;
    function clear() {
      generation++;account=null;chosen=latest=history=pending=null;
      submitting=false;submitAttempt++;page=1;root.replaceChildren();
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
      // Reusing the exact same actor object after a new authentication
      // session must never preserve old vehicle readings or draft reports.
      if (account===user && session===epoch())return;
      clear();account=user;session=epoch();
    }
    function renderGarage(vehicles) {
      if (actor()!==account||session!==epoch()) {clear();return;}
      if (account?.role!=="customer")return;
      // A background garage refresh must not discard an in-flight report or
      // pending idempotency key for a vehicle that still belongs to this user.
      const sameVehicle = chosen && vehicles.find(v => String(v.id) === String(chosen.id));
      const previousStatus = sameVehicle ? root.querySelector("[data-manual-soc-status]") : null;
      const previousPanel = sameVehicle ? root.querySelector("[data-manual-soc-panel]") : null;
      if (sameVehicle) {
        chosen = sameVehicle;
      } else {
        generation++;chosen=latest=history=pending=null;submitting=false;submitAttempt++;page=1;
      }
      root.replaceChildren(tr("Manual battery reports","تقارير البطارية اليدوية","h3"),
        tr("Battery values are your own estimates, never measured by the phone, vehicle API or charger. Reports are not used to start/stop charging, authorize reservations or calculate invoices.",
        "قيم البطارية تقديرات منك وليست قياسات الهاتف أو واجهة المركبة أو الشاحن. لا تستخدم التقارير لبدء أو إيقاف الشحن أو السماح بالحجز أو احتساب الفواتير.","p"));
      if (!vehicles.length)root.append(tr("Add a vehicle before reporting battery state.",
        "أضف مركبة أولاً لتقديم تقرير عن البطارية.","p"));
      for (const vehicle of vehicles)
        root.append(button("Report battery for "+vehicle.name,"تسجيل بطارية "+vehicle.name,
          ()=>open(vehicle)));
      if (previousStatus) root.append(previousStatus);
      if (previousPanel) root.append(previousPanel);
    }
    const reportState=(request,current,history,vehicleId)=>{
      if(!request||!current||!history||
          !Number.isInteger(request.expected_revision))return null;
      const expectedRevision=request.expected_revision+1;
      const inHistory=history.data?.some(row=>
        row.vehicle_id===vehicleId &&
        row.revision===expectedRevision &&
        row.battery_percent===request.battery_percent &&
        row.source==="CUSTOMER_MANUAL_SELF_REPORT")===true;
      if(!inHistory || current.vehicle_id!==vehicleId ||
          !Number.isInteger(current.revision))return null;
      if(current.revision===expectedRevision)
        return current.battery_percent===request.battery_percent?"current":null;
      // Another self-report may have superseded ours. The old observation
      // remains safely confirmed by its exact source-qualified history row.
      return current.revision>expectedRevision?"superseded":null;
    };
    const recordedStatus=state=>state==="superseded"
      ? (ar()?"تم حفظ تقريرك، لكن يوجد تقرير أحدث للبطارية." :
        "Manual report saved; a newer battery report is now the latest.")
      : (ar()?"تم حفظ التقرير اليدوي." : "Manual report saved.");
    async function open(vehicle) {
      if (actor()!==account||session!==epoch()) {clear();return;}
      if (actor()?.role!=="customer"||!account)return;
      const token=++generation;
      // Hide the old vehicle's editable panel immediately. A slow new GET
      // must not leave a previous form capable of submitting to new selection.
      root.querySelector("[data-manual-soc-panel]")?.remove();
      // Refreshing the SAME vehicle must not erase a possibly committed
      // observation's idempotency identity. Switching vehicles still does.
      const oldPending=chosen&&String(chosen.id)===String(vehicle.id)?pending:null;
      chosen=vehicle;latest=history=null;pending=oldPending;
      submitting=false;submitAttempt++;page=1;
      status(ar()?"جارٍ تحميل بيانات البطارية...":"Loading manual battery reports…");
      try {
        const response=await api("vehicles/"+vehicle.id+"/manual-soc");
        if(!alive(token))return;
        latest=response;
        await loadHistory(1);
      }catch(e){if(alive(token))status(String(e.message||e));}
    }
    async function loadHistory(target=page) {
      if(actor()!==account||session!==epoch()){clear();return false;}
      if(!chosen||!account)return false;
      const token=++generation;
      page=target;
      try {
        const result=await api("vehicles/"+chosen.id+"/manual-soc/history?page="+page);
        if(!alive(token))return;
        history=result;
        const recorded=pending?reportState(pending,latest,history,chosen.id):null;
        if(recorded){
          pending=null;render();
          status(recordedStatus(recorded));
        } else {
          render();
          status(pending
            ? (ar()?"نتيجة التقرير غير مؤكدة؛ راجع السجل أو أعد نفس التقرير." :
              "Result uncertain. Check history or retry the identical report.")
            : "");
        }
        return true;
      }catch(e){if(alive(token))status(String(e.message||e));return false;}
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
      const formTicket=generation, formVehicleId=chosen.id;
      const editable=()=>alive(formTicket)&&chosen?.id===formVehicleId&&latest!==null;
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
      const retry=button("Retry the same report","إعادة إرسال نفس التقرير",
        ()=>{if(editable())return submitReport();});
      retry.disabled=pending===null||submitting;
      form.append(retry);
      form.append(button("Clear pending after reviewing history","مسح المعلق بعد مراجعة السجل",
        ()=>{if(!editable())return;pending=null;render();status("");}));
      form.onsubmit=event=>{
        event.preventDefault();
        if(!editable())return;
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
        return submitReport();
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
      panel.append(button("Refresh source and history","تحديث المصدر والسجل",
        ()=>{if(editable())return open(chosen);}));
      panel.append(button("Close","إغلاق",()=>panel.remove()));
      root.append(panel);
    }
    async function submitReport() {
      if(submitting||!pending||!chosen||!latest||!account)return;
      const token=generation, request=Object.freeze({...pending}), vehicleId=chosen.id;
      submitting=true;
      const attempt=++submitAttempt;
      status(ar()?"جارٍ إرسال التقرير..." : "Saving self-reported battery…");
      try {
        await api("vehicles/"+vehicleId+"/manual-soc","POST",request);
        if(!alive(token)||chosen?.id!==vehicleId)return;
        // A successful POST does not prove that the subsequent read-back will
        // complete. Retain the original operation key through BOTH GETs so
        // an uncertain refresh can safely replay the same observation.
        const refreshed=await api("vehicles/"+vehicleId+"/manual-soc");
        if(!alive(token)||chosen?.id!==vehicleId)return;
        latest=refreshed;
        const reload=loadHistory(1), reloadTicket=generation;
        const loaded=await reload;
        if(!alive(reloadTicket)||chosen?.id!==vehicleId)return;
        if(!loaded){
          submitting=false;
          render(); // Restore the same-key retry control after history failure.
          status(ar()?"تعذّر تحديث السجل. راجع السجل أو أعد نفس التقرير." :
            "History refresh uncertain. Check history or retry the identical report.");
          return;
        }
        // Successful HTTP/refresh responses are insufficient unless BOTH
        // authoritative readbacks contain this exact user observation.
        const recorded=reportState(request,refreshed,history,vehicleId);
        if(!recorded){
          submitting=false;
          render();
          status(ar()?"لم يتم تأكيد التقرير من القراءة والسجل. أعد نفس التقرير." :
            "Result uncertain. Current reading/history did not confirm this report; retry the same report.");
          return;
        }
        pending=null;submitting=false;
        render(); // The successful report must no longer offer a replay button.
        status(recordedStatus(recorded));
      }catch(e) {
        if(!alive(token))return;
        submitting=false;
        status(ar()?"تعذرت معرفة النتيجة. راجع السجل أو أعد نفس التقرير." :
          "Result uncertain. Check history or retry the identical report. "+String(e.message||e));
        render();
      }finally{
        if(submitAttempt===attempt && account===actor() && session===epoch() && chosen?.id===vehicleId)
          submitting=false;
      }
    }
    return {clear,setActor,renderGarage,open,loadHistory};
  }
  window.VehicleManualSoc={create};
})();
