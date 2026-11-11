"use strict";

// Existing vendor_company_staff remains the only permission ledger.
// Review actions never mutate raw roles, KYC documents or financial records.
window.EvAdminStaffPermissions = (() => {
    const root = document.getElementById("admin-staff-permissions");
    let generation = 0;
    let currentRequest = null;
    let request = null;
    let status = null;
    let company = null;
    let message = null;
    let results = null;
    let page = 1;

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
    function active(epoch, signal) {
        return generation === epoch && request !== null && !signal.aborted;
    }
    async function load(nextPage = 1) {
        if (!request) return;
        currentRequest?.abort();
        const controller = new AbortController();
        currentRequest = controller;
        const epoch = generation;
        page = nextPage;
        const filters = new URLSearchParams({page: String(page)});
        if (status.value) filters.set("status", status.value);
        const id = company.value.trim();
        if (id) filters.set("company_id", id);
        results.replaceChildren();
        message.textContent = "Loading permission grants / جاري التحميل…";
        try {
            const response = await request("admin/permission-grants?" + filters.toString(),
                "GET", undefined, controller.signal);
            if (!active(epoch, controller.signal) || currentRequest !== controller) return;
            const rows = Array.isArray(response.data) ? response.data : [];
            for (const grant of rows) {
                const staffId = Number(grant.id);
                if (!Number.isSafeInteger(staffId) || staffId <= 0) continue;
                const card = node("article");
                const person = grant.user?.name ?? "Unknown";
                const org = grant.company?.name ?? "Unknown company";
                card.append(
                    node("h4", org + " · " + person),
                    node("p", String(grant.user?.email ?? "") + " · Company #" + grant.company_id),
                    node("p", "Permission status: " + grant.status +
                        " · Version " + grant.invitation_version),
                    node("p", "Granted scopes: " +
                        (Array.isArray(grant.permissions) ? grant.permissions.join(", ") : "None"))
                );
                if (grant.status === "active" || grant.status === "invited") {
                    const revoke = button("Emergency revoke / إلغاء الوصول", () => {
                        if (!active(epoch, controller.signal)) return;
                        const form = node("form");
                        form.setAttribute("aria-label", "Revoke permission grant " + staffId);
                        const reason = node("textarea");
                        reason.minLength = 10;
                        reason.maxLength = 2000;
                        reason.required = true;
                        reason.setAttribute("aria-label", "Private revocation reason");
                        const confirm = button("Confirm access revocation / تأكيد", () => {});
                        confirm.type = "submit";
                        const cancel = button("Cancel / إلغاء", () => actionHost.replaceChildren(revoke));
                        form.append(field("Private reason (10–2000 characters)", reason), confirm, cancel);
                        form.onsubmit = async (event) => {
                            event.preventDefault();
                            if (!active(epoch, controller.signal)) return;
                            if (reason.value.trim().length < 10) {
                                message.textContent = "A documented review reason is required.";
                                return;
                            }
                            confirm.disabled = true;
                            try {
                                await request("admin/permission-grants/" + staffId + "/revoke", "POST", {
                                    expected_version: grant.invitation_version,
                                    reason: reason.value.trim(),
                                }, controller.signal);
                                if (active(epoch, controller.signal)) {
                                    message.textContent = "Staff access revoked; refreshing.";
                                    await load(page);
                                }
                            } catch {
                                if (active(epoch, controller.signal)) {
                                    message.textContent = "Grant changed or revocation failed; refresh to review.";
                                }
                            } finally {
                                confirm.disabled = false;
                            }
                        };
                        actionHost.replaceChildren(form);
                    });
                    const actionHost = node("div");
                    actionHost.append(revoke);
                    card.append(actionHost);
                }

                if (grant.admin_emergency_hold === true && grant.status === "revoked") {
                    card.append(node("p", "Admin security hold active. The company owner cannot re-invite this person."));
                    const actions = node("div");
                    const release = button("Release Admin hold / رفع التعليق", () => {
                        if (!active(epoch, controller.signal)) return;
                        const form = node("form");
                        form.setAttribute("aria-label", "Release permission hold " + staffId);
                        const reason = node("textarea");
                        reason.minLength = 10;
                        reason.maxLength = 2000;
                        reason.required = true;
                        reason.setAttribute("aria-label", "Private hold release reason");
                        const confirm = button("Confirm hold release / تأكيد رفع التعليق", () => {});
                        confirm.type = "submit";
                        const cancel = button("Cancel / إلغاء", () => actions.replaceChildren(release));
                        form.append(field("Reason for releasing hold", reason), confirm, cancel);
                        form.onsubmit = async event => {
                            event.preventDefault();
                            if (!active(epoch, controller.signal)) return;
                            if (reason.value.trim().length < 10) {
                                message.textContent = "A documented review reason is required.";
                                return;
                            }
                            confirm.disabled = true;
                            try {
                                await request("admin/permission-grants/" + staffId + "/release", "POST", {
                                    expected_version: grant.invitation_version,
                                    reason: reason.value.trim(),
                                }, controller.signal);
                                if (active(epoch, controller.signal)) {
                                    message.textContent = "Hold released; company owner must re-invite staff.";
                                    await load(page);
                                }
                            } catch {
                                if (active(epoch, controller.signal)) {
                                    message.textContent = "Hold was not released. Refresh the permission version.";
                                }
                            } finally {
                                confirm.disabled = false;
                            }
                        };
                        actions.replaceChildren(form);
                    });
                    actions.append(release);
                    card.append(actions);
                }

                const history = button("Private revocation history / السجل", async () => {
                    if (!active(epoch, controller.signal)) return;
                    try {
                        const response = await request("admin/permission-grants/" + staffId +
                            "/revocations", "GET", undefined, controller.signal);
                        if (!active(epoch, controller.signal)) return;
                        const list = node("ul");
                        for (const record of response.data || []) {
                            list.append(node("li", record.created_at + " · " +
                                (record.action === "released" ? "Admin hold released" : "Admin revoked") +
                                " · " + record.reason + " · prior version " + record.replaced_version));
                        }
                        if (!response.data?.length) list.append(node("li", "No revocation events"));
                        historyHost.replaceChildren(list);
                    } catch {
                        if (active(epoch, controller.signal))
                            historyHost.replaceChildren(node("p", "Private history unavailable."));
                    }
                });
                const historyHost = node("div");
                card.append(history, historyHost);
                results.append(card);
            }
            if (!rows.length) results.append(node("p", "No grants match these filters."));
            const nav = node("div");
            nav.className = "pagination";
            const previous = button("Previous / السابق", () => load(page - 1));
            const next = button("Next / التالي", () => load(page + 1));
            previous.disabled = page <= 1;
            next.disabled = page >= response.last_page;
            nav.append(previous, node("span", "Page " + page + " / " + response.last_page), next);
            results.append(nav);
            message.textContent = String(response.total) + " staff permission records.";
        } catch {
            if (active(epoch, controller.signal)) {
                results.replaceChildren(button("Retry / إعادة المحاولة", () => load(page)));
                message.textContent = "Staff permission records unavailable.";
            }
        }
    }

    function setActor(user, api) {
        generation += 1;
        currentRequest?.abort();
        currentRequest = null;
        request = null;
        root.replaceChildren();
        if (user?.role !== "admin") return;

        request = api;
        const filters = node("form");
        filters.setAttribute("aria-label", "Permission grants filters");
        status = node("select");
        status.setAttribute("aria-label", "Grant status");
        for (const item of [
            ["", "All statuses"], ["active", "Active"], ["invited", "Invited"], ["revoked", "Revoked"]
        ]) {
            const option = node("option", item[1]);
            option.value = item[0];
            status.append(option);
        }
        company = node("input");
        company.type = "number";
        company.min = 1;
        company.setAttribute("aria-label", "Company ID");
        const submit = button("Search / بحث", () => {});
        submit.type = "submit";
        filters.append(field("Status", status), field("Company ID (optional)", company), submit);
        filters.onsubmit = event => { event.preventDefault(); load(1); };
        message = node("p");
        message.setAttribute("role", "status");
        message.setAttribute("aria-live", "polite");
        results = node("div");
        root.append(filters, message, results);
        load(1);
    }

    return {setActor};
})();
