"use strict";

// Private Admin KYC triage using the existing authorized VendorOps workflow.
// This component neither stores evidence nor invents an alternate review API.
window.EvAdminKycQueue = (() => {
    const root = document.getElementById("admin-kyc-queue");
    let generation = 0;
    let currentFetch = null;
    let request = null;
    let status = null;
    let country = null;
    let state = null;
    let results = null;
    let currentPage = 1;

    function element(tag, value) {
        const node = document.createElement(tag);
        if (value !== undefined) node.textContent = String(value);
        return node;
    }

    function button(value, action) {
        const node = element("button", value);
        node.type = "button";
        node.onclick = action;
        return node;
    }

    function field(text, control) {
        const label = element("label");
        label.append(element("span", text), control);
        return label;
    }

    function alive(expected, signal) {
        return generation === expected && request !== null && !signal.aborted;
    }

    function allowDecisions(company) {
        return {
            submitted: ["approved", "rejected", "changes_requested"],
            approved: ["suspended", "changes_requested"],
            suspended: ["approved", "changes_requested"],
        }[company.status] || [];
    }

    function renderCompany(company, expected, signal) {
        const companyId = Number(company.id);
        const card = element("article");
        if (!Number.isSafeInteger(companyId) || companyId <= 0) return card;

        card.append(
            element("h4", company.legal_name),
            element("p", "Company #" + companyId + " · " + company.country + " · " + company.status),
            element("p", "Owner: " + (company.owner?.name ?? "Unknown") +
                " · " + (company.owner?.email ?? "No email") +
                (company.owner?.account_status === "suspended" ? " · Owner suspended" : "")),
            element("p", "License expires: " + (company.license_expires_on || "Not provided")),
            element("p", "Evidence on file: " + company.document_count + " · " +
                (Array.isArray(company.document_kinds) && company.document_kinds.length
                    ? company.document_kinds.join(", ") : "No documents")),
            element("p", "Document presence does not verify authenticity. Manual review required.")
        );

        const documentHost = element("div");
        const documentButton = button("Inspect private evidence / مراجعة المستندات", async () => {
            if (!alive(expected, signal)) return;
            documentButton.disabled = true;
            try {
                const docs = await request(
                    "vendor-ops/companies/" + companyId + "/documents?page=1",
                    "GET", undefined, signal
                );
                if (!alive(expected, signal)) return;
                const list = element("ul");
                const entries = Array.isArray(docs.data) ? docs.data : [];
                for (const doc of entries) {
                    const documentId = Number(doc.id);
                    if (!Number.isSafeInteger(documentId) || documentId <= 0) continue;
                    const item = element("li");
                    const link = element("a",
                        String(doc.kind || "Document") + " · " + String(doc.mime_type || "File"));
                    link.href = "/api/vendor-ops/companies/" + companyId +
                        "/documents/" + documentId;
                    link.rel = "noopener noreferrer";
                    link.target = "_blank";
                    item.append(link);
                    list.append(item);
                }
                if (!entries.length) list.append(element("li", "No submitted documents."));
                documentHost.replaceChildren(
                    element("p", "Private downloads use the authenticated, no-store KYC endpoint."),
                    list
                );
            } catch {
                if (alive(expected, signal)) {
                    documentHost.replaceChildren(element("p", "Document review unavailable."));
                }
            } finally {
                documentButton.disabled = false;
            }
        });
        card.append(documentButton, documentHost);

        const decisions = allowDecisions(company);
        if (decisions.length) {
            const form = element("form");
            form.setAttribute("aria-label", "KYC decision for company " + companyId);
            const choice = element("select");
            choice.setAttribute("aria-label", "Review decision");
            for (const decision of decisions) {
                const option = element("option", decision.replaceAll("_", " "));
                option.value = decision;
                choice.append(option);
            }
            choice.value = decisions[0];
            const reason = element("textarea");
            reason.maxLength = 2000;
            reason.minLength = 10;
            reason.required = true;
            reason.setAttribute("aria-label", "Private KYC review reason");
            const submit = button("Confirm KYC review / تأكيد المراجعة", () => {});
            submit.type = "submit";
            form.append(field("Decision", choice), field("Private review reason", reason), submit);
            form.onsubmit = async (event) => {
                event.preventDefault();
                if (!alive(expected, signal)) return;
                if (reason.value.trim().length < 10) {
                    state.textContent = "Explain the KYC decision with at least 10 characters.";
                    return;
                }
                if (choice.value === "approved" && (
                    company.owner?.account_status === "suspended" ||
                    !Array.isArray(company.document_kinds) ||
                    !company.document_kinds.includes("trade_license")
                )) {
                    state.textContent = "Cannot approve without active owner and trade-license evidence.";
                    return;
                }
                submit.disabled = true;
                try {
                    if (choice.value === "approved") {
                        // Queue metadata proves only that a document row
                        // exists. Recheck the actual encrypted trade-license
                        // before offering a positive manual review decision.
                        const latest = await request(
                            "vendor-ops/companies/" + companyId,
                            "GET", undefined, signal
                        );
                        if (!alive(expected, signal)) return;
                        if (latest?.status !== company.status) {
                            state.textContent =
                                "Company review state changed. Refresh before deciding.";
                            return;
                        }
                        if (!latest.submission_readiness ||
                            latest.submission_readiness.license_valid !== true ||
                            latest.submission_readiness.trade_license_integrity_valid !== true) {
                            state.textContent =
                                "Cannot approve: trade-license evidence is unavailable, expired or failed integrity verification.";
                            return;
                        }
                    }
                    await request("vendor-ops/companies/" + companyId + "/review", "POST", {
                        status: choice.value,
                        reason: reason.value.trim(),
                    }, signal);
                    if (alive(expected, signal)) await load(currentPage);
                } catch {
                    if (alive(expected, signal)) state.textContent =
                        "KYC decision was not saved. Refresh the company review state.";
                } finally {
                    submit.disabled = false;
                }
            };
            card.append(form);
        } else {
            card.append(element("p", "No available review transition for this status."));
        }

        return card;
    }

    async function load(page = 1) {
        if (!request) return;
        currentFetch?.abort();
        const controller = new AbortController();
        currentFetch = controller;
        const epoch = generation;
        currentPage = page;
        const filter = new URLSearchParams({
            status: status.value,
            page: String(page),
        });
        if (country.value.trim()) filter.set("country", country.value.trim().toUpperCase());
        results.replaceChildren();
        state.textContent = "Loading private KYC cases / جاري التحميل…";
        try {
            const response = await request("admin/kyc-queue?" + filter.toString(),
                "GET", undefined, controller.signal);
            if (!alive(epoch, controller.signal) || currentFetch !== controller) return;
            const rows = Array.isArray(response.data) ? response.data : [];
            for (const company of rows) {
                results.append(renderCompany(company, epoch, controller.signal));
            }
            if (!rows.length) results.append(element("p", "No companies in this review queue."));
            const nav = element("div");
            nav.className = "pagination";
            const previous = button("Previous / السابق", () => load(page - 1));
            const next = button("Next / التالي", () => load(page + 1));
            previous.disabled = page <= 1;
            next.disabled = page >= response.last_page;
            nav.append(previous, element("span",
                "Page " + page + " / " + response.last_page), next);
            results.append(nav);
            state.textContent = String(response.total) + " companies in review queue.";
        } catch {
            if (alive(epoch, controller.signal)) {
                results.replaceChildren(button("Retry / إعادة المحاولة", () => load(page)));
                state.textContent = "KYC queue unavailable.";
            }
        }
    }

    function setActor(user, api) {
        generation += 1;
        currentFetch?.abort();
        currentFetch = null;
        request = null;
        root.replaceChildren();
        if (user?.role !== "admin") return;

        request = api;
        const filters = element("form");
        filters.setAttribute("aria-label", "KYC queue filters");
        status = element("select");
        for (const name of [
            "submitted", "approved", "rejected", "changes_requested", "suspended", "draft"
        ]) {
            const option = element("option", name.replaceAll("_", " "));
            option.value = name;
            status.append(option);
        }
        status.value = "submitted";
        country = element("input");
        country.type = "text";
        country.maxLength = 2;
        country.placeholder = "AE";
        country.setAttribute("aria-label", "Country ISO code");
        const submit = button("Apply filters / تطبيق", () => {});
        submit.type = "submit";
        filters.append(field("Review state", status), field("Country (optional)", country), submit);
        filters.onsubmit = event => { event.preventDefault(); load(1); };
        state = element("p");
        state.setAttribute("role", "status");
        state.setAttribute("aria-live", "polite");
        results = element("div");
        root.append(filters, state, results);
        load(1);
    }

    return { setActor };
})();
