window.createTariffWorkspace = ({ root, api, ui, actor }) => {
    let generation = 0,
        detailGeneration = 0,
        request;
    const raw = (tag, text) => {
        const n = document.createElement(tag);
        n.textContent = text ?? "—";
        return n;
    };
    function clear() {
        generation++;
        detailGeneration++;
        request?.abort();
        root.replaceChildren();
        root.hidden = true;
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
    function pager(parent, data, load) {
        parent.append(
            ui("p", "Page {page} of {last} · {total} records", {
                page: data.current_page,
                last: data.last_page,
                total: data.total,
            }),
        );
        button(parent, "Previous", () => load(data.current_page - 1)).disabled =
            data.current_page <= 1;
        button(parent, "Next", () => load(data.current_page + 1)).disabled =
            data.current_page >= data.last_page;
    }
    async function load(page = 1) {
        clear();
        if (!["vendor", "admin"].includes(actor()?.role)) return;
        const epoch = generation;
        request = new AbortController();
        root.hidden = false;
        root.append(
            ui("h2", "Simulated pricing"),
            ui("p", "Tariff disclaimer"),
            ui("p", "Loading…"),
        );
        try {
            const result = await api(
                `finance/stations?page=${page}`,
                "GET",
                undefined,
                request.signal,
            );
            if (epoch !== generation) return;
            if (result.data.some((row) => row.evidence !== "SIMULATED"))
                throw new Error("Unsupported pricing evidence.");
            root.replaceChildren(
                ui("h2", "Simulated pricing"),
                ui("p", "Tariff disclaimer"),
            );
            button(root, "Refresh", () => load(page));
            const detail = document.createElement("div");
            detail.dataset.tariffDetail = "";
            for (const station of result.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                card.append(
                    raw("h3", station.name),
                    raw("p", station.currency),
                );
                button(card, "Manage pricing", () => editor(station, detail));
                root.append(card);
            }
            if (!result.data.length)
                root.append(ui("p", "No pricing stations"));
            pager(root, result, load);
            root.append(detail);
        } catch (e) {
            if (epoch === generation) {
                root.replaceChildren(
                    ui("h2", "Simulated pricing"),
                    ui("p", "Pricing load failed"),
                );
                button(root, "Retry", () => load(page));
            }
        }
    }
    async function editor(station, target, page = 1) {
        const epoch = generation,
            version = ++detailGeneration;
        target.replaceChildren(ui("p", "Loading…"));
        try {
            const result = await api(
                `stations/${station.id}/tariffs?page=${page}`,
                "GET",
                undefined,
                request.signal,
            );
            if (epoch !== generation || version !== detailGeneration) return;
            if (result.pricing_context?.evidence !== "SIMULATED")
                throw new Error("Unsupported pricing evidence.");
            station = { ...station, ...result.pricing_context };
            target.replaceChildren(
                raw("h3", station.name),
                ui("p", "Tariff snapshot disclaimer"),
            );
            button(target, "Refresh pricing history", () =>
                editor(station, target),
            );
            if (
                Number.isInteger(station.minor_units) &&
                station.minor_units >= 0 &&
                station.minor_units <= 3 &&
                page === 1
            ) {
                const latest = result.data[0],
                    form = document.createElement("form");
                form.dataset.tariffForm = "";
                form.append(ui("h4", "Publish pricing version"));
                for (const [name, label, value] of [
                    [
                        "price",
                        "Price per kWh",
                        latest
                            ? EvMoney.format(
                                  latest.rate_minor_per_kwh,
                                  latest.minor_units,
                              )
                            : "",
                    ],
                    [
                        "tax",
                        "Tax percentage",
                        latest ? EvMoney.format(latest.tax_bps, 2) : "",
                    ],
                    ...(actor()?.role === "admin"
                        ? [
                              [
                                  "commission",
                                  "Commission percentage",
                                  latest
                                      ? EvMoney.format(latest.commission_bps, 2)
                                      : "",
                              ],
                          ]
                        : []),
                ]) {
                    const l = ui("label", label),
                        input = document.createElement("input");
                    input.name = name;
                    input.inputMode = "decimal";
                    input.required = true;
                    input.maxLength = 20;
                    input.value = value;
                    l.append(input);
                    form.append(l);
                }
                form.append(
                    ui("p", "Pricing maximum", {
                        amount: EvMoney.format(1000000, station.minor_units),
                        currency: station.currency,
                    }),
                );
                if (actor()?.role !== "admin")
                    form.append(ui("p", "Commission policy retained"));
                const state = ui("p", "");
                state.setAttribute("role", "status");
                const publish = ui("button", "Publish pricing version");
                publish.type = "submit";
                form.append(publish, state);
                const key = crypto.randomUUID();
                form.onsubmit = async (e) => {
                    e.preventDefault();
                    const price = EvMoney.parse(
                            form.elements.price.value,
                            station.minor_units,
                        ),
                        tax = EvMoney.parse(form.elements.tax.value, 2, 10000),
                        commission =
                            actor()?.role === "admin"
                                ? EvMoney.parse(
                                      form.elements.commission.value,
                                      2,
                                      10000,
                                  )
                                : 0;
                    if ([price, tax, commission].includes(null)) {
                        EvLocale.bind(state, "Invalid exact price");
                        return;
                    }
                    const fields = [...form.elements];
                    fields.forEach((n) => (n.disabled = true));
                    try {
                        await api(`stations/${station.id}/tariffs`, "POST", {
                            rate_minor_per_kwh: price,
                            tax_bps: tax,
                            commission_bps: commission,
                            idempotency_key: key,
                            expected_minor_units: station.minor_units,
                            expected_currency: station.currency,
                        });
                        if (
                            epoch === generation &&
                            version === detailGeneration
                        )
                            await editor(station, target);
                    } catch (error) {
                        if (
                            epoch === generation &&
                            version === detailGeneration
                        ) {
                            EvLocale.bind(
                                state,
                                [422, 429].includes(error.status)
                                    ? "Pricing rejected; correct or wait"
                                    : "Pricing outcome uncertain",
                            );
                            if ([422, 429].includes(error.status))
                                fields.forEach((n) => (n.disabled = false));
                        }
                    }
                };
                target.append(form);
            } else if (station.minor_units == null)
                target.append(ui("p", "Pricing region missing"));
            if (!result.data.length)
                target.append(ui("p", "No pricing versions"));
            for (const row of result.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                card.append(
                    ui("h4", "Pricing version {id}", { id: row.id }),
                    raw(
                        "p",
                        `${EvMoney.format(row.rate_minor_per_kwh, row.minor_units)} ${row.currency} / kWh`,
                    ),
                );
                card.append(
                    ui("p", "Pricing percentages", {
                        tax: EvMoney.format(row.tax_bps, 2),
                        commission: EvMoney.format(row.commission_bps, 2),
                    }),
                    raw("p", row.effective_from),
                );
                target.append(card);
            }
            pager(target, result, (page) => editor(station, target, page));
        } catch (e) {
            if (epoch === generation && version === detailGeneration) {
                target.replaceChildren(ui("p", "Pricing load failed"));
                button(target, "Retry", () => editor(station, target, page));
            }
        }
    }
    return { clear, setActor: () => load() };
};
