"use strict";

window.EvAdminStaffPermissions = (() => {
    const root = document.getElementById("admin-staff-permissions");
    const statuses = ["active", "invited", "revoked"];
    const pageLimit = 100000;
    let currentRequest = null;
    let request = null;
    let status = null;
    let company = null;
    let hold = null;
    let message = null;
    let results = null;

    function node(tag, value) {
        const element = document.createElement(tag);
        if (value !== undefined) element.textContent = String(value);
        return element;
    }

    function button(value, action) {
        const element = node("button", value);
        element.type = "button";
        element.onclick = action;
        return element;
    }

    function field(text, input) {
        const label = node("label");
        label.append(node("span", text), input);
        return label;
    }

    function options(label, items) {
        const input = node("select");
        input.setAttribute("aria-label", label);
        for (const [value, text] of items) {
            const option = node("option", text);
            option.value = value;
            input.append(option);
        }
        return input;
    }

    function positiveInteger(value) {
        return Number.isSafeInteger(value) && value > 0;
    }

    function active(controller) {
        return currentRequest === controller && request !== null && !controller.signal.aborted;
    }

    function invalidate() {
        currentRequest?.abort();
        currentRequest = null;
    }

    function validPage(response, page) {
        return response?.current_page === page && positiveInteger(response.last_page)
            && Number.isSafeInteger(response.total) && response.total >= 0
            && Array.isArray(response.data) && response.data.length <= 25
            && response.data.length <= response.total;
    }

    function pagination(page, lastPage, changePage) {
        const nav = node("div");
        nav.className = "pagination";
        const previous = button("Previous / السابق", () => changePage(page - 1));
        const next = button("Next / التالي", () => changePage(page + 1));
        previous.disabled = page <= 1;
        next.disabled = page >= Math.min(lastPage, pageLimit);
        nav.append(previous, node("span", "Page " + page + " / " + lastPage), next);
        return nav;
    }

    function validGrant(grant, scope) {
        return positiveInteger(grant?.id) && positiveInteger(grant.company_id)
            && positiveInteger(grant.user_id) && positiveInteger(grant.invitation_version)
            && statuses.includes(grant.status) && typeof grant.admin_emergency_hold === "boolean"
            && Array.isArray(grant.permissions) && grant.permissions.every(value => typeof value === "string")
            && (grant.company === null || grant.company?.id === grant.company_id)
            && (grant.user === null || grant.user?.id === grant.user_id)
            && (scope.company_id === null || scope.company_id === grant.company_id)
            && (scope.status === null || scope.status === grant.status)
            && (scope.hold === null || (scope.hold === "held") === grant.admin_emergency_hold);
    }

    function actionControl(grant, controller, refresh) {
        const host = node("div");
        const releasing = grant.admin_emergency_hold;
        const action = releasing ? "release" : "revoke";
        const trigger = button(
            releasing ? "Release Admin hold / رفع التعليق" : "Emergency revoke / إلغاء الوصول",
            () => {
                if (!active(controller)) return;
                const form = node("form");
                form.setAttribute("aria-label", (releasing ? "Release permission hold " : "Revoke permission grant ") + grant.id);
                const reason = node("textarea");
                reason.minLength = 10;
                reason.maxLength = 2000;
                reason.required = true;
                reason.setAttribute("aria-label", releasing ? "Private hold release reason" : "Private revocation reason");
                const confirm = node("button", releasing ? "Confirm hold release / تأكيد رفع التعليق" : "Confirm access revocation / تأكيد");
                confirm.type = "submit";
                const cancel = button("Cancel / إلغاء", () => host.replaceChildren(trigger));
                form.append(field("Private reason (10–2000 characters)", reason), confirm, cancel);
                form.onsubmit = async event => {
                    event.preventDefault();
                    if (!active(controller) || confirm.disabled) return;
                    const explanation = reason.value.trim();
                    if (explanation.length < 10 || explanation.length > 2000) {
                        message.textContent = "A documented review reason is required.";
                        return;
                    }
                    confirm.disabled = true;
                    cancel.disabled = true;
                    let notice;
                    try {
                        const receipt = await request("admin/permission-grants/" + grant.id + "/" + action, "POST", {
                            expected_version: grant.invitation_version,
                            reason: explanation,
                        }, controller.signal);
                        if (receipt?.id !== grant.id || receipt.status !== "revoked"
                            || receipt.invitation_version !== grant.invitation_version + 1
                            || receipt.admin_emergency_hold !== !releasing
                            || (releasing && receipt.requires_fresh_invitation !== true)) {
                            throw new Error("Invalid permission receipt");
                        }
                        notice = releasing ? "Hold released. The company owner must re-invite staff." : "Staff access revoked.";
                    } catch {
                        notice = "The change could not be confirmed. Review the current grant before trying again.";
                    }
                    if (active(controller)) await refresh(notice);
                };
                host.replaceChildren(form);
            }
        );
        host.append(trigger);
        return host;
    }

    function privateHistory(grant, controller) {
        const container = node("div");
        const history = node("div");
        history.setAttribute("aria-live", "polite");

        async function load(page = 1) {
            if (!active(controller) || open.disabled) return;
            open.disabled = true;
            history.replaceChildren(node("p", "Loading private history…"));
            try {
                const response = await request("admin/permission-grants/" + grant.id + "/revocations?page=" + page,
                    "GET", undefined, controller.signal);
                if (!active(controller)) return;
                if (!validPage(response, page) || response.membership_id !== grant.id
                    || !response.data.every(record => positiveInteger(record?.id)
                        && positiveInteger(record.replaced_version)
                        && ["revoked", "released"].includes(record.action)
                        && typeof record.created_at === "string" && typeof record.reason === "string")) {
                    throw new Error("Invalid permission history");
                }
                const events = node("ul");
                for (const record of response.data) {
                    const action = record.action === "released" ? "Admin hold released" : "Admin revoked";
                    events.append(node("li", record.created_at + " · " + action
                        + " · " + record.reason + " · prior version " + record.replaced_version));
                }
                if (!response.data.length) events.append(node("li", "No revocation events"));
                history.replaceChildren(events, pagination(page, response.last_page, load));
            } catch {
                if (active(controller)) history.replaceChildren(node("p", "Private history unavailable."));
            } finally {
                open.disabled = false;
            }
        }

        const open = button("Private revocation history / السجل", () => load());
        container.append(open, history);
        return container;
    }

    async function load(page = 1, notice = "") {
        if (!request || !positiveInteger(page) || page > pageLimit) return;
        invalidate();
        results.replaceChildren();
        const companyText = company.value.trim();
        if (companyText && (!/^\d+$/.test(companyText) || !positiveInteger(Number(companyText)))) {
            message.textContent = "Enter a valid company ID.";
            return;
        }

        const controller = new AbortController();
        currentRequest = controller;
        const scope = {
            company_id: companyText ? Number(companyText) : null,
            status: status.value || null,
            hold: hold.value || null,
        };
        const filters = new URLSearchParams({page: String(page)});
        for (const [key, value] of Object.entries(scope)) {
            if (value !== null) filters.set(key, String(value));
        }
        message.textContent = "Loading permission grants / جاري التحميل…";
        try {
            const response = await request("admin/permission-grants?" + filters.toString(),
                "GET", undefined, controller.signal);
            if (!active(controller)) return;
            if (!validPage(response, page) || !response.filters
                || !Object.keys(scope).every(key => response.filters[key] === scope[key])
                || !response.data.every(grant => validGrant(grant, scope))
                || new Set(response.data.map(grant => grant.id)).size !== response.data.length) {
                throw new Error("Invalid permission grant page");
            }

            for (const grant of response.data) {
                const card = node("article");
                card.append(
                    node("h4", (grant.company?.name ?? "Unknown company") + " · " + (grant.user?.name ?? "Unknown")),
                    node("p", String(grant.user?.email ?? "") + " · Company #" + grant.company_id),
                    node("p", "Permission status: " + grant.status + " · Version " + grant.invitation_version),
                    node("p", "Granted scopes: " + (grant.permissions.join(", ") || "None"))
                );
                if (grant.admin_emergency_hold) {
                    card.append(node("p", "Admin security hold active. The company owner cannot re-invite this person."));
                } else if (grant.status === "revoked") {
                    card.append(node("p", "Company access is revoked. Place an Admin hold to block re-invitation."));
                }
                card.append(actionControl(grant, controller, outcome => load(page, outcome)));
                card.append(privateHistory(grant, controller));
                results.append(card);
            }
            if (!response.data.length) results.append(node("p", "No grants match these filters."));
            results.append(pagination(page, response.last_page, load));
            message.textContent = [notice, response.total + " staff permission records."].filter(Boolean).join(" ");
        } catch {
            if (active(controller)) {
                results.replaceChildren(button("Retry / إعادة المحاولة", () => load(page, notice)));
                message.textContent = [notice, "Staff permission records unavailable."].filter(Boolean).join(" ");
            }
        }
    }

    function setActor(user, api) {
        invalidate();
        request = null;
        root.replaceChildren();
        if (user?.role !== "admin") return;

        request = api;
        const filters = node("form");
        filters.setAttribute("aria-label", "Permission grants filters");
        status = options("Grant status", [["", "All statuses"], ["active", "Active"],
            ["invited", "Invited"], ["revoked", "Revoked"]]);
        hold = options("Admin hold", [["", "All holds"], ["held", "Held"], ["unheld", "Unheld"]]);
        company = node("input");
        company.type = "number";
        company.min = 1;
        company.max = Number.MAX_SAFE_INTEGER;
        company.setAttribute("aria-label", "Company ID");
        const submit = node("button", "Search / بحث");
        submit.type = "submit";
        filters.append(field("Status", status), field("Company ID (optional)", company),
            field("Admin hold", hold), submit);
        filters.onsubmit = event => { event.preventDefault(); load(); };
        filters.oninput = () => {
            invalidate();
            results.replaceChildren();
            message.textContent = "Search to apply the filters.";
        };
        message = node("p");
        message.setAttribute("role", "status");
        message.setAttribute("aria-live", "polite");
        results = node("div");
        root.append(filters, message, results);
        load();
    }

    return {setActor};
})();
