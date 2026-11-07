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
    let catalogFilters = {};
    const raw = (tag, value) => {
        const node = document.createElement(tag);
        node.textContent = value ?? "";
        return node;
    };
    const reconciliationLabels = {
        partner_not_authorized_and_negotiated:
            "Partner authorization or negotiation is required",
        credit_requires_separate_reconciliation:
            "Credit record needs separate review",
        session_reference_missing: "CDR has no session reference",
        session_not_received:
            "Referenced partner session has not been received",
        session_not_completed: "Partner session is not completed",
        energy_precision_unsupported: "Energy precision needs review",
        energy_mismatch: "Session and CDR energy differ",
        history_exceeds_comparison_limit:
            "Partner history exceeds automatic comparison limit",
        history_contains_unlinked_cdrs:
            "Partner history contains unlinked CDRs",
        multiple_cdrs_for_session: "Multiple CDRs claim the same session",
        currency_mismatch: "Session and CDR currencies differ",
        location_id_mismatch: "Session and CDR locations differ",
        evse_uid_mismatch: "Session and CDR EVSEs differ",
        connector_id_mismatch: "Session and CDR connectors differ",
        start_date_time_mismatch: "Session and CDR start times differ",
        end_date_time_mismatch: "Session and CDR end times differ",
        cost_excl_vat_missing_or_unsupported:
            "Net cost is missing or needs precision review",
        cost_incl_vat_missing_or_unsupported:
            "Gross cost is missing or needs precision review",
        cost_excl_vat_mismatch: "Session and CDR net costs differ",
        cost_incl_vat_mismatch: "Session and CDR gross costs differ",
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
                if (b.isConnected) b.disabled = b.dataset.uncertain === "true";
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
    function location(parent, item, metadata = null) {
        const card = document.createElement("article");
        card.className = "station-card";
        if (Number.isSafeInteger(metadata?.id))
            card.dataset.partnerLocationId = String(metadata.id);
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
        if (metadata?.distance_basis === "great_circle" && Number.isFinite(Number(metadata.distance_km)))
            line(card, "Roaming straight-line distance", `${Number(metadata.distance_km).toFixed(2)} km`);
        if (Number.isSafeInteger(metadata?.reported_source_age_minutes) && metadata.reported_source_age_minutes >= 0)
            line(card, "Roaming partner source age", `${metadata.reported_source_age_minutes} min · not live availability`);
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
    function discoverySearch() {
        const form = document.createElement("form");
        const field = (title, input) => {
            const label = ui("label", title);
            label.append(input);
            form.append(label);
            return input;
        };
        const query = document.createElement("input");
        query.name = "partner_q";
        query.maxLength = 80;
        query.value = catalogFilters.q || "";
        field("Search partner locations", query);
        const country = document.createElement("input");
        country.name = "partner_country";
        country.maxLength = 3;
        country.pattern = "[a-zA-Z]{3}";
        country.value = catalogFilters.country || "";
        field("Country code (e.g. ARE)", country);
        const connector = document.createElement("select");
        connector.name = "partner_connector";
        for (const [value, title] of [
            ["", "All connectors"],
            ["IEC_62196_T2_COMBO", "CCS2"],
            ["IEC_62196_T1_COMBO", "CCS1"],
            ["IEC_62196_T2", "Type 2"],
            ["CHADEMO", "CHAdeMO"],
        ]) {
            const option = ui("option", title);
            option.value = value;
            connector.append(option);
        }
        connector.value = catalogFilters.connector_standard || "";
        field("Connector", connector);
        // Optional coordinates are a single grouped filter. They never request device location.
        const latitude = document.createElement("input");
        const longitude = document.createElement("input");
        const radius = document.createElement("input");
        for (const [input, name, min, max, value, label] of [
            [latitude, "latitude", -90, 90, catalogFilters.latitude, "Partner search latitude"],
            [longitude, "longitude", -180, 180, catalogFilters.longitude, "Partner search longitude"],
            [radius, "radius_km", 1, 200, catalogFilters.radius_km, "Partner search radius km"],
        ]) {
            input.name = name;
            input.type = "number";
            input.step = "any";
            input.min = String(min);
            input.max = String(max);
            input.value = value ?? "";
            field(label, input);
        }
        const nearby = [latitude, longitude, radius];
        const requireGroup = () => {
            const used = nearby.some(input => input.value.trim() !== "");
            for (const input of nearby) input.required = used;
        };
        for (const input of nearby) input.addEventListener("input", requireGroup);
        requireGroup();
        const submit = ui("button", "Search partner locations");
        submit.type = "submit";
        form.append(submit);
        form.onsubmit = async (event) => {
            event.preventDefault();
            submit.disabled = true;
            catalogFilters = {
                ...(query.value.trim() ? { q: query.value.trim() } : {}),
                ...(country.value.trim()
                    ? { country: country.value.trim().toUpperCase() }
                    : {}),
                ...(connector.value
                    ? { connector_standard: connector.value }
                    : {}),
                ...(nearby.every(input => input.value.trim() !== "") ? {
                    latitude: latitude.value,
                    longitude: longitude.value,
                    radius_km: radius.value,
                } : {}),
            };
            await catalog(1);
        };
        return form;
    }
    async function catalog(page = 1, savedOnly = false) {
        const viewer = actor(),
            epoch = generation;
        if (savedOnly && viewer?.role !== "customer") return catalog(1, false);
        publicRequest?.abort();
        // Clear old partner pins before any refresh, filtering or revocation check.
        window.dispatchEvent(new CustomEvent("roaming-map-data", { detail: [] }));
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
                `roaming/${savedOnly ? "favorites" : "locations"}?${new URLSearchParams({ ...(!savedOnly ? catalogFilters : {}), page: String(page) })}`,
                "GET",
                undefined,
                request.signal,
            );
            if (request.signal.aborted) return;
            if (
                savedOnly &&
                !result.data.length &&
                result.last_page >= 1 &&
                page > result.last_page
            )
                return catalog(result.last_page, true);
            let favorites = new Set();
            if (viewer?.role === "customer" && result.data.length) {
                if (savedOnly)
                    favorites = new Set(result.data.map((row) => row.id));
                else {
                    const status = await api(
                        "roaming/favorites/status",
                        "POST",
                        { record_ids: result.data.map((row) => row.id) },
                        request.signal,
                    );
                    favorites = new Set(status.favorite_record_ids);
                }
            }
            if (
                request.signal.aborted ||
                (viewer?.role === "customer" &&
                    (epoch !== generation ||
                        actor()?.id !== viewer?.id ||
                        actor()?.role !== "customer"))
            )
                return;
            publicRoot.replaceChildren(
                ui("h2", "Roaming discovery"),
                ui("p", "Roaming disclaimer"),
            );
            button(publicRoot, "Refresh", () => catalog(page, savedOnly));
            if (viewer?.role === "customer") {
                const toggle = button(
                    publicRoot,
                    savedOnly
                        ? "All partner locations"
                        : "Saved partner locations",
                    () => catalog(1, !savedOnly),
                );
                toggle.dataset.privateRoaming = "";
            }
            if (viewer?.role === "customer") {
                const history = button(
                    publicRoot,
                    "Review sandbox history",
                    () => sandboxWorkspace(),
                );
                history.dataset.privateRoaming = "";
            }
            if (!savedOnly) publicRoot.append(discoverySearch());
            const content = document.createElement("div");
            if (savedOnly) content.dataset.privateRoaming = "";
            const list = document.createElement("div");
            list.className = "station-grid";
            const mapRows = [];
            for (const row of result.data) {
                // Fail closed if a future API changes the safety contract.
                if (
                    row.evidence !== "PARTNER_REPORTED_UNVERIFIED" ||
                    row.availability !== "unknown" ||
                    row.remote_control !== false ||
                    row.reservable !== false
                )
                    throw new Error("Unexpected roaming capability.");
                if (row.location_available === false) {
                    const card = document.createElement("article");
                    card.append(
                        ui("p", "Saved partner location is unavailable"),
                    );
                    list.append(card);
                } else {
                    mapRows.push(row);
                    location(list, row.location, row);
                    line(
                        list.lastElementChild,
                        "Roaming received",
                        row.received_at,
                    );
                }
                if (viewer?.role === "customer") {
                    const saved = favorites.has(row.id);
                    const save = button(
                        list.lastElementChild,
                        saved
                            ? "Remove saved partner location"
                            : "Save partner location",
                        async () => {
                            if (
                                epoch !== generation ||
                                actor()?.id !== viewer.id ||
                                actor()?.role !== "customer"
                            )
                                return;
                            try {
                                await api(
                                    "roaming/favorites/" + row.id,
                                    saved ? "DELETE" : "PUT",
                                );
                                if (
                                    epoch === generation &&
                                    actor()?.id === viewer.id
                                )
                                    await catalog(page, savedOnly);
                            } catch (e) {
                                if (
                                    epoch === generation &&
                                    actor()?.id === viewer.id &&
                                    save.isConnected
                                ) {
                                    save.dataset.uncertain = "true";
                                    const note = ui(
                                        "p",
                                        "Saved location update uncertain. Refresh to check.",
                                    );
                                    note.dataset.privateRoaming = "";
                                    save.parentElement.append(note);
                                }
                                throw e;
                            }
                        },
                    );
                    save.dataset.privateRoaming = "";
                    if (row.location_available !== false) {
                        const offers = button(
                            list.lastElementChild,
                            "View sandbox offers",
                            () => sandboxWorkspace(row.id),
                        );
                        offers.dataset.privateRoaming = "";
                    }
                }
            }
            if (!result.data.length)
                list.append(
                    ui(
                        "p",
                        savedOnly
                            ? "No saved partner locations"
                            : Object.keys(catalogFilters).length
                              ? "No matching partner locations"
                              : "No published roaming locations",
                    ),
                );
            content.append(list);
            window.dispatchEvent(new CustomEvent("roaming-map-data", { detail: mapRows }));
            pager(content, result, (page) => catalog(page, savedOnly));
            publicRoot.append(content);
        } catch (e) {
            if (!request.signal.aborted) {
                publicRoot.replaceChildren(
                    ui("h2", "Roaming discovery"),
                    ui("p", "Roaming load failed"),
                );
                button(publicRoot, "Retry", () => catalog(page, savedOnly));
            }
        } finally {
            if (publicRequest === request)
                publicRoot.removeAttribute("aria-busy");
        }
    }
    // This private workspace never changes the public discovery capability contract.
    let roamingPending = null,
        roamingVersion = 0;
    function sandboxContract(row) {
        if (
            row.evidence !== "SANDBOX_PARTNER_UNVERIFIED" ||
            row.billing_eligible !== false ||
            row.live_commands_enabled !== false ||
            row.local_invoice_created !== false ||
            row.physical_charging_verified !== false
        )
            throw new Error("Unexpected sandbox capability.");
        return row;
    }
    async function sandboxWorkspace(
        locationId = null,
        providerId = null,
        page = 1,
    ) {
        const viewer = actor(),
            epoch = generation,
            version = ++roamingVersion;
        if (!viewer || (!providerId && viewer.role !== "customer")) return;
        publicRoot
            .querySelectorAll("[data-roaming-sandbox]")
            .forEach((n) => n.remove());
        const panel = document.createElement("section");
        panel.dataset.privateRoaming = "";
        panel.dataset.roamingSandbox = "";
        const target = providerId ? privateRoot : publicRoot;
        target
            .querySelectorAll("[data-roaming-sandbox]")
            .forEach((n) => n.remove());
        target.append(panel);
        const current = () =>
            epoch === generation &&
            version === roamingVersion &&
            actor()?.id === viewer.id &&
            panel.isConnected;
        const title = () => {
            panel.replaceChildren(
                ui("h3", "Sandbox roaming activity"),
                ui("p", "Sandbox roaming warning"),
            );
        };
        title();
        panel.append(ui("p", "Loading…"));
        const request = async (path, method = "GET", body) => {
            if (!current()) return null;
            const data = await api(path, method, body);
            return current() ? data : null;
        };
        const mutate = async (path, method, body) => {
            if (!current() || roamingPending) return;
            // Frozen identity is retained until an authoritative response. Never create
            // a replacement START or STOP after a lost HTTP response.
            roamingPending = {
                path,
                method,
                body: Object.freeze({ ...body }),
                actorId: viewer.id,
            };
            await retryPending();
        };
        const pendingControls = () => {
            if (!roamingPending || roamingPending.actorId !== viewer.id) return;
            panel.append(ui("p", "Sandbox response uncertain"));
            button(panel, "Retry same sandbox request", retryPending);
        };
        const retryPending = async () => {
            const frozen = roamingPending;
            if (!current() || !frozen || frozen.actorId !== viewer.id) return;
            panel
                .querySelectorAll("button")
                .forEach((n) => (n.disabled = true));
            try {
                const row = await request(
                    frozen.path,
                    frozen.method,
                    frozen.body,
                );
                if (!row) return;
                sandboxContract(row);
                if (roamingPending === frozen) roamingPending = null;
                await sandboxWorkspace(null, providerId);
            } catch (e) {
                if (!current()) return;
                // The server enforces one unresolved order per driver and EVSE even
                // after navigation or app restart. Resolution never resends source work.
                title();
                pendingControls();
                button(panel, "Review sandbox history", () =>
                    sandboxWorkspace(null, providerId),
                );
                notice(e);
            }
        };
        const display = (row) => {
            sandboxContract(row);
            const card = document.createElement("article");
            card.dataset.sandboxOrder = row.id;
            card.className = "station-card";
            card.append(
                raw(
                    "h4",
                    `${row.location_name} · ${row.evse_uid} / ${row.connector_id}`,
                ),
            );
            for (const [label, value] of [
                ["Sandbox order state", row.state],
                ["Sandbox token state", row.token_state],
                ["Roaming reported status", row.source_status],
                ["Sandbox reported energy Wh", row.energy_wh],
                ["Sandbox source observed", row.source_observed_at],
                ["Sandbox reconciliation", row.reconciliation_state],
            ])
                line(card, label, value);
            if (row.reported_cost)
                line(
                    card,
                    "Sandbox reported cost",
                    JSON.stringify(row.reported_cost),
                );
            for (const cmd of row.commands || [])
                card.append(
                    raw(
                        "p",
                        `${cmd.action} · ${cmd.state} · ${cmd.transport_result ?? "—"} · ${cmd.callback_result ?? "—"}`,
                    ),
                );
            if (!providerId) {
                button(card, "Refresh sandbox source", async () => {
                    const updated = await request(
                        `roaming/sandbox-orders/${row.id}/reconcile`,
                        "POST",
                        {},
                    );
                    if (updated) await sandboxWorkspace();
                });
                if (row.can_stop === true)
                    button(card, "Request sandbox stop", async () => {
                        if (
                            !confirm(EvLocale.text("Sandbox stop confirmation"))
                        )
                            return;
                        await mutate(
                            `roaming/sandbox-orders/${row.id}/stop`,
                            "POST",
                            { request_key: crypto.randomUUID() },
                        );
                    }).disabled = !!roamingPending;
                if (row.can_revoke === true)
                    button(card, "Revoke sandbox authority", async () => {
                        if (
                            !confirm(
                                EvLocale.text("Sandbox revoke confirmation"),
                            )
                        )
                            return;
                        await mutate(
                            `roaming/sandbox-orders/${row.id}/authority`,
                            "DELETE",
                            {},
                        );
                    }).disabled = !!roamingPending;
            }
            panel.append(card);
        };
        try {
            if (locationId && !roamingPending) {
                const result = await request(
                    `roaming/locations/${locationId}/sandbox-offers`,
                );
                if (!result) return;
                if (
                    result.evidence !== "SANDBOX_PARTNER_UNVERIFIED" ||
                    result.live_commands_enabled !== false ||
                    result.billing_eligible !== false
                )
                    throw new Error("Unexpected sandbox offer.");
                title();
                line(panel, "Roaming provider", result.provider_name);
                for (const offer of result.offers) {
                    const form = document.createElement("form");
                    form.dataset.sandboxOffer = "";
                    form.append(
                        raw(
                            "p",
                            `${offer.evse_uid} / ${offer.connector_id} · ${offer.tariff_id}`,
                        ),
                    );
                    // Exact server tariff strings are displayed; no client billing math.
                    line(form, "Currency", offer.tariff.currency);
                    for (const element of offer.tariff.elements || []) {
                        for (const price of element.price_components || []) {
                            const unit = {
                                ENERGY: "per kWh",
                                TIME: "per charging hour",
                                PARKING_TIME: "per parking hour",
                                FLAT: "per session",
                            }[price.type];
                            line(
                                form,
                                "Reported tariff",
                                `${price.price} ${offer.tariff.currency} · ${EvLocale.text(unit || "Unit unavailable")}`,
                            );
                            line(
                                form,
                                "Billing step",
                                `${price.step_size} · ${price.type === "ENERGY" ? "Wh" : price.type === "FLAT" ? EvLocale.text("session") : EvLocale.text("seconds")}`,
                            );
                            line(
                                form,
                                "VAT percent",
                                price.vat ?? EvLocale.text("Not reported"),
                            );
                        }
                    }
                    const label = ui("label", "Sandbox explicit consent");
                    const consent = document.createElement("input");
                    consent.type = "checkbox";
                    consent.required = true;
                    label.prepend(consent);
                    form.append(label);
                    const submit = ui("button", "Start sandbox roaming");
                    submit.type = "submit";
                    form.append(submit);
                    form.onsubmit = async (e) => {
                        e.preventDefault();
                        if (!current() || !consent.checked || roamingPending)
                            return;
                        submit.disabled = true;
                        await mutate(
                            `roaming/locations/${locationId}/sandbox-orders`,
                            "POST",
                            {
                                request_key: crypto.randomUUID(),
                                evse_uid: offer.evse_uid,
                                connector_id: offer.connector_id,
                                tariff_id: offer.tariff_id,
                                offer_hash: offer.offer_hash,
                                terms_version: offer.terms_version,
                                consent: true,
                            },
                        );
                    };
                    panel.append(form);
                }
                if (!result.offers.length)
                    panel.append(ui("p", "No approved sandbox offers"));
                if (result.truncated)
                    panel.append(ui("p", "Sandbox offer limit reached"));
                button(panel, "Review sandbox history", () =>
                    sandboxWorkspace(),
                );
            } else {
                const result = await request(
                    providerId
                        ? `ocpi/providers/${providerId}/sandbox-orders?page=${page}`
                        : `roaming/sandbox-orders?page=${page}`,
                );
                if (!result) return;
                title();
                pendingControls();
                button(panel, "Refresh", () =>
                    sandboxWorkspace(null, providerId, page),
                );
                for (const row of result.data) display(row);
                if (!result.data.length)
                    panel.append(ui("p", "No sandbox activity"));
                pager(panel, result, (p) =>
                    sandboxWorkspace(null, providerId, p),
                );
            }
        } catch (e) {
            if (current()) {
                title();
                pendingControls();
                panel.append(ui("p", "Sandbox activity unavailable"));
                button(panel, "Review sandbox history", () =>
                    sandboxWorkspace(null, providerId),
                );
                notice(e);
            }
        }
    }
    function clear() {
        generation++;
        publicRequest?.abort();
        window.dispatchEvent(new CustomEvent("roaming-map-data", { detail: [] }));
        roamingVersion++;
        roamingPending = null;
        details++;
        privateRequest?.abort();
        privateRoot.replaceChildren();
        privateRoot.hidden = true;
        publicRoot
            .querySelectorAll("[data-private-roaming]")
            .forEach((node) => node.remove());
    }
    async function registration(parent, epoch, signal) {
        const box = document.createElement("section");
        box.dataset.ocpiRegistration = "";
        parent.append(box);
        box.append(
            ui("h3", "Register roaming provider"),
            ui("p", "Roaming registration disclaimer"),
            ui("p", "Loading…"),
        );
        try {
            const [connections, owners] = await Promise.all([
                api("ocpi/connection-options", "GET", undefined, signal),
                api("ocpi/owner-options?page=1", "GET", undefined, signal),
            ]);
            if (epoch !== generation || !box.isConnected) return;
            box.replaceChildren(
                ui("h3", "Register roaming provider"),
                ui("p", "Roaming registration disclaimer"),
            );
            const options = connections.data.filter(
                (c) => c.configuration_valid && !c.assigned,
            );
            if (!options.length) {
                box.append(ui("p", "No approved roaming connections"));
                return;
            }
            const form = document.createElement("form");
            const field = (label, name, control) => {
                const wrapper = ui("label", label);
                control.name = name;
                control.required = true;
                wrapper.append(control);
                form.append(wrapper);
                return control;
            };
            const connection = field(
                "Approved connection",
                "connection_key",
                document.createElement("select"),
            );
            for (const option of options) {
                const node = raw("option", option.key);
                node.value = option.key;
                connection.append(node);
            }
            const owner = field(
                "Provider vendor",
                "owner_id",
                document.createElement("select"),
            );
            const ownerStatus = document.createElement("div");
            const ownerSearch = document.createElement("input");
            ownerSearch.name = "owner_search";
            ownerSearch.maxLength = 80;
            const searchLabel = ui("label", "Search provider vendors");
            searchLabel.append(ownerSearch);
            form.append(searchLabel, ownerStatus);
            let ownerPage = 1,
                ownerGeneration = 0;
            async function showOwners(result) {
                owner.replaceChildren();
                for (const item of result.data) {
                    const node = raw("option", `${item.name} · #${item.id}`);
                    node.value = item.id;
                    owner.append(node);
                }
                owner.disabled = !result.data.length;
                ownerStatus.replaceChildren();
                if (!result.data.length)
                    ownerStatus.append(ui("span", "No provider vendors"));
                pager(ownerStatus, result, async (page) => {
                    ownerPage = page;
                    await loadOwners();
                });
            }
            async function loadOwners() {
                const version = ++ownerGeneration;
                owner.disabled = true;
                save.disabled = true;
                ownerStatus.replaceChildren(ui("p", "Loading…"));
                try {
                    const result = await api(
                        `ocpi/owner-options?page=${ownerPage}&q=${encodeURIComponent(ownerSearch.value.trim())}`,
                        "GET",
                        undefined,
                        signal,
                    );
                    if (
                        epoch !== generation ||
                        version !== ownerGeneration ||
                        !box.isConnected
                    )
                        return;
                    await showOwners(result);
                } catch (e) {
                    if (
                        epoch === generation &&
                        version === ownerGeneration &&
                        box.isConnected
                    ) {
                        owner.replaceChildren();
                        ownerStatus.replaceChildren(
                            ui("p", "Roaming load failed"),
                        );
                        button(ownerStatus, "Retry", loadOwners);
                    }
                } finally {
                    if (
                        epoch === generation &&
                        version === ownerGeneration &&
                        box.isConnected
                    )
                        save.disabled = !owner.value;
                }
            }
            button(form, "Search provider vendors", async () => {
                ownerPage = 1;
                await loadOwners();
            });
            const name = field(
                "Provider name",
                "name",
                document.createElement("input"),
            );
            name.maxLength = 150;
            const save = ui("button", "Register roaming provider");
            save.type = "submit";
            form.append(save);
            form.onsubmit = async (event) => {
                event.preventDefault();
                if (
                    owner.disabled ||
                    save.disabled ||
                    !form.reportValidity() ||
                    !owner.value ||
                    !confirm(EvLocale.text("Roaming registration confirmation"))
                )
                    return;
                for (const control of form.elements) control.disabled = true;
                try {
                    await api("ocpi/providers", "POST", {
                        name: name.value.trim(),
                        owner_id: Number(owner.value),
                        connection_key: connection.value,
                        authorized: false,
                    });
                    if (epoch === generation) await providers();
                } catch (e) {
                    if (epoch === generation && box.isConnected) {
                        box.replaceChildren(
                            ui("h3", "Register roaming provider"),
                            ui("p", "Roaming registration uncertain"),
                        );
                        button(box, "Refresh", () => providers());
                        notice(e);
                    }
                }
            };
            box.append(form);
            await showOwners(owners);
            save.disabled = !owner.value;
        } catch (e) {
            if (epoch === generation && box.isConnected) {
                box.replaceChildren(
                    ui("p", "Roaming registration unavailable"),
                );
                button(box, "Retry", () => providers());
            }
        }
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
            if (actor()?.role === "admin")
                await registration(privateRoot, epoch, request.signal);
            if (epoch !== generation) return;
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
                button(card, "Roaming credentials", () =>
                    credentialWorkspace(provider, detail),
                );
                button(card, "Sandbox roaming activity", () =>
                    sandboxWorkspace(null, provider.id),
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
    async function credentialWorkspace(provider, target, page = 1) {
        const epoch = generation,
            version = ++details;
        target.replaceChildren(
            ui("h3", "Roaming credentials"),
            ui("p", "Loading…"),
        );
        let result;
        try {
            result = await api(
                `ocpi/providers/${provider.id}/credentials?page=${page}`,
                "GET",
                undefined,
                privateRequest.signal,
            );
        } catch (e) {
            if (epoch === generation && version === details) {
                target.replaceChildren(
                    ui("p", "Roaming credentials unavailable"),
                );
                button(target, "Refresh", () =>
                    credentialWorkspace(provider, target, page),
                );
            }
            return;
        }
        if (epoch !== generation || version !== details) return;
        target.replaceChildren(
            raw("h3", provider.name),
            ui("h4", "Roaming credentials"),
            ui("p", "Roaming credentials disclaimer"),
        );
        target.append(
            ui(
                "p",
                result.configured
                    ? "Roaming managed credentials configured"
                    : "Roaming credentials unavailable",
            ),
            ui("p", `Roaming credential state ${result.state}`),
        );
        if (result.bootstrap_expires_at)
            line(
                target,
                "Roaming enrollment expires",
                result.bootstrap_expires_at,
            );
        if (result.exchanged_at) target.append(raw("p", result.exchanged_at));
        button(target, "Refresh", () =>
            credentialWorkspace(provider, target, page),
        );
        if (actor()?.role === "admin")
            for (const action of result.available_actions) {
                const form = document.createElement("form");
                const label = ui("label", "Roaming credentials reason"),
                    note = document.createElement("textarea");
                note.required = true;
                note.minLength = 10;
                note.maxLength = 2000;
                label.append(note);
                const submit = ui("button", `Roaming credentials ${action}`);
                submit.type = "submit";
                form.append(label, submit);
                target.append(form);
                form.onsubmit = async (event) => {
                    event.preventDefault();
                    if (!form.reportValidity() || form.dataset.attempted)
                        return;
                    if (
                        !confirm(EvLocale.text("Roaming external confirmation"))
                    )
                        return;
                    const body = {
                        action,
                        reason: note.value,
                        idempotency_key: crypto.randomUUID(),
                    };
                    form.dataset.attempted = "true";
                    for (const input of target.querySelectorAll(
                        "input,textarea,button",
                    ))
                        input.disabled = true;
                    try {
                        await api(
                            `ocpi/providers/${provider.id}/credentials`,
                            "POST",
                            body,
                        );
                        if (epoch === generation && version === details) {
                            await providers();
                            const fresh = privateRoot.querySelector(
                                "[data-roaming-detail]",
                            );
                            if (fresh)
                                await credentialWorkspace(provider, fresh);
                        }
                    } catch (e) {
                        if (epoch === generation && version === details) {
                            target.replaceChildren(
                                ui("p", "Roaming credentials uncertain"),
                            );
                            button(target, "Refresh", () =>
                                credentialWorkspace(provider, target),
                            );
                            notice(e);
                        }
                    }
                };
            }
        for (const operation of result.operations.data)
            target.append(
                raw(
                    "p",
                    `#${operation.id} · ${EvLocale.text("Roaming credential action " + operation.action)} · ${EvLocale.text("Roaming credential state " + operation.state)} · ${operation.attempted_at || operation.created_at}`,
                ),
            );
        pager(target, result.operations, (p) =>
            credentialWorkspace(provider, target, p),
        );
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
            if (row.reconciliation) {
                const report = row.reconciliation;
                card.append(
                    ui(
                        "p",
                        report.status === "source_consistent"
                            ? "Partner session and CDR agree"
                            : "Partner reconciliation requires review",
                    ),
                );
                card.append(
                    ui("p", "Source comparison does not authorize billing"),
                );
                for (const issue of report.issues || [])
                    card.append(
                        ui(
                            "p",
                            reconciliationLabels[issue] ||
                                "Source fields require review",
                        ),
                    );
            }
            target.append(card);
        }
        if (!result.data.length) target.append(ui("p", "No roaming records"));
        pager(target, result, (page) =>
            records(provider, module, target, page),
        );
    }
    return {
        catalog,
        setActor: async () => {
            await providers();
            await catalog(1);
        },
        clear,
    };
};
