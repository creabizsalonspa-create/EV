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
    let companySearch = null;
    let companyCountry = null;
    let companyStatus = null;
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
        if (!request || !Number.isSafeInteger(page) || page < 1 || page > 100000) return;
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
        if (companySearch.value.trim()) params.set("company_q", companySearch.value.trim());
        if (companyCountry.value.trim()) params.set("company_country", companyCountry.value.trim().toUpperCase());
        if (companyStatus.value) params.set("company_status", companyStatus.value);
        state.textContent = "Loading private account directory / جاري التحميل…";
        output.replaceChildren();
        try {
            const response = await request("admin/accounts?" + params.toString(), "GET", undefined, controller.signal);
            if (!active(expected, controller.signal) || currentRequest !== controller) return;
            if (response?.current_page !== page || !Number.isSafeInteger(response.last_page) || response.last_page < page ||
                !Number.isSafeInteger(response.total) || response.total < 0 || !Array.isArray(response.data) || response.data.length > 25 ||
                new Set(response.data.map(account => account?.id)).size !== response.data.length ||
                !response.data.every(account => Number.isSafeInteger(account?.id) && account.id > 0 &&
                    ['customer','vendor','admin'].includes(account.role) && ['active','suspended'].includes(account.account_status) &&
                    typeof account.email_verified === 'boolean' && Array.isArray(account.companies))) throw Error('Invalid account directory');
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
                const current = () => active(expected, controller.signal) && currentRequest === controller;
                let actionRevision = 0;
                let decisionUnconfirmed = false;
                if (account.role === "admin") {
                    actionCell.textContent = "Protected Admin account";
                } else {
                    const nextStatus = currentStatus === "active" ? "suspended" : "active";
                    const action = button(nextStatus === "suspended" ? "Suspend / إيقاف" : "Restore / استعادة", () => {
                        if (!current() || decisionUnconfirmed) return;
                        const own = ++actionRevision;
                        const form = element("form");
                        const reason = element("textarea");
                        reason.minLength = 10;
                        reason.maxLength = 2000;
                        reason.required = true;
                        reason.setAttribute("aria-label", "Moderation reason");
                        const submit = button("Confirm decision / تأكيد", () => {});
                        submit.type = "submit";
                        const cancel = button("Cancel / إلغاء", () => {
                            if (!current() || own !== actionRevision || submit.disabled) return;
                            actionRevision++;
                            actionCell.replaceChildren(action, history);
                        });
                        form.append(field("Private reason (10-2000 characters)", reason), submit, cancel);
                        form.onsubmit = async (event) => {
                            event.preventDefault();
                            if (!current() || own !== actionRevision || submit.disabled) return;
                            if (reason.value.trim().length < 10 || reason.value.trim().length > 2000) {
                                state.textContent = "Enter a review reason of 10–2000 characters.";
                                return;
                            }
                            submit.disabled = true;
                            try {
                                const receipt = await request("admin/accounts/" + account.id + "/moderate", "PATCH", {
                                    expected_status: currentStatus,
                                    account_status: nextStatus,
                                    reason: reason.value.trim()
                                }, controller.signal);
                                if (current() && own === actionRevision) {
                                    if (receipt?.id !== account.id || receipt.account_status !== nextStatus)
                                        throw Error("Unexpected moderation receipt");
                                    state.textContent = "Moderation saved. Existing sessions were revoked.";
                                    await load(currentPage);
                                }
                            } catch {
                                if (current() && own === actionRevision) {
                                    decisionUnconfirmed = true;
                                    actionRevision++;
                                    actionCell.replaceChildren(history);
                                    state.textContent = "Moderation outcome unconfirmed. Refresh before another decision.";
                                }
                            } finally {
                                submit.disabled = false;
                            }
                        };
                        actionCell.replaceChildren(form);
                    });
                    async function loadHistory(historyPage = 1) {
                        if (!current() || !Number.isSafeInteger(historyPage) || historyPage < 1 || historyPage > 100000) return;
                        const own = ++actionRevision;
                        actionCell.replaceChildren(element("p", "Loading private review history… / جارٍ تحميل سجل المراجعة…"));
                        try {
                            const response = await request("admin/accounts/" + account.id + "/moderations" +
                                (historyPage === 1 ? "" : "?page=" + historyPage), "GET", undefined, controller.signal);
                            if (!current() || own !== actionRevision) return;
                            if (response?.target_user_id !== account.id || !Array.isArray(response.data) || response.data.length > 25 ||
                                !Number.isSafeInteger(response.current_page) || response.current_page !== historyPage ||
                                !Number.isSafeInteger(response.last_page) || response.last_page < historyPage || response.last_page > 100000 ||
                                new Set(response.data.map(event => event?.id)).size !== response.data.length ||
                                !response.data.every(event => event && Number.isSafeInteger(event.id) && event.id > 0 && ["active","suspended"].includes(event.from_status) &&
                                    ["active","suspended"].includes(event.to_status) && event.from_status !== event.to_status &&
                                    typeof event.created_at === "string" && typeof event.reason === "string" && event.reason.length <= 2000))
                                throw Error("Unexpected private history");
                            const list = element("ul");
                            for (const event of response.data) {
                                list.append(element("li", event.from_status + " → " + event.to_status
                                    + " · " + event.created_at + " · " + event.reason));
                            }
                            if (!response.data.length) list.append(element("li", "No moderation history"));
                            const nav = element("div");
                            const previous = button("Previous history / السجل السابق", () => {
                                if (own === actionRevision && historyPage > 1) loadHistory(historyPage - 1);
                            });
                            const next = button("Next history / السجل التالي", () => {
                                if (own === actionRevision && historyPage < response.last_page) loadHistory(historyPage + 1);
                            });
                            previous.disabled = historyPage <= 1;
                            next.disabled = historyPage >= response.last_page;
                            nav.append(previous, element("span", "Page " + historyPage + " / " + response.last_page), next);
                            actionCell.replaceChildren(...(decisionUnconfirmed ? [history, list, nav] : [action, history, list, nav]));
                        } catch {
                            if (current() && own === actionRevision) {
                                actionCell.replaceChildren(...(decisionUnconfirmed ? [history] : [action, history]));
                                state.textContent = "Private moderation history unavailable.";
                            }
                        }
                    }
                    const history = button("Review history / سجل المراجعة", () => loadHistory());
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
            next.disabled = currentPage >= Math.min(response.last_page, 100000);
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
        companySearch = element("input");
        companySearch.type = "search";
        companySearch.maxLength = 100;
        companySearch.placeholder = "Business legal name";
        companyCountry = element("input");
        companyCountry.type = "text";
        companyCountry.maxLength = 2;
        companyCountry.placeholder = "AE";
        companyStatus = select([
            ["", "Any company status / الكل"],
            ["draft", "Draft / مسودة"],
            ["submitted", "Submitted / مرسلة"],
            ["approved", "Approved / معتمدة"],
            ["rejected", "Rejected / مرفوضة"],
            ["changes_requested", "Changes required / مطلوب تعديل"],
            ["suspended", "Suspended / موقوفة"]
        ]);
        const submit = element("button", "Search / بحث");
        submit.type = "submit";
        form.append(field("Account name/email / الاسم", search),
            field("Role / الدور", role),
            field("Email verification / التحقق", verified),
            field("Account status / حالة الحساب", accountStatus),
            field("Business name / اسم الشركة", companySearch),
            field("Business country / الدولة", companyCountry),
            field("Business review / حالة الشركة", companyStatus), submit);
        form.oninput = () => {
            currentRequest?.abort();
            currentRequest = null;
            output.replaceChildren();
            state.textContent = 'Search to apply the filters.';
        };
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
