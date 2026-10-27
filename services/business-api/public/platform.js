let user = null,
    csrf = document.querySelector("meta[name=csrf-token]").content;
const el = (id) => document.getElementById(id),
    notice = (s) => (el("notice").textContent = s);
const client = new EvApi(csrf);
async function api(path, method = "GET", data, signal) {
    client.token = csrf;
    try {
        return await client.request(path, method, data, signal);
    } catch (error) {
        if (error.status === 401 && user) {
            user = null;
            renderIdentity();
            clearPrivateViews();
        }
        throw error;
    }
}
function clearPrivateViews() {
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
    const state = node("p", message);
    state.className = "empty-state";
    el(container).append(state);
}
function renderIdentity() {
    el("account").hidden = !!user;
    el("forgot-password").hidden = !!user;
    el("signed").hidden = !user;
    el("bookings").hidden = !user;
    el("vendor").hidden = user?.role !== "vendor";
    el("who").textContent = user ? user.name + " · " + user.role : "";
    el("email-status").textContent = user
        ? user.email_verified_at
            ? "Email verified / البريد مؤكد"
            : "Email not verified / البريد غير مؤكد"
        : "";
    el("verify-email").hidden = !user || !!user.email_verified_at;
    for (const id of ["simulations", "fleets", "garage", "support"])
        el(id).hidden = !user;
    el("admin").hidden = el("region-config").hidden = user?.role !== "admin";
    document.querySelectorAll("[data-access]").forEach((n) => {
        n.hidden =
            n.dataset.access === "auth"
                ? !user
                : user?.role !== n.dataset.access;
    });
    el("workspace-label").textContent = user
        ? user.role === "admin"
            ? "Operations workspace"
            : user.role === "vendor"
              ? "Operator workspace"
              : "Driver workspace"
        : "Your charging companion";
}

