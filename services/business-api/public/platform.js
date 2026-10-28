let unconfirmedFavorite = null;
let favoriteMode = false,
    previousCatalogQuery = "";
let commandRequest = null,
    commandPage = 1;
let user = null,
    csrf = document.querySelector("meta[name=csrf-token]").content;
const el = (id) => document.getElementById(id),
    notice = (value) => {
        if (value?.name === "SessionChangedError") return;
        return EvLocale.bind(
            el("notice"),
            value instanceof Error ? errorMessage(value) : value,
        );
    };
const client = new EvApi(csrf);
async function api(path, method = "GET", data, signal) {
    client.token = csrf;
    try {
        return await client.request(path, method, data, signal);
    } catch (error) {
        if ([401, 419].includes(error.status) && user) {
            clearIdentity();
        }
        throw error;
    }
}
let identityBusy = false;
function setIdentityBusy(value) {
    identityBusy = value;
    el("account")
        .querySelectorAll("input,button")
        .forEach((n) => (n.disabled = value));
    el("logout").disabled = el("logout-retry").disabled = value;
}
function pendingLogout(value) {
    try {
        if (value === undefined)
            return sessionStorage.getItem("creabiz.logout-pending") === "1";
        if (value) sessionStorage.setItem("creabiz.logout-pending", "1");
        else sessionStorage.removeItem("creabiz.logout-pending");
    } catch {}
    return false;
}
function clearIdentity() {
    const privateCatalog = favoriteMode;
    window.dispatchEvent(new CustomEvent("catalog-map-clear"));
    unconfirmedFavorite = null;
    favoriteMode = false;
    const epoch = client.invalidateSession();
    user = null;
    renderIdentity();
    clearPrivateViews();
    el("stations")
        .querySelectorAll("[data-favorite-control]")
        .forEach((n) => n.remove());
    if (privateCatalog) {
        stationRequest?.abort();
        el("stations").replaceChildren();
        el("catalog-pagination").replaceChildren();
        el("catalog-state").textContent = "";
        delete el("catalog-state").dataset.i18n;
        delete el("catalog-state").dataset.i18nValues;
    }
    el("stations")
        .querySelectorAll("form")
        .forEach((n) => n.remove());
    setIdentityBusy(false);
    return epoch;
}
function clearPrivateViews() {
    vendorWorkspace.clear();
    roamingWorkspace.clear();
    invoiceHistory.clear();
    commercialWorkspace.clear();
    tariffWorkspace.clear();
    commandRequest?.abort();
    el("commands-state").textContent = "";
    delete el("commands-state").dataset.i18n;
    for (const id of [
        "reservation-list",
        "simulation-list",
        "fleet-list",
        "fleet-invitations",
        "fleet-detail",
        "vehicle-list",
        "ticket-list",
        "ticket-detail",
        "notifications",
        "overview",
        "command-list",
        "command-pagination",
    ])
        el(id).replaceChildren();
    for (const id of [
        "profile-form",
        "support-create",
        "vehicle-create",
        "station-form",
        "fleet-create",
    ])
        el(id).reset();
}
function empty(container, message) {
    const state = ui("p", message);
    state.className = "empty-state";
    el(container).append(state);
}
function renderIdentity() {
    renderCatalogMode();
    el("account").hidden = !!user;
    el("forgot-password").hidden = !!user;
    el("signed").hidden = !user;
    el("bookings").hidden = !user;
    el("vendor").hidden = user?.role !== "vendor";
    if (user)
        EvLocale.bind(el("who"), "{name} · {role}", {
            name: user.name,
            role: { translate: user.role },
        });
    else {
        el("who").textContent = "";
        delete el("who").dataset.i18n;
        delete el("who").dataset.i18nValues;
    }
    if (user)
        EvLocale.bind(
            el("email-status"),
            user.email_verified_at
                ? "Email verified / البريد مؤكد"
                : "Email not verified / البريد غير مؤكد",
        );
    else {
        el("email-status").textContent = "";
        delete el("email-status").dataset.i18n;
    }
    el("verify-email").hidden = !user || !!user.email_verified_at;
    for (const id of ["simulations", "fleets", "garage", "support"])
        el(id).hidden = !user;
    el("admin").hidden = el("region-config").hidden = user?.role !== "admin";
    el("command-history").hidden = !["vendor", "admin"].includes(user?.role);
    document.querySelectorAll("[data-access]").forEach((n) => {
        n.hidden =
            n.dataset.access === "auth"
                ? !user
                : n.dataset.access === "operations"
                  ? !["vendor", "admin"].includes(user?.role)
                  : user?.role !== n.dataset.access;
    });
    EvLocale.bind(
        el("workspace-label"),
        user
            ? user.role === "admin"
                ? "Operations workspace"
                : user.role === "vendor"
                  ? "Operator workspace"
                  : "Driver workspace"
            : "Your charging companion",
    );
}

function node(tag, text) {
    let e = document.createElement(tag);
    e.textContent = text;
    return e;
}
function ui(tag, key, values = {}) {
    const element = document.createElement(tag);
    if (tag === "label")
        element.append(
            EvLocale.bind(document.createElement("span"), key, values),
        );
    else if (["article", "p"].includes(tag))
        element.append(
            EvLocale.bind(document.createElement("span"), key, values),
        );
    else EvLocale.bind(element, key, values);
    return element;
}
function errorMessage(error) {
    return (
        {
            401: "Your session has ended. Please sign in again.",
            419: "Your security session expired. Reload this page before trying again.",
            429: "Too many requests. Please wait before trying again.",
            422: "Please check the entered values.",
            403: "This action is not available for your account.",
            404: "The record is unavailable.",
            409: "Refresh the record before repeating this action.",
        }[error.status] ||
        (error.status
            ? "The service is temporarily unavailable. Please retry."
            : error.message)
    );
}
async function identity() {
    let epoch = client.sessionEpoch;
    el("ticket-detail").replaceChildren();
    el("fleet-detail").replaceChildren();
    el("overview").replaceChildren();
    try {
        const current = await api("me");
        if (epoch !== client.sessionEpoch) return;
        if (
            !current ||
            !Number.isSafeInteger(current.id) ||
            current.id <= 0 ||
            typeof current.name !== "string" ||
            !["customer", "vendor", "admin"].includes(current.role)
        )
            throw new ApiError("Unreadable account identity.", 502);
        if (user && (current.id !== user.id || current.role !== user.role))
            epoch = clearIdentity();
        user = current;
    } catch (error) {
        if (
            epoch !== client.sessionEpoch ||
            error.name === "SessionChangedError"
        )
            return;
        epoch = clearIdentity();
        if (error.status !== 401) notice(error);
    }
    renderIdentity();
    if (user && epoch === client.sessionEpoch) setIdentityBusy(false);
    if (!user) clearPrivateViews();
    if (user) {
        await bookings();
        await simulations();
        await fleets();
        await garage();
        await support();
    }
    await vendorWorkspace.setActor(user);
    await roamingWorkspace.setActor(user);
    await invoiceHistory.setActor(user);
    await tariffWorkspace.setActor(user);
    await commercialWorkspace.setActor(user);
    if (user?.role === "admin") await adminOverview();
    if (["vendor", "admin"].includes(user?.role)) await commandHistory();
}
let stationRequest = null,
    stationQuery = "";
