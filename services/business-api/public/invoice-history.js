/* Read-only finance history; no payment instruments, captures or payouts. */
window.createInvoiceHistory = ({ root, api, ui, actor }) => {
    let generation = 0,
        detailVersion = 0,
        request,
        query = {},
        current = 1;
    const raw = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = value ?? "—";
        return n;
    };
    function clear() {
        generation++;
        detailVersion++;
        request?.abort();
        root.replaceChildren();
        root.hidden = true;
        query = {};
        current = 1;
    }
    function button(parent, label, action) {
        const b = ui("button", label);
        b.type = "button";
        b.onclick = async () => {
            b.disabled = true;
            try {
                await action();
            } finally {
                if (b.isConnected) b.disabled = false;
            }
        };
        parent.append(b);
        return b;
    }
    function amount(parent, label, value, currency) {
        parent.append(
            ui("p", "{label}: {amount} minor units {currency}", {
                label: { translate: label },
                amount: value,
                currency,
            }),
        );
    }
    async function load(page = 1, reset = false) {
        if (reset) clear();
        if (!actor()) return;
        request?.abort();
        request = new AbortController();
        const signal = request.signal,
            epoch = ++generation;
        detailVersion++;
        current = page;
        root.hidden = false;
        root.replaceChildren(
            ui("h2", "Invoice history"),
            ui("p", "Invoice simulation disclaimer"),
        );
        const form = document.createElement("form");
        form.dataset.invoiceFilters = "";
        for (const [name, label, type, pattern] of [
            ["q", "Invoice number prefix", "text", "[A-Za-z0-9-]+"],
            ["currency", "Currency", "text", "[A-Z]{3}"],
            ["from", "Invoice from UTC", "date"],
            ["to", "Invoice through UTC", "date"],
        ]) {
            const l = ui("label", label),
                input = document.createElement("input");
            input.name = name;
            input.type = type;
            input.value = query[name] || "";
            if (pattern) input.pattern = pattern;
            if (name === "q") input.maxLength = 80;
            if (name === "currency") input.maxLength = 3;
            l.append(input);
            form.append(l);
        }
        const search = ui("button", "Search");
        search.type = "submit";
        form.append(search);
        form.onsubmit = (e) => {
            e.preventDefault();
            query = Object.fromEntries(
                [...new FormData(form)].filter(([, v]) => v !== ""),
            );
            load();
        };
        root.append(form);
        const state = ui("p", "Loading…");
        state.setAttribute("role", "status");
        root.append(state);
        try {
            const result = await api(
                "finance/invoices?" + new URLSearchParams({ ...query, page }),
                "GET",
                undefined,
                signal,
            );
            if (epoch !== generation || signal.aborted) return;
            if (result.data.some((row) => row.evidence !== "SIMULATED"))
                throw new Error("Unsupported finance evidence.");
            state.replaceChildren();
            const detail = document.createElement("div");
            detail.dataset.invoiceDetail = "";
            for (const invoice of result.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                card.append(
                    raw("h3", invoice.number),
                    raw("p", invoice.created_at),
                );
                amount(card, "Total", invoice.total_minor, invoice.currency);
                button(card, "View invoice", () =>
                    show(invoice.id, detail, epoch),
                );
                root.append(card);
            }
            if (!result.data.length)
                root.append(ui("p", "No matching invoices"));
            root.append(
                ui("p", "Page {page} of {last} · {total} records", {
                    page: result.current_page,
                    last: result.last_page,
                    total: result.total,
                }),
            );
            const pager = document.createElement("div");
            pager.className = "pagination";
            button(pager, "Previous", () =>
                load(result.current_page - 1),
            ).disabled = result.current_page <= 1;
            button(pager, "Next", () =>
                load(result.current_page + 1),
            ).disabled = result.current_page >= result.last_page;
            button(pager, "Refresh", () => load(current));
            root.append(pager, detail);
        } catch (e) {
            if (epoch === generation && !signal.aborted) {
                state.replaceChildren(
                    ui(
                        "span",
                        e.status === 422
                            ? "Please check the entered values."
                            : "Invoice history load failed",
                    ),
                );
                button(root, "Retry", () => load(page));
            }
        }
    }
    async function show(id, target, epoch) {
        const version = ++detailVersion;
        target.replaceChildren(ui("p", "Loading…"));
        try {
            const summary = await api(
                `finance/invoices/${id}`,
                "GET",
                undefined,
                request.signal,
            );
            if (epoch !== generation || version !== detailVersion) return;
            if (
                summary.payment_provider !== "SIMULATOR_ONLY" ||
                summary.real_payment_processed !== false ||
                summary.invoice.evidence !== "SIMULATED"
            )
                throw new Error("Unsupported finance evidence.");
            const invoice = summary.invoice;
            target.replaceChildren(
                ui("h3", "Invoice details"),
                raw("h4", invoice.number),
                ui("p", "Invoice simulation disclaimer"),
            );
            for (const [label, value] of [
                ["Subtotal", invoice.subtotal_minor],
                ["Tax", invoice.tax_minor],
                ["Total", invoice.total_minor],
                ["Simulated collected", summary.paid_minor],
                ["Simulated refunded", summary.refunded_minor],
                ["Amount due", summary.amount_due_minor],
            ])
                amount(target, label, value, invoice.currency);
            target.append(
                ui(
                    "p",
                    summary.ledger_balanced
                        ? "Ledger balanced"
                        : "Ledger needs reconciliation",
                ),
            );
            if (["vendor", "admin"].includes(actor()?.role)) {
                target.append(ui("p", "Allocation disclaimer"));
                amount(
                    target,
                    "Simulated commission allocation",
                    invoice.commission_minor,
                    invoice.currency,
                );
                amount(
                    target,
                    "Simulated vendor allocation",
                    invoice.vendor_minor,
                    invoice.currency,
                );
            }
            for (const [key, label] of [
                ["payments", "Simulated collections"],
                ["refunds", "Simulated refunds"],
            ]) {
                target.append(ui("h4", label));
                if (!summary[key]?.length)
                    target.append(ui("p", "No recorded transactions"));
                for (const row of summary[key] || []) {
                    const card = document.createElement("article");
                    card.className = "station-card";
                    amount(card, label, row.amount_minor, invoice.currency);
                    card.append(raw("p", row.created_at));
                    if (key === "refunds") card.append(raw("p", row.reason));
                    target.append(card);
                }
            }
        } catch (e) {
            if (epoch === generation && version === detailVersion) {
                target.replaceChildren(ui("p", "Invoice history load failed"));
                button(target, "Retry", () => show(id, target, epoch));
            }
        }
    }
    return { setActor: () => load(1, true), clear };
};
