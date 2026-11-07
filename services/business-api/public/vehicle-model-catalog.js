/* Admin-curated catalog; not manufacturer-certified connector compatibility. */
(function () {
  "use strict";
  const tr = (en, ar, tag = "span") => {
    const n = document.createElement(tag);
    n.dataset.en = en; n.dataset.ar = ar;
    n.textContent = document.documentElement.lang === "ar" ? ar : en;
    return n;
  };
  const isAr = () => document.documentElement.lang === "ar";
  const button = (en, ar, fn) => {
    const n = document.createElement("button");
    n.type = "button"; n.append(tr(en,ar)); n.onclick = fn;
    return n;
  };
  const text = v => typeof v === "string" ? v : "";
  const title = row => [row.make, row.model, row.variant, row.model_year].filter(v=>v !== null && v !== "").join(" ") + " · " + row.connector_type;
  const controls = [
    ["make","Make / brand","العلامة التجارية","text",80],
    ["model","Model","الطراز","text",100],
    ["variant","Variant (optional)","الفئة (اختياري)","text",100],
    ["model_year","Model year (optional)","سنة الطراز (اختياري)","number",4],
    ["source_url","Administrator-provided reference URL (https://)","رابط المرجع الرسمي (https://)","url",500],
  ];
  function create({adminRoot, customerRoot, api, actor, epoch}) {
    let current = null, session = 0, generation = 0;
    let adminPage = 1, adminQuery = "", adminEdit = null;
    let selectedVehicle = null, selectedLink = null;
    let modelPage = 1, modelQuery = "", models = [];

    const valid = ticket => actor() === current && current != null &&
      epoch() === session && generation === ticket;

    function clear() {
      generation++;
      current = null; adminEdit = null; selectedVehicle = selectedLink = null;
      models = []; adminPage = modelPage = 1; adminQuery = modelQuery = "";
      adminRoot.replaceChildren(); customerRoot.replaceChildren();
    }

    function message(root, value) {
      let n = root.querySelector("[data-vehicle-model-message]");
      if (!n) {
        n = document.createElement("p");
        n.dataset.vehicleModelMessage = "yes";
        n.setAttribute("role","status");
        n.setAttribute("aria-live","polite");
        root.prepend(n);
      }
      n.textContent = value;
    }

    async function setActor(value) {
      if (!value || !["customer","admin"].includes(value.role)) { clear(); return; }
      if (current === value) return;
      clear();
      current = value; session = epoch();
      if (value.role === "admin") await adminList(1);
    }

    function renderGarage(vehicles) {
      if (current?.role !== "customer" || actor() !== current) return;
      // Retain same-account in-flight selection when the garage refreshes
      // independently (e.g. vehicle creation). A removed/changed vehicle
      // invalidates all outstanding responses and drops its private panel.
      const stillOwned = selectedVehicle && vehicles.find(v =>
        String(v.id) === String(selectedVehicle.id) &&
        v.connector_type === selectedVehicle.connector_type);
      const status = stillOwned
        ? customerRoot.querySelector("[data-vehicle-model-message]") : null;
      const panel = stillOwned
        ? customerRoot.querySelector("[data-vehicle-model-picker]") : null;
      if (stillOwned) selectedVehicle = stillOwned;
      else {
        generation++;
        selectedVehicle = selectedLink = null;
      }
      customerRoot.replaceChildren(tr(
        "My vehicle model catalog", "كتالوج طراز مركبتي", "h3",
      ), tr(
        "Choose an Admin-curated model for an existing vehicle. The displayed connector is a catalog claim, not manufacturer certification.",
        "اختر طرازًا من كتالوج الإدارة لمركبة موجودة. الموصل المعروض مصدره الكتالوج وليس شهادة من الشركة المصنعة.",
        "p",
      ));
      if (!vehicles.length) {
        customerRoot.append(tr("Add a vehicle above before choosing a model.", "أضف مركبة أولاً لاختيار الطراز.", "p"));
        return;
      }
      const list = document.createElement("ul");
      for (const v of vehicles) {
        const item = document.createElement("li");
        item.append(tr(v.name + " (" + v.connector_type + ")", v.name + " (" + v.connector_type + ")"));
        const choose = button("Select catalog model", "اختيار طراز من الكتالوج", () => openVehicle(v));
        item.append(choose); list.append(item);
      }
      customerRoot.append(list);
      if (status) customerRoot.append(status);
      if (panel) customerRoot.append(panel);
    }

    async function openVehicle(vehicle) {
      if (actor()?.role !== "customer" || !current) return;
      const ticket = ++generation;
      const owner = current, e = session;
      selectedVehicle = vehicle;
      selectedLink = null;
      modelPage = 1; modelQuery = "";
      message(customerRoot, isAr() ? "جارٍ تحميل طراز المركبة..." : "Loading vehicle model…");
      try {
        const info = await api("vehicles/" + vehicle.id + "/model");
        if (!valid(ticket) || owner !== current || e !== session) return;
        selectedLink = info;
        await modelList(1);
      } catch (err) {
        if (valid(ticket)) message(customerRoot, text(err.message) || String(err));
      }
    }

    async function modelList(page) {
      if (!selectedVehicle || current?.role !== "customer") return;
      const ticket = ++generation;
      modelPage = page;
      message(customerRoot, isAr() ? "جارٍ تحميل النماذج..." : "Loading curated models…");
      try {
        const query = new URLSearchParams({page:String(page)});
        if (modelQuery) query.set("q", modelQuery);
        query.set("connector_type", selectedVehicle.connector_type);
        const list = await api("vehicle-models?" + query.toString());
        if (!valid(ticket)) return;
        models = list.data.filter(m=>m.published === true &&
          m.connector_type === selectedVehicle.connector_type);
        renderCustomerModels(list);
      } catch (err) {
        if (valid(ticket)) message(customerRoot, text(err.message) || String(err));
      }
    }

    function renderCustomerModels(list) {
      customerRoot.querySelector("[data-vehicle-model-picker]")?.remove();
      const panel = document.createElement("section");
      panel.dataset.vehicleModelPicker = "yes";
      panel.append(tr("Catalog choice · " + selectedVehicle.name, "اختيار الطراز · " + selectedVehicle.name, "h4"));
      const linked = selectedLink?.model;
      if (linked) {
        panel.append(tr("Current: " + title(linked) + (linked.published ? "" : " (archived)"),
          "الحالي: " + title(linked) + (linked.published ? "" : " (مؤرشف)"), "p"));
      } else {
        panel.append(tr("No catalog model associated.", "لم يتم ربط طراز من الكتالوج.", "p"));
      }
      panel.append(tr("Only models on this catalog page with the vehicle connector are offered. Search other models by name.",
        "تظهر طرازات هذه الصفحة المتوافقة مع موصل المركبة فقط؛ ابحث بالاسم عن طرازات أخرى.", "p"));
      const form = document.createElement("form");
      const search = document.createElement("input");
      search.type = "search"; search.maxLength = 100; search.value = modelQuery;
      search.setAttribute("aria-label", "Search vehicle model / البحث عن طراز المركبة");
      form.append(search, button("Search catalog", "البحث في الكتالوج", () => {
        modelQuery = search.value.trim();
        modelList(1);
      }));
      form.onsubmit = event => {event.preventDefault();modelQuery=search.value.trim();modelList(1);};
      panel.append(form);
      const select = document.createElement("select");
      select.setAttribute("aria-label", "Catalog model / طراز الكتالوج");
      for (const model of models) {
        const option = document.createElement("option");
        option.value = String(model.id); option.textContent = title(model);
        select.append(option);
      }
      // Preserve the linked model after a refresh or successful save rather
      // than silently selecting a different (first) model in the list.
      if (models.some(model => model.id === linked?.id))
        select.value = String(linked.id);
      panel.append(select);
      const reference = document.createElement("a");
      reference.target = "_blank";
      reference.rel = "noopener noreferrer";
      reference.setAttribute("aria-label", "Admin-provided model reference / مرجع الطراز من الإدارة");
      const showReference = () => {
        const model = models.find(m => String(m.id) === select.value);
        const url = typeof model?.source_url === "string" &&
          model.source_url.startsWith("https://") ? model.source_url : "";
        reference.hidden = !url;
        reference.href = url || "#";
        reference.textContent = url
          ? (isAr() ? "المرجع المقدم من الإدارة: " : "Admin-provided reference: ") + url : "";
      };
      select.onchange = showReference;
      showReference();
      panel.append(reference);
      if (!models.length) panel.append(tr("No compatible published models on this page.",
        "لا توجد طرازات منشورة متوافقة في هذه الصفحة.", "p"));
      const save = button("Link selected model", "ربط الطراز المحدد", async () => {
        if (!select.value || save.disabled) return;
        save.disabled = true;
        const ticket = generation, modelId = Number(select.value);
        try {
          selectedLink = await api("vehicles/" + selectedVehicle.id + "/model","PUT",{
            model_id:modelId, expected_model_id:selectedLink?.model_id ?? null,
          });
          if (!valid(ticket)) return;
          renderCustomerModels(list);
          message(customerRoot,isAr() ? "تم ربط الطراز." : "Model linked.");
        } catch (e) {
          if (valid(ticket)) message(customerRoot,e.status === 409
            ? (isAr() ? "تغير الربط؛ حدّث قبل الحفظ." : "Link changed; refresh before saving.")
            : text(e.message) || String(e));
        } finally {save.disabled=false;}
      });
      save.disabled = !models.length;
      panel.append(save);
      panel.append(button("Remove model association", "إزالة ارتباط الطراز", async () => {
        const ticket = generation;
        try {
          selectedLink = await api("vehicles/" + selectedVehicle.id + "/model","PUT",{
            model_id:null,expected_model_id:selectedLink?.model_id ?? null,
          });
          if (!valid(ticket)) return;
          renderCustomerModels(list);
          message(customerRoot,isAr() ? "تمت إزالة ارتباط الطراز." : "Model link removed.");
        } catch (e) {
          if (valid(ticket)) message(customerRoot,text(e.message) || String(e));
        }
      }));
      if (modelPage > 1) panel.append(button("Previous", "السابق", () => modelList(modelPage-1)));
      if (modelPage < list.last_page) panel.append(button("Next", "التالي", () => modelList(modelPage+1)));
      panel.append(button("Refresh vehicle link", "تحديث ارتباط المركبة", () => openVehicle(selectedVehicle)));
      panel.append(button("Close model picker", "إغلاق", () => panel.remove()));
      customerRoot.append(panel);
    }

    async function adminList(page = adminPage) {
      if (current?.role !== "admin") return;
      const ticket = ++generation;
      adminPage = page;
      adminRoot.replaceChildren();
      message(adminRoot, isAr() ? "جارٍ تحميل كتالوج الإدارة..." : "Loading Admin catalog…");
      try {
        const params = new URLSearchParams({page:String(page),include_archived:"1"});
        if (adminQuery) params.set("q",adminQuery);
        const result = await api("vehicle-models?" + params);
        if (!valid(ticket)) return;
        renderAdmin(result);
        message(adminRoot,"");
      } catch (err) {
        if (valid(ticket)) message(adminRoot,text(err.message) || String(err));
      }
    }

    function renderAdmin(result) {
      adminRoot.replaceChildren(tr("Curated vehicle model catalog", "كتالوج طرازات المركبات", "h3"));
      adminRoot.append(tr(
        "Admin-sourced records only. Source URLs must be HTTPS; publishing is not manufacturer certification.",
        "السجلات مصدرها الإدارة فقط. يجب أن تكون روابط المصادر HTTPS؛ النشر ليس شهادة من الشركة المصنعة.", "p",
      ));
      const search = document.createElement("form");
      const query = document.createElement("input");
      query.type = "search"; query.maxLength=100; query.value=adminQuery;
      query.setAttribute("aria-label","Search catalog / البحث في الكتالوج");
      search.append(query,button("Search", "بحث", () => {adminQuery=query.value.trim();adminList(1);}));
      search.onsubmit = event => {event.preventDefault();adminQuery=query.value.trim();adminList(1);};
      adminRoot.append(search);
      const form = document.createElement("form");
      form.className = "vehicle-model-admin-form";
      const inputs = {};
      for (const [name,en,ar,type,max] of controls) {
        const label = document.createElement("label");
        label.append(tr(en,ar));
        const n=document.createElement("input");
        n.name=name;n.type=type;n.maxLength=max;
        if (type === "number") {n.min="1990";n.max="2100";}
        if (["make","model","source_url"].includes(name))n.required=true;
        label.append(n);form.append(label);inputs[name]=n;
      }
      const connector = document.createElement("select");
      connector.name="connector_type";
      for (const name of ["CCS2","Type2","CHAdeMO"]) {
        const option=document.createElement("option");
        option.value=name;option.textContent=name;connector.append(option);
      }
      const cLabel=document.createElement("label");
      cLabel.append(tr("Catalog connector claim", "الموصل بحسب الكتالوج"),connector);
      form.append(cLabel);
      const published=document.createElement("input");
      published.type="checkbox";published.name="published";
      const publishLabel=document.createElement("label");
      publishLabel.append(published,tr("Published for customers", "منشور للعملاء"));
      form.append(publishLabel);
      if (adminEdit) {
        for (const [key] of controls)
          inputs[key].value=adminEdit[key] ?? "";
        connector.value=adminEdit.connector_type;
        published.checked=adminEdit.published === true;
      }
      form.append(tr(adminEdit ? "Edit catalog record" : "Create catalog record",
        adminEdit ? "تعديل سجل الكتالوج" : "إضافة سجل الكتالوج", "h4"));
      const submit=document.createElement("button");
      submit.type="submit";submit.append(tr(adminEdit ? "Save changes" : "Create record",
        adminEdit ? "حفظ التغييرات" : "إنشاء السجل"));
      form.append(submit);
      if (adminEdit) form.append(button("Cancel editing", "إلغاء التعديل", () => {adminEdit=null;renderAdmin(result);}));
      form.onsubmit=async event=>{
        event.preventDefault();
        if (submit.disabled) return;
        const body={
          make:inputs.make.value.trim(), model:inputs.model.value.trim(),
          variant:inputs.variant.value.trim()||null,
          model_year:inputs.model_year.value ? Number(inputs.model_year.value):null,
          connector_type:connector.value,
          source_url:inputs.source_url.value.trim(),
          published:published.checked,
        };
        if (adminEdit) body.revision=adminEdit.revision;
        submit.disabled=true;
        const ticket=generation;
        try {
          await api(adminEdit ? "vehicle-models/"+adminEdit.id : "vehicle-models",
            adminEdit ? "PUT" : "POST",body);
          if (!valid(ticket)) return;
          adminEdit=null;await adminList(adminPage);
          message(adminRoot,isAr()?"تم حفظ السجل." : "Catalog entry saved.");
        } catch (e) {
          if (valid(ticket))message(adminRoot,e.status === 409
            ? (isAr()?"تعارض نسخة السجل؛ حدث الصفحة." : "Catalog record changed; refresh before saving.")
            : text(e.message)||String(e));
        } finally {submit.disabled=false;}
      };
      adminRoot.append(form);
      if (!result.data.length) adminRoot.append(tr("No model records found.", "لا توجد سجلات طرازات.", "p"));
      const list=document.createElement("ul");
      for (const row of result.data) {
        const item=document.createElement("li");
        item.append(tr(title(row)+(row.published?" · Published":" · Archived"),
          title(row)+(row.published?" · منشور":" · مؤرشف")));
        item.append(button("Edit", "تعديل", () => {adminEdit=row;renderAdmin(result);}));
        item.append(button(row.published?"Archive":"Publish",
          row.published?"أرشفة":"نشر", async () => {
            try {
              await api("vehicle-models/"+row.id,"PUT",{
                make:row.make,model:row.model,variant:row.variant,model_year:row.model_year,
                connector_type:row.connector_type,source_url:row.source_url,
                published:!row.published,revision:row.revision,
              });
              await adminList(adminPage);
            } catch (err) {message(adminRoot,text(err.message)||String(err));}
          }));
        list.append(item);
      }
      adminRoot.append(list);
      if (adminPage>1) adminRoot.append(button("Previous", "السابق",()=>adminList(adminPage-1)));
      if (adminPage<result.last_page) adminRoot.append(button("Next", "التالي",()=>adminList(adminPage+1)));
    }
    return {clear,setActor,renderGarage,adminList};
  }
  window.VehicleModelCatalog = {create};
})();
