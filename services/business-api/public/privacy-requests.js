/* Private, account-scoped privacy review requests. No automatic deletion. */
(function () {
  "use strict";
  const ar = () => document.documentElement.lang === "ar";
  const text = (en, arabic, tag = "span") => {
    const n = document.createElement(tag);
    n.dataset.en = en;
    n.dataset.ar = arabic;
    n.textContent = ar() ? arabic : en;
    return n;
  };
  const p = (en, arabic) => text(en, arabic, "p");
  const btn = (en, arabic, action) => {
    const n = document.createElement("button");
    n.type = "button"; n.append(text(en, arabic)); n.onclick = action;
    return n;
  };
  const ownLabel = type => type === "account_deletion"
    ? ["Account deletion review", "مراجعة طلب حذف الحساب"]
    : ["Personal data access", "طلب الوصول للبيانات الشخصية"];
  const safe = v => typeof v === "string" ? v : "";
  const key = () => {
    if (!globalThis.crypto?.getRandomValues) return null;
    return [...globalThis.crypto.getRandomValues(new Uint8Array(16))]
      .map(n => n.toString(16).padStart(2, "0")).join("");
  };
  function createPrivacyWorkspace({selfRoot, adminRoot, api, actor}) {
    let current = null, sequence = 0, pending = null, ownPage = 1, adminPage = 1;
    const alive = (owner, ticket) => owner === current && actor() === owner && ticket === sequence;
    function clear() {
      sequence++; current = pending = null; ownPage = adminPage = 1;
      selfRoot.replaceChildren(); adminRoot.replaceChildren();
    }
    function errorOn(root, message) {
      let line = root.querySelector("[data-privacy-message]");
      if (!line) {line = document.createElement("p"); line.dataset.privacyMessage = "yes"; line.setAttribute("role","status");line.setAttribute("aria-live","polite");root.prepend(line);}
      line.textContent = message;
    }
    const statusNames = {
    submitted:["Submitted","تم التقديم"], in_review:["Under review","قيد المراجعة"],
    closed:["Review closed","أُغلقت المراجعة"], withdrawn:["Withdrawn","تم السحب"],
  };
  const outcomeNames = {
    referred:["Referred to compliance","أُحيل للامتثال"],
    declined:["Declined after review","رُفض بعد المراجعة"],
    review_documented:["Review documented","المراجعة موثقة"],
  };
  const pair = (table, key) => table[key] || [safe(key),safe(key)];
    function oneRow(row, onWithdraw) {
      const card = document.createElement("article");
      card.className = "privacy-request-card";
      const labels = ownLabel(row.request_type);
      card.append(text(labels[0], labels[1], "h4"));
      const state = pair(statusNames,row.status);
      card.append(p("Status: " + state[0] + " · Request #" + row.id, "الحالة: " + state[1] + " · الطلب #" + row.id));
      if (row.reason) card.append(p("Reason: " + row.reason, "السبب: " + row.reason));
      if (row.resolution) {
        const outcome = pair(outcomeNames,row.resolution);
        card.append(p("Review outcome: " + outcome[0] + ". This is not proof of data deletion.", "نتيجة المراجعة: " + outcome[1] + ". ليست إثباتاً لحذف البيانات."));
      }
      if (row.decision_note) card.append(p("Review note: " + row.decision_note, "ملاحظة المراجعة: " + row.decision_note));
      if (onWithdraw && ["submitted","in_review"].includes(row.status))
        card.append(btn("Withdraw request", "سحب الطلب", () => onWithdraw(row.id)));
      return card;
    }
    async function refreshMine(target = ownPage) {
      const owner = current, ticket = ++sequence;
      if (!owner) return;
      ownPage = target; selfRoot.replaceChildren();
      selfRoot.append(text("Privacy and data requests", "طلبات الخصوصية والبيانات", "h3"));
      selfRoot.append(p("Request your personal data or ask for account deletion review. Submitting does not delete accounts, invoices or records.", "اطلب بياناتك أو مراجعة حذف الحساب. تقديم الطلب لا يحذف الحسابات أو الفواتير أو السجلات."));
      const form = document.createElement("form");
      form.className = "privacy-request-form";
      const typeLabel = document.createElement("label");
      typeLabel.append(text("Request type", "نوع الطلب"));
      const type = document.createElement("select"); type.name = "request_type";
      for (const v of ["data_access","account_deletion"]) {
        const option = document.createElement("option");
        option.value = v; option.textContent = ar() ? ownLabel(v)[1] : ownLabel(v)[0];
        type.append(option);
      }
      if (pending) type.value = pending.body.request_type;
      typeLabel.append(type); form.append(typeLabel);
      const reasonLabel = document.createElement("label");
      reasonLabel.append(text("Optional explanation (up to 500 characters)", "توضيح اختياري (حتى ٥٠٠ حرف)"));
      const reason = document.createElement("textarea");
      reason.name = "reason"; reason.maxLength = 500; reason.rows = 3;
      if (pending) reason.value = pending.body.reason || "";
      reasonLabel.append(reason); form.append(reasonLabel);
      const consentLabel = document.createElement("label");
      const consent = document.createElement("input"); consent.type = "checkbox"; consent.required = true;
      consentLabel.append(consent, text("I understand this is a review request, not instant account deletion.", "أفهم أن هذا طلب مراجعة وليس حذفاً فورياً للحساب."));
      form.append(consentLabel);
      const submit = document.createElement("button");
      submit.type = "submit"; submit.append(text("Submit request", "إرسال الطلب")); form.append(submit);
      form.append(btn("Start a different request", "بدء طلب مختلف", () => {pending = null; form.reset(); errorOn(selfRoot,"");}));
      form.onsubmit = async event => {
        event.preventDefault();
        if (!alive(owner, ticket)) return;
        if (!consent.checked) {errorOn(selfRoot, ar() ? "أكد فهمك أولاً." : "Confirm the review notice first.");return;}
        const body = {request_type:type.value, reason:reason.value.trim() || null};
        if (pending && JSON.stringify(body) !== JSON.stringify(pending.body)) {
          errorOn(selfRoot, ar() ? "أعد نفس الطلب أو اختر بدء طلب مختلف." : "Retry the original request or start a different one.");
          return;
        }
        if (!pending) {
          const id = key();
          if (!id) {errorOn(selfRoot, ar() ? "إنشاء معرف آمن غير متاح." : "Secure request identity unavailable.");return;}
          pending = {body, id};
        }
        submit.disabled = true;
        errorOn(selfRoot, ar() ? "جارٍ إرسال الطلب..." : "Submitting request…");
        try {
          const recorded = await api("privacy-requests", "POST", {...pending.body, idempotency_key:pending.id});
          if (!alive(owner, ticket)) return;
          // A successful write followed by a failed read must retain the
          // original idempotency key. Only clear it after the committed row
          // has been observed again in the authoritative account list.
          const refreshed = await refreshMine(1);
          if (recorded && refreshed?.some(row => String(row.id) === String(recorded.id))) {
            pending = null;
          } else if (actor() === owner) {
            errorOn(selfRoot, ar()
              ? "تم إرسال الطلب، لكن التحقق من القائمة فشل. أعد محاولة الطلب نفسه."
              : "Request may be recorded, but list verification failed. Retry the same request.");
          }
        } catch (e) {
          if (alive(owner, ticket)) errorOn(selfRoot, safe(e.message) || String(e));
        } finally {submit.disabled = false;}
      };
      selfRoot.append(form);
      selfRoot.append(btn("Refresh my requests", "تحديث طلباتي", () => refreshMine(ownPage)));
      const list = document.createElement("div"); list.className = "privacy-request-list"; selfRoot.append(list);
      try {
        const result = await api("privacy-requests?page=" + ownPage);
        if (!alive(owner, ticket)) return;
        if (!result.data?.length) list.append(p("No privacy requests submitted.", "لم يتم تقديم أي طلبات خصوصية."));
        for (const row of result.data || []) {
          const withdraw = async id => {
            if (actor() !== owner) return;
            try {await api("privacy-requests/" + id + "/withdraw", "POST", {});if (actor() === owner) await refreshMine(ownPage);}
            catch (e) {if (actor() === owner) errorOn(selfRoot, safe(e.message) || String(e));}
          };
          list.append(oneRow(row, withdraw));
        }
        if (ownPage > 1) selfRoot.append(btn("Previous", "السابق", () => refreshMine(ownPage - 1)));
        if (ownPage < result.last_page) selfRoot.append(btn("Next", "التالي", () => refreshMine(ownPage + 1)));
        return result.data || [];
      } catch (e) {
        if (alive(owner, ticket)) errorOn(selfRoot, safe(e.message) || String(e));
        return null;
      }
    }
    async function refreshAdmin(target = adminPage) {
      const owner = current, ticket = ++sequence;
      if (owner?.role !== "admin") {adminRoot.replaceChildren();return;}
      adminPage = target;
      adminRoot.replaceChildren(text("Privacy request reviews", "مراجعة طلبات الخصوصية", "h3"));
      adminRoot.append(p("Administrative review does not erase customer data or establish legal fulfillment.", "المراجعة الإدارية لا تحذف بيانات العميل ولا تثبت الوفاء القانوني."));
      adminRoot.append(btn("Refresh review queue", "تحديث قائمة المراجعة", () => refreshAdmin(adminPage)));
      const list = document.createElement("div"); adminRoot.append(list);
      try {
        const result = await api("admin/privacy-requests?page=" + adminPage);
        if (!alive(owner, ticket)) return;
        if (!result.data?.length) list.append(p("No review requests.", "لا توجد طلبات للمراجعة."));
        for (const row of result.data || []) {
          const card = oneRow(row);
          card.append(p("Request from: " + safe(row.user?.email), "طلب من: " + safe(row.user?.email)));
          if (row.status === "submitted") card.append(btn("Start review", "بدء المراجعة", async () => {
            if (actor() !== owner) return;
            try {await api("admin/privacy-requests/" + row.id, "PATCH", {status:"in_review"}); await refreshAdmin(adminPage);}
            catch (e) {if (actor() === owner) errorOn(adminRoot, safe(e.message) || String(e));}
          }));
          if (row.status === "in_review") {
            const f = document.createElement("form"); f.className = "privacy-review-form";
            const choices = document.createElement("select");
            choices.setAttribute("aria-label","Review outcome / نتيجة المراجعة");
            for (const [v,en,arabic] of [
              ["referred","Referred to compliance","إحالة للامتثال"],
              ["declined","Declined after review","رفض بعد المراجعة"],
              ["review_documented","Review documented (not deletion confirmation)","مراجعة موثقة (ليست تأكيداً للحذف)"],
            ]) {
              const o = document.createElement("option");o.value = v;o.textContent = ar() ? arabic : en;choices.append(o);
            }
            const note = document.createElement("textarea");note.required = true;note.minLength=15;note.maxLength=2000;note.rows=3;
            note.setAttribute("aria-label","Admin review note / ملاحظة المراجعة");
            const submit = document.createElement("button");submit.type="submit";submit.append(text("Close administrative review", "إغلاق المراجعة الإدارية"));
            f.append(choices,note,submit);
            f.onsubmit = async event => {
              event.preventDefault();
              if (actor() !== owner) return;
              submit.disabled = true;
              try {await api("admin/privacy-requests/" + row.id,"PATCH", {
                status:"closed",resolution:choices.value,decision_note:note.value.trim(),
              });if (actor() === owner) await refreshAdmin(adminPage);}
              catch (e) {if (actor() === owner) errorOn(adminRoot, safe(e.message) || String(e));submit.disabled=false;}
            };
            card.append(f);
          }
          list.append(card);
        }
        if (adminPage > 1) adminRoot.append(btn("Previous", "السابق", () => refreshAdmin(adminPage - 1)));
        if (adminPage < result.last_page) adminRoot.append(btn("Next", "التالي", () => refreshAdmin(adminPage + 1)));
      } catch (e) {if (alive(owner, ticket)) errorOn(adminRoot, safe(e.message) || String(e));}
    }
    async function setActor(value) {
      if (!value) {clear();return;}
      if (current === value) return;
      clear(); current = value;
      // Separate session-scoped requests; no cross-user response may modify a new account.
      await refreshMine();
      if (actor() === value && value.role === "admin") await refreshAdmin();
    }
    return {clear, setActor, refreshMine, refreshAdmin};
  }
  window.createPrivacyWorkspace = createPrivacyWorkspace;
})();
