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
            const effective = result.active_tariff;
            if (!Object.hasOwn(result, "active_tariff") ||
                typeof result.active_tariff_context_matches !== "boolean" ||
                typeof result.tariff_as_of !== "string" ||
                !Number.isFinite(Date.parse(result.tariff_as_of)) ||
                (effective === null && result.active_tariff_context_matches) ||
                (effective !== null && (!Number.isSafeInteger(effective?.id) ||
                    effective.id < 1 || effective.station_id !== station.id ||
                    !Number.isSafeInteger(effective.rate_minor_per_kwh) ||
                    effective.rate_minor_per_kwh < 0 || effective.rate_minor_per_kwh > 1000000 ||
                    !Number.isInteger(effective.tax_bps) || effective.tax_bps < 0 || effective.tax_bps > 10000 ||
                    !Number.isInteger(effective.commission_bps) || effective.commission_bps < 0 || effective.commission_bps > 10000 ||
                    (result.active_tariff_context_matches &&
                        (effective.currency !== station.currency || effective.minor_units !== station.minor_units)))))
                throw new Error("Invalid effective tariff authority.");
            const compatible = effective === null || result.active_tariff_context_matches;
            const matchingRow = effective && result.data.find(row => row.id === effective.id);
            if (matchingRow && ["station_id", "rate_minor_per_kwh", "tax_bps",
                "commission_bps", "currency", "minor_units", "effective_from"]
                .some(k => matchingRow[k] !== effective[k]))
                throw new Error("Conflicting effective tariff history.");
            target.replaceChildren(
                raw("h3", station.name),
                ui("p", "Tariff snapshot disclaimer"),
            );
            button(target, "Refresh pricing history", () =>
                editor(station, target),
            );
            const label = raw("p", "");
            label.dataset.en = effective === null ? "No effective pricing version." :
                "Effective pricing version #" + effective.id + " · " + result.tariff_as_of;
            label.dataset.ar = effective === null ? "لا يوجد إصدار تسعير سارٍ." :
                "إصدار التسعير الساري #" + effective.id + " · " + result.tariff_as_of;
            label.textContent = EvLocale.language === "ar" ? label.dataset.ar : label.dataset.en;
            if (effective !== null) label.dataset.effectiveTariff = String(effective.id);
            target.append(label);
            if (!compatible) target.append(raw("p",
                "Effective tariff denomination mismatch / اختلاف عملة أو دقة التسعير الساري"));
            if (
                compatible &&
                Number.isInteger(station.minor_units) &&
                station.minor_units >= 0 &&
                station.minor_units <= 3 &&
                page === 1
            ) {
                const latest = effective,
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
                            expected_active_tariff_id: effective?.id ?? null,
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
