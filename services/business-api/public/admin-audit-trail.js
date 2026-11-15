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

    function node(tag, text) {
        const result = document.createElement(tag);
        if (text !== undefined) result.textContent = String(text);
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

    async function loadDenials() {
        if (!request || !denialOutput || !denialWindow) return;
        denialController?.abort();
        const activeController = new AbortController();
        denialController = activeController;
        const epoch = generation;
        denialOutput.replaceChildren(node("p",
            "Reading observed Admin MFA denials / قراءة محاولات المصادقة المرفوضة…"));
        try {
            const response = await request(
                "admin/security-denials?window=" + encodeURIComponent(denialWindow.value),
                "GET", undefined, activeController.signal);
            if (!alive(epoch, activeController.signal) || denialController !== activeController) return;
            denialOutput.replaceChildren();
            denialOutput.append(
                node("h4", "Observed Admin MFA denials / حالات رفض المصادقة المسجلة"),
                node("p", "Window: " + response.window +
                    " · Observed: " + response.observed_denials +
                    " · Source: Admin MFA audit only"));
            const groups = response.per_action && typeof response.per_action === "object"
                ? response.per_action : {};
            for (const [action, count] of Object.entries(groups)) {
                if (!Number.isSafeInteger(count) || count < 0) continue;
                denialOutput.append(node("p", action + ": " + count));
            }
            if (response.sample_truncated === true) {
                denialOutput.append(node("p",
                    "Limited to the most recent 500 matching events. Counts are partial / عرض جزئي حتى ٥٠٠ حدث"));
            } else {
                denialOutput.append(node("p",
                    "Only recorded MFA denials are counted; this does not prove compromise, incident or automatic action."));
            }
        } catch {
            if (alive(epoch, activeController.signal)) {
                denialOutput.replaceChildren(node("p",
                    "Security evidence unavailable; no account action was taken."));
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
        const denialButton = button("Review MFA denial evidence / مراجعة رفض المصادقة",
            () => loadDenials());
        denialOutput = node("section");
        denialOutput.setAttribute("aria-label", "Observed MFA denial evidence");
        root.append(filters, message, results, field("Security observation window", denialWindow),
            denialButton, denialOutput);
        load(true);
    }
    return {setActor};
})();
