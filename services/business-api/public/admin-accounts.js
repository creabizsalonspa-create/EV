"use strict";

// Admin-only account directory and audited moderation. No raw HTML or private KYC
// material; the server must independently authorize and whitelist every field.
window.EvAdminAccounts = (() => {
    const root = document.getElementById("admin-accounts");
    let revision = 0;
    let currentRequest = null;
    let request = null;
    let currentPage = 1;
    let search = null;
    let role = null;
    let verified = null;
    let accountStatus = null;
    let output = null;
    let state = null;

    function element(tag, value) {
        const node = document.createElement(tag);
        if (value !== undefined) node.textContent = String(value);
        return node;
    }

    function button(label, handler) {
        const node = element("button", label);
        node.type = "button";
        node.onclick = handler;
        return node;
    }

    function field(label, control) {
        const node = element("label");
        node.append(element("span", label), control);
        return node;
    }

    function select(options) {
        const control = element("select");
        for (const [value, label] of options) {
            const option = element("option", label);
            option.value = value;
            control.append(option);
        }
        return control;
    }

    function active(expected, signal) {
        return revision === expected && !signal.aborted && request !== null;
    }

    async function load(page = 1) {
        if (!request) return;
        currentRequest?.abort();
        const controller = new AbortController();
        currentRequest = controller;
        const expected = revision;
        const params = new URLSearchParams({ page: String(page) });
        const q = search.value.trim();
        if (q) params.set("q", q);
        if (role.value) params.set("role", role.value);
        if (verified.value) params.set("verified", verified.value);
        if (accountStatus.value) params.set("account_status", accountStatus.value);
        state.textContent = "Loading private account directory / جاري التحميل…";
        output.replaceChildren();
        try {
            const response = await request("admin/accounts?" + params.toString(), "GET", undefined, controller.signal);
            if (!active(expected, controller.signal) || currentRequest !== controller) return;
            currentPage = response.current_page;
            const table = element("table");
            table.className = "audit-table";
            const header = element("thead");
            const titles = element("tr");
            for (const heading of ["Account / الحساب", "Role / الدور", "Verified / مؤكد", "Account status / الحالة", "Business review / مراجعة الشركة", "Moderation / الإشراف"]) {
                titles.append(element("th", heading));
            }
            header.append(titles);
            table.append(header);
            const body = element("tbody");
            for (const account of response.data) {
                const tr = element("tr");
                const identity = element("td");
                identity.append(element("strong", account.name), element("p", account.email), element("small", "#" + account.id));
                tr.append(identity);
                tr.append(element("td", account.role));
                tr.append(element("td", account.email_verified ? "Verified / مؤكد" : "Unverified / غير مؤكد"));
                const currentStatus = account.account_status === "suspended" ? "suspended" : "active";
                tr.append(element("td", currentStatus === "suspended" ? "Suspended / موقوف" : "Active / نشط"));
                const businesses = element("td");
                if (!account.companies.length) {
                    businesses.textContent = "No owned company / لا توجد شركة";
                } else {
                    for (const company of account.companies) {
                        businesses.append(element("p", company.legal_name + " · " + company.country + " · " + company.status));
                    }
                }
                tr.append(businesses);

                const actionCell = element("td");
                if (account.role === "admin") {
                    actionCell.textContent = "Protected Admin account";
                } else {
                    const nextStatus = currentStatus === "active" ? "suspended" : "active";
                    const action = button(nextStatus === "suspended" ? "Suspend / إيقاف" : "Restore / استعادة", () => {
                        if (revision !== expected) return;
                        const form = element("form");
                        const reason = element("textarea");
                        reason.minLength = 10;
                        reason.maxLength = 2000;
                        reason.required = true;
                        reason.setAttribute("aria-label", "Moderation reason");
                        const submit = button("Confirm decision / تأكيد", () => {});
                        submit.type = "submit";
                        const cancel = button("Cancel / إلغاء", () => actionCell.replaceChildren(action, history));
                        form.append(field("Private reason (10-2000 characters)", reason), submit, cancel);
                        form.onsubmit = async (event) => {
                            event.preventDefault();
                            if (revision !== expected || !request) return;
                            if (reason.value.trim().length < 10) {
                                state.textContent = "Enter a review reason of at least 10 characters.";
                                return;
                            }
                            submit.disabled = true;
                            try {
                                await request("admin/accounts/" + account.id + "/moderate", "PATCH", {
                                    expected_status: currentStatus,
                                    account_status: nextStatus,
                                    reason: reason.value.trim()
                                });
                                if (revision === expected) {
                                    state.textContent = "Moderation saved. Existing sessions were revoked.";
                                    await load(currentPage);
                                }
                            } catch {
                                if (revision === expected) {
                                    state.textContent = "Moderation was not saved. Refresh and try again.";
                                }
                            } finally {
                                submit.disabled = false;
                            }
                        };
                        actionCell.replaceChildren(form);
                    });
                    const history = button("Review history / سجل المراجعة", async () => {
                        if (revision !== expected || !request) return;
                        try {
                            const response = await request("admin/accounts/" + account.id + "/moderations");
                            if (revision !== expected) return;
                            const list = element("ul");
                            for (const event of response.data) {
                                list.append(element("li", event.from_status + " → " + event.to_status
                                    + " · " + event.created_at + " · " + event.reason));
                            }
                            if (!response.data.length) list.append(element("li", "No moderation history"));
                            actionCell.replaceChildren(action, history, list);
                        } catch {
                            if (revision === expected) state.textContent = "Private moderation history unavailable.";
                        }
                    });
                    actionCell.append(action, history);
                }
                tr.append(actionCell);
                body.append(tr);
            }
            table.append(body);
            const scroller = element("div");
            scroller.className = "table-scroll";
            scroller.tabIndex = 0;
            scroller.setAttribute("role", "region");
            scroller.setAttribute("aria-label", "Private Admin account directory");
            scroller.append(table);
            const nav = element("div");
            nav.className = "pagination";
            const prev = button("Previous / السابق", () => load(currentPage - 1));
            const next = button("Next / التالي", () => load(currentPage + 1));
            prev.disabled = currentPage <= 1;
            next.disabled = currentPage >= response.last_page;
            nav.append(prev, element("span", "Page " + currentPage + " / " + response.last_page), next);
            output.replaceChildren(scroller, nav);
            state.textContent = response.total + " matching accounts / حساب مطابق. Admin review actions are audited.";
        } catch (error) {
            if (active(expected, controller.signal)) {
                output.replaceChildren();
                state.textContent = "Account directory unavailable. Retry / تعذر التحميل.";
                const retry = button("Retry / إعادة المحاولة", () => load(page));
                output.append(retry);
            }
        }
    }

    function setActor(account, api) {
        revision += 1;
        currentRequest?.abort();
        currentRequest = null;
        request = null;
        root.replaceChildren();
        if (account?.role !== "admin") return;
        request = api;
        const form = element("form");
        form.setAttribute("aria-label", "Account filters");
        search = element("input");
        search.type = "search";
        search.maxLength = 100;
        search.placeholder = "Name or email";
        role = select([["", "All / الكل"], ["customer", "Customer"], ["vendor", "Vendor"], ["admin", "Admin"]]);
        verified = select([["", "Any / الكل"], ["yes", "Verified"], ["no", "Unverified"]]);
        accountStatus = select([["", "All statuses"], ["active", "Active"], ["suspended", "Suspended"]]);
        const submit = element("button", "Search / بحث");
        submit.type = "submit";
        form.append(field("Search / البحث", search), field("Role / الدور", role), field("Email verification / التحقق", verified), field("Account status / حالة الحساب", accountStatus), submit);
        form.onsubmit = event => {
            event.preventDefault();
            load(1);
        };
        state = element("p");
        state.setAttribute("role", "status");
        state.setAttribute("aria-live", "polite");
        output = element("div");
        root.append(form, state, output);
        load(1);
    }

    return { setActor };
})();