function reservationForm(s, mapping = null) {
    let f = document.createElement("form"),
        l = ui("label", "UTC start (00 or 30 minutes)"),
        i = document.createElement("input");
    i.type = "datetime-local";
    i.step = 1800;
    i.required = true;
    l.append(i);
    f.append(l);
    let b = ui("button", "Reserve simulator slot");
    f.append(b);
    let bookingKey = crypto.randomUUID();
    f.onsubmit = async (e) => {
        e.preventDefault();
        b.disabled = true;
        try {
            await api("reservations", "POST", {
                station_id: s.id,
                ...(mapping ? { connector_mapping_id: mapping.id } : {}),
                starts_at:
                    i.value.length === 16 ? i.value + ":00Z" : i.value + "Z",
                idempotency_key: bookingKey,
            });
            bookingKey = crypto.randomUUID();
            notice(
                "Simulation inventory reserved. No physical charger command was sent.",
            );
            await bookings();
        } catch (e) {
            notice(e);
        } finally {
            b.disabled = false;
        }
    };
    return f;
}
async function connectorChoices(station, root, page = 1) {
    const identity = client.sessionEpoch;
    root.replaceChildren(ui("p", "Loading…"));
    try {
        const result = await api(
            `stations/${station.id}/connectors?page=${page}`,
        );
        if (!root.isConnected || identity !== client.sessionEpoch) return;
        root.replaceChildren(ui("p", "Connector choice disclaimer"));
        for (const option of result.data) {
            const row = document.createElement("div");
            row.dataset.mappingId = option.id;
            row.append(
                ui("h4", "EVSE {evse} · Connector {connector}", {
                    evse: option.evse_number,
                    connector: option.connector_number,
                }),
                node(
                    "p",
                    `${option.connector_type} · ${option.max_power_kw} kW`,
                ),
            );
            if (option.reservable && user?.role === "customer")
                row.append(reservationForm(station, option));
            else
                row.append(
                    ui(
                        "p",
                        option.reservable
                            ? "Sign in to reserve connector"
                            : "Connector currently unavailable",
                    ),
                );
            root.append(row);
        }
        root.append(
            ui("p", "Page {page} of {last} · {total} records", {
                page: result.current_page,
                last: result.last_page,
                total: result.total,
            }),
        );
        for (const [label, target, enabled] of [
            ["Previous", page - 1, page > 1],
            ["Next", page + 1, page < result.last_page],
        ]) {
            const button = ui("button", label);
            button.type = "button";
            button.disabled = !enabled;
            button.onclick = () => connectorChoices(station, root, target);
            root.append(button);
        }
    } catch (error) {
        if (root.isConnected && identity === client.sessionEpoch) {
            root.replaceChildren(ui("p", "Roaming load failed"));
            const retry = ui("button", "Retry");
            retry.onclick = () => connectorChoices(station, root, page);
            root.append(retry);
        }
    }
}
function renderCatalogMode() {
    if (user?.role !== "customer") {
        unconfirmedFavorite = null;
        el("stations")
            .querySelectorAll("[data-favorite-control]")
            .forEach((node) => node.remove());
        if (favoriteMode) {
            window.dispatchEvent(new CustomEvent("catalog-map-clear"));
            favoriteMode = false;
            stationRequest?.abort();
            el("stations").replaceChildren();
            el("catalog-pagination").replaceChildren();
            el("catalog-state").textContent = "";
            delete el("catalog-state").dataset.i18n;
            delete el("catalog-state").dataset.i18nValues;
        }
    }
    EvLocale.bind(
        el("catalog-title"),
        favoriteMode ? "Saved stations" : "Find a place to recharge",
    );
    const button = el("favorite-catalog");
    button.hidden = user?.role !== "customer";
    EvLocale.bind(button, favoriteMode ? "All stations" : "Saved stations");
    el("filters").hidden = favoriteMode;
}

