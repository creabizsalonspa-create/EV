"use strict";

// Administrative audit explorer. The existing audit_events table remains the
// sole source of truth. This component never edits or fabricates audit events.
window.EvAdminAuditTrail = (() => {
    const root = document.getElementById("admin-audit-trail");
    let generation = 0;
    let controller = null;
    let request = null;
    let prefix = null;
    let actor = null;
    let results = null;
    let message = null;
    let nextCursor = null;
    let hasMore = false;
    let denialController = null;
    let denialOutput = null;
    let denialWindow = null;
    let denialActor = null;
    const denialActions = [
        'admin.second_factor.enrollment_password_denied', 'admin.second_factor.enrollment_code_denied',
        'admin.second_factor.verify_denied', 'admin.second_factor.recovery_denied',
        'admin.second_factor.recovery_rotation_denied', 'admin.second_factor.recovery_reset_password_denied',
    ];

    function node(tag, text) {
        const result = document.createElement(tag);
        if (text !== undefined) result.textContent = String(text);
        return result;
    }
    function translated(tag, english, arabic) {
        const result = node(tag, document.documentElement?.lang === "ar" ? arabic : english);
        result.setAttribute("data-en", english);
        result.setAttribute("data-ar", arabic);
        return result;
    }
    function button(text, callback) {
        const b = node("button", text);
        b.type = "button";
        b.onclick = callback;
        return b;
    }
    function field(text, input) {
        const label = node("label");
        label.append(node("span", text), input);
        return label;
    }
    function alive(epoch, signal) {
        return generation === epoch && request !== null && !signal.aborted;
    }
    async function load(reset = true) {
        if (!request || (!reset && (!hasMore || nextCursor === null))) return;
        controller?.abort();
        const activeController = new AbortController();
        controller = activeController;
        const epoch = generation;
        const filters = new URLSearchParams({limit: "25"});
        if (prefix.value.trim()) filters.set("action_prefix", prefix.value.trim());
        if (actor.value.trim()) filters.set("actor_id", actor.value.trim());
        if (!reset) filters.set("before_id", String(nextCursor));
        if (reset) {
            results.replaceChildren();
            nextCursor = null;
            hasMore = false;
        }
        message.textContent = "Loading audit events / جاري تحميل التدقيق…";
        try {
            const response = await request("admin/audit-events?" + filters.toString(),
                "GET", undefined, activeController.signal);
            if (!alive(epoch, activeController.signal) || controller !== activeController) return;

            // Discard obsolete pagination controls before drawing the next
            // cursor page; no HTML is injected from actions or subjects.
            for (const item of Array.from(results.children)) {
                if (item.getAttribute?.("data-audit-load-more") === "true") {
                    item.remove();
                }
            }
            const events = Array.isArray(response.events) ? response.events : [];
            for (const item of events) {
                const entry = node("article");
                entry.append(
                    node("h4", item.action),
                    node("p", "Event #" + item.id + " · Actor " +
                        (item.actor_name || item.actor_id) + " · Record " + item.subject),
                    node("p", "UTC: " + item.created_at)
                );
                results.append(entry);
            }
            nextCursor = Number.isSafeInteger(response.next_before_id)
                ? response.next_before_id : null;
            hasMore = response.has_more === true && nextCursor !== null;
            if (hasMore) {
                const more = button("Load older audit events / أحداث أقدم", () => load(false));
                more.setAttribute("data-audit-load-more", "true");
                results.append(more);
            }
            if (!results.children.length) {
                results.append(node("p", "No audit events match these filters."));
            }
            message.textContent = events.length + " events loaded" +
                (hasMore ? " · more history available" : " · end of matching history");
        } catch {
            if (alive(epoch, activeController.signal)) {
                message.textContent = "Audit history unavailable. No records were changed.";
                const retry = button("Retry / إعادة المحاولة", () => load(reset));
                retry.setAttribute("data-audit-load-more", "true");
                results.append(retry);
            }
        }
    }

    function invalidateDenials() {
        denialController?.abort();
        denialController = null;
        denialOutput?.replaceChildren();
    }
    function validDenials(response, window, actorId) {
        const count = n => Number.isSafeInteger(n) && n >= 0 && n <= 500;
        const groups = response?.per_action;
        const hours = {'1h':1, '24h':24, '7d':168}[window];
        const from = Date.parse(response?.from), asOf = Date.parse(response?.as_of);
        if (!response || response.window !== window || response.actor_id !== actorId ||
            response.source !== 'existing_admin_mfa_denial_audit_only' ||
            !hours || !Number.isFinite(from) || !Number.isFinite(asOf) || asOf - from !== hours * 3600000 ||
            response.sample_limit !== 500 || !count(response.observed_denials) ||
            typeof response.sample_truncated !== 'boolean' ||
            (response.sample_truncated && response.observed_denials !== 500) ||
            response.complete_for_selected_actions !== !response.sample_truncated ||
            response.suspicious_activity_verified !== false || response.incident_confirmed !== false ||
            response.automated_action_taken !== false || !groups || typeof groups !== 'object' ||
            Array.isArray(groups) || Object.keys(groups).length !== denialActions.length ||
            !denialActions.every(key => Object.hasOwn(groups,key) && count(groups[key])) ||
            denialActions.reduce((total,key) => total + groups[key],0) !== response.observed_denials ||
            !Array.isArray(response.top_actors) || response.top_actors.length > 25) return false;
        const ids = new Set();
        let attributed = 0, previous = null;
        for (const row of response.top_actors) {
            if (!row || !Number.isSafeInteger(row.actor_id) || row.actor_id < 1 ||
                (actorId !== null && row.actor_id !== actorId) || ids.has(row.actor_id) ||
                !count(row.observed_denials) || row.observed_denials === 0 ||
                (previous && (row.observed_denials > previous.observed_denials ||
                    (row.observed_denials === previous.observed_denials && row.actor_id < previous.actor_id)))) return false;
            attributed += row.observed_denials;
            ids.add(row.actor_id);
            previous = row;
        }
        return attributed <= response.observed_denials &&
            (actorId === null || attributed === response.observed_denials);
    }

    async function loadDenials() {
        if (!request || !denialOutput || !denialWindow || !denialActor) return;
        invalidateDenials();
        const window = denialWindow.value, rawActor = denialActor.value.trim();
        const actorId = rawActor ? Number(rawActor) : null;
        if (!['1h','24h','7d'].includes(window) || (rawActor &&
            (!/^[1-9][0-9]{0,15}$/.test(rawActor) || !Number.isSafeInteger(actorId)))) {
            denialOutput.append(translated("p", "Invalid observation filters.", "مرشحات الرصد غير صالحة."));
            return;
        }
        const activeController = new AbortController();
        denialController = activeController;
        const epoch = generation;
        denialOutput.replaceChildren(translated("p",
            "Reading observed Admin MFA denials…", "قراءة محاولات المصادقة المرفوضة…"));
        try {
            const response = await request(
                "admin/security-denials?window=" + encodeURIComponent(window) +
                    (actorId === null ? "" : "&actor_id=" + actorId),
                "GET", undefined, activeController.signal);
            if (!alive(epoch, activeController.signal) || denialController !== activeController) return;
            if (!validDenials(response, window, actorId)) throw Error('Unverified MFA evidence');
            denialOutput.replaceChildren();
            denialOutput.append(
                node("h4", "Observed Admin MFA denials / حالات رفض المصادقة المسجلة"),
                translated("p", "Window: " + response.window +
                    " · Observed: " + response.observed_denials +
                    " · Source: Admin MFA audit only",
                    "الفترة: " + response.window + " · الحالات المسجلة: " + response.observed_denials +
                    " · المصدر: سجل تدقيق مصادقة المشرف فقط"));
            for (const action of denialActions) {
                denialOutput.append(node("p", action + ": " + response.per_action[action]));
            }
            denialOutput.append(translated("h4", "Observed actors (up to 25; not a risk ranking)",
                "الحسابات المرصودة (حتى ٢٥؛ ليست تصنيفاً للمخاطر)"));
            for (const row of response.top_actors) {
                denialOutput.append(node("p", "#" + row.actor_id + " · " + row.observed_denials));
            }
            denialOutput.append(translated("p",
                "Only recorded denials are counted; this does not prove compromise, incident or automatic action.",
                "تُحسب حالات الرفض المسجلة فقط؛ ولا يثبت ذلك اختراقاً أو حادثاً أو إجراءً تلقائياً."));
            if (response.sample_truncated === true) {
                denialOutput.append(translated("p",
                    "Limited to the most recent 500 matching events. Counts are partial",
                    "النتائج محدودة بآخر ٥٠٠ حدث مطابق؛ الأعداد جزئية وليست إحصاءً شاملاً."));
            } else {
                denialOutput.append(translated("p",
                    "Only recorded MFA denials are counted; this does not prove compromise, incident or automatic action.",
                    "تُحسب حالات رفض المصادقة المسجلة فقط؛ وهذا لا يثبت اختراقاً أو حادثاً ولا يعني اتخاذ إجراء تلقائي."));
            }
        } catch {
            if (alive(epoch, activeController.signal)) {
                denialOutput.replaceChildren(translated("p",
                    "Security evidence unavailable; no account action was taken.",
                    "تعذر عرض أدلة الأمان؛ لم يُتخذ أي إجراء على الحساب."));
            }
        }
    }

    function setActor(user, api) {
        generation++;
        controller?.abort();
        denialController?.abort();
        controller = null;
        denialController = null;
        request = null;
        denialOutput = null;
        denialWindow = null;
        denialActor = null;
        root.replaceChildren();
        if (user?.role !== "admin") return;

        request = api;
        const filters = node("form");
        filters.setAttribute("aria-label", "Admin audit trail filters");
        prefix = node("input");
        prefix.type = "search";
        prefix.maxLength = 80;
        prefix.placeholder = "admin.account.";
        prefix.setAttribute("aria-label", "Audit action prefix");
        actor = node("input");
        actor.type = "number";
        actor.min = 1;
        actor.placeholder = "Actor ID";
        actor.setAttribute("aria-label", "Audit actor ID");
        const apply = button("Search history / بحث", () => {});
        apply.type = "submit";
        filters.append(field("Action prefix", prefix), field("Actor ID (optional)", actor), apply);
        filters.onsubmit = event => {
            event.preventDefault();
            load(true);
        };
        message = node("p");
        message.setAttribute("role", "status");
        message.setAttribute("aria-live", "polite");
        results = node("div");
        denialWindow = node("select");
        denialWindow.setAttribute("aria-label", "MFA denial evidence window");
        for (const [value, description] of [["1h", "Past hour"], ["24h", "Past 24 hours"], ["7d", "Past 7 days"]]) {
            const option = node("option", description);
            option.value = value;
            denialWindow.append(option);
        }
        denialWindow.value = "24h";
        denialWindow.onchange = invalidateDenials;
        denialActor = node("input");
        denialActor.type = "text";
        denialActor.inputMode = "numeric";
        denialActor.maxLength = 16;
        denialActor.setAttribute("aria-label", "MFA denial actor ID");
        denialActor.oninput = invalidateDenials;
        const denialButton = button("Review MFA denial evidence / مراجعة رفض المصادقة",
            () => loadDenials());
        denialOutput = node("section");
        denialOutput.setAttribute("aria-label", "Observed MFA denial evidence");
        root.append(filters, message, results, field("Security observation window", denialWindow),
            field("Security actor ID (optional)", denialActor), denialButton, denialOutput);
        load(true);
    }
    return {setActor};
})();
