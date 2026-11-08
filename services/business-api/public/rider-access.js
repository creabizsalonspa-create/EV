/* Private rider identifiers remain in memory only; never browser storage. */
window.createRiderAccessWorkspace = ({ root, api, ui, notice, expired }) => {
    let generation = 0,
        account = null,
        controller = null,
        listVersion = 0;
    const nav = document.querySelector('a[href="#rider-access"]');
    const raw = (tag, text) => {
        const n = document.createElement(tag);
        n.textContent = String(text ?? "");
        return n;
    };
    const clear = () => {
        generation++;
        account = null;
        controller?.abort();
        root.replaceChildren();
        root.hidden = true;
        if (nav) nav.hidden = true;
    };
    const live = (epoch) =>
        epoch === generation &&
        account?.role === "customer" &&
        !controller?.signal.aborted;
    function button(parent, key, action) {
        const b = ui("button", key);
        b.type = "button";
        b.onclick = async () => {
            b.disabled = true;
            try {
                await action();
            } catch (e) {
                if (e.status === 401 || e.status === 419) expired();
                else notice(e);
            } finally {
                if (b.isConnected) b.disabled = false;
            }
        };
        parent.append(b);
        return b;
    }
    function form(parent, title, specs, submit, epoch) {
        const f = document.createElement("form");
        f.autocomplete = "off";
        f.append(ui("h3", title));
        let key = crypto.randomUUID(),
            frozen = null;
        for (const spec of specs) {
            const label = ui("label", spec.label),
                input = document.createElement(
                    spec.choices ? "select" : "input",
                );
            input.name = spec.name;
            if (spec.choices)
                for (const [value, text] of Object.entries(spec.choices)) {
                    const option = ui("option", text);
                    option.value = value;
                    input.append(option);
                }
            else {
                input.type = spec.type || "text";
                input.maxLength = spec.max || 100;
                input.autocomplete = "off";
            }
            input.required = !spec.optional;
            if (spec.value !== undefined) input.value = spec.value;
            label.append(input);
            f.append(label);
        }
        const send = ui("button", "Save");
        f.append(send);
        f.onsubmit = async (e) => {
            e.preventDefault();
            if (!live(epoch) || send.disabled) return;
            frozen ??= {
                ...Object.fromEntries(new FormData(f)),
                idempotency_key: key,
            };
            send.disabled = true;
            try {
                await submit(structuredClone(frozen));
                if (!live(epoch) || !f.isConnected) return;
                frozen = null;
                key = crypto.randomUUID();
                f.reset();
            } catch (error) {
                if (!live(epoch)) return;
                if ([409, 422].includes(error.status)) frozen = null;
                if ([401, 419].includes(error.status)) expired();
                else notice(error);
            } finally {
                if (live(epoch) && f.isConnected) {
                    for (const input of f.querySelectorAll("input,select"))
                        input.disabled = frozen !== null;
                    EvLocale.bind(
                        send,
                        frozen ? "Retry saved request" : "Save",
                    );
                    send.disabled = false;
                }
            }
        };
        parent.append(f);
    }
    async function setActor(user) {
        clear();
        if (user?.role !== "customer") return;
        account = user;
        controller = new AbortController();
        const epoch = generation;
        root.hidden = false;
        if (nav) nav.hidden = false;
        root.append(
            ui("h2", "Rider access tokens"),
            ui("p", "Rider access disclaimer"),
        );
        const secrets = document.createElement("div"),
            cards = document.createElement("div");
        form(
            root,
            "Register rider token",
            [
                { name: "label", label: "Token label", max: 80 },
                {
                    name: "token_type",
                    label: "Token type",
                    choices: {
                        Central: "Virtual token",
                        ISO14443: "ISO14443",
                        ISO15693: "ISO15693",
                    },
                },
                {
                    name: "value",
                    label: "Card identifier (optional for virtual)",
                    type: "password",
                    max: 20,
                    optional: true,
                },
                {
                    name: "expires_at",
                    label: "Token expiry UTC",
                    max: 20,
                    value: new Date(Date.now() + 30 * 86400000)
                        .toISOString()
                        .replace(/\.\d{3}Z$/, "Z"),
                },
            ],
            async (body) => {
                if (!body.value) delete body.value;
                const result = await api(
                    "rider-access/tokens",
                    "POST",
                    body,
                    controller.signal,
                );
                if (!live(epoch)) return;
                secrets.replaceChildren();
                if (result.value_once) {
                    const value = document.createElement("input");
                    value.type = "password";
                    value.readOnly = true;
                    value.autocomplete = "off";
                    value.value = result.value_once;
                    const label = ui("label", "One-time rider identifier");
                    label.append(value);
                    secrets.append(label, ui("p", "Rider identifier privacy"));
                    button(secrets, "Show identifier", () => {
                        value.type = "text";
                    });
                    button(secrets, "Hide identifier", () => {
                        value.value = "";
                        secrets.replaceChildren();
                    });
                }
                loadCards().catch(notice);
            },
            epoch,
        );
        root.append(secrets, cards);
        button(root, "Refresh", () => loadCards());
        async function grants(token, pane, page = 1) {
            const result = await api(
                `rider-access/tokens/${token.id}/grants?page=${page}`,
                "GET",
                undefined,
                controller.signal,
            );
            if (!live(epoch) || !pane.isConnected) return;
            pane.replaceChildren(ui("h4", "Station approvals"));
            for (const grant of result.data) {
                const row = document.createElement("article");
                row.append(
                    raw(
                        "p",
                        `${grant.station_name ?? grant.station_id} · ${grant.state}`,
                    ),
                    raw("p", grant.expires_at ?? "—"),
                );
                if (!grant.revoked_at)
                    button(row, "Withdraw station approval", async () => {
                        await api(
                            `rider-access/tokens/${token.id}/grants/${grant.id}`,
                            "DELETE",
                            undefined,
                            controller.signal,
                        );
                        if (live(epoch) && pane.isConnected)
                            await grants(token, pane, page);
                    });
                pane.append(row);
            }
            if (result.current_page > 1)
                button(pane, "Previous", () => grants(token, pane, page - 1));
            if (result.current_page < result.last_page)
                button(pane, "Next", () => grants(token, pane, page + 1));
            if (token.revoked_at || new Date(token.expires_at) <= new Date())
                return;
            const search = document.createElement("input");
            search.maxLength = 100;
            const label = ui("label", "Station search");
            label.append(search);
            pane.append(label);
            const choices = document.createElement("div");
            pane.append(choices);
            async function stations(page = 1) {
                const data = await api(
                    `rider-access/stations?q=${encodeURIComponent(search.value)}&page=${page}`,
                    "GET",
                    undefined,
                    controller.signal,
                );
                if (!live(epoch) || !choices.isConnected) return;
                choices.replaceChildren();
                for (const station of data.data) {
                    const stationRow = document.createElement("article");
                    stationRow.append(raw("p", station.name));
                    choices.append(stationRow);
                    button(stationRow, "Request station approval", async () => {
                        await api(
                            `rider-access/tokens/${token.id}/grants`,
                            "POST",
                            { station_id: station.id },
                            controller.signal,
                        );
                        if (live(epoch) && pane.isConnected)
                            await grants(token, pane);
                    });
                }
                if (data.current_page > 1)
                    button(choices, "Previous", () => stations(page - 1));
                if (data.current_page < data.last_page)
                    button(choices, "Next", () => stations(page + 1));
            }
            button(pane, "Search stations", () => stations());
            await stations();
        }
        async function loadCards(page = 1) {
            const version = ++listVersion;
            const result = await api(
                `rider-access/tokens?page=${page}`,
                "GET",
                undefined,
                controller.signal,
            );
            if (!live(epoch) || version !== listVersion) return;
            cards.replaceChildren();
            for (const token of result.data) {
                const row = document.createElement("article");
                row.dataset.riderToken = token.id;
                row.append(
                    raw("h3", token.label),
                    raw("p", `${token.token_type} · ••••${token.suffix}`),
                    raw("p", token.expires_at),
                    ui(
                        "p",
                        token.revoked_at
                            ? "Rider token revoked"
                            : new Date(token.expires_at) <= new Date()
                              ? "Rider token expired"
                              : "Operator approval required",
                    ),
                    raw("p", token.provisioning_state),
                );
                if (!token.revoked_at) {
                    button(row, "Revoke rider token", async () => {
                        if (
                            !confirm(
                                EvLocale.text(
                                    "Revoke rider token confirmation",
                                ),
                            )
                        )
                            return;
                        await api(
                            `rider-access/tokens/${token.id}`,
                            "DELETE",
                            undefined,
                            controller.signal,
                        );
                        if (live(epoch)) await loadCards(page);
                    });
                    form(
                        row,
                        "Change token expiry",
                        [
                            {
                                name: "expires_at",
                                label: "Token expiry UTC",
                                max: 20,
                            },
                        ],
                        async (body) => {
                            await api(
                                `rider-access/tokens/${token.id}`,
                                "PATCH",
                                { expires_at: body.expires_at },
                                controller.signal,
                            );
                            if (live(epoch)) loadCards(page).catch(notice);
                        },
                        epoch,
                    );
                }
                const pane = document.createElement("div");
                row.append(pane);
                button(row, "Station approvals", () => grants(token, pane));
                const activityPane = document.createElement("div");
                row.append(activityPane);
                button(row, "Charging access activity", () =>
                    window.loadRiderAccessHistory({
                        root: activityPane,
                        path: `rider-access/tokens/${token.id}/activity`,
                        api,
                        ui,
                        valid: () => live(epoch),
                        signal: controller.signal,
                    }),
                );
                cards.append(row);
            }
            if (result.current_page > 1)
                button(cards, "Previous", () => loadCards(page - 1));
            if (result.current_page < result.last_page)
                button(cards, "Next", () => loadCards(page + 1));
        }
        try {
            await loadCards();
        } catch (error) {
            if (live(epoch)) {
                cards.replaceChildren(ui("p", "Rider access unavailable"));
                if ([401, 419].includes(error.status)) expired();
            }
        }
    }
    return { clear, setActor };
};