async function stations(query = "") {
    const identity = client.sessionEpoch,
        savedMode = favoriteMode;
    stationRequest?.abort();
    const request = new AbortController();
    stationRequest = request;
    el("stations").setAttribute("aria-busy", "true");
    EvLocale.bind(
        el("catalog-state"),
        "Loading stations… / جارٍ تحميل المحطات",
    );
    let j;
    try {
        j = await api(
            (savedMode ? "favorites" : "stations") + query,
            "GET",
            undefined,
            request.signal,
        );
        if (request.signal.aborted || identity !== client.sessionEpoch) return;
        if (savedMode && !j.data.length && j.current_page > j.last_page)
            return stations("?page=" + j.last_page);
        if (!savedMode && user?.role === "customer" && j.data.length) {
            const params = new URLSearchParams();
            for (const station of j.data)
                params.append("station_ids[]", station.id);
            const status = await api(
                "favorites/status?" + params,
                "GET",
                undefined,
                request.signal,
            );
            if (
                !Array.isArray(status.favorite_station_ids) ||
                !status.favorite_station_ids.every(
                    (id) => Number.isSafeInteger(id) && id > 0,
                )
            )
                throw new Error("Unreadable preference status");
            const ids = new Set(status.favorite_station_ids);
            j.data = j.data.map((station) => ({
                ...station,
                is_favorite: ids.has(station.id),
            }));
        }
    } catch (error) {
        if (request.signal.aborted) return;
        EvLocale.bind(el("catalog-state"), errorMessage(error));
        el("stations").removeAttribute("aria-busy");
        return;
    }
    if (
        request.signal.aborted ||
        identity !== client.sessionEpoch ||
        savedMode !== favoriteMode
    )
        return;
    el("stations").removeAttribute("aria-busy");
    stationQuery = query;
    if (
        user?.role === "customer" &&
        unconfirmedFavorite !== null &&
        (j.data.some((station) => station.id === unconfirmedFavorite) ||
            (savedMode && j.total === 0))
    ) {
        unconfirmedFavorite = null;
        if (el("notice").dataset.i18n === "Favorite update unconfirmed") {
            el("notice").textContent = "";
            delete el("notice").dataset.i18n;
            delete el("notice").dataset.i18nValues;
        }
    }
    EvLocale.bind(
        el("catalog-state"),
        "{total} stations · availability is unverified / الحالة غير مؤكدة",
        { total: j.total },
    );
    el("catalog-pagination").replaceChildren();
    for (const [label, url] of [
        ["Previous / السابق", j.prev_page_url],
        ["Next / التالي", j.next_page_url],
    ]) {
        const button = ui("button", label);
        button.disabled = !url;
        button.onclick = () => stations(new URL(url, location.origin).search);
        el("catalog-pagination").append(button);
    }
    el("catalog-pagination").append(
        ui("span", "Page {current} / {last}", {
            current: j.current_page,
            last: j.last_page,
        }),
    );
    window.dispatchEvent(new CustomEvent("catalog-map-data", { detail: j.data }));
    el("stations").replaceChildren();
    if (!j.data.length)
        el("stations").append(
            ui(
                "p",
                savedMode
                    ? "No saved stations yet"
                    : "No stations match. No live network data is connected.",
            ),
        );
    for (let s of j.data) {
        let a = node("article", "");
        a.className = "station-card";
        a.dataset.stationId = s.id;
        a.append(
            node("h3", s.name),
            Object.assign(
                ui("span", "{evidence} · {status}", {
                    evidence: { translate: s.evidence },
                    status: { translate: s.availability },
                }),
                {
                    className: "badge",
                },
            ),
            node(
                "p",
                s.reservation_policy === "canonical"
                    ? EvLocale.text("Mapped connector capabilities") +
                          " · " +
                          s.country
                    : s.connector_type +
                          " · " +
                          s.power_kw +
                          " kW · " +
                          s.country,
            ),
        );
        if (Number.isFinite(Number(s.distance_km)) && s.distance_km != null) {
            const distance = node("p", "");
            distance.dataset.en = `${Number(s.distance_km).toFixed(2)} km straight-line distance`;
            distance.dataset.ar = `${Number(s.distance_km).toFixed(2)} كم بخط مستقيم`;
            distance.textContent =
                document.documentElement.lang === "ar"
                    ? distance.dataset.ar
                    : distance.dataset.en;
            a.append(distance);
        }
        const details = document.createElement("details");
        details.append(
            ui("summary", "Location & access / الموقع والوصول"),
            node("p", s.latitude + ", " + s.longitude + " · " + s.currency),
            ui(
                "p",
                "Live connector status, commercial tariffs and navigation are not connected.",
            ),
        );
        a.append(details);
        if (user?.role === "customer") {
            const button = ui(
                "button",
                s.is_favorite ? "Remove saved station" : "Save station",
            );
            button.type = "button";
            button.dataset.favoriteControl = "true";
            button.setAttribute(
                "aria-pressed",
                s.is_favorite ? "true" : "false",
            );
            button.onclick = async () => {
                const epoch = client.sessionEpoch;
                button.disabled = true;
                try {
                    await api(
                        "favorites/" + s.id,
                        s.is_favorite ? "DELETE" : "PUT",
                    );
                    if (epoch === client.sessionEpoch && a.isConnected)
                        await stations(stationQuery);
                } catch (error) {
                    if (epoch === client.sessionEpoch && a.isConnected) {
                        unconfirmedFavorite = s.id;
                        notice("Favorite update unconfirmed");
                    }
                } finally {
                    if (epoch === client.sessionEpoch && button.isConnected)
                        button.disabled = false;
                }
            };
            a.append(button);
        }
        if (s.reservation_policy === "managed_blocked")
            a.append(ui("p", "Managed reservations unavailable"));
        if (s.reservable && user?.role === "customer")
            a.append(reservationForm(s));
        if (s.reservation_policy === "canonical") {
            const choices = document.createElement("div"),
                button = ui("button", "Choose connector");
            button.type = "button";
            button.onclick = () => connectorChoices(s, choices);
            a.append(button, choices);
        }
        el("stations").append(a);
    }
}
async function bookings() {
    let j = await api("reservations");
    el("reservation-list").replaceChildren();
    if (!j.data.length)
        empty(
            "reservation-list",
            "No reservations yet. Choose a simulator station to try a booking. / لا توجد حجوزات بعد",
        );
    for (let r of j.data) {
        let a = ui(
            "article",
            "#{id} · station {station} · {time} UTC · {status}",
            {
                id: r.id,
                station: r.station_id,
                time: r.starts_at,
                status: { translate: r.status },
            },
        );
        if (r.checked_in_at)
            a.append(
                ui("p", "Self-reported arrival · {time}", {
                    time: r.checked_in_at,
                }),
            );
        if (user.role === "customer" && r.can_check_in) {
            const arrival = ui("button", "Check in");
            arrival.type = "button";
            arrival.onclick = async () => {
                if (!confirm(EvLocale.text("Arrival confirmation"))) return;
                arrival.disabled = true;
                try {
                    await api(`reservations/${r.id}/check-in`, "POST", {
                        self_reported_arrival: true,
                    });
                    await bookings();
                } catch (error) {
                    notice(error);
                } finally {
                    if (arrival.isConnected) arrival.disabled = false;
                }
            };
            a.append(arrival);
        }
        if (user.role === "customer" && r.status === "confirmed") {
            let b = ui("button", "Cancel");
            b.onclick = async () => {
                try {
                    await api("reservations/" + r.id, "DELETE");
                    await bookings();
                    await simulations();
                } catch (e) {
                    notice(e);
                }
            };
            a.append(b);
            let start = ui(
                "button",
                r.csms_bound
                    ? "Request CSMS simulator start"
                    : "Start simulator",
            );
            start.onclick = async () => {
                try {
                    await api(
                        "reservations/" +
                            r.id +
                            (r.csms_bound ? "/csms-start" : "/simulate-start"),
                        "POST",
                    );
                    await simulations();
                } catch (e) {
                    notice(e);
                }
            };
            a.append(start);
        }
        el("reservation-list").append(a);
    }
}
async function simulations() {
    let j = await api("simulations");
    el("simulation-list").replaceChildren();
    if (!j.data.length)
        empty(
            "simulation-list",
            "No simulated sessions yet. Your session history will appear here. / لا توجد جلسات بعد",
        );
    for (let s of j.data) {
        let a = ui(
            "article",
            "SIMULATED · #{id} · {status} · {energy} Wh · {cost} minor units {currency} · No payment processed",
            {
                id: s.id,
                status: { translate: s.status },
                energy: s.energy_wh,
                cost: s.total_minor ?? { translate: "pending" },
                currency: s.currency,
            },
        );
        if (s.csms_linked) a.append(ui("p", "Source controls disclaimer"));
        if (s.csms_linked) {
            for (const [label, value] of [
                ["Start transport · {status}", s.start_command_status],
                ["Stop transport · {status}", s.stop_command_status],
            ])
                if (value)
                    a.append(ui("p", label, { status: { translate: value } }));
            if (
                [s.start_command_status, s.stop_command_status].includes(
                    "source_observed",
                )
            )
                a.append(ui("p", "Source outcome disclaimer"));
            if (
                [s.start_command_status, s.stop_command_status].some((value) =>
                    [
                        "pending",
                        "dispatching",
                        "outcome_unknown",
                        "submitted",
                        "rejected",
                    ].includes(value),
                ) &&
                !["cancelled", "expired_unstarted"].includes(s.status)
            ) {
                const reconcile = ui("button", "Reconcile received evidence");
                reconcile.onclick = async () => {
                    reconcile.disabled = true;
                    try {
                        const result = await api(
                            `charging-sessions/${s.id}/reconcile`,
                            "POST",
                            {},
                        );
                        await simulations();
                        notice(
                            EvLocale.text(
                                result.reconciled
                                    ? "Source evidence reconciled"
                                    : "No new applied source evidence",
                            ),
                        );
                    } catch (error) {
                        notice(error);
                    } finally {
                        if (reconcile.isConnected) reconcile.disabled = false;
                    }
                };
                a.append(reconcile);
            }
        }
        if (s.start_admission_blocked)
            a.append(ui("p", "Start admission blocked"));
        if (user.role === "customer" && s.can_cancel_before_dispatch) {
            const cancel = ui("button", "Cancel undispatched start");
            cancel.onclick = async () => {
                if (!confirm(EvLocale.text("Cancel undispatched consent")))
                    return;
                cancel.disabled = true;
                try {
                    await api("reservations/" + s.reservation_id, "DELETE");
                    await bookings();
                    await simulations();
                } catch (e) {
                    notice(e);
                } finally {
                    if (cancel.isConnected) cancel.disabled = false;
                }
            };
            a.append(cancel);
        }

        if (s.status === "stopped") {
            const button = ui("button", "View simulated invoice"),
                details = document.createElement("div");
            button.onclick = async () => {
                button.disabled = true;
                try {
                    const summary = await api(
                        "finance/sessions/" + s.id + "/invoice",
                        "POST",
                    );
                    if (
                        summary.payment_provider !== "SIMULATOR_ONLY" ||
                        summary.real_payment_processed !== false
                    )
                        throw new Error("Unsupported finance evidence.");
                    const invoice = summary.invoice;
                    details.replaceChildren(
                        ui("p", "Invoice simulation disclaimer"),
                        node("h4", invoice.number),
                    );
                    for (const [label, amount] of [
                        ["Subtotal", invoice.subtotal_minor],
                        ["Tax", invoice.tax_minor],
                        ["Total", invoice.total_minor],
                        ["Simulated collected", summary.paid_minor],
                        ["Simulated refunded", summary.refunded_minor],
                        ["Amount due", summary.amount_due_minor],
                    ]) {
                        details.append(
                            ui(
                                "p",
                                "{label}: {amount} minor units {currency}",
                                {
                                    label: { translate: label },
                                    amount,
                                    currency: invoice.currency,
                                },
                            ),
                        );
                    }
                    details.append(
                        ui(
                            "p",
                            summary.ledger_balanced
                                ? "Ledger balanced"
                                : "Ledger needs reconciliation",
                        ),
                    );
                } catch (error) {
                    notice(error);
                } finally {
                    button.disabled = false;
                }
            };
            a.append(button, details);
        }
        if (s.status === "charging") {
            let stop = ui(
                "button",
                s.csms_linked ? "Request CSMS stop" : "Stop simulation",
            );
            stop.onclick = async () => {
                try {
                    await api(
                        s.csms_linked
                            ? "charging-sessions/" + s.id + "/csms-stop"
                            : "simulations/" + s.id + "/stop",
                        "POST",
                    );
                    await simulations();
                } catch (e) {
                    notice(e);
                }
            };
            a.append(stop);
            if (user.role === "vendor" && !s.csms_linked) {
                let f = document.createElement("form"),
                    i = document.createElement("input");
                i.type = "number";
                i.min = s.energy_wh;
                i.max = 1000000;
                i.required = true;
                EvLocale.attribute(
                    i,
                    "aria-label",
                    "Simulated cumulative energy in Wh",
                );
                let b = ui("button", "Submit simulated meter (Wh)");
                f.append(i, b);
                f.onsubmit = async (e) => {
                    e.preventDefault();
                    try {
                        await api("simulations/" + s.id + "/meter", "PUT", {
                            energy_wh: Number(i.value),
                        });
                        await simulations();
                    } catch (e) {
                        notice(e);
                    }
                };
                a.append(f);
            }
        }
        el("simulation-list").append(a);
    }
}
async function regions() {
    let j = await api("regions");
    el("region-list").replaceChildren();
    for (let r of j)
        el("region-list").append(
            ui(
                "p",
                "{country} · {currency} · {timezone} · commerce disabled",
                r,
            ),
        );
}
async function fleets() {
    let j = await api("fleets");
    el("fleet-list").replaceChildren();
    if (!j.data.length)
        empty(
            "fleet-list",
            "Create a fleet or accept an invitation to get started. / أنشئ أسطولاً أو اقبل دعوة",
        );
    for (let f of j.data) {
        let b = node("button", f.name);
        b.onclick = () => fleetDetail(f.id).catch((e) => notice(e));
        el("fleet-list").append(b);
    }
    let invites = await api("fleet-invitations");
    el("fleet-invitations").replaceChildren();
    for (let i of invites.data) {
        let b = ui("button", "Accept fleet #{id}", { id: i.fleet_id });
        b.onclick = async () => {
            try {
                await api("fleet-invitations/" + i.id + "/accept", "POST");
                await fleets();
            } catch (e) {
                notice(e);
            }
        };
        el("fleet-invitations").append(b);
    }
}
async function fleetDetail(id) {
    let j = await api("fleets/" + id),
        d = el("fleet-detail");
    d.replaceChildren(node("h3", j.fleet.name));
    let owner = j.fleet.owner_id === user.id;
    if (owner) {
        for (let m of j.members) {
            let row = ui("p", "Member #{id} · {status}", {
                id: m.user_id,
                status: { translate: m.status },
            });
            if (m.status !== "revoked") {
                let b = ui("button", "Revoke");
                b.onclick = async () => {
                    try {
                        await api(
                            "fleets/" + id + "/members/" + m.id,
                            "DELETE",
                        );
                        await fleetDetail(id);
                    } catch (e) {
                        notice(e);
                    }
                };
                row.append(b);
            }
            d.append(row);
        }
        let f = document.createElement("form"),
            i = document.createElement("input");
        i.type = "email";
        EvLocale.attribute(i, "placeholder", "Driver email");
        EvLocale.attribute(i, "aria-label", "Driver email");
        i.required = true;
        f.append(i, ui("button", "Invite existing account"));
        f.onsubmit = async (e) => {
            e.preventDefault();
            try {
                notice(
                    (
                        await api("fleets/" + id + "/invitations", "POST", {
                            email: i.value,
                        })
                    ).message,
                );
                await fleetDetail(id);
            } catch (e) {
                notice(e);
            }
        };
        d.append(f);
        let cf = document.createElement("form"),
            code = document.createElement("input"),
            name = document.createElement("input");
        EvLocale.attribute(code, "placeholder", "Cost center code");
        EvLocale.attribute(code, "aria-label", "Cost center code");
        EvLocale.attribute(name, "placeholder", "Cost center name");
        EvLocale.attribute(name, "aria-label", "Cost center name");
        code.required = name.required = true;
        cf.append(code, name, ui("button", "Add cost center"));
        cf.onsubmit = async (e) => {
            e.preventDefault();
            try {
                await api("fleets/" + id + "/cost-centers", "POST", {
                    code: code.value,
                    name: name.value,
                });
                await fleetDetail(id);
            } catch (e) {
                notice(e);
            }
        };
        d.append(cf);
    }
    for (let c of j.cost_centers)
        d.append(
            ui("p", "Cost center #{id} · {code} · {name}", {
                id: c.id,
                code: c.code,
                name: c.name,
            }),
        );
    if (j.cost_centers.length) {
        const own = await api("vehicles");
        if (own.data.length) {
            const form = document.createElement("form");
            form.id = "fleet-assign";
            const vehicleLabel = ui("label", "Your vehicle / مركبتك"),
                vehicle = document.createElement("select");
            vehicle.name = "vehicle_id";
            for (const v of own.data) vehicle.append(new Option(v.name, v.id));
            vehicleLabel.append(vehicle);
            const centerLabel = ui("label", "Cost center / مركز التكلفة"),
                center = document.createElement("select");
            center.name = "cost_center_id";
            for (const c of j.cost_centers)
                center.append(new Option(c.name, c.id));
            centerLabel.append(center);
            form.append(
                vehicleLabel,
                centerLabel,
                ui("button", "Assign vehicle"),
            );
            form.onsubmit = async (event) => {
                event.preventDefault();
                try {
                    await api(
                        "fleets/" + id + "/vehicles",
                        "POST",
                        Object.fromEntries(new FormData(form)),
                    );
                    await fleetDetail(id);
                } catch (error) {
                    notice(error);
                }
            };
            d.append(form);
        }
    }
    for (let v of j.vehicles)
        d.append(
            ui("p", "Vehicle #{vehicle} · cost center #{center}", {
                vehicle: v.vehicle_id,
                center: v.cost_center_id,
            }),
        );
}
el("fleet-create").onsubmit = async (e) => {
    e.preventDefault();
    try {
        await api("fleets", "POST", Object.fromEntries(new FormData(e.target)));
        await fleets();
    } catch (e) {
        notice(e);
    }
};
el("region-config").onsubmit = async (e) => {
    e.preventDefault();
    try {
        let v = Object.fromEntries(new FormData(e.target)),
            country = v.country;
        delete v.country;
        if (!v.tax_bps) v.tax_bps = null;
        await api("regions/" + encodeURIComponent(country), "PUT", v);
        await regions();
        notice("Configuration saved. Commercial activation remains disabled.");
    } catch (e) {
        notice(e);
    }
};
el("account").onsubmit = async (e) => {
    e.preventDefault();
    if (identityBusy) return;
    const mode = e.submitter.value,
        values = Object.fromEntries(new FormData(e.target));
    const epoch = clearIdentity();
    setIdentityBusy(true);
    try {
        csrf = (await api("csrf")).token;
        await api(mode, "POST", values);
        pendingLogout(false);
        el("logout-retry").hidden = true;
        e.target.reset();
        csrf = (await api("csrf")).token;
        await identity();
        if (epoch !== client.sessionEpoch) return;
        await stations();
        if (epoch === client.sessionEpoch) notice("Signed in.");
    } catch (error) {
        notice(error);
    } finally {
        if (epoch === client.sessionEpoch) setIdentityBusy(false);
    }
};
async function signOut() {
    if (identityBusy) return;
    pendingLogout(true);
    const epoch = clearIdentity();
    setIdentityBusy(true);
    el("logout-retry").hidden = true;
    try {
        csrf = (await api("csrf")).token;
        try {
            await api("logout", "POST");
        } catch (error) {
            if (error.status !== 401) throw error;
        }
        pendingLogout(false);
        notice("Signed out.");
        stations().catch(notice);
    } catch (error) {
        if (epoch !== client.sessionEpoch) return;
        el("logout-retry").hidden = false;
        notice("Sign-out could not be confirmed. Reconnect and retry.");
    } finally {
        if (epoch === client.sessionEpoch) setIdentityBusy(false);
    }
}
el("logout").onclick = el("logout-retry").onclick = signOut;
const nearbyInputs = ["latitude", "longitude", "radius_km"].map((name) =>
    el("filters").elements.namedItem(name),
);
for (const input of nearbyInputs) {
    input.addEventListener("input", () => {
        const any = nearbyInputs.some((field) => field.value !== "");
        for (const field of nearbyInputs) field.required = any;
    });
}
el("filters").onsubmit = (e) => {
    e.preventDefault();
    favoriteMode = false;
    renderCatalogMode();
    let q = new URLSearchParams();
    for (let [k, v] of new FormData(e.target)) if (v) q.set(k, v);
    stations("?" + q).catch((e) => notice(e));
};
el("station-form").onsubmit = async (e) => {
    e.preventDefault();
    try {
        await api(
            "stations",
            "POST",
            Object.fromEntries(new FormData(e.target)),
        );
        await stations();
        notice("Station registered.");
    } catch (e) {
        notice(e);
    }
};
const vendorWorkspace = createVendorWorkspace({
    root: el("vendor-operations"),
    api,
    ui,
    actor: () => user,
    csrf: () => csrf,
    notice,
    expired: clearIdentity,
});
const roamingWorkspace = createRoamingWorkspace({
    publicRoot: el("roaming-discovery"),
    privateRoot: el("roaming-operations"),
    api,
    ui,
    actor: () => user,
    notice,
});
const invoiceHistory = createInvoiceHistory({
    root: el("invoice-history"),
    api,
    ui,
    actor: () => user,
});
const commercialWorkspace = createCommercialWorkspace({
    root: el("commercial-finance"),
    api,
    ui,
});
const tariffWorkspace = createTariffWorkspace({
    root: el("tariff-operations"),
    api,
    ui,
    actor: () => user,
});
(async () => {
    const publicRoaming = roamingWorkspace.catalog();
    if (pendingLogout()) await signOut();
    else await identity();
    await stations();
    await regions();
    await publicRoaming;
})().catch((e) => notice(e));

