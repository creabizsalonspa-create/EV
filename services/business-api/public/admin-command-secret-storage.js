"use strict";

// Memory-only bounded observations. Sealing/key changes stay in operator tooling.
window.EvAdminCommandSecretStorage = (() => {
    const root = document.getElementById("admin-command-secret-storage");
    if (!root) return {setActor() {}};
    let revision = 0, buttonVersion = 0, api = null, controller = null;
    let reading = false, cursor = 0, hasMore = false, retryAfter = null, output, status, refresh, next, retry;
    const categories = {
        encrypted_rows: ["Rows with encrypted known fields", "صفوف ذات حقول معروفة مشفرة"],
        legacy_rows: ["Rows with legacy plaintext fields", "صفوف ذات حقول نصية قديمة"],
        unreadable_rows: ["Unreadable or oversized rows", "صفوف غير قابلة للقراءة أو تتجاوز الحد"],
        unknown_shape_rows: ["Unrecognized payload shapes", "بنى بيانات غير معروفة"],
        no_known_secret_rows: ["Rows without recognized secret fields", "صفوف دون حقول سرية معروفة"],
    };
    function n(tag, en, ar = en) {
        const node = document.createElement(tag);
        node.dataset.en = en; node.dataset.ar = ar;
        node.textContent = document.documentElement.lang === "ar" ? ar : en;
        return node;
    }
    const count = value => Number.isSafeInteger(value) && value >= 0;
    function project(data, after) {
        if (data?.scope !== "bounded_command_secret_storage" || data.after_id !== after || data.limit !== 25 || data.max_field_bytes !== 65536 ||
            data.whole_table_verified !== false || data.mutation_performed !== false || data.production_acceptance !== false ||
            data.commands_dispatched !== 0 || data.network_requests !== 0 || typeof data.has_more !== "boolean") throw Error("Invalid storage observation");
        if (typeof data.as_of !== "string" || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(data.as_of) ||
            !Number.isFinite(Date.parse(data.as_of)) || new Date(data.as_of).toISOString().slice(0, 19) !== data.as_of.slice(0, 19)) throw Error("Invalid observation time");
        const keys = [...Object.keys(categories), "encrypted_fields", "legacy_fields", "rows_examined", "next_after_id"];
        if (!keys.every(key => count(data[key])) || data.rows_examined > 25 ||
            Object.keys(categories).reduce((sum, key) => sum + data[key], 0) !== data.rows_examined ||
            (data.has_more ? data.rows_examined !== 25 || data.next_after_id <= after : data.next_after_id !== 0) ||
            data.legacy_fields < data.legacy_rows || (data.legacy_rows === 0 && data.legacy_fields !== 0) ||
            data.encrypted_fields < data.encrypted_rows || (data.legacy_rows === 0 && data.encrypted_rows === 0 && data.encrypted_fields !== 0) ||
            data.encrypted_fields + data.legacy_fields > data.rows_examined * 2 * 65536) throw Error("Incoherent observation counts");
        // Unrecognized source properties can never become DOM content/history.
        return Object.freeze(Object.fromEntries(["as_of", "has_more", ...keys].map(key => [key, data[key]])));
    }
    function bind() {
        if (!refresh) return;
        const own = revision, version = ++buttonVersion, request = api, after = cursor, failedAfter = retryAfter;
        const live = () => own === revision && version === buttonVersion && request === api && api && !document.hidden && !reading;
        refresh.disabled = !api || reading || document.hidden;
        next.disabled = refresh.disabled || !hasMore;
        retry.hidden = failedAfter === null; retry.disabled = refresh.disabled || failedAfter === null;
        refresh.onclick = () => {if (live()) load(0, own, request);};
        next.onclick = () => {if (live() && hasMore && cursor === after) load(after, own, request);};
        retry.onclick = () => {if (live() && failedAfter !== null && failedAfter === retryAfter) load(failedAfter, own, request);};
    }
    function reset(message = false) {
        revision++; buttonVersion++; controller?.abort(); controller = null;
        reading = false; cursor = 0; hasMore = false; retryAfter = null; output?.replaceChildren();
        output?.removeAttribute("role"); output?.removeAttribute("aria-labelledby");
        status?.replaceChildren(...(message ? [n("span", "Private observation cleared. Inspect explicitly when ready.", "تم مسح الملاحظة الخاصة. افحص صراحة عندما تكون مستعداً.")] : []));
        bind();
    }
    function clear() {
        reset(); api = null; root.replaceChildren();
        output = status = refresh = next = retry = null;
    }
    async function load(after, own, request) {
        if (own !== revision || request !== api || document.hidden || reading) return;
        reading = true; cursor = 0; hasMore = false; retryAfter = null; output.replaceChildren();
        const pending = new AbortController(); controller = pending; bind();
        status.replaceChildren(n("span", "Inspecting a private storage batch…", "جارٍ فحص دفعة تخزين خاصة…"));
        const active = () => own === revision && request === api && controller === pending && !pending.signal.aborted && !document.hidden;
        try {
            const data = project(await request("admin/command-secret-storage?limit=25&after=" + after, "GET", undefined, pending.signal), after);
            if (!active()) return;
            const heading = n("h4", "Observed batch counts", "أعداد الدفعة المرصودة"); heading.id = "command-secret-batch-title";
            output.setAttribute("role", "region"); output.setAttribute("aria-labelledby", heading.id);
            const grid = document.createElement("dl"); grid.className = "command-secret-counts";
            for (const [key, label] of Object.entries(categories)) {
                const card = document.createElement("div"), value = document.createElement("dd");
                value.textContent = String(data[key]); card.append(n("dt", ...label), value); grid.append(card);
            }
            const observed = n("p", "Observed at UTC", "وقت الملاحظة بالتوقيت العالمي");
            const time = document.createElement("time"); time.dateTime = data.as_of; time.dir = "ltr";
            time.className = "command-secret-time"; time.textContent = data.as_of;
            output.append(heading, grid, observed, time,
                n("p", "Known fields: " + data.encrypted_fields + " encrypted; " + data.legacy_fields + " legacy plaintext.", "الحقول المعروفة: " + data.encrypted_fields + " مشفرة؛ " + data.legacy_fields + " نصية قديمة."));
            cursor = data.next_after_id; hasMore = data.has_more;
            status.replaceChildren(n("span", data.has_more ? "More rows remain. Inspect the next batch explicitly." : "End of this scan at this observation; not security or production acceptance.",
                data.has_more ? "توجد صفوف أخرى. افحص الدفعة التالية صراحة." : "نهاية الفحص عند هذه الملاحظة؛ ليست قبولاً أمنياً أو إنتاجياً."));
        } catch {
            if (active()) {
                reset(); retryAfter = after;
                status.replaceChildren(n("span", "Storage observations unavailable. Retry this read-only batch or inspect the first batch explicitly.", "ملاحظات التخزين غير متاحة. أعد قراءة هذه الدفعة أو افحص الدفعة الأولى صراحة."));
                bind();
            }
        } finally {
            if (active()) {reading = false; controller = null; bind();}
        }
    }
    function setActor(actor, request) {
        clear();
        if (actor?.role !== "admin" || typeof request !== "function") return;
        api = request;
        root.append(n("p", "Read-only counts of recognized command credential fields in a limited batch. This does not verify every record, free text, provider stores, backups or production security. No credentials are displayed and no storage changes or charger commands are performed.",
            "أعداد للقراءة فقط لحقول بيانات اعتماد الأوامر المعروفة ضمن دفعة محدودة. لا يتحقق هذا الفحص من جميع السجلات أو النص الحر أو تخزين المزود أو النسخ الاحتياطية أو أمن الإنتاج. لا تُعرض أسرار ولا تُجرى تغييرات في التخزين أو أوامر شحن."),
            n("p", "Inspect up to 25 rows, limited to 64 KiB per field. Larger fields are reported as unreadable here.", "يُفحص ما يصل إلى 25 صفاً، بحد 65536 بايت لكل حقل. تُدرج الحقول الأكبر ضمن غير القابلة للقراءة هنا."));
        refresh = n("button", "Inspect first storage batch", "فحص دفعة التخزين الأولى"); refresh.type = "button";
        next = n("button", "Inspect next storage batch", "فحص دفعة التخزين التالية"); next.type = "button";
        retry = n("button", "Retry failed storage batch", "إعادة قراءة دفعة التخزين المتعذرة"); retry.type = "button";
        status = document.createElement("p"); status.setAttribute("role", "status"); status.setAttribute("aria-live", "polite");
        const actions = document.createElement("div"); actions.className = "command-secret-actions"; actions.append(refresh, next, retry);
        output = document.createElement("div"); root.append(actions, status, output); bind();
    }
    document.addEventListener("visibilitychange", () => {if (api) reset(true);});
    window.addEventListener("pagehide", clear);
    return {setActor};
})();