// Shared private customer/operator read model. No raw card identifiers or notes.
window.loadRiderAccessHistory = (() => {
    const versions = new WeakMap();
    const visibilityHandlers = new WeakMap();
    return async function load({
        root,
        path,
        api,
        ui,
        valid,
        signal,
        kind = "events",
        page = 1,
    }) {
        const version = (versions.get(root) || 0) + 1;
        versions.set(root, version);
        const oldHandler = visibilityHandlers.get(root);
        if (oldHandler)
            document.removeEventListener("visibilitychange", oldHandler);
        const hide = () => {
            if (document.hidden) {
                versions.set(root, version + 1);
                root.replaceChildren();
                document.removeEventListener("visibilitychange", hide);
            }
        };
        visibilityHandlers.set(root, hide);
        document.addEventListener("visibilitychange", hide, { signal });
        if (document.hidden) {
            hide();
            return;
        }
        const result = await api(
            `${path}?kind=${kind}&page=${page}`,
            "GET",
            undefined,
            signal,
        );
        if (!valid() || !root.isConnected || versions.get(root) !== version)
            return;
        if (
            result.read_only !== true ||
            result.kind !== kind ||
            result.hardware_verified !== false
        )
            throw new Error("Unverified access history");
        root.replaceChildren(
            ui("h4", "Charging access activity"),
            ui("p", "Charging access history disclaimer"),
        );
        const navigate = (key, nextKind, nextPage) => {
            const b = ui("button", key);
            b.type = "button";
            b.onclick = async () => {
                b.disabled = true;
                try {
                    await load({
                        root,
                        path,
                        api,
                        ui,
                        valid,
                        signal,
                        kind: nextKind,
                        page: nextPage,
                    });
                } catch {
                    if (
                        valid() &&
                        root.isConnected &&
                        versions.get(root) === version
                    )
                        root.replaceChildren(
                            ui("p", "Rider access unavailable"),
                        );
                } finally {
                    if (b.isConnected) b.disabled = false;
                }
            };
            root.append(b);
        };
        navigate("Access lifecycle", "events", 1);
        navigate("Card charging attempts", "sessions", 1);
        if (!result.data.length) root.append(ui("p", "No access activity"));
        for (const row of result.data) {
            const entry = document.createElement("article");
            let state = row.state;
            if (
                (state === "source_active" &&
                    row.source_start_observed !== true) ||
                (state === "source_ended" &&
                    (row.source_start_observed !== true ||
                        row.source_end_observed !== true))
            )
                state = "requires_review";
            entry.append(
                ui(
                    "p",
                    kind === "events"
                        ? `Rider activity ${row.action}`
                        : `Rider session ${state}`,
                ),
            );
            const stamp = document.createElement("p");
            stamp.textContent = row.created_at;
            entry.append(stamp);
            if (kind === "sessions") {
                const protocol = document.createElement("p");
                protocol.textContent = `OCPP ${row.protocol} · #${row.charging_session_id}`;
                entry.append(protocol);
            }
            root.append(entry);
        }
        if (result.current_page > 1) navigate("Previous", kind, page - 1);
        if (result.current_page < result.last_page)
            navigate("Next", kind, page + 1);
    };
})();
