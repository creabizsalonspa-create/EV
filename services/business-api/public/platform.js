let commandRequest = null,
    commandPage = 1;
let user = null,
    csrf = document.querySelector("meta[name=csrf-token]").content;
const el = (id) => document.getElementById(id),
    notice = (value) =>
        EvLocale.bind(
            el("notice"),
            value instanceof Error ? errorMessage(value) : value,
        );
const client = new EvApi(csrf);
async function api(path, method = "GET", data, signal) {
    client.token = csrf;
    try {
        return await client.request(path, method, data, signal);
    } catch (error) {
        if ([401, 419].includes(error.status) && user) {
            user = null;
            renderIdentity();
            clearPrivateViews();
        }
        throw error;
    }
}
function clearPrivateViews() {
    vendorWorkspace.clear();
    roamingWorkspace.clear();
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
    el("ticket-detail").replaceChildren();
    el("fleet-detail").replaceChildren();
    el("overview").replaceChildren();
    try {
        user = await api("me");
    } catch (error) {
        user = null;
        if (error.status !== 401) notice(error);
    }
    renderIdentity();
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
    if (user?.role === "admin") await adminOverview();
    if (["vendor", "admin"].includes(user?.role)) await commandHistory();
}
let stationRequest = null,
    stationQuery = "";
async function stations(query = "") {
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
        j = await api("stations" + query, "GET", undefined, request.signal);
    } catch (error) {
        if (request.signal.aborted) return;
        EvLocale.bind(el("catalog-state"), errorMessage(error));
        el("stations").removeAttribute("aria-busy");
        return;
    }
    if (request.signal.aborted) return;
    el("stations").removeAttribute("aria-busy");
    stationQuery = query;
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
    el("stations").replaceChildren();
    if (!j.data.length)
        el("stations").append(
            ui("p", "No stations match. No live network data is connected."),
        );
    for (let s of j.data) {
        let a = node("article", "");
        a.className = "station-card";
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
                s.connector_type + " · " + s.power_kw + " kW · " + s.country,
            ),
        );
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
        if (s.reservable && user?.role === "customer") {
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
            f.onsubmit = async (e) => {
                e.preventDefault();
                b.disabled = true;
                try {
                    await api("reservations", "POST", {
                        station_id: s.id,
                        starts_at:
                            i.value.length === 16
                                ? i.value + ":00Z"
                                : i.value + "Z",
                        idempotency_key: crypto.randomUUID(),
                    });
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
            a.append(f);
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
        if (user.role === "customer" && r.status === "confirmed") {
            let b = ui("button", "Cancel");
            b.onclick = async () => {
                try {
                    await api("reservations/" + r.id, "DELETE");
                    await bookings();
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
    try {
        await api(
            e.submitter.value,
            "POST",
            Object.fromEntries(new FormData(e.target)),
        );
        e.target.reset();
        csrf = (await api("csrf")).token;
        await identity();
        await stations();
        notice("Signed in.");
    } catch (e) {
        notice(e);
    }
};
el("logout").onclick = async () => {
    try {
        await api("logout", "POST");
        clearPrivateViews();
        csrf = (await api("csrf")).token;
        await identity();
        await stations();
    } catch (error) {
        notice(error);
    }
};
el("filters").onsubmit = (e) => {
    e.preventDefault();
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
    expired: () => {
        user = null;
        renderIdentity();
        clearPrivateViews();
    },
});
const roamingWorkspace = createRoamingWorkspace({
    publicRoot: el("roaming-discovery"),
    privateRoot: el("roaming-operations"),
    api,
    ui,
    actor: () => user,
    notice,
});
(async () => {
    await roamingWorkspace.catalog();
    await identity();
    await stations();
    await regions();
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