function node(tag, text) {
    let e = document.createElement(tag);
    e.textContent = text;
    return e;
}
async function identity() {
    el("ticket-detail").replaceChildren();
    el("fleet-detail").replaceChildren();
    el("overview").replaceChildren();
    try {
        user = await api("me");
    } catch (error) {
        user = null;
        if (error.status !== 401) notice(error.message);
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
    if (user?.role === "admin") await adminOverview();
}
let stationRequest = null,
    stationQuery = "";
async function stations(query = "") {
    stationRequest?.abort();
    const request = new AbortController();
    stationRequest = request;
    el("stations").setAttribute("aria-busy", "true");
    el("catalog-state").textContent = "Loading stations… / جارٍ تحميل المحطات";
    let j;
    try {
        j = await api("stations" + query, "GET", undefined, request.signal);
    } catch (error) {
        if (request.signal.aborted) return;
        el("catalog-state").textContent = error.message;
        el("stations").removeAttribute("aria-busy");
        return;
    }
    if (request.signal.aborted) return;
    el("stations").removeAttribute("aria-busy");
    stationQuery = query;
    el("catalog-state").textContent =
        j.total + " stations · availability is unverified / الحالة غير مؤكدة";
    el("catalog-pagination").replaceChildren();
    for (const [label, url] of [
        ["Previous / السابق", j.prev_page_url],
        ["Next / التالي", j.next_page_url],
    ]) {
        const button = node("button", label);
        button.disabled = !url;
        button.onclick = () => stations(new URL(url, location.origin).search);
        el("catalog-pagination").append(button);
    }
    el("catalog-pagination").append(
        node("span", "Page " + j.current_page + " / " + j.last_page),
    );
    el("stations").replaceChildren();
    if (!j.data.length)
        el("stations").append(
            node("p", "No stations match. No live network data is connected."),
        );
    for (let s of j.data) {
        let a = node("article", "");
        a.className = "station-card";
        a.append(
            node("h3", s.name),
            Object.assign(node("span", s.evidence + " · " + s.availability), {
                className: "badge",
            }),
            node(
                "p",
                s.connector_type + " · " + s.power_kw + " kW · " + s.country,
            ),
        );
        const details = document.createElement("details");
        details.append(
            node("summary", "Location & access / الموقع والوصول"),
            node("p", s.latitude + ", " + s.longitude + " · " + s.currency),
            node(
                "p",
                "Live connector status, commercial tariffs and navigation are not connected.",
            ),
        );
        a.append(details);
        if (s.reservable && user?.role === "customer") {
            let f = document.createElement("form"),
                l = node("label", "UTC start (00 or 30 minutes)"),
                i = document.createElement("input");
            i.type = "datetime-local";
            i.step = 1800;
            i.required = true;
            l.append(i);
            f.append(l);
            let b = node("button", "Reserve simulator slot");
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
                    notice(e.message);
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
        let a = node(
            "article",
            "#" +
                r.id +
                " · station " +
                r.station_id +
                " · " +
                r.starts_at +
                " UTC · " +
                r.status,
        );
        if (user.role === "customer" && r.status === "confirmed") {
            let b = node("button", "Cancel");
            b.onclick = async () => {
                try {
                    await api("reservations/" + r.id, "DELETE");
                    await bookings();
                } catch (e) {
                    notice(e.message);
                }
            };
            a.append(b);
            let start = node("button", "Start simulator");
            start.onclick = async () => {
                try {
                    await api(
                        "reservations/" + r.id + "/simulate-start",
                        "POST",
                    );
                    await simulations();
                } catch (e) {
                    notice(e.message);
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
        let a = node(
            "article",
            "SIMULATED · #" +
                s.id +
                " · " +
                s.status +
                " · " +
                s.energy_wh +
                " Wh · " +
                (s.total_minor ?? "pending") +
                " minor units " +
                s.currency +
                " · No payment processed",
        );
        if (s.status === "charging") {
            let stop = node("button", "Stop simulation");
            stop.onclick = async () => {
                try {
                    await api("simulations/" + s.id + "/stop", "POST");
                    await simulations();
                } catch (e) {
                    notice(e.message);
                }
            };
            a.append(stop);
            if (user.role === "vendor") {
                let f = document.createElement("form"),
                    i = document.createElement("input");
                i.type = "number";
                i.min = s.energy_wh;
                i.max = 1000000;
                i.required = true;
                i.setAttribute(
                    "aria-label",
                    "Simulated cumulative energy in Wh",
                );
                let b = node("button", "Submit simulated meter (Wh)");
                f.append(i, b);
                f.onsubmit = async (e) => {
                    e.preventDefault();
                    try {
                        await api("simulations/" + s.id + "/meter", "PUT", {
                            energy_wh: Number(i.value),
                        });
                        await simulations();
                    } catch (e) {
                        notice(e.message);
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
            node(
                "p",
                r.country +
                    " · " +
                    r.currency +
                    " · " +
                    r.timezone +
                    " · commerce disabled",
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
        b.onclick = () => fleetDetail(f.id).catch((e) => notice(e.message));
        el("fleet-list").append(b);
    }
    let invites = await api("fleet-invitations");
    el("fleet-invitations").replaceChildren();
    for (let i of invites.data) {
        let b = node("button", "Accept fleet #" + i.fleet_id);
        b.onclick = async () => {
            try {
                await api("fleet-invitations/" + i.id + "/accept", "POST");
                await fleets();
            } catch (e) {
                notice(e.message);
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
            let row = node("p", "Member #" + m.user_id + " · " + m.status);
            if (m.status !== "revoked") {
                let b = node("button", "Revoke");
                b.onclick = async () => {
                    try {
                        await api(
                            "fleets/" + id + "/members/" + m.id,
                            "DELETE",
                        );
                        await fleetDetail(id);
                    } catch (e) {
                        notice(e.message);
                    }
                };
                row.append(b);
            }
            d.append(row);
        }
        let f = document.createElement("form"),
            i = document.createElement("input");
        i.type = "email";
        i.placeholder = "Driver email";
        i.setAttribute("aria-label", "Driver email");
        i.required = true;
        f.append(i, node("button", "Invite existing account"));
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
                notice(e.message);
            }
        };
        d.append(f);
        let cf = document.createElement("form"),
            code = document.createElement("input"),
            name = document.createElement("input");
        code.placeholder = "Cost center code";
        code.setAttribute("aria-label", "Cost center code");
        name.placeholder = "Cost center name";
        name.setAttribute("aria-label", "Cost center name");
        code.required = name.required = true;
        cf.append(code, name, node("button", "Add cost center"));
        cf.onsubmit = async (e) => {
            e.preventDefault();
            try {
                await api("fleets/" + id + "/cost-centers", "POST", {
                    code: code.value,
                    name: name.value,
                });
                await fleetDetail(id);
            } catch (e) {
                notice(e.message);
            }
        };
        d.append(cf);
    }
    for (let c of j.cost_centers)
        d.append(
            node("p", "Cost center #" + c.id + " · " + c.code + " · " + c.name),
        );
    if (j.cost_centers.length) {
        const own = await api("vehicles");
        if (own.data.length) {
            const form = document.createElement("form");
            form.id = "fleet-assign";
            const vehicleLabel = node("label", "Your vehicle / مركبتك"),
                vehicle = document.createElement("select");
            vehicle.name = "vehicle_id";
            for (const v of own.data) vehicle.append(new Option(v.name, v.id));
            vehicleLabel.append(vehicle);
            const centerLabel = node("label", "Cost center / مركز التكلفة"),
                center = document.createElement("select");
            center.name = "cost_center_id";
            for (const c of j.cost_centers)
                center.append(new Option(c.name, c.id));
            centerLabel.append(center);
            form.append(
                vehicleLabel,
                centerLabel,
                node("button", "Assign vehicle"),
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
                    notice(error.message);
                }
            };
            d.append(form);
        }
    }
    for (let v of j.vehicles)
        d.append(
            node(
                "p",
                "Vehicle #" +
                    v.vehicle_id +
                    " · cost center #" +
                    v.cost_center_id,
            ),
        );
}
el("fleet-create").onsubmit = async (e) => {
    e.preventDefault();
    try {
        await api("fleets", "POST", Object.fromEntries(new FormData(e.target)));
        await fleets();
    } catch (e) {
        notice(e.message);
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
        notice(e.message);
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
        notice(e.message);
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
        notice(error.message);
    }
};
el("filters").onsubmit = (e) => {
    e.preventDefault();
    let q = new URLSearchParams();
    for (let [k, v] of new FormData(e.target)) if (v) q.set(k, v);
    stations("?" + q).catch((e) => notice(e.message));
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
        notice(e.message);
    }
};
el("language").onclick = () => {
    let ar = document.documentElement.lang !== "ar";
    document.documentElement.lang = ar ? "ar" : "en";
    document.documentElement.dir = ar ? "rtl" : "ltr";
    document
        .querySelectorAll("[data-en]")
        .forEach((n) => (n.textContent = n.dataset[ar ? "ar" : "en"]));
};
(async () => {
    await identity();
    await stations();
    await regions();
})().catch((e) => notice(e.message));

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
        notice(e.message);
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
        notice(e.message);
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
        notice("Profile updated.");
        await identity();
    } catch (e) {
        notice(e.message);
    }
};
async function support() {
    const j = await api("support");
    el("ticket-list").replaceChildren();
    for (const t of j.data) {
        const b = node(
            "button",
            "#" + t.id + " · " + t.subject + " · " + t.status,
        );
        b.onclick = () => ticketDetail(t.id).catch((e) => notice(e.message));
        el("ticket-list").append(b);
    }
    const n = await api("notifications");
    el("notifications").replaceChildren();
    for (const m of n.data) {
        const row = node("p", m.kind + " · ticket #" + m.support_ticket_id);
        if (!m.read_at) {
            const b = node("button", "Mark read");
            b.onclick = async () => {
                try {
                    await api("notifications/" + m.id + "/read", "POST");
                    await support();
                } catch (e) {
                    notice(e.message);
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
    d.replaceChildren(node("h3", j.ticket.subject + " · " + j.ticket.status));
    for (const m of j.messages.data)
        d.append(node("p", (m.internal ? "INTERNAL · " : "") + m.body));
    if (j.ticket.status !== "closed") {
        const f = document.createElement("form"),
            body = document.createElement("textarea");
        body.required = true;
        body.maxLength = 5000;
        body.setAttribute("aria-label", "Reply message");
        f.append(body, node("button", "Reply"));
        if (user.role === "admin") {
            const label = node("label", "Internal note"),
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
                notice(e.message);
            }
        };
        d.append(f);
    }
    if (user.role === "admin") {
        const f = document.createElement("form"),
            select = document.createElement("select");
        select.setAttribute("aria-label", "Ticket status");
        for (const value of ["open", "in_progress", "resolved", "closed"]) {
            const o = node("option", value);
            o.value = value;
            select.append(o);
        }
        const reason = document.createElement("input");
        reason.required = true;
        reason.maxLength = 1000;
        reason.placeholder = "Reason shared with requester";
        reason.setAttribute("aria-label", "Reason for status change");
        f.append(select, reason, node("button", "Update ticket status"));
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
                notice(e.message);
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
        notice(e.message);
    }
};

el("verify-email").onclick = async () => {
    el("verify-email").disabled = true;
    try {
        notice((await api("email/verification-notification", "POST")).message);
    } catch (e) {
        notice(e.message);
    } finally {
        el("verify-email").disabled = false;
    }
};
el("refresh-identity").onclick = () =>
    identity().catch((e) => notice(e.message));
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
        card.append(node("span", label), node("strong", String(data[key])));
        metrics.append(card);
    }
    const table = document.createElement("table");
    table.className = "audit-table";
    table.append(
        node("caption", "Audit history / سجل التدقيق — latest 50 events"),
    );
    const head = document.createElement("thead"),
        headers = document.createElement("tr");
    for (const label of ["Event", "Actor", "Record", "Time (UTC)"])
        headers.append(node("th", label));
    head.append(headers);
    table.append(head);
    const body = document.createElement("tbody");
    const tools = node("div", "");
    tools.className = "audit-tools";
    const label = node("label", "Filter latest events / تصفية أحدث الأحداث");
    const search = document.createElement("input");
    search.id = "audit-search";
    search.type = "search";
    search.maxLength = 100;
    label.append(search);
    const count = node("p", "");
    count.id = "audit-count";
    count.setAttribute("role", "status");
    const exportButton = node("button", "Export filtered CSV / تصدير النتائج");
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
        count.textContent =
            visible.length +
            " / " +
            data.audit_events.length +
            " latest events / أحدث الأحداث";
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
    scroll.setAttribute("aria-label", "Audit history");
    scroll.append(table);
    container.append(metrics, tools, scroll);
}
