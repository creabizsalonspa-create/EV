/* OCPI data is partner-reported, never a booking or billing authority. */
window.createRoamingWorkspace = ({
    publicRoot,
    privateRoot,
    api,
    ui,
    actor,
    notice,
}) => {
    let publicRequest,
        privateRequest,
        generation = 0,
        details = 0;
    const raw = (tag, value) => {
        const node = document.createElement(tag);
        node.textContent = value ?? "";
        return node;
    };
    const line = (parent, label, value) => {
        const p = ui("p", label);
        p.append(raw("span", ` ${value ?? "—"}`));
        parent.append(p);
    };
    const button = (parent, label, action) => {
        const b = ui("button", label);
        b.type = "button";
        b.onclick = async () => {
            b.disabled = true;
            try {
                await action();
            } catch (e) {
                if (e.name !== "AbortError" && b.isConnected) notice(e);
            } finally {
                if (b.isConnected) b.disabled = false;
            }
        };
        parent.append(b);
        return b;
    };
    function pager(parent, result, load) {
        parent.append(
            ui("p", "Page {page} of {last} · {total} records", {
                page: result.current_page,
                last: result.last_page,
                total: result.total,
            }),
        );
        for (const [label, target, enabled] of [
            ["Previous", result.current_page - 1, result.current_page > 1],
            [
                "Next",
                result.current_page + 1,
                result.current_page < result.last_page,
            ],
        ])
            button(parent, label, () => load(target)).disabled = !enabled;
    }
    function location(parent, item) {
        const card = document.createElement("article");
        card.className = "station-card";
        card.append(raw("h3", item.name || item.id));
        card.append(
            raw(
                "p",
                [item.address, item.city, item.country]
                    .filter(Boolean)
                    .join(" · "),
            ),
        );
        line(
            card,
            "Roaming coordinates",
            `${item.latitude}, ${item.longitude}`,
        );
        line(card, "Roaming source updated", item.last_updated);
        for (const evse of item.evses || []) {
            const group = document.createElement("div");
            line(group, "Roaming EVSE", evse.uid);
            line(group, "Roaming reported status", evse.reported_status);
            for (const connector of evse.connectors || [])
                line(
                    group,
                    "Roaming connector",
                    `${connector.id} · ${connector.standard}`,
                );
            card.append(group);
        }
        card.append(ui("p", "Roaming unknown availability"));
        parent.append(card);
    }
    async function catalog(page = 1) {
        publicRequest?.abort();
        const request = new AbortController();
        publicRequest = request;
        publicRoot.setAttribute("aria-busy", "true");
        publicRoot.replaceChildren(
            ui("h2", "Roaming discovery"),
            ui("p", "Roaming disclaimer"),
            ui("p", "Loading…"),
        );
        try {
            const result = await api(
                `roaming/locations?page=${page}`,
                "GET",
                undefined,
                request.signal,
            );
            if (request.signal.aborted) return;
            publicRoot.replaceChildren(
                ui("h2", "Roaming discovery"),
                ui("p", "Roaming disclaimer"),
            );
            button(publicRoot, "Refresh", () => catalog(page));
            const list = document.createElement("div");
            list.className = "station-grid";
            for (const row of result.data) {
                // Fail closed if a future API changes the safety contract.
                if (
                    row.evidence !== "PARTNER_REPORTED_UNVERIFIED" ||
                    row.availability !== "unknown" ||
                    row.remote_control !== false ||
                    row.reservable !== false
                )
                    throw new Error("Unexpected roaming capability.");
                location(list, row.location);
                line(
                    list.lastElementChild,
                    "Roaming received",
                    row.received_at,
                );
            }
            if (!result.data.length)
                list.append(ui("p", "No published roaming locations"));
            publicRoot.append(list);
            pager(publicRoot, result, catalog);
        } catch (e) {
            if (!request.signal.aborted) {
                publicRoot.replaceChildren(
                    ui("h2", "Roaming discovery"),
                    ui("p", "Roaming load failed"),
                );
                button(publicRoot, "Retry", () => catalog(page));
            }
        } finally {
            if (publicRequest === request)
                publicRoot.removeAttribute("aria-busy");
        }
    }
    function clear() {
        generation++;
        details++;
        privateRequest?.abort();
        privateRoot.replaceChildren();
        privateRoot.hidden = true;
    }
    async function providers(page = 1) {
        clear();
        if (!["vendor", "admin"].includes(actor()?.role)) return;
        const epoch = generation;
        const request = new AbortController();
        privateRequest = request;
        privateRoot.hidden = false;
        privateRoot.append(
            ui("h2", "Roaming operations"),
            ui("p", "Roaming operator disclaimer"),
            ui("p", "Loading…"),
        );
        try {
            const result = await api(
                `ocpi/providers?page=${page}`,
                "GET",
                undefined,
                request.signal,
            );
            if (epoch !== generation) return;
            privateRoot.replaceChildren(
                ui("h2", "Roaming operations"),
                ui("p", "Roaming operator disclaimer"),
            );
            button(privateRoot, "Refresh", () => providers(page));
            const detail = document.createElement("div");
            detail.dataset.roamingDetail = "";
            for (const provider of result.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                card.append(raw("h3", provider.name));
                card.append(
                    ui(
                        "p",
                        provider.authorized
                            ? "Roaming authorized"
                            : "Roaming revoked",
                    ),
                );
                line(card, "Roaming protocol", provider.version);
                line(card, "Roaming last sync", provider.last_synced_at);
                for (const module of [
                    "locations",
                    "tariffs",
                    "sessions",
                    "cdrs",
                ])
                    button(card, `Roaming ${module}`, () =>
                        records(provider, module, detail),
                    );
                if (provider.authorized) {
                    button(card, "Roaming negotiate", async () => {
                        if (
                            !confirm(
                                EvLocale.text("Roaming external confirmation"),
                            )
                        )
                            return;
                        await api(
                            `ocpi/providers/${provider.id}/negotiate`,
                            "POST",
                            {},
                        );
                        if (epoch === generation) await providers(page);
                    });
                }
                if (actor()?.role === "admin")
                    button(
                        card,
                        provider.authorized
                            ? "Roaming revoke"
                            : "Roaming authorize",
                        async () => {
                            if (
                                !confirm(
                                    EvLocale.text(
                                        "Roaming authorization confirmation",
                                    ),
                                )
                            )
                                return;
                            await api(
                                `ocpi/providers/${provider.id}/authorization`,
                                "PATCH",
                                { authorized: !provider.authorized },
                            );
                            if (epoch === generation) {
                                await providers(page);
                                await catalog();
                            }
                        },
                    );
                privateRoot.append(card);
            }
            if (!result.data.length)
                privateRoot.append(ui("p", "No roaming providers"));
            pager(privateRoot, result, providers);
            privateRoot.append(detail);
        } catch (e) {
            if (epoch === generation) {
                privateRoot.replaceChildren(
                    ui("h2", "Roaming operations"),
                    ui("p", "Roaming load failed"),
                );
                button(privateRoot, "Retry", () => providers(page));
            }
        }
    }
    async function records(provider, module, target, page = 1) {
        const epoch = generation,
            version = ++details;
        target.replaceChildren(
            ui("h3", `Roaming ${module}`),
            ui("p", "Loading…"),
        );
        let result;
        try {
            result = await api(
                `ocpi/providers/${provider.id}/records/${module}?page=${page}`,
                "GET",
                undefined,
                privateRequest.signal,
            );
        } catch (e) {
            if (epoch === generation && version === details) {
                target.replaceChildren(
                    ui("h3", `Roaming ${module}`),
                    ui("p", "Roaming load failed"),
                );
                button(target, "Retry", () =>
                    records(provider, module, target, page),
                );
            }
            return;
        }
        if (epoch !== generation || version !== details) return;
        target.replaceChildren(
            ui("h3", `Roaming ${module}`),
            ui("p", "Roaming operator disclaimer"),
        );
        if (provider.authorized && provider.version === "2.2.1")
            button(target, "Roaming synchronize", async () => {
                if (!confirm(EvLocale.text("Roaming external confirmation")))
                    return;
                await api(
                    `ocpi/providers/${provider.id}/sync/${module}`,
                    "POST",
                    {},
                );
                if (epoch === generation && version === details) {
                    await records(provider, module, target);
                    await catalog();
                }
            });
        for (const row of result.data) {
            const card = document.createElement("article");
            card.className = "station-card";
            card.append(raw("h4", row.external_id));
            line(card, "Roaming received", row.received_at);
            if (module === "locations") location(card, row.normalized);
            else {
                const n = row.normalized;
                line(card, "Currency", n.currency);
                if (module === "tariffs") {
                    for (const element of n.elements || [])
                        for (const component of element.price_components ||
                            []) {
                            line(
                                card,
                                "Roaming price component",
                                component.type,
                            );
                            line(card, "Roaming unit price", component.price);
                            line(
                                card,
                                "Roaming step size",
                                component.step_size,
                            );
                            line(card, "Roaming VAT", component.vat);
                        }
                } else {
                    if (n.status)
                        line(card, "Roaming reported status", n.status);
                    line(card, "Roaming start time", n.start_date_time);
                    line(card, "Roaming end time", n.end_date_time);
                    line(card, "Roaming energy", n.energy_kwh);
                    line(card, "Roaming location", n.location_id);
                    line(card, "Roaming EVSE", n.evse_uid);
                    line(card, "Roaming connector", n.connector_id);
                    line(card, "Roaming net cost", n.cost?.excl_vat);
                    line(card, "Roaming gross cost", n.cost?.incl_vat);
                }
                line(card, "Roaming source updated", n.last_updated);
            }
            target.append(card);
        }
        if (!result.data.length) target.append(ui("p", "No roaming records"));
        pager(target, result, (page) =>
            records(provider, module, target, page),
        );
    }
    return { catalog, setActor: () => providers(), clear };
};