el("forgot-password").onsubmit = async (e) => {
    e.preventDefault();
    try {
        notice(
            (
                await api(
                    "forgot-password",
                    "POST",
                    Object.fromEntries(new FormData(e.target)),
                )
            ).message,
        );
    } catch (e) {
        notice(e);
    }
};

async function garage() {
    const j = await api("vehicles");
    el("vehicle-list").replaceChildren();
    for (const v of j.data)
        el("vehicle-list").append(
            node("p", "#" + v.id + " · " + v.name + " · " + v.connector_type),
        );
    el("profile-form").elements.name.value = user.name;
    el("profile-form").elements.locale.value = user.locale;
}
el("vehicle-create").onsubmit = async (e) => {
    e.preventDefault();
    try {
        await api(
            "vehicles",
            "POST",
            Object.fromEntries(new FormData(e.target)),
        );
        await garage();
    } catch (e) {
        notice(e);
    }
};
el("profile-form").onsubmit = async (e) => {
    e.preventDefault();
    try {
        user = await api(
            "profile",
            "PATCH",
            Object.fromEntries(new FormData(e.target)),
        );
        EvLocale.set(user.locale);
        notice("Profile updated.");
        await identity();
    } catch (e) {
        notice(e);
    }
};
async function support() {
    const j = await api("support");
    el("ticket-list").replaceChildren();
    for (const t of j.data) {
        const b = ui("button", "#{id} · {subject} · {status}", {
            id: t.id,
            subject: t.subject,
            status: { translate: t.status },
        });
        b.onclick = () => ticketDetail(t.id).catch((e) => notice(e));
        el("ticket-list").append(b);
    }
    const n = await api("notifications");
    el("notifications").replaceChildren();
    for (const m of n.data) {
        const row = ui("p", "{kind} · ticket #{id}", {
            kind: { translate: m.kind },
            id: m.support_ticket_id,
        });
        if (!m.read_at) {
            const b = ui("button", "Mark read");
            b.onclick = async () => {
                try {
                    await api("notifications/" + m.id + "/read", "POST");
                    await support();
                } catch (e) {
                    notice(e);
                }
            };
            row.append(b);
        }
        el("notifications").append(row);
    }
}
async function ticketDetail(id) {
    const j = await api("support/" + id),
        d = el("ticket-detail");
    d.replaceChildren(
        ui("h3", "{subject} · {status}", {
            subject: j.ticket.subject,
            status: { translate: j.ticket.status },
        }),
    );
    for (const m of j.messages.data)
        d.append(node("p", (m.internal ? "INTERNAL · " : "") + m.body));
    if (j.ticket.status !== "closed") {
        const f = document.createElement("form"),
            body = document.createElement("textarea");
        body.required = true;
        body.maxLength = 5000;
        EvLocale.attribute(body, "aria-label", "Reply message");
        f.append(body, ui("button", "Reply"));
        if (user.role === "admin") {
            const label = ui("label", "Internal note"),
                check = document.createElement("input");
            check.type = "checkbox";
            check.name = "internal";
            label.append(check);
            f.append(label);
        }
        f.onsubmit = async (e) => {
            e.preventDefault();
            try {
                await api("support/" + id + "/messages", "POST", {
                    body: body.value,
                    internal: !!f.elements.internal?.checked,
                });
                await support();
                await ticketDetail(id);
            } catch (e) {
                notice(e);
            }
        };
        d.append(f);
    }
    if (user.role === "admin") {
        const f = document.createElement("form"),
            select = document.createElement("select");
        EvLocale.attribute(select, "aria-label", "Ticket status");
        for (const value of ["open", "in_progress", "resolved", "closed"]) {
            const o = ui("option", value);
            o.value = value;
            select.append(o);
        }
        const reason = document.createElement("input");
        reason.required = true;
        reason.maxLength = 1000;
        EvLocale.attribute(
            reason,
            "placeholder",
            "Reason shared with requester",
        );
        EvLocale.attribute(reason, "aria-label", "Reason for status change");
        f.append(select, reason, ui("button", "Update ticket status"));
        f.onsubmit = async (e) => {
            e.preventDefault();
            try {
                await api("support/" + id + "/status", "PATCH", {
                    status: select.value,
                    reason: reason.value,
                });
                await support();
                await ticketDetail(id);
            } catch (e) {
                notice(e);
            }
        };
        d.append(f);
    }
}
el("support-create").onsubmit = async (e) => {
    e.preventDefault();
    try {
        const t = await api(
            "support",
            "POST",
            Object.fromEntries(new FormData(e.target)),
        );
        await support();
        await ticketDetail(t.id);
    } catch (e) {
        notice(e);
    }
};

