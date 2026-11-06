/* Authenticated company workflow. No KYC evidence or company data in browser storage. */
window.createVendorWorkspace = ({
    root,
    api,
    ui,
    actor,
    csrf,
    notice,
    expired,
}) => {
    let generation = 0,
        detailGeneration = 0,
        controller = null,
        selected = null,
        regions = [];
    const downloads = new Set();
    const base = "vendor-ops/";
    const raw = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = value;
        return n;
    };
    const documentKind = (kind) =>
        ({
            trade_license: "Trade license",
            identity: "Identity",
            agreement: "Agreement",
        })[kind] || kind;
    const permissionName = (value) =>
        ({
            "company.read": "Read company",
            "inventory.read": "Read inventory",
            "inventory.write": "Manage inventory",
        })[value] || value;
    function permissions(values) {
        const list = document.createElement("ul");
        for (const value of values)
            list.append(ui("li", permissionName(value)));
        return list;
    }
    const companyStatus = (status) => ({
        translate:
            "company.status." +
            ([
                "draft",
                "submitted",
                "approved",
                "rejected",
                "changes_requested",
                "suspended",
            ].includes(status)
                ? status
                : "unknown"),
    });
    function clear() {
        for (const url of downloads) URL.revokeObjectURL(url);
        downloads.clear();
        generation++;
        detailGeneration++;
        controller?.abort();
        selected = null;
        root.replaceChildren();
        root.hidden = true;
    }
    const valid = (epoch) => epoch === generation && actor();
    async function run(action, button) {
        const epoch = generation;
        if (button) button.disabled = true;
        try {
            await action(epoch);
        } catch (error) {
            if (valid(epoch) && error.name !== "AbortError") notice(error);
        } finally {
            if (button?.isConnected) button.disabled = false;
        }
    }
    function button(parent, label, action) {
        const b = ui("button", label);
        b.type = "button";
        b.onclick = () => run(action, b);
        parent.append(b);
        return b;
    }
    function form(parent, title, specs, submit) {
        const f = document.createElement("form");
        f.dataset.vendorForm = title;
        const creationKey = crypto.randomUUID();
        const operationInput = document.createElement("input");
        operationInput.type = "hidden";
        operationInput.name = "idempotency_key";
        operationInput.value = creationKey;
        f.append(operationInput);
        f.append(ui("h3", title));
        for (const spec of specs) {
            if (spec.checks) {
                const group = document.createElement("fieldset");
                group.className = "vendor-permissions";
                group.append(ui("legend", spec.label));
                for (const [value, text] of Object.entries(spec.checks)) {
                    const label = ui("label", text),
                        checkbox = document.createElement("input");
                    checkbox.type = "checkbox";
                    checkbox.name = spec.name;
                    checkbox.value = value;
                    label.append(checkbox);
                    group.append(label);
                }
                f.append(group);
                continue;
            }

            const label = ui("label", spec.label),
                input = document.createElement(
                    spec.choices ? "select" : "input",
                );
            input.name = spec.name;
            if (spec.choices)
                for (const [value, text] of Object.entries(spec.choices)) {
                    const o = new Option(text, value);
                    EvLocale.bind(o, text);
                    input.append(o);
                }
            else {
                input.type = spec.type || "text";
                input.maxLength = spec.max || 150;
            }
            input.required = !spec.optional;
            if (spec.name === "trade_license_number")
                input.autocomplete = "off";
            if (spec.value !== undefined) input.value = spec.value;
            if (spec.type === "number") {
                input.min = spec.min ?? "1";
                if (spec.limit) input.max = spec.limit;
                if (spec.step) input.step = spec.step;
            }
            if (spec.type === "date")
                input.min = new Date(Date.now() + 86400000)
                    .toISOString()
                    .slice(0, 10);
            if (spec.type === "file")
                input.accept = "application/pdf,image/jpeg,image/png";
            EvLocale.attribute(input, "aria-label", spec.label);
            label.append(input);
            f.append(label);
        }
        const save = ui("button", "Save");
        save.type = "submit";
        f.append(save);
        f.onsubmit = (event) => {
            event.preventDefault();
            run(async (epoch) => {
                const data = new FormData(f),
                    values = Object.fromEntries(data);
                for (const spec of specs)
                    if (spec.checks) values[spec.name] = data.getAll(spec.name);
                for (const spec of specs)
                    if (spec.optional && values[spec.name] === "")
                        delete values[spec.name];
                await submit(values, f, epoch);
            }, save);
        };
        parent.append(f);
        return f;
    }
    function pager(parent, result, load) {
        const p = document.createElement("div");
        p.className = "pagination";
        p.append(
            ui("p", "Page {page} of {last} · {total} records", {
                page: result.current_page,
                last: result.last_page,
                total: result.total,
            }),
        );
        const prev = button(p, "Previous", () => load(result.current_page - 1)),
            next = button(p, "Next", () => load(result.current_page + 1));
        prev.disabled = result.current_page <= 1;
        next.disabled = result.current_page >= result.last_page;
        parent.append(p);
    }
    async function paged(parent, path, render, page = 1, epoch = generation) {
        parent.setAttribute("aria-busy", "true");
        try {
            const result = await api(
                base + path + "?page=" + page,
                "GET",
                undefined,
                controller?.signal,
            );
            if (!valid(epoch) || !parent.isConnected) return;
            parent.replaceChildren();
            for (const row of result.data) await render(row, parent);
            if (!result.data.length) parent.append(ui("p", "No records."));
            pager(parent, result, (p) => paged(parent, path, render, p, epoch));
        } finally {
            if (parent.isConnected) parent.removeAttribute("aria-busy");
        }
    }
    async function download(company, document, epoch) {
        const response = await fetch(
            "/api/" +
                base +
                "companies/" +
                company.id +
                "/documents/" +
                document.id,
            {
                credentials: "same-origin",
                redirect: "error",
                headers: {
                    Accept: "application/json",
                },
                signal: controller.signal,
            },
        );
        if (!valid(epoch)) return;
        if ([401, 419].includes(response.status)) {
            expired();
            throw Object.assign(
                new Error("Your session has ended. Please sign in again."),
                { status: response.status },
            );
        }
        if (!response.ok)
            throw Object.assign(
                new Error("Private evidence download failed."),
                { status: response.status },
            );
        const blob = await response.blob(),
            type = blob.type.split(";")[0];
        if (
            !["application/pdf", "image/jpeg", "image/png"].includes(type) ||
            blob.size !== Number(document.size_bytes) ||
            blob.size > 5 * 1024 * 1024
        )
            throw new Error("Private evidence download failed.");
        if (!valid(epoch)) return;
        const url = URL.createObjectURL(blob);
        downloads.add(url);
        const link = window.document.createElement("a");
        link.href = url;
        link.download =
            "kyc-document-" +
            document.id +
            "." +
            {
                "application/pdf": "pdf",
                "image/jpeg": "jpg",
                "image/png": "png",
            }[type];
        window.document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => {
            URL.revokeObjectURL(url);
            downloads.delete(url);
        }, 1000);
    }
    async function upload(company, values, f, epoch, current) {
        const file = values.document;
        if (!file?.size || file.size > 5 * 1024 * 1024)
            throw new Error("Choose a PDF, JPEG or PNG up to 5 MiB.");
        const response = await fetch(
            "/api/" + base + "companies/" + company.id + "/documents",
            {
                method: "POST",
                credentials: "same-origin",
                redirect: "error",
                headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf() },
                body: new FormData(f),
                signal: controller.signal,
            },
        );
        if (!valid(epoch)) return;
        if ([401, 419].includes(response.status)) {
            expired();
            throw Object.assign(
                new Error("Your session has ended. Please sign in again."),
                { status: response.status },
            );
        }
        if (!response.ok)
            throw Object.assign(new Error("Please check the entered values."), {
                status: response.status,
            });
        await response.json(); // Finish the JSON exchange before replacing the upload form.
        if (valid(epoch) && current()) {
            f.reset();
            await detail(company);
        }
    }
    async function detail(company) {
        const epoch = generation,
            version = ++detailGeneration;
        selected = company;
        const current = () => valid(epoch) && version === detailGeneration;
        const host = root.querySelector("[data-company-detail]");
        host.replaceChildren(
            ui("h3", "Company details"),
            raw("h4", company.legal_name),
        );
        const caps = company.capabilities || {},
            owner = caps.owner,
            admin = caps.reviewer;
        if (owner || admin)
            button(host, "Signed firmware releases", () =>
                firmwareReleases(company, host, epoch),
            );
        button(host, "Rider authorization requests", () =>
            riderRequests(company, host, epoch),
        );
        host.append(
            ui("p", "Company status: {status}", {
                status: companyStatus(company.status),
            }),
            ui("p", "Company workflow disclaimer"),
            ui("p", "License expiry: {date}", {
                date: company.license_expires_on?.slice(0, 10),
            }),
        );
        if (caps.inventory_read)
            button(host, "Charging operational analytics", () =>
                operationalAnalytics(company, host, epoch),
            );
        const editable =
            owner &&
            ["draft", "changes_requested", "rejected"].includes(company.status);
        const operational =
            company.status === "approved" &&
            caps.inventory_operational === true;
        button(host, "Refresh company", async () => {
            if (!caps.company_read) {
                await setActor(actor());
                return;
            }
            const refreshedCompany = await api(
                base + "companies/" + company.id,
            );
            if (current()) await detail(refreshedCompany);
        });
        if (editable) {
            form(
                host,
                "Edit company",
                [
                    {
                        name: "legal_name",
                        label: "Legal name",
                        value: company.legal_name,
                    },
                    {
                        name: "country",
                        label: "Country",
                        choices: Object.fromEntries(
                            regions.map((r) => [r.country, r.country]),
                        ),
                        value: company.country,
                    },
                    {
                        name: "trade_license_number",
                        label: "Replacement trade license number",
                        max: 100,
                        optional: true,
                    },
                    {
                        name: "license_expires_on",
                        label: "License expiry",
                        type: "date",
                        value: company.license_expires_on?.slice(0, 10),
                    },
                ],
                async (v, f, e) => {
                    const c = await api(
                        base + "companies/" + company.id,
                        "PATCH",
                        v,
                    );
                    if (valid(e) && current())
                        await detail({
                            ...c,
                            capabilities: c.capabilities || caps,
                        });
                },
            );
            form(
                host,
                "Upload private evidence",
                [
                    {
                        name: "kind",
                        label: "Document kind",
                        choices: {
                            trade_license: "Trade license",
                            identity: "Identity",
                            agreement: "Agreement",
                        },
                    },
                    { name: "document", label: "Evidence file", type: "file" },
                ],
                (v, f, e) => upload(company, v, f, e, current),
            );
            button(host, "Submit for internal review", async () => {
                const c = await api(
                    base + "companies/" + company.id + "/submit",
                    "POST",
                );
                if (current())
                    await detail({
                        ...c,
                        capabilities: c.capabilities || caps,
                    });
            });
        }
        if (admin) {
            const targets = {
                submitted: {
                    approved: "Approve",
                    rejected: "Reject",
                    changes_requested: "Request changes",
                },
                approved: {
                    suspended: "Suspend",
                    changes_requested: "Request changes",
                },
                suspended: {
                    approved: "Approve",
                    changes_requested: "Request changes",
                },
            }[company.status];
            if (targets)
                form(
                    host,
                    "Internal review decision",
                    [
                        { name: "status", label: "Decision", choices: targets },
                        { name: "reason", label: "Review reason", max: 2000 },
                    ],
                    async (v, f, e) => {
                        const c = await api(
                            base + "companies/" + company.id + "/review",
                            "POST",
                            v,
                        );
                        if (valid(e) && current())
                            await detail({
                                ...c,
                                capabilities: c.capabilities || caps,
                            });
                    },
                );
        }
        if (caps.private_evidence) {
            const docs = document.createElement("div"),
                history = document.createElement("div");
            host.append(
                ui("h3", "Private documents"),
                docs,
                ui("h3", "Review history"),
                history,
            );
            await paged(
                docs,
                "companies/" + company.id + "/documents",
                (d, p) => {
                    const a = document.createElement("a");
                    a.href =
                        "/api/" +
                        base +
                        "companies/" +
                        company.id +
                        "/documents/" +
                        d.id;
                    a.download = "";
                    a.onclick = (event) => {
                        event.preventDefault();
                        run(() => download(company, d, epoch));
                    };
                    EvLocale.bind(a, "{kind} · {type} · {size} bytes", {
                        kind: { translate: documentKind(d.kind) },
                        type: d.mime_type,
                        size: d.size_bytes,
                    });
                    const entry = document.createElement("p");
                    entry.append(a);
                    p.append(entry);
                },
                1,
                epoch,
            );
            if (!current()) return;
            await paged(
                history,
                "companies/" + company.id + "/reviews",
                (r, p) => {
                    p.append(
                        ui("p", "Review transition: {from} → {to}", {
                            from: companyStatus(r.from_status),
                            to: companyStatus(r.to_status),
                        }),
                        raw("p", r.reason),
                    );
                },
                1,
                epoch,
            );
        }
        if (!current()) return;
        if (owner) {
            const staff = document.createElement("div");
            host.append(ui("h3", "Company staff"), staff);
            form(
                host,
                "Invite company staff",
                [
                    { name: "email", label: "Email", type: "email", max: 254 },
                    {
                        name: "permissions",
                        label: "Staff access",
                        checks: {
                            "company.read": "Read company",
                            "inventory.read": "Read inventory",
                            "inventory.write": "Manage inventory",
                        },
                    },
                ],
                async (v, f, e) => {
                    const permissions = v.permissions;
                    if (!permissions.length)
                        throw new Error(
                            "Choose at least one staff permission.",
                        );
                    await api(
                        base + "companies/" + company.id + "/staff",
                        "POST",
                        { email: v.email, permissions },
                    );
                    if (valid(e) && current()) {
                        notice(
                            "Invitation recorded if the account is eligible. Acceptance is required.",
                        );
                        await detail(company);
                    }
                },
            );
            await paged(
                staff,
                "companies/" + company.id + "/staff",
                (m, p) => {
                    const row = document.createElement("article");
                    row.append(
                        ui("p", "Member #{id}: {status}", {
                            id: m.user_id,
                            status: { translate: m.status },
                        }),
                        permissions(m.permissions),
                    );
                    if (m.status !== "revoked")
                        button(row, "Revoke access", async () => {
                            if (
                                !confirm(
                                    EvLocale.text("Revoke company access?"),
                                )
                            )
                                return;
                            await api(
                                base +
                                    "companies/" +
                                    company.id +
                                    "/staff/" +
                                    m.id,
                                "DELETE",
                            );
                            if (current()) await detail(company);
                        });
                    p.append(row);
                },
                1,
                epoch,
            );
        }
        if (!current()) return;
        const inventory = document.createElement("div");
        host.append(
            ui("h3", "Company inventory"),
            ui("p", "Inventory metadata disclaimer"),
            inventory,
        );
        if (caps.inventory_write && operational) {
            const siteForm = form(
                host,
                "Create site",
                [
                    { name: "name", label: "Site name" },
                    {
                        name: "country",
                        label: "Country",
                        value: company.country,
                        max: 2,
                    },
                    {
                        name: "timezone",
                        label: "Timezone",
                        value:
                            regions.find((r) => r.country === company.country)
                                ?.timezone || "UTC",
                        max: 100,
                    },
                    { name: "address", label: "Address", max: 300 },
                    {
                        name: "latitude",
                        label: "Latitude",
                        type: "number",
                        min: -90,
                        limit: 90,
                        step: "any",
                    },
                    {
                        name: "longitude",
                        label: "Longitude",
                        type: "number",
                        min: -180,
                        limit: 180,
                        step: "any",
                    },
                ],
                async (v, f, e) => {
                    if (v.station_id === "") delete v.station_id;
                    await api(
                        base + "companies/" + company.id + "/sites",
                        "POST",
                        v,
                    );
                    if (valid(e) && current()) await detail(company);
                },
            );
            stationPicker(company, siteForm, epoch);
        }
        if (caps.inventory_read)
            await paged(
                inventory,
                "companies/" + company.id + "/sites",
                (site, p) => {
                    const row = document.createElement("article");
                    row.append(raw("h4", site.name), raw("p", site.address));
                    button(row, "Open chargers", () =>
                        chargers(company, site, row, operational, epoch),
                    );
                    p.append(row);
                },
                1,
                epoch,
            );
        else inventory.append(ui("p", "Inventory access is not granted."));
    }
    function stationPicker(company, form, epoch) {
        const label = ui("label", "Catalog station link"),
            select = document.createElement("select");
        select.name = "station_id";
        select.append(ui("option", "No catalog link"));
        select.options[0].value = "";
        label.append(select);
        form.insertBefore(label, form.querySelector("button[type=submit]"));
        const note = ui("p", "Catalog link gate notice"),
            controls = document.createElement("div"),
            search = document.createElement("input");
        EvLocale.attribute(
            search,
            "aria-label",
            "Search owned catalog stations",
        );
        search.maxLength = 100;
        const searchLabel = ui("label", "Search owned catalog stations");
        searchLabel.append(search);
        controls.append(searchLabel);
        form.append(note, controls);
        const cache = new Map();
        let requestVersion = 0;
        async function load(page = 1) {
            const version = ++requestVersion;
            select.setAttribute("aria-busy", "true");
            try {
                const result = await api(
                    base +
                        "companies/" +
                        company.id +
                        "/catalog-stations?" +
                        new URLSearchParams({
                            page,
                            ...(search.value ? { q: search.value } : {}),
                        }),
                );
                if (
                    !valid(epoch) ||
                    version !== requestVersion ||
                    !form.isConnected
                )
                    return;
                const selected = select.value;
                select.replaceChildren(ui("option", "No catalog link"));
                select.options[0].value = "";
                for (const station of result.data) {
                    cache.set(String(station.id), station);
                    select.append(
                        new Option(
                            station.name + " · " + station.country,
                            String(station.id),
                        ),
                    );
                }
                if (
                    selected &&
                    cache.has(selected) &&
                    !result.data.some((s) => String(s.id) === selected)
                ) {
                    const station = cache.get(selected);
                    select.append(
                        new Option(
                            station.name + " · " + station.country,
                            selected,
                        ),
                    );
                }
                select.value = selected;
                controls.querySelectorAll("button").forEach((n) => n.remove());
                button(controls, "Search catalog choices", () => load());
                button(controls, "Previous catalog choices", () =>
                    load(result.current_page - 1),
                ).disabled = result.current_page <= 1;
                button(controls, "Next catalog choices", () =>
                    load(result.current_page + 1),
                ).disabled = result.current_page >= result.last_page;
            } catch (e) {
                if (valid(epoch) && form.isConnected) {
                    controls
                        .querySelectorAll("button")
                        .forEach((n) => n.remove());
                    controls.append(ui("p", "Catalog choices unavailable"));
                    button(controls, "Retry", () => load(page));
                }
            } finally {
                if (
                    valid(epoch) &&
                    version === requestVersion &&
                    form.isConnected
                )
                    select.removeAttribute("aria-busy");
            }
        }
        select.onchange = () => {
            const station = cache.get(select.value);
            if (station)
                for (const name of [
                    "country",
                    "timezone",
                    "latitude",
                    "longitude",
                ])
                    form.elements[name].value = station[name];
        };
        load();
    }

    async function chargers(company, site, parent, operational, epoch) {
        parent.querySelector("[data-chargers]")?.remove();
        const pane = document.createElement("div");
        pane.dataset.chargers = "";
        parent.append(pane);
        if (company.capabilities.inventory_write && operational)
            form(
                pane,
                "Create charger",
                [
                    {
                        name: "asset_reference",
                        label: "Asset reference",
                        max: 80,
                    },
                    { name: "manufacturer", label: "Manufacturer", max: 100 },
                    { name: "model", label: "Model", max: 100 },
                    {
                        name: "ocpp_protocol",
                        label: "OCPP protocol",
                        choices: { 1.6: "1.6", "2.0.1": "2.0.1" },
                    },
                    {
                        name: "source",
                        label: "Inventory source",
                        choices: {
                            discovery: "Discovery only",
                            simulator: "Simulator",
                            direct_unverified: "Direct unverified",
                            partner_unverified: "Partner unverified",
                        },
                    },
                ],
                async (v, f, e) => {
                    await api(
                        base +
                            "companies/" +
                            company.id +
                            "/sites/" +
                            site.id +
                            "/chargers",
                        "POST",
                        v,
                    );
                    if (valid(e))
                        await chargers(
                            company,
                            site,
                            parent,
                            operational,
                            epoch,
                        );
                },
            );
        const list = document.createElement("div");
        pane.append(list);
        await paged(
            list,
            "companies/" + company.id + "/sites/" + site.id + "/chargers",
            (charger, p) => {
                const row = document.createElement("article");
                row.append(
                    raw("h4", charger.asset_reference),
                    raw("p", charger.manufacturer + " · " + charger.model),
                );
                button(row, "Open connectors", () =>
                    connectors(company, charger, row, operational, epoch),
                );
                button(row, "Canonical EVSE mapping", () =>
                    topology(company, charger, row, operational, epoch),
                );
                button(row, "Secure charger admission", () =>
                    admission(company, charger, row, epoch),
                );
                button(row, "Signed firmware operations", () =>
                    firmwareOperations(company, charger, row, epoch),
                );
                button(row, "Safe reset operations", () =>
                    resets(company, charger, row, epoch),
                );
                button(row, "Source diagnostics", () =>
                    diagnostics(company, charger, row, epoch),
                );
                button(row, "Device operating settings", () =>
                    configurationReads(company, charger, row, epoch),
                );
                p.append(row);
            },
            1,
            epoch,
        );
    }
    async function firmwareReleases(company, parent, epoch, page = 1) {
        parent.querySelector("[data-firmware-releases]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.firmwareReleases = "";
        parent.append(pane);
        const path = base + `companies/${company.id}/firmware-releases`;
        const result = await api(
            path + `?page=${page}`,
            "GET",
            undefined,
            controller?.signal,
        );
        if (!valid(epoch) || !pane.isConnected) return;
        pane.append(
            ui("h3", "Signed firmware releases"),
            ui("p", "Signed firmware disclaimer"),
        );
        const refresh = () => firmwareReleases(company, parent, epoch, page);
        button(pane, "Refresh", refresh);
        if (
            result.can_publish &&
            result.adapter_enabled &&
            result.configured_candidates.length
        )
            form(
                pane,
                "Approve configured firmware",
                [
                    {
                        name: "catalog_key",
                        label: "Configured signed release",
                        choices: Object.fromEntries(
                            result.configured_candidates.map((v) => [
                                v.key,
                                `${v.manufacturer} · ${v.model} · ${v.version}`,
                            ]),
                        ),
                    },
                    {
                        name: "note",
                        label: "Operator recovery note",
                        max: 2000,
                    },
                ],
                async (body) => {
                    await api(
                        path,
                        "POST",
                        { catalog_key: body.catalog_key, note: body.note },
                        controller?.signal,
                    );
                    if (valid(epoch) && pane.isConnected) await refresh();
                },
            );
        for (const release of result.data) {
            const row = document.createElement("article");
            row.dataset.firmwareRelease = release.id;
            row.append(
                raw(
                    "h4",
                    `${release.manufacturer} · ${release.model} · ${release.version}`,
                ),
                raw("p", release.sha256),
                raw("p", release.signer_fingerprint),
                raw("p", release.size),
            );
            if (release.revoked_at)
                row.append(ui("p", "Firmware release revoked"));
            else if (result.can_publish && result.adapter_enabled)
                form(
                    row,
                    "Revoke signed release",
                    [
                        {
                            name: "note",
                            label: "Operator recovery note",
                            max: 2000,
                        },
                    ],
                    async (body) => {
                        await api(
                            path + `/${release.id}`,
                            "DELETE",
                            { note: body.note },
                            controller?.signal,
                        );
                        if (valid(epoch) && pane.isConnected) await refresh();
                    },
                );
            pane.append(row);
        }
        if (result.current_page > 1)
            button(pane, "Previous", () =>
                firmwareReleases(company, parent, epoch, page - 1),
            );
        if (result.current_page < result.last_page)
            button(pane, "Next", () =>
                firmwareReleases(company, parent, epoch, page + 1),
            );
    }
    async function firmwareOperations(
        company,
        charger,
        parent,
        epoch,
        page = 1,
        releasePage = 1,
    ) {
        parent.querySelector("[data-firmware-operations]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.firmwareOperations = "";
        parent.append(pane);
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/firmware-operations`;
        const result = await api(
            path + `?page=${page}`,
            "GET",
            undefined,
            controller?.signal,
        );
        if (!valid(epoch) || !pane.isConnected) return;
        pane.append(
            ui("h3", "Signed firmware operations"),
            ui("p", "Signed firmware disclaimer"),
        );
        const refresh = () =>
            firmwareOperations(
                company,
                charger,
                parent,
                epoch,
                page,
                releasePage,
            );
        button(pane, "Refresh", refresh);
        if (result.can_manage && result.adapter_enabled) {
            const releases = await api(
                base +
                    `companies/${company.id}/firmware-releases?` +
                    new URLSearchParams({
                        page: String(releasePage),
                        manufacturer: charger.manufacturer,
                        model: charger.model,
                        active: "true",
                    }),
                "GET",
                undefined,
                controller?.signal,
            );
            if (!valid(epoch) || !pane.isConnected) return;
            const compatible = releases.data.filter(
                (v) =>
                    !v.revoked_at &&
                    v.manufacturer === charger.manufacturer &&
                    v.model === charger.model,
            );
            if (releases.current_page > 1)
                button(pane, "Previous", () =>
                    firmwareOperations(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        releasePage - 1,
                    ),
                );
            if (releases.current_page < releases.last_page)
                button(pane, "Next", () =>
                    firmwareOperations(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        releasePage + 1,
                    ),
                );
            if (compatible.length)
                form(
                    pane,
                    "Request signed firmware",
                    [
                        {
                            name: "release_id",
                            label: "Approved signed release",
                            choices: Object.fromEntries(
                                compatible.map((v) => [v.id, v.version]),
                            ),
                        },
                        {
                            name: "reason",
                            label: "Operator recovery note",
                            max: 2000,
                        },
                    ],
                    async (body) => {
                        await api(
                            path,
                            "POST",
                            { ...body, release_id: Number(body.release_id) },
                            controller?.signal,
                        );
                        if (valid(epoch) && pane.isConnected) await refresh();
                    },
                );
            else pane.append(ui("p", "No compatible signed firmware"));
        }
        for (const operation of result.data) {
            const row = document.createElement("article");
            row.dataset.firmwareOperation = operation.id;
            row.append(
                raw("h4", `#${operation.id} · ${operation.version}`),
                raw("p", operation.status),
                raw("p", operation.sha256),
                raw("p", operation.created_at),
            );
            if (
                !operation.reviewed_at &&
                result.can_manage &&
                result.adapter_enabled
            )
                form(
                    row,
                    operation.status === "requested"
                        ? "Cancel firmware before dispatch"
                        : "Review firmware source evidence",
                    [
                        {
                            name: "note",
                            label: "Operator recovery note",
                            max: 2000,
                        },
                    ],
                    async (body) => {
                        await api(
                            path +
                                `/${operation.id}/${operation.status === "requested" ? "cancel" : "review"}`,
                            "POST",
                            { note: body.note },
                            controller?.signal,
                        );
                        if (valid(epoch) && pane.isConnected) await refresh();
                    },
                );
            pane.append(row);
        }
        if (result.current_page > 1)
            button(pane, "Previous", () =>
                firmwareOperations(
                    company,
                    charger,
                    parent,
                    epoch,
                    page - 1,
                    releasePage,
                ),
            );
        if (result.current_page < result.last_page)
            button(pane, "Next", () =>
                firmwareOperations(
                    company,
                    charger,
                    parent,
                    epoch,
                    page + 1,
                    releasePage,
                ),
            );
    }
    async function resets(company, charger, parent, epoch, page = 1) {
        parent.querySelector("[data-reset-operations]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.resetOperations = "";
        parent.append(pane);
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/reset-operations`;
        const result = await api(
            path + `?page=${page}`,
            "GET",
            undefined,
            controller?.signal,
        );
        if (!valid(epoch) || !pane.isConnected) return;
        pane.append(
            ui("h3", "Safe reset operations"),
            ui("p", "Safe reset disclaimer"),
        );
        const refresh = () => resets(company, charger, parent, epoch, page);
        button(pane, "Refresh", refresh);
        if (result.can_manage && result.adapter_enabled)
            form(
                pane,
                "Request safe reset",
                [{ name: "reason", label: "Reset reason", max: 2000 }],
                async (body) => {
                    await api(path, "POST", body, controller?.signal);
                    if (valid(epoch) && pane.isConnected) await refresh();
                },
            );
        for (const operation of result.data) {
            const row = document.createElement("article");
            row.dataset.resetOperation = operation.id;
            row.append(
                raw("h4", `#${operation.id} · ${operation.reset_type}`),
                raw("p", operation.status),
                raw("p", operation.created_at),
            );
            if (operation.reviewed_at)
                row.append(ui("p", "Reset source reviewed"));
            else if (result.can_manage && result.adapter_enabled)
                form(
                    row,
                    "Review source after reset",
                    [
                        {
                            name: "note",
                            label: "Operator recovery note",
                            max: 2000,
                        },
                    ],
                    async (body) => {
                        await api(
                            path + `/${operation.id}/review`,
                            "POST",
                            { note: body.note },
                            controller?.signal,
                        );
                        if (valid(epoch) && pane.isConnected) await refresh();
                    },
                );
            pane.append(row);
        }
        if (result.current_page > 1)
            button(pane, "Previous", () =>
                resets(company, charger, parent, epoch, page - 1),
            );
        if (result.current_page < result.last_page)
            button(pane, "Next", () =>
                resets(company, charger, parent, epoch, page + 1),
            );
    }
    async function configurationReads(
        company,
        charger,
        parent,
        epoch,
        page = 1,
        pending = null,
    ) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-source-diagnostics]")?.remove();
        parent.querySelector("[data-configuration-reads]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.configurationReads = "";
        parent.append(pane);
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/configuration-reads`;
        const current = () => valid(epoch) && pane.isConnected;
        const check = (row) => {
            if (
                row.evidence !== "SIMULATED_SOURCE" ||
                row.settings_changed !== false ||
                row.physical_charging_verified !== false
            )
                throw new Error("Unexpected settings capability.");
        };
        const state = (value) =>
            ({
                source_observed: "Device reply observed",
                transport_submitted: "Awaiting device reply",
                device_rejected: "Device rejected the read",
                not_dispatched: "Read was not sent",
                invalid_source_response: "Invalid device reply",
                observed: "Observed",
                unsupported: "Not supported",
                invalid_value: "Invalid device value",
            })[value] || "Outcome uncertain";
        const labels = {
            HeartbeatInterval: "Heartbeat interval (seconds)",
            "OCPPCommCtrlr.HeartbeatInterval": "Heartbeat interval (seconds)",
            MeterValueSampleInterval: "Meter sample interval (seconds)",
            NumberOfConnectors: "Connector count",
            "SampledDataCtrlr.TxUpdatedInterval":
                "Transaction update interval (seconds)",
            "ClockCtrlr.TimeZone": "Time zone",
        };
        const safeValue = (row) => {
            if (row.status !== "observed" || typeof row.value !== "string")
                return "—";
            if (row.key === "ClockCtrlr.TimeZone")
                return row.value === "UTC" ||
                    (row.value.length <= 64 &&
                        /^[A-Za-z_+-]+\/[A-Za-z_+/-]+$/.test(row.value))
                    ? row.value
                    : "—";
            return /^[0-9]{1,5}$/.test(row.value) && Number(row.value) <= 86400
                ? row.value
                : "—";
        };
        const header = () =>
            pane.replaceChildren(
                ui("h3", "Device operating settings"),
                ui("p", "Settings read disclaimer"),
            );
        const request = async (intent) => {
            if (!current()) return;
            header();
            pane.append(ui("p", "Loading…"));
            try {
                const result = await api(
                    path,
                    "POST",
                    intent,
                    controller?.signal,
                );
                check(result);
                if (current())
                    await configurationReads(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                    );
            } catch (error) {
                if (!current()) return;
                header();
                pane.append(
                    ui(
                        "p",
                        "Read unavailable. Refresh source history before another action.",
                    ),
                );
                if (![401, 403, 404, 419].includes(error.status))
                    button(pane, "Resolve read request", () => request(intent));
                button(pane, "Refresh source history", () =>
                    configurationReads(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        intent,
                    ),
                );
                notice(error);
            }
        };
        header();
        pane.append(ui("p", "Loading…"));
        try {
            const result = await api(
                path + "?page=" + page,
                "GET",
                undefined,
                controller?.signal,
            );
            if (!current()) return;
            if (result.settings_writes_enabled !== false)
                throw new Error("Unexpected settings capability.");
            result.data.forEach(check);
            header();
            button(pane, "Refresh source history", () =>
                configurationReads(
                    company,
                    charger,
                    parent,
                    epoch,
                    page,
                    pending,
                ),
            );
            if (result.can_request)
                button(
                    pane,
                    pending ? "Resolve read request" : "Read device settings",
                    () =>
                        request(
                            pending || {
                                idempotency_key: crypto.randomUUID(),
                                reason: "Inspect device operating settings",
                            },
                        ),
                );
            if (pending) pane.append(ui("p", "Resolve previous settings read"));
            if (!result.data.length)
                pane.append(ui("p", "No settings reads yet."));
            for (const row of result.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                card.append(
                    raw("p", "OCPP " + row.protocol),
                    ui("p", state(row.status)),
                );
                if (row.observed_at)
                    card.append(raw("p", row.observed_at + " UTC"));
                for (const setting of row.snapshot || [])
                    if (labels[setting.key]) {
                        card.append(
                            ui("p", "Settings reading", {
                                field: { translate: labels[setting.key] },
                                value: safeValue(setting),
                            }),
                            ui("p", state(setting.status)),
                        );
                    }
                if (
                    [
                        "transport_submitted",
                        "outcome_unknown",
                        "dispatching",
                    ].includes(row.status)
                )
                    button(card, "Check device reply", async () => {
                        if (!current()) return;
                        const observed = await api(
                            path + "/" + row.id + "/observe",
                            "POST",
                            {},
                            controller?.signal,
                        );
                        check(observed);
                        if (current())
                            await configurationReads(
                                company,
                                charger,
                                parent,
                                epoch,
                                page,
                                pending,
                            );
                    });
                pane.append(card);
            }
            if (result.current_page > 1)
                button(pane, "Previous", () =>
                    configurationReads(
                        company,
                        charger,
                        parent,
                        epoch,
                        page - 1,
                        pending,
                    ),
                );
            if (result.current_page < result.last_page)
                button(pane, "Next", () =>
                    configurationReads(
                        company,
                        charger,
                        parent,
                        epoch,
                        page + 1,
                        pending,
                    ),
                );
        } catch (error) {
            if (current()) {
                header();
                pane.append(
                    ui(
                        "p",
                        "Read unavailable. Refresh source history before another action.",
                    ),
                );
                button(pane, "Refresh source history", () =>
                    configurationReads(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        pending,
                    ),
                );
                notice(error);
            }
        }
    }
    async function operationalAnalytics(company, parent, epoch) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-operational-analytics]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.operationalAnalytics = "";
        parent.append(pane);
        const current = () => valid(epoch) && pane.isConnected;
        const path = base + `companies/${company.id}/operational-analytics`;
        pane.append(
            ui("h3", "Charging operational analytics"),
            ui("p", "Operational analytics source notice"),
        );
        const form = document.createElement("form");
        const fields = {};
        const now = new Date();
        for (const [name, title, type, value] of [
            [
                "from",
                "From (UTC)",
                "date",
                new Date(now.getTime() - 6 * 86400000)
                    .toISOString()
                    .slice(0, 10),
            ],
            ["to", "To (UTC)", "date", now.toISOString().slice(0, 10)],
            ["site_id", "Site number (optional)", "number", ""],
        ]) {
            const label = ui("label", title),
                input = document.createElement("input");
            input.type = type;
            input.name = "operational_" + name;
            input.value = value;
            if (type === "date") input.required = true;
            else {
                input.min = "1";
                input.step = "1";
            }
            label.append(input);
            form.append(label);
            fields[name] = input;
        }
        const submit = ui("button", "Apply and refresh");
        submit.type = "submit";
        form.append(submit);
        pane.append(form);
        const content = document.createElement("section");
        content.setAttribute("aria-live", "polite");
        pane.append(content);
        let version = 0,
            request;
        const metrics = {
            mapped_evses: "Mapped EVSEs",
            source_started: "Source starts",
            source_completed: "Completed source sessions",
            pending_source: "Awaiting source start at snapshot",
            completed_energy_wh: "Completed energy (Wh)",
            observed_busy_seconds: "Observed EVSE busy seconds",
            possible_evse_seconds: "Current EVSE window seconds",
            status_known_seconds: "Fresh status evidence seconds",
            status_unknown_seconds: "Unknown connector seconds",
            reported_fault_seconds: "Reported fault seconds",
            maximum_session_interval_average_w:
                "Maximum observed session interval average (W)",
            excluded_sessions: "Sessions requiring review",
        };
        async function load() {
            const own = ++version;
            request?.abort();
            request = new AbortController();
            const filters = Object.fromEntries(
                Object.entries(fields)
                    .filter(([, v]) => v.value)
                    .map(([k, v]) => [k, v.value]),
            );
            submit.disabled = true;
            content.replaceChildren(ui("p", "Loading…"));
            try {
                const data = await api(
                    path + "?" + new URLSearchParams(filters),
                    "GET",
                    undefined,
                    request.signal,
                );
                if (!current() || version !== own) return;
                if (
                    data.company_id !== company.id ||
                    data.evidence !== "SIMULATED_SOURCE" ||
                    data.physical_uptime_verified !== false ||
                    data.billing_authority !== false ||
                    typeof data.financial_visible !== "boolean"
                )
                    throw Error("Unexpected analytics authority.");
                content.replaceChildren(
                    raw("p", data.from + " — " + data.to + " UTC"),
                    raw("p", data.as_of),
                );
                const links = document.createElement("nav");
                for (const [kind, title] of [
                    ["export", "Download report CSV"],
                    ["pdf", "Download report PDF"],
                ]) {
                    const link = ui("a", title);
                    const query = {
                        ...filters,
                        ...(kind === "pdf"
                            ? {
                                  locale:
                                      document.documentElement.lang === "ar"
                                          ? "ar"
                                          : "en",
                              }
                            : {}),
                    };
                    link.href =
                        "/api/" +
                        path +
                        "/" +
                        kind +
                        "?" +
                        new URLSearchParams(query);
                    link.onclick = (event) => {
                        if (!current()) {
                            event.preventDefault();
                            return;
                        }
                        if (kind === "pdf")
                            link.href =
                                "/api/" +
                                path +
                                "/pdf?" +
                                new URLSearchParams({
                                    ...filters,
                                    locale:
                                        document.documentElement.lang === "ar"
                                            ? "ar"
                                            : "en",
                                });
                    };
                    link.rel = "noopener";
                    link.download =
                        "charging-operations." +
                        (kind === "pdf" ? "pdf" : "csv");
                    links.append(link);
                }
                content.append(links);
                for (const row of data.sites) {
                    const card = document.createElement("article");
                    card.append(raw("h4", row.name + " · #" + row.site_id));
                    for (const [key, title] of Object.entries(metrics)) {
                        if (!Number.isSafeInteger(row[key]) || row[key] < 0)
                            throw Error("Inexact source metric.");
                        card.append(
                            ui("p", "Operational metric: {label} — {value}", {
                                label: { translate: title },
                                value: row[key],
                            }),
                        );
                    }
                    content.append(card);
                }
                if (data.financial_visible)
                    content.append(
                        ui("h4", "Invoice references, not cash or settlements"),
                    );
                for (const row of data.invoice_facts) {
                    if (
                        !data.financial_visible ||
                        !Number.isSafeInteger(row.invoiced_minor) ||
                        !Number.isSafeInteger(row.vendor_allocated_minor) ||
                        !Number.isInteger(row.minor_units) ||
                        row.minor_units < 0 ||
                        row.minor_units > 3 ||
                        !/^[A-Z]{3}$/.test(row.currency) ||
                        row.evidence !== "SIMULATED"
                    )
                        throw Error("Invalid invoice fact.");
                    content.append(ui("p", "Invoiced amount"));
                    content.append(
                        raw(
                            "p",
                            row.currency +
                                " " +
                                EvMoney.format(
                                    row.invoiced_minor,
                                    row.minor_units,
                                ) +
                                " · " +
                                row.evidence,
                        ),
                    );
                    content.append(
                        ui("p", "Vendor allocated amount"),
                        raw(
                            "p",
                            row.currency +
                                " " +
                                EvMoney.format(
                                    row.vendor_allocated_minor,
                                    row.minor_units,
                                ),
                        ),
                    );
                }
            } catch (error) {
                if (current() && version === own) {
                    content.replaceChildren(
                        ui(
                            "p",
                            "Operational report unavailable. Apply filters to retry.",
                        ),
                    );
                    if (error.name !== "AbortError") notice(error);
                }
            } finally {
                if (current() && version === own) submit.disabled = false;
            }
        }
        const visibility = () => {
            if (document.hidden) {
                version++;
                request?.abort();
                content.replaceChildren();
                submit.disabled = false;
            }
        };
        document.addEventListener("visibilitychange", visibility);
        const observer = new MutationObserver(() => {
            if (!current()) {
                version++;
                request?.abort();
                document.removeEventListener("visibilitychange", visibility);
                observer.disconnect();
            }
        });
        observer.observe(parent, { childList: true });
        form.onsubmit = (event) => {
            event.preventDefault();
            load();
        };
        await load();
    }

    async function diagnosticFiles(
        company,
        charger,
        parent,
        epoch,
        page = 1,
        pending = null,
        repairOrder = null,
    ) {
        if (!valid(epoch)) return;
        const sourceHost = parent.closest(
            "[data-source-diagnostics]",
        )?.parentElement;
        if (sourceHost && sourceHost !== parent)
            return diagnosticFiles(
                company,
                charger,
                sourceHost,
                epoch,
                page,
                pending,
                repairOrder,
            );
        parent.querySelector("[data-diagnostic-files]")?.remove();
        parent.querySelector("[data-source-diagnostics]")?.remove();
        parent.querySelector("[data-configuration-reads]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.diagnosticFiles = "";
        parent.append(pane);
        const current = () => valid(epoch) && pane.isConnected;
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/diagnostic-logs`;
        pane.append(
            ui("h3", "Diagnostic files"),
            ui("p", "Diagnostic files privacy notice"),
        );
        button(pane, "Refresh diagnostic history", () =>
            diagnosticFiles(
                company,
                charger,
                parent,
                epoch,
                page,
                pending,
                repairOrder,
            ),
        );
        const history = document.createElement("section");
        pane.append(history);
        const hidden = () => {
            if (document.hidden) history.replaceChildren();
        };
        document.addEventListener("visibilitychange", hidden);
        const observer = new MutationObserver(() => {
            if (!current()) {
                document.removeEventListener("visibilitychange", hidden);
                observer.disconnect();
            }
        });
        observer.observe(parent, { childList: true });
        async function send(intent) {
            if (!current()) return;
            pending = intent;
            pane.querySelectorAll("button").forEach((b) => (b.disabled = true));
            try {
                await api(path, "POST", intent);
                if (current())
                    await diagnosticFiles(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        null,
                        repairOrder,
                    );
            } catch (error) {
                if (current()) {
                    notice(error);
                    await diagnosticFiles(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        pending,
                        repairOrder,
                    );
                }
            }
        }
        async function download(row) {
            if (
                !current() ||
                row.can_download !== true ||
                !Number.isSafeInteger(row.file_size) ||
                row.file_size < 1 ||
                row.file_size > 524288 ||
                !/^[a-f0-9]{64}$/.test(row.file_sha256)
            )
                return;
            const response = await fetch(
                "/api/" + path + "/" + row.id + "/download",
                {
                    credentials: "same-origin",
                    redirect: "error",
                    signal: controller.signal,
                },
            );
            if (!current()) return;
            if ([401, 419].includes(response.status)) {
                expired();
                throw Error("Private diagnostic session expired.");
            }
            if (
                !response.ok ||
                response.headers.get("content-type")?.split(";")[0] !==
                    "application/octet-stream"
            )
                throw Error("Private diagnostic download unavailable.");
            const reader = response.body.getReader(),
                parts = [];
            let size = 0;
            try {
                while (true) {
                    const item = await reader.read();
                    if (item.done) break;
                    size += item.value.length;
                    if (size > 524288)
                        throw Error("Diagnostic file exceeds limit.");
                    parts.push(item.value);
                }
            } finally {
                await reader.cancel();
                reader.releaseLock();
            }
            if (!current() || document.hidden) return;
            const bytes = new Uint8Array(size);
            let offset = 0;
            for (const part of parts) {
                bytes.set(part, offset);
                offset += part.length;
            }
            const hash = [
                ...new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
            ]
                .map((x) => x.toString(16).padStart(2, "0"))
                .join("");
            if (!current() || document.hidden) return;
            if (size !== row.file_size || hash !== row.file_sha256)
                throw Error("Diagnostic integrity mismatch.");
            const url = URL.createObjectURL(
                new Blob([bytes], { type: "application/octet-stream" }),
            );
            downloads.add(url);
            const link = document.createElement("a");
            link.href = url;
            link.download = "charger-log-" + row.id + ".bin";
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => {
                URL.revokeObjectURL(url);
                downloads.delete(url);
            }, 1000);
        }
        try {
            const result = await api(
                path +
                    "?" +
                    new URLSearchParams({
                        page: String(page),
                        ...(repairOrder
                            ? { repair_order_id: String(repairOrder) }
                            : {}),
                    }),
                "GET",
                undefined,
                controller.signal,
            );
            if (!current() || document.hidden) return;
            if (
                typeof result.can_request !== "boolean" ||
                typeof result.can_manage !== "boolean" ||
                result.evidence !== "SIMULATED_SOURCE" ||
                result.maximum_file_bytes !== 524288 ||
                result.charging_capacity_changed !== false
            )
                throw Error("Unexpected diagnostic capability.");
            if (pending) {
                history.append(
                    ui(
                        "p",
                        "Diagnostic request outcome unknown. Review history or resolve the original identity. No automatic redispatch.",
                    ),
                );
                if (result.can_request)
                    button(history, "Resolve diagnostic request", () =>
                        send(pending),
                    );
                button(
                    history,
                    "Clear pending form after reviewing history",
                    () =>
                        diagnosticFiles(
                            company,
                            charger,
                            parent,
                            epoch,
                            page,
                            null,
                            repairOrder,
                        ),
                );
            } else if (result.can_request) {
                form(
                    history,
                    "Request diagnostic file",
                    [
                        {
                            name: "reason",
                            label: "Diagnostic reason",
                            max: 500,
                        },
                        {
                            name: "repair_order_id",
                            label: "Repair work order (optional)",
                            type: "number",
                            optional: true,
                            value: repairOrder ? String(repairOrder) : "",
                        },
                    ],
                    async (values) => {
                        if (values.reason.trim().length < 10) return;
                        await send({
                            request_key: crypto.randomUUID(),
                            reason: values.reason.trim(),
                            ...(values.repair_order_id
                                ? {
                                      repair_order_id: Number(
                                          values.repair_order_id,
                                      ),
                                  }
                                : {}),
                        });
                    },
                );
            }
            const states = {
                requested: "Diagnostic request recorded",
                dispatching: "Diagnostic delivery pending",
                transport_submitted: "Diagnostic source transport submitted",
                outcome_unknown: "Diagnostic delivery unknown",
                not_dispatched: "Diagnostic not dispatched",
                device_rejected: "Diagnostic device rejected",
            };
            const statusLabels = {
                Uploading: "Diagnostic device uploading",
                Uploaded: "Diagnostic source uploaded",
                UploadFailed: "Diagnostic source upload failed",
                UploadFailure: "Diagnostic source upload failed",
                BadMessage: "Diagnostic source upload failed",
                NotSupportedOperation: "Diagnostic source upload failed",
                PermissionDenied: "Diagnostic source upload failed",
                legacy_status_unattributed:
                    "Legacy diagnostic status is unattributed",
            };
            for (const row of result.data) {
                if (
                    row.evidence !== "SIMULATED_SOURCE" ||
                    row.physical_device_verified !== false ||
                    typeof row.can_download !== "boolean"
                )
                    throw Error("Unexpected diagnostic receipt.");
                const card = document.createElement("article");
                card.append(
                    raw("h4", "#" + row.id + " · OCPP " + row.protocol),
                    ui(
                        "p",
                        states[row.dispatch_state] ||
                            "Diagnostic source review required",
                    ),
                    raw("p", row.expires_at),
                );
                if (row.repair_order_id)
                    card.append(
                        ui("p", "Repair work order #{id}", {
                            id: row.repair_order_id,
                        }),
                    );
                if (row.expired)
                    card.append(ui("p", "Diagnostic upload expired"));
                if (row.cancelled_at)
                    card.append(
                        ui(
                            "p",
                            "Diagnostic upload revoked and content removed",
                        ),
                    );
                if (row.accepted_at)
                    card.append(
                        ui("p", "Canonical diagnostic acceptance observed"),
                    );
                if (statusLabels[row.device_status])
                    card.append(ui("p", statusLabels[row.device_status]));
                if (row.file_received_at)
                    card.append(
                        ui("p", "Diagnostic file received"),
                        raw("p", row.file_size + " B · " + row.file_expires_at),
                        raw("p", row.file_sha256),
                    );
                if (row.can_download)
                    button(card, "Download private diagnostic file", () =>
                        download(row),
                    );
                if (!row.cancelled_at)
                    button(card, "Inspect diagnostic source", async () => {
                        await api(path + "/" + row.id + "/observe", "POST", {});
                        if (current())
                            await diagnosticFiles(
                                company,
                                charger,
                                parent,
                                epoch,
                                page,
                                pending,
                                repairOrder,
                            );
                    });
                if (result.can_manage && !row.cancelled_at)
                    button(
                        card,
                        "Revoke diagnostic upload and remove file",
                        async () => {
                            if (
                                !confirm(
                                    document.documentElement.lang === "ar"
                                        ? "إلغاء صلاحية الرفع وحذف الملف المخزن؟ قد يستكمل الجهاز طلبه الأصلي؛ لا يُرسل أمر إلغاء للجهاز."
                                        : "Revoke upload and remove the stored file? The device may finish its original request; no device cancel is sent.",
                                )
                            )
                                return;
                            await api(path + "/" + row.id, "DELETE");
                            if (current())
                                await diagnosticFiles(
                                    company,
                                    charger,
                                    parent,
                                    epoch,
                                    page,
                                    pending,
                                    repairOrder,
                                );
                        },
                    );
                history.append(card);
            }
            pager(history, result, (target) =>
                diagnosticFiles(
                    company,
                    charger,
                    parent,
                    epoch,
                    target,
                    pending,
                    repairOrder,
                ),
            );
        } catch (error) {
            if (current()) notice(error);
        }
    }

    async function repairOrders(
        company,
        charger,
        parent,
        epoch,
        page = 1,
        pending = null,
        selectedAlert = null,
    ) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-repair-orders]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.repairOrders = "";
        parent.append(pane);
        const current = () => valid(epoch) && pane.isConnected;
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/repair-orders`;
        pane.append(
            ui("h3", "Repair work orders"),
            ui(
                "p",
                "Repair documentation does not release charging capacity or verify physical repair.",
            ),
        );
        button(pane, "Refresh", () =>
            repairOrders(company, charger, parent, epoch, page, pending),
        );
        async function send(intent) {
            if (!current()) return;
            pending = intent;
            pane.querySelectorAll("button").forEach(
                (button) => (button.disabled = true),
            );
            try {
                await api(path, "POST", intent);
                if (current())
                    await repairOrders(company, charger, parent, epoch, page);
            } catch (error) {
                if (current()) {
                    notice(error);
                    await repairOrders(
                        company,
                        charger,
                        parent,
                        epoch,
                        page,
                        pending,
                    );
                }
            }
        }
        function action(parent, label, alert, version, state) {
            button(parent, label, async () => {
                const note = window.prompt(
                    document.documentElement.lang === "ar"
                        ? "ملاحظة الإصلاح (10 أحرف على الأقل)"
                        : "Repair note (at least 10 characters)",
                );
                if (!current() || !note || note.trim().length < 10) return;
                await send({
                    request_key: crypto.randomUUID(),
                    alert_id: alert,
                    expected_version: version,
                    state,
                    note: note.trim(),
                });
            });
        }
        try {
            const data = await api(`${path}?page=${page}`);
            if (!current()) return;
            if (
                data.commands_sent !== false ||
                data.capacity_changed !== false ||
                data.physical_repair_verified !== false ||
                typeof data.can_manage !== "boolean"
            )
                throw Error("Unexpected repair capability.");
            if (pending) {
                pane.append(
                    ui(
                        "p",
                        "Repair update uncertain. Resolve the original request before another update.",
                    ),
                );
                if (data.can_manage)
                    button(pane, "Resolve original repair update", () =>
                        send(pending),
                    );
                button(
                    pane,
                    "Clear pending form after reviewing repair history",
                    () => repairOrders(company, charger, parent, epoch, page),
                );
            } else if (data.can_manage) {
                // The source alert id is selected from the scoped paginated fault list.
                const form = document.createElement("form");
                const label = ui("label", "Fault alert number");
                const field = document.createElement("input");
                field.type = "number";
                field.min = "1";
                field.step = "1";
                field.required = true;
                field.name = "repair_alert";
                field.value = selectedAlert ? String(selectedAlert) : "";
                label.append(field);
                form.append(label);
                const submit = ui("button", "Open repair work order");
                submit.type = "submit";
                form.append(submit);
                form.onsubmit = async (event) => {
                    event.preventDefault();
                    submit.disabled = true;
                    try {
                        await actionCreate(Number(field.value));
                    } finally {
                        if (current()) submit.disabled = false;
                    }
                };
                async function actionCreate(alert) {
                    const note = window.prompt(
                        document.documentElement.lang === "ar"
                            ? "ملاحظة الإصلاح (10 أحرف على الأقل)"
                            : "Repair note (at least 10 characters)",
                    );
                    if (
                        !current() ||
                        !Number.isSafeInteger(alert) ||
                        alert < 1 ||
                        !note ||
                        note.trim().length < 10
                    )
                        return;
                    await send({
                        request_key: crypto.randomUUID(),
                        alert_id: alert,
                        expected_version: 0,
                        state: "open",
                        note: note.trim(),
                    });
                }
                pane.append(form);
            }
            const states = {
                open: "Repair open",
                in_progress: "Repair in progress",
                review: "Repair awaiting review",
                closed: "Repair review closed",
            };
            for (const row of data.data) {
                const card = document.createElement("article");
                card.append(
                    raw("h4", `#${row.id} · ${row.alert_id}`),
                    ui("p", states[row.state] || "Repair state unavailable"),
                    raw("p", row.updated_at),
                );
                card.append(
                    ui(
                        "p",
                        row.recovery_observation_id
                            ? "Source recovery observed"
                            : "Source recovery not observed",
                    ),
                );
                const history = document.createElement("section");
                async function loadHistory(page = 1) {
                    const events = await api(
                        `${path}/${row.id}/history?page=${page}`,
                    );
                    if (!current()) return;
                    history.replaceChildren();
                    for (const item of events.data)
                        history.append(
                            ui(
                                "p",
                                states[item.state] ||
                                    "Repair state unavailable",
                            ),
                            raw("p", item.note),
                            raw("p", item.created_at),
                        );
                    pager(history, events, loadHistory);
                }
                button(card, "Diagnostic files", () =>
                    diagnosticFiles(
                        company,
                        charger,
                        parent,
                        epoch,
                        1,
                        null,
                        row.id,
                    ),
                );
                button(card, "Repair history", () => loadHistory());
                card.append(history);
                if (data.can_manage && !pending) {
                    const transitions = {
                        open: [["Begin repair", "in_progress"]],
                        in_progress: [
                            ["Submit repair review", "review"],
                            ["Reopen repair", "open"],
                        ],
                        review: [
                            ["Continue repair", "in_progress"],
                            ...(row.recovery_observation_id
                                ? [["Close repair review", "closed"]]
                                : []),
                        ],
                        closed: [["Reopen repair", "open"]],
                    };
                    for (const [title, state] of transitions[row.state] || [])
                        action(card, title, row.alert_id, row.version, state);
                }
                pane.append(card);
            }
            pager(pane, data, (target) =>
                repairOrders(company, charger, parent, epoch, target, pending),
            );
        } catch (error) {
            if (current()) notice(error);
        }
    }

    async function diagnostics(company, charger, parent, epoch, before = null) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-source-diagnostics]")?.remove();
        parent.querySelector("[data-configuration-reads]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.sourceDiagnostics = "";
        parent.append(pane);
        pane.append(
            ui("h3", "Source diagnostics"),
            ui("p", "Source diagnostics disclaimer"),
        );
        button(pane, "Refresh", () =>
            diagnostics(company, charger, parent, epoch),
        );
        button(pane, "Repair work orders", () =>
            repairOrders(company, charger, pane, epoch),
        );
        button(pane, "Diagnostic files", () =>
            diagnosticFiles(company, charger, parent, epoch),
        );
        const faults = document.createElement("section");
        pane.append(faults);
        button(pane, "Fault alerts", () =>
            faultAlerts(company, charger, faults, epoch),
        );
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/source-diagnostics` +
            (before ? `?before=${before}` : "");
        try {
            const result = await api(
                path,
                "GET",
                undefined,
                controller?.signal,
            );
            if (!valid(epoch) || !pane.isConnected) return;
            if (result.can_manage_faults)
                button(pane, "Inspect source faults", async () => {
                    const inspected = await api(
                        base +
                            `companies/${company.id}/chargers/${charger.id}/fault-alerts/inspect` +
                            (before ? `?before=${before}` : ""),
                        "POST",
                        {},
                        controller?.signal,
                    );
                    if (valid(epoch) && pane.isConnected) {
                        await faultAlerts(company, charger, faults, epoch);
                        if (inspected.next_before)
                            button(pane, "Inspect older source faults", () =>
                                diagnostics(
                                    company,
                                    charger,
                                    parent,
                                    epoch,
                                    inspected.next_before,
                                ),
                            );
                    }
                });
            const monitor = result.automatic_monitor;
            pane.append(ui("p", "Automatic fault monitoring"));
            pane.append(
                ui(
                    "p",
                    monitor
                        ? {
                              caught_up: "Fault monitor caught up",
                              catching_up: "Fault monitor catching up",
                              not_observed: "Fault monitor awaiting source",
                              requires_review: "Fault monitor requires review",
                          }[monitor.state] || "Fault monitor not observed"
                        : "Fault monitor not observed",
                ),
            );
            if (monitor?.checked_at) pane.append(raw("p", monitor.checked_at));
            pane.append(raw("p", result.protocol));
            pane.append(
                ui(
                    "p",
                    result.source_registered
                        ? "Source registered"
                        : "Source not observed",
                ),
            );
            if (result.source_registered) {
                pane.append(
                    ui(
                        "p",
                        result.source_online
                            ? "Source reports online"
                            : "Source reports offline",
                    ),
                );
                pane.append(
                    ui(
                        "p",
                        result.recent_message_observed
                            ? "Recent source message"
                            : "No recent source message",
                    ),
                );
                pane.append(
                    raw("p", result.last_message_at || "—"),
                    raw("p", result.firmware_version || "—"),
                );
            }
            for (const event of result.events) {
                const card = document.createElement("article");
                card.append(
                    raw(
                        "p",
                        `${event.evseId ?? "—"}/${event.connectorId ?? "—"} · ${event.connectorStatus} · ${event.errorCode ?? "—"}`,
                    ),
                    raw("p", event.timestamp),
                );
                pane.append(card);
            }
            if (result.next_before)
                button(pane, "Next", () =>
                    diagnostics(
                        company,
                        charger,
                        parent,
                        epoch,
                        result.next_before,
                    ),
                );
        } catch (error) {
            if (valid(epoch) && pane.isConnected) {
                pane.append(ui("p", "Source diagnostics unavailable"));
                if ([401, 419].includes(error.status)) expired();
            }
        }
    }
    async function faultAlerts(company, charger, pane, epoch, page = 1) {
        if (!valid(epoch) || !pane.isConnected) return;
        pane.replaceChildren(
            ui("h3", "Fault alerts"),
            ui("p", "Fault acknowledgment disclaimer"),
        );
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/fault-alerts`;
        const result = await api(
            path + `?page=${page}`,
            "GET",
            undefined,
            controller?.signal,
        );
        if (!valid(epoch) || !pane.isConnected) return;
        for (const alert of result.data) {
            const row = document.createElement("article");
            row.dataset.faultAlert = alert.id;
            row.append(raw("p", `#${alert.id}`));
            button(row, "Repair work orders", () =>
                repairOrders(
                    company,
                    charger,
                    pane.parentElement,
                    epoch,
                    1,
                    null,
                    alert.id,
                ),
            );
            row.append(
                raw(
                    "p",
                    `${alert.evse_number}/${alert.connector_number} · ${alert.status} · ${alert.error_code ?? "—"}`,
                ),
                raw("p", alert.occurred_at),
            );
            row.append(
                ui(
                    "p",
                    alert.recovery_observed_at
                        ? "Recovery source observed"
                        : "No recovery source observed",
                ),
            );
            if (alert.acknowledged_at)
                row.append(ui("p", "Fault acknowledged"));
            else if (result.can_manage_faults)
                form(
                    row,
                    "Acknowledge fault",
                    [
                        {
                            name: "note",
                            label: "Operator recovery note",
                            max: 2000,
                        },
                    ],
                    async (values) => {
                        await api(
                            path + `/${alert.id}/acknowledge`,
                            "PATCH",
                            { note: values.note },
                            controller?.signal,
                        );
                        if (valid(epoch) && pane.isConnected)
                            await faultAlerts(
                                company,
                                charger,
                                pane,
                                epoch,
                                page,
                            );
                    },
                );
            pane.append(row);
        }
        if (result.current_page > 1)
            button(pane, "Previous", () =>
                faultAlerts(company, charger, pane, epoch, page - 1),
            );
        if (result.current_page < result.last_page)
            button(pane, "Next", () =>
                faultAlerts(company, charger, pane, epoch, page + 1),
            );
    }
    async function admission(company, charger, parent, epoch) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-admission]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.admission = "";
        parent.append(pane);
        pane.append(
            ui("h3", "Secure charger admission"),
            ui("p", "Admission disclaimer"),
        );
        const path =
            base + `companies/${company.id}/chargers/${charger.id}/admission`;
        const live = () => valid(epoch) && pane.isConnected;
        const refresh = () => admission(company, charger, parent, epoch);
        button(pane, "Refresh admission", refresh);
        const result = await api(path, "GET", undefined, controller?.signal);
        if (!live()) return;
        const profile = result.profile;
        if (!profile && result.can_manage)
            form(
                pane,
                "Prepare identity",
                [
                    { name: "identity", label: "Charger identity", max: 100 },
                    {
                        name: "certificate_sha256",
                        label: "Certificate SHA-256 (optional)",
                        optional: true,
                        max: 64,
                    },
                ],
                async (values, _form, e) => {
                    await api(
                        path,
                        "POST",
                        {
                            identity: values.identity,
                            certificate_sha256:
                                values.certificate_sha256 || null,
                        },
                        controller?.signal,
                    );
                    if (valid(e) && live()) await refresh();
                },
            );
        if (!profile) return;
        pane.append(
            raw("p", profile.identity + " · OCPP " + profile.protocol),
            ui("p", "Credential version: {version}", {
                version: profile.credential_version,
            }),
            ui(
                "p",
                profile.enabled ? "Admission enabled" : "Admission disabled",
            ),
        );
        if (profile.expires_at)
            pane.append(raw("p", profile.expires_at + " UTC"));
        const stateKey = "admission.state." + result.credential_state;
        if (result.credential_state) pane.append(ui("p", stateKey));
        const history = document.createElement("div");
        pane.append(history);
        async function events(page = 1) {
            history.replaceChildren(ui("p", "Loading…"));
            const rows = await api(
                path + "/events?page=" + page,
                "GET",
                undefined,
                controller?.signal,
            );
            if (!live()) return;
            history.replaceChildren(ui("h4", "Credential history"));
            for (const event of rows.data) {
                const item = document.createElement("article");
                const actionKey = "admission.event." + event.action;
                item.append(
                    ui("p", actionKey),
                    ui("p", "Credential version: {version}", {
                        version: event.credential_version,
                    }),
                    ui(
                        "span",
                        event.actor_type === "system"
                            ? "System operation"
                            : "Operator action",
                    ),
                    raw("time", event.created_at),
                );
                history.append(item);
            }
            if (rows.current_page > 1)
                button(history, "Previous", () => events(page - 1));
            if (rows.current_page < rows.last_page)
                button(history, "Next", () => events(page + 1));
        }
        button(pane, "Credential history", () => events());
        async function change(revoke) {
            if (
                !confirm(
                    EvLocale.text(
                        revoke ? "Admission revocation" : "Admission rotation",
                    ),
                )
            )
                return;
            try {
                const reply = await api(
                    path + "/credential",
                    revoke ? "DELETE" : "POST",
                    revoke
                        ? undefined
                        : {
                              expected_version: profile.credential_version,
                              days: 30,
                          },
                    controller?.signal,
                );
                if (!live()) return;
                if (revoke) {
                    await refresh();
                    return;
                }
                // Never store a charger credential in local/session storage or notices.
                pane.replaceChildren(ui("p", "One-time credential notice"));
                const secret = document.createElement("textarea");
                secret.readOnly = true;
                secret.value = reply.secret;
                secret.autocomplete = "off";
                secret.setAttribute(
                    "aria-label",
                    EvLocale.text("One-time charger secret"),
                );
                pane.append(secret);
                const hide = () => {
                    secret.value = "";
                    pane.replaceChildren(ui("p", "Credential hidden"));
                    if (live()) button(pane, "Refresh admission", refresh);
                };
                button(pane, "Hide secret", hide);
                setTimeout(() => {
                    secret.value = "";
                    if (live()) hide();
                }, 30000);
            } catch (error) {
                if (live()) {
                    pane.replaceChildren(
                        ui("p", "Admission outcome unconfirmed"),
                    );
                    button(pane, "Refresh admission", refresh);
                }
                throw error;
            }
        }
        if (result.can_manage) {
            button(pane, "Issue / rotate credential", () => change(false));
            button(pane, "Revoke credential", () => change(true));
        }
    }
    async function topology(company, charger, parent, operational, epoch) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-topology]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.topology = "";
        parent.append(pane);
        pane.append(
            ui("h3", "Canonical EVSE mapping"),
            ui("p", "Canonical mapping disclaimer"),
        );
        const path = `companies/${company.id}/chargers/${charger.id}`;
        const request = async (path, method, body) => {
            try {
                return await api(base + path, method, body, controller?.signal);
            } catch (error) {
                if (
                    valid(epoch) &&
                    pane.isConnected &&
                    (!error.status || error.status >= 500)
                ) {
                    pane.replaceChildren(
                        ui("p", "Mapping outcome unconfirmed"),
                    );
                    button(pane, "Refresh", () =>
                        topology(company, charger, parent, operational, epoch),
                    );
                }
                throw error;
            }
        };
        if (company.capabilities.inventory_write && operational)
            form(
                pane,
                "Create EVSE",
                [
                    {
                        name: "number",
                        label: "EVSE number",
                        type: "number",
                        min: 1,
                        limit: 1000,
                    },
                    {
                        name: "mode",
                        label: "Simulator mode",
                        choices: {
                            application: "Application simulator",
                            csms: "CSMS simulator",
                        },
                    },
                    {
                        name: "binding_id",
                        label: "Provisioned source binding ID",
                        type: "number",
                        optional: true,
                    },
                    {
                        name: "source_evse_number",
                        label: "Source EVSE number",
                        type: "number",
                        min: 0,
                        limit: 1000,
                        value: 0,
                    },
                ],
                async (data) => {
                    if (!confirm(EvLocale.text("Canonical mapping disclaimer")))
                        return;
                    await request(`${path}/evses`, "POST", {
                        number: Number(data.number),
                        mode: data.mode,
                        binding_id: data.binding_id
                            ? Number(data.binding_id)
                            : null,
                        source_evse_number: Number(data.source_evse_number),
                    });
                    if (valid(epoch))
                        await topology(
                            company,
                            charger,
                            parent,
                            operational,
                            epoch,
                        );
                },
            );
        const sources = document.createElement("div");
        pane.append(ui("h4", "Provisioned simulator sources"), sources);
        await paged(
            sources,
            `${path}/source-options`,
            (source, p) =>
                p.append(raw("p", `#${source.id} · OCPP ${source.protocol}`)),
            1,
            epoch,
        );
        const list = document.createElement("div");
        pane.append(list);
        await paged(
            list,
            `${path}/evses`,
            (evse, p) => {
                const row = document.createElement("article");
                row.dataset.evseId = evse.id;
                row.append(
                    ui("h4", "EVSE {number}", { number: evse.number }),
                    raw("p", `#${evse.id}`),
                    ui(
                        "p",
                        evse.mode === "csms"
                            ? "CSMS simulator"
                            : "Application simulator",
                    ),
                );
                if (company.capabilities.inventory_write && operational)
                    button(row, "Map connector", async () => {
                        row.querySelector("[data-mapping-choices]")?.remove();
                        const choices = document.createElement("div");
                        choices.dataset.mappingChoices = "";
                        row.append(choices);
                        await paged(
                            choices,
                            `${path}/connectors`,
                            (connector, p) => {
                                const choice = document.createElement("div");
                                choice.dataset.connectorId = connector.id;
                                choice.append(
                                    raw(
                                        "p",
                                        `${connector.connector_number} · ${connector.connector_type} · ${connector.max_power_kw} kW`,
                                    ),
                                );
                                if (connector.canonical_mapping_id)
                                    choice.append(
                                        ui("p", "Connector already mapped"),
                                    );
                                else
                                    form(
                                        choice,
                                        "Map connector",
                                        evse.mode === "csms"
                                            ? [
                                                  {
                                                      name: "source_connector_number",
                                                      label: "Source connector number",
                                                      type: "number",
                                                      limit: 100,
                                                  },
                                              ]
                                            : [],
                                        async (data) => {
                                            if (
                                                !confirm(
                                                    EvLocale.text(
                                                        "Canonical mapping disclaimer",
                                                    ),
                                                )
                                            )
                                                return;
                                            await request(
                                                `${path}/evses/${evse.id}/connectors/${connector.id}/mapping`,
                                                "POST",
                                                {
                                                    source_connector_number:
                                                        evse.mode === "csms"
                                                            ? Number(
                                                                  data.source_connector_number,
                                                              )
                                                            : null,
                                                },
                                            );
                                            if (valid(epoch))
                                                await topology(
                                                    company,
                                                    charger,
                                                    parent,
                                                    operational,
                                                    epoch,
                                                );
                                        },
                                    );
                                p.append(choice);
                            },
                            1,
                            epoch,
                        );
                    });
                p.append(row);
            },
            1,
            epoch,
        );
    }
    async function riderRequests(company, parent, epoch, page = 1) {
        parent.querySelector("[data-rider-grants]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.riderGrants = "";
        parent.append(pane);
        pane.append(
            ui("h3", "Rider authorization requests"),
            ui("p", "Rider approval disclaimer"),
        );
        const result = await api(
            base + `companies/${company.id}/rider-grants?page=${page}`,
            "GET",
            undefined,
            controller?.signal,
        );
        if (!valid(epoch) || !pane.isConnected) return;
        const refresh = () => riderRequests(company, parent, epoch, page);
        button(pane, "Refresh", refresh);
        for (const grant of result.data) {
            const row = document.createElement("article");
            row.dataset.riderGrant = grant.id;
            row.append(
                raw("h4", grant.label),
                raw(
                    "p",
                    `${grant.token_type} · ••••${grant.suffix} · ${grant.state}`,
                ),
                raw("p", grant.provisioning_state),
            );
            if (!grant.revoked_at && !grant.token_revoked_at)
                form(
                    row,
                    "Review rider approval",
                    [
                        {
                            name: "state",
                            label: "Approval decision",
                            choices: {
                                approved: "Approve",
                                rejected: "Reject",
                                revoked: "Revoke",
                            },
                        },
                        {
                            name: "expires_at",
                            label: "Approval expiry UTC",
                            max: 20,
                            optional: true,
                        },
                        {
                            name: "note",
                            label: "Operator recovery note",
                            max: 2000,
                        },
                    ],
                    async (body) => {
                        if (!body.expires_at) delete body.expires_at;
                        await api(
                            base +
                                `companies/${company.id}/rider-grants/${grant.id}/review`,
                            "POST",
                            body,
                            controller?.signal,
                        );
                        if (valid(epoch) && pane.isConnected) await refresh();
                    },
                );
            if (
                result.source_adapter_enabled &&
                grant.state === "approved" &&
                !grant.revoked_at &&
                !grant.token_revoked_at
            )
                button(row, "Provision rider source", async () => {
                    await api(
                        base +
                            `companies/${company.id}/rider-tokens/${grant.token_id}/provision`,
                        "POST",
                        {},
                        controller?.signal,
                    );
                    if (valid(epoch) && pane.isConnected) await refresh();
                });
            pane.append(row);
        }
        if (result.current_page > 1)
            button(pane, "Previous", () =>
                riderRequests(company, parent, epoch, page - 1),
            );
        if (result.current_page < result.last_page)
            button(pane, "Next", () =>
                riderRequests(company, parent, epoch, page + 1),
            );
    }
    async function maintenanceWindows(
        company,
        charger,
        connector,
        parent,
        epoch,
        page = 1,
    ) {
        if (!valid(epoch)) return;
        parent.querySelector("[data-maintenance-windows]")?.remove();
        const pane = document.createElement("section");
        pane.dataset.maintenanceWindows = "";
        parent.append(pane);
        pane.append(
            ui("h3", "Scheduled maintenance"),
            ui("p", "Scheduled maintenance disclaimer"),
        );
        const path =
            base +
            `companies/${company.id}/chargers/${charger.id}/connectors/${connector.id}/maintenance-windows`;
        const result = await api(
            path + `?page=${page}`,
            "GET",
            undefined,
            controller?.signal,
        );
        if (!valid(epoch) || !pane.isConnected) return;
        const refresh = () =>
            maintenanceWindows(
                company,
                charger,
                connector,
                parent,
                epoch,
                page,
            );
        button(pane, "Refresh", refresh);
        if (company.capabilities.inventory_write)
            form(
                pane,
                "Schedule maintenance",
                [
                    {
                        name: "starts_at",
                        label: "Maintenance starts (UTC ISO)",
                        max: 20,
                    },
                    {
                        name: "ends_at",
                        label: "Maintenance ends (UTC ISO)",
                        max: 20,
                    },
                    { name: "reason", label: "Maintenance reason", max: 1000 },
                ],
                async (values) => {
                    await api(path, "POST", values, controller?.signal);
                    if (valid(epoch) && pane.isConnected) await refresh();
                },
            );
        for (const window of result.data) {
            const row = document.createElement("article");
            row.dataset.maintenanceWindow = window.id;
            row.append(
                raw("p", `${window.starts_at} → ${window.ends_at} UTC`),
                raw("p", window.reason),
            );
            if (window.cancelled_at)
                row.append(ui("p", "Maintenance cancelled"));
            else if (
                company.capabilities.inventory_write &&
                company.capabilities.inventory_operational
            )
                button(row, "Cancel maintenance", async () => {
                    if (
                        !confirm(
                            EvLocale.text(
                                "Cancel scheduled maintenance confirmation",
                            ),
                        )
                    )
                        return;
                    await api(
                        path + `/${window.id}`,
                        "DELETE",
                        undefined,
                        controller?.signal,
                    );
                    if (valid(epoch) && pane.isConnected) await refresh();
                });
            pane.append(row);
        }
        if (result.current_page > 1)
            button(pane, "Previous", () =>
                maintenanceWindows(
                    company,
                    charger,
                    connector,
                    parent,
                    epoch,
                    page - 1,
                ),
            );
        if (result.current_page < result.last_page)
            button(pane, "Next", () =>
                maintenanceWindows(
                    company,
                    charger,
                    connector,
                    parent,
                    epoch,
                    page + 1,
                ),
            );
    }
    async function connectors(company, charger, parent, operational, epoch) {
        parent.querySelector("[data-connectors]")?.remove();
        const pane = document.createElement("div");
        pane.dataset.connectors = "";
        parent.append(pane);
        if (company.capabilities.inventory_write && operational)
            form(
                pane,
                "Create connector",
                [
                    {
                        name: "connector_number",
                        label: "Connector number",
                        type: "number",
                        limit: 100,
                    },
                    {
                        name: "connector_type",
                        label: "Connector type",
                        choices: {
                            CCS2: "CCS2",
                            Type2: "Type2",
                            CHAdeMO: "CHAdeMO",
                        },
                    },
                    {
                        name: "max_power_kw",
                        label: "Maximum power kW",
                        type: "number",
                        limit: 1000,
                    },
                ],
                async (v, f, e) => {
                    await api(
                        base +
                            "companies/" +
                            company.id +
                            "/chargers/" +
                            charger.id +
                            "/connectors",
                        "POST",
                        v,
                    );
                    if (valid(e))
                        await connectors(
                            company,
                            charger,
                            parent,
                            operational,
                            epoch,
                        );
                },
            );
        const list = document.createElement("div");
        pane.append(list);
        await paged(
            list,
            "companies/" +
                company.id +
                "/chargers/" +
                charger.id +
                "/connectors",
            (c, p) => {
                const row = document.createElement("article");
                row.append(
                    ui("p", "Connector #{id} · {type} · {power} kW", {
                        id: c.connector_number,
                        type: c.connector_type,
                        power: c.max_power_kw,
                    }),
                    ui(
                        "p",
                        c.maintenance_blocked
                            ? "Maintenance blocked"
                            : "Maintenance not blocked",
                    ),
                );
                button(row, "Scheduled maintenance", () =>
                    maintenanceWindows(company, charger, c, row, epoch),
                );
                if (company.capabilities.inventory_write)
                    form(
                        row,
                        "Set maintenance",
                        [
                            {
                                name: "maintenance_blocked",
                                label: "Maintenance state",
                                choices: operational
                                    ? { true: "Block", false: "Unblock" }
                                    : { true: "Block" },
                            },
                            {
                                name: "maintenance_reason",
                                label: "Maintenance reason",
                                max: 1000,
                            },
                        ],
                        async (v, f, e) => {
                            await api(
                                base +
                                    "companies/" +
                                    company.id +
                                    "/chargers/" +
                                    charger.id +
                                    "/connectors/" +
                                    c.id +
                                    "/maintenance",
                                "PATCH",
                                {
                                    ...v,
                                    maintenance_blocked:
                                        v.maintenance_blocked === "true",
                                },
                            );
                            if (valid(e))
                                await connectors(
                                    company,
                                    charger,
                                    parent,
                                    operational,
                                    epoch,
                                );
                        },
                    );
                p.append(row);
            },
            1,
            epoch,
        );
    }
    async function setActor(user) {
        clear();
        if (!user) return;
        root.hidden = false;
        controller = new AbortController();
        const epoch = generation;
        root.append(
            ui("h2", "Company workspace"),
            ui("p", "Company workflow disclaimer"),
        );
        button(root, "Refresh companies", () => setActor(actor()));
        try {
            regions = await api("regions");
            if (!valid(epoch)) return;
            if (user.role !== "admin" && !regions.length)
                root.append(ui("p", "Configure a region before applying."));
            if (user.role !== "admin" && regions.length)
                form(
                    root,
                    "Apply as a company",
                    [
                        { name: "legal_name", label: "Legal name" },
                        {
                            name: "country",
                            label: "Country",
                            choices: Object.fromEntries(
                                regions.map((r) => [r.country, r.country]),
                            ),
                        },
                        {
                            name: "trade_license_number",
                            label: "Trade license number",
                            max: 100,
                        },
                        {
                            name: "license_expires_on",
                            label: "License expiry",
                            type: "date",
                        },
                    ],
                    async (v, f, e) => {
                        const c = await api(base + "companies", "POST", {
                            ...v,
                            country: v.country.toUpperCase(),
                        });
                        if (valid(e)) {
                            f.reset();
                            await setActor(actor());
                            if (
                                actor()?.id === c.owner_id &&
                                root.querySelector("[data-company-detail]")
                            )
                                await detail({
                                    ...c,
                                    capabilities: {
                                        owner: true,
                                        company_read: true,
                                        inventory_read: true,
                                        inventory_write: true,
                                        private_evidence: true,
                                    },
                                });
                        }
                    },
                );
            const companies = document.createElement("div"),
                invites = document.createElement("div"),
                detailHost = document.createElement("div");
            detailHost.dataset.companyDetail = "";
            root.append(
                ui("h3", "Your companies"),
                companies,
                ui("h3", "Staff invitations"),
                invites,
                detailHost,
            );
            await paged(
                companies,
                "companies",
                (c, p) => {
                    const row = document.createElement("article");
                    row.append(
                        raw("h4", c.legal_name),
                        ui("p", "Company status: {status}", {
                            status: companyStatus(c.status),
                        }),
                    );
                    button(row, "Open company", () => detail(c));
                    p.append(row);
                },
                1,
                epoch,
            );
            if (!valid(epoch)) return;
            await paged(
                invites,
                "staff-invitations",
                (m, p) => {
                    const row = document.createElement("article");
                    row.append(
                        raw("h4", m.company?.legal_name || ""),
                        ui("p", "Company #{id}", { id: m.company_id }),
                        permissions(m.permissions),
                    );
                    button(row, "Accept staff invitation", async () => {
                        if (
                            !confirm(
                                EvLocale.text(
                                    "Accept these company permissions?",
                                ) +
                                    " " +
                                    (m.company?.legal_name || "") +
                                    " " +
                                    m.permissions
                                        .map((p) =>
                                            EvLocale.text(permissionName(p)),
                                        )
                                        .join(", "),
                            )
                        )
                            return;
                        await api(
                            base + "staff-invitations/" + m.id + "/accept",
                            "POST",
                            { expected_version: m.invitation_version },
                        );
                        if (valid(epoch)) await setActor(actor());
                    });
                    p.append(row);
                },
                1,
                epoch,
            );
        } catch (error) {
            if (valid(epoch) && error.name !== "AbortError") {
                notice(error);
                root.append(
                    ui(
                        "p",
                        "Company workspace could not load. Refresh to retry.",
                    ),
                );
            }
        }
    }
    return { clear, setActor };
};
