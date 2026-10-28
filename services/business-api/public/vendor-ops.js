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
        host.append(
            ui("p", "Company status: {status}", {
                status: companyStatus(company.status),
            }),
            ui("p", "Company workflow disclaimer"),
            ui("p", "License expiry: {date}", {
                date: company.license_expires_on?.slice(0, 10),
            }),
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
                p.append(row);
            },
            1,
            epoch,
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