el("verify-email").onclick = async () => {
    el("verify-email").disabled = true;
    try {
        notice((await api("email/verification-notification", "POST")).message);
    } catch (e) {
        notice(e);
    } finally {
        el("verify-email").disabled = false;
    }
};
el("refresh-identity").onclick = () => identity().catch((e) => notice(e));
if (new URLSearchParams(location.search).get("verification") === "signin")
    notice(
        "Sign in to the account that received the email, then reopen its verification link.",
    );

el("favorite-catalog").onclick = () => {
    if (user?.role !== "customer") return;
    if (!favoriteMode) previousCatalogQuery = stationQuery;
    favoriteMode = !favoriteMode;
    renderCatalogMode();
    stations(favoriteMode ? "" : previousCatalogQuery).catch(notice);
};
el("refresh-catalog").onclick = () => stations(stationQuery);
function connectionStatus() {
    el("offline-banner").hidden = navigator.onLine;
}
window.addEventListener("offline", connectionStatus);
window.addEventListener("online", connectionStatus);
connectionStatus();

async function adminOverview() {
    const data = await api("admin/overview"),
        container = el("overview");
    container.replaceChildren();
    const metrics = node("div", "");
    metrics.className = "metric-grid";
    for (const [key, label] of [
        ["users", "Accounts / الحسابات"],
        ["stations", "Station records / المحطات"],
        ["reservations", "Reservations / الحجوزات"],
        ["simulations", "Simulated sessions / الجلسات التجريبية"],
    ]) {
        const card = node("article", "");
        card.append(ui("span", label), node("strong", String(data[key])));
        metrics.append(card);
    }
    const table = document.createElement("table");
    table.className = "audit-table";
    table.append(
        ui("caption", "Audit history / سجل التدقيق — latest 50 events"),
    );
    const head = document.createElement("thead"),
        headers = document.createElement("tr");
    for (const label of ["Event", "Actor", "Record", "Time (UTC)"])
        headers.append(ui("th", label));
    head.append(headers);
    table.append(head);
    const body = document.createElement("tbody");
    const tools = node("div", "");
    tools.className = "audit-tools";
    const label = ui("label", "Filter latest events / تصفية أحدث الأحداث");
    const search = document.createElement("input");
    search.id = "audit-search";
    search.type = "search";
    search.maxLength = 100;
    label.append(search);
    const count = node("p", "");
    count.id = "audit-count";
    count.setAttribute("role", "status");
    const exportButton = ui("button", "Export filtered CSV / تصدير النتائج");
    exportButton.type = "button";
    exportButton.id = "audit-export";
    let visible = [];
    const fields = ["action", "actor_id", "subject", "created_at"];
    function render() {
        const term = search.value.trim().toLocaleLowerCase();
        visible = data.audit_events.filter((event) =>
            fields.some((key) =>
                String(event[key]).toLocaleLowerCase().includes(term),
            ),
        );
        body.replaceChildren();
        for (const event of visible) {
            const row = document.createElement("tr");
            for (const key of fields)
                row.append(node("td", String(event[key])));
            body.append(row);
        }
        EvLocale.bind(
            count,
            "{visible} / {total} latest events / أحدث الأحداث",
            { visible: visible.length, total: data.audit_events.length },
        );
        exportButton.disabled = visible.length === 0;
    }
    search.oninput = render;
    exportButton.onclick = () => {
        const cell = (value) => {
            let text = String(value ?? "");
            if (/^[\s]*[=+\-@]/.test(text)) text = "'" + text;
            return '"' + text.replaceAll('"', '""') + '"';
        };
        const csv = [
            fields,
            ...visible.map((event) => fields.map((key) => event[key])),
        ]
            .map((row) => row.map(cell).join(","))
            .join("\r\n");
        const url = URL.createObjectURL(
            new Blob(["\ufeff", csv], { type: "text/csv;charset=utf-8" }),
        );
        const link = document.createElement("a");
        link.href = url;
        link.download = "creabiz-audit.csv";
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    tools.append(label, exportButton, count);
    render();
    table.append(body);
    const scroll = node("div", "");
    scroll.className = "table-scroll";
    scroll.tabIndex = 0;
    scroll.setAttribute("role", "region");
    EvLocale.attribute(scroll, "aria-label", "Audit history");
    scroll.append(table);
    container.append(metrics, tools, scroll);
}

async function openOperationalCase(command, parent, actor, signal) {
    parent.querySelector("[data-operational-case]")?.remove();
    const panel = document.createElement("section");
    panel.dataset.operationalCase = "";
    parent.append(panel);
    const live = () => user === actor && panel.isConnected && !signal.aborted;
    const path = `charging-operations/${command.id}`;
    panel.append(
        ui("h4", "Operational recovery"),
        ui("p", "Operational recovery disclaimer"),
    );
    const state = await api(path + "/case", "GET", undefined, signal);
    if (!live()) return;
    const refresh = () => openOperationalCase(command, parent, actor, signal);
    const refreshButton = ui("button", "Refresh");
    refreshButton.type = "button";
    refreshButton.onclick = () => refresh().catch(notice);
    panel.append(refreshButton);
    if (state.case)
        panel.append(
            ui("p", "Recovery case: {state}", {
                state: { translate: "recovery." + state.case.state },
            }),
        );
    const form = document.createElement("form"),
        label = ui("label", "Operator recovery note"),
        note = document.createElement("textarea");
    note.required = true;
    note.minLength = 10;
    note.maxLength = 2000;
    label.append(note);
    form.append(label);
    let choice;
    if (state.case) {
        choice = document.createElement("select");
        for (const value of [
            "investigating",
            "awaiting_source",
            ...(state.source_observed ? ["resolved"] : []),
        ]) {
            const option = new Option("", value);
            EvLocale.bind(option, "recovery." + value);
            choice.append(option);
        }
        form.append(choice);
    }
    const save = ui(
        "button",
        state.case ? "Save recovery review" : "Open recovery case",
    );
    save.type = "submit";
    form.append(save);
    panel.append(form);
    form.onsubmit = async (event) => {
        event.preventDefault();
        save.disabled = true;
        try {
            await api(
                path + "/case",
                state.case ? "PATCH" : "POST",
                {
                    note: note.value,
                    ...(state.case ? { state: choice.value } : {}),
                },
                signal,
            );
            if (live()) await refresh();
        } catch (error) {
            if (live()) {
                panel.replaceChildren(ui("p", "Recovery outcome unconfirmed"));
                panel.append(refreshButton);
                notice(error);
            }
        } finally {
            if (save.isConnected) save.disabled = false;
        }
    };
    if (state.case && state.can_inspect) {
        const inspect = ui("button", "Inspect authenticated simulator source");
        inspect.type = "button";
        inspect.onclick = async () => {
            inspect.disabled = true;
            try {
                await api(path + "/inspect-source", "POST", {}, signal);
                if (live()) await refresh();
            } catch (error) {
                if (live()) notice(error);
            } finally {
                if (inspect.isConnected) inspect.disabled = false;
            }
        };
        panel.append(inspect);
    }
    const history = document.createElement("div");
    panel.append(history);
    async function events(page) {
        const data =
            page === 1
                ? state
                : await api(
                      path + "/case?page=" + page,
                      "GET",
                      undefined,
                      signal,
                  );
        if (!live()) return;
        history.replaceChildren();
        for (const event of data.events?.data || []) {
            const row = document.createElement("article");
            row.append(
                document.createTextNode(event.action + " · " + event.note),
            );
            history.append(row);
        }
        if (data.events?.current_page < data.events?.last_page) {
            const next = ui("button", "Next");
            next.type = "button";
            next.onclick = () => events(page + 1).catch(notice);
            history.append(next);
        }
    }
    await events(1);
}
async function commandHistory(page = 1) {
    if (!["vendor", "admin"].includes(user?.role)) return;
    commandRequest?.abort();
    const request = new AbortController(),
        actor = user;
    commandRequest = request;
    el("commands-refresh").disabled = true;
    el("command-list").setAttribute("aria-busy", "true");
    EvLocale.bind(el("commands-state"), "Loading command history…");
    try {
        const result = await api(
            "charger-commands?page=" + page,
            "GET",
            undefined,
            request.signal,
        );
        if (request.signal.aborted || user !== actor) return;
        const list = el("command-list"),
            pager = el("command-pagination");
        list.replaceChildren();
        pager.replaceChildren();
        commandPage = result.current_page;
        for (const row of result.data) {
            const card = document.createElement("article");
            card.dataset.commandId = row.id;
            card.append(
                ui("h3", "Command #{id}", { id: row.id }),
                ui("p", "Station #{id}", { id: row.station_id }),
                ui("p", "Command action: {action}", {
                    action: { translate: row.action },
                }),
                ui("p", "Transport status: {status}", {
                    status: { translate: row.status },
                }),
            );
            if (
                [
                    "dispatching",
                    "submitted",
                    "outcome_unknown",
                    "rejected",
                    "source_observed",
                ].includes(row.status)
            ) {
                const recovery = ui("button", "Operational recovery");
                recovery.type = "button";
                recovery.onclick = () =>
                    openOperationalCase(row, card, actor, request.signal).catch(
                        (e) => {
                            if (user === actor) notice(e);
                        },
                    );
                card.append(recovery);
            }
            list.append(card);
        }
        if (!result.data.length) empty("command-list", "No recorded commands.");
        EvLocale.bind(
            el("commands-state"),
            "Page {page} of {last} · {total} commands",
            {
                page: result.current_page,
                last: result.last_page,
                total: result.total,
            },
        );
        for (const [label, target, enabled] of [
            ["Previous", result.current_page - 1, result.current_page > 1],
            [
                "Next",
                result.current_page + 1,
                result.current_page < result.last_page,
            ],
        ]) {
            const button = ui("button", label);
            button.disabled = !enabled;
            button.onclick = () => commandHistory(target);
            pager.append(button);
        }
    } catch (error) {
        if (!request.signal.aborted && user === actor)
            EvLocale.bind(el("commands-state"), errorMessage(error));
    } finally {
        if (commandRequest === request) {
            el("commands-refresh").disabled = false;
            el("command-list").removeAttribute("aria-busy");
        }
    }
}
el("commands-refresh").onclick = () => commandHistory(commandPage);

el("sessions-refresh").onclick = async function () {
    this.disabled = true;
    try {
        await simulations();
    } catch (error) {
        notice(error);
    } finally {
        this.disabled = false;
    }
};
