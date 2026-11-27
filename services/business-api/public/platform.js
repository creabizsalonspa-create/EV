let inboxUnreadOnly = false;
let inboxCategory = "all";
let inboxPreferences = null;
let inboxPreferencesFor = null;
let inboxPage = 1;
let inboxGeneration = 0;
// Fleet and invitation pages are independently scoped private reads.
let fleetPage = 1, fleetGeneration = 0, fleetRequest = null, fleetOwner = null;
let invitationPage = 1, invitationGeneration = 0,
    invitationRequest = null, invitationOwner = null;
let fleetDetailGeneration = 0, fleetDetailRequest = null;
let fleetCreatePending = false;
let regionalConfigBusy = false;
// Startup's pending-logout branch can clear identity before lower sections
// initialize. Every mutation lock read by clearPrivateViews must exist now.
let vehicleCreatePending = false,
    vehicleCreateGeneration = 0, profileUpdateGeneration = 0,
    profileUpdatePending = false;
let pendingTicketCreate = null, ticketCreatePending = false, ticketCreateGeneration = 0;
let unconfirmedFavorite = null;
let favoriteMode = false,
    previousCatalogQuery = "";
let commandRequest = null,
    commandPage = 1;
// Private draft references must be initialized before any identity callback
// and dropped on logout as well as on a new authenticated actor/epoch.
let connectorFleetFilter = { epoch: -1, actor: null, draftStation: '', selectedStation: '' };
let reservationPage = 1,
    reservationGeneration = 0,
    reservationRequest = null,
    reservationOwner = null;
let supportPage = 1,
    supportGeneration = 0,
    supportRequest = null,
    supportOwner = null;
let ticketDetailGeneration = 0, ticketDetailRequest = null;
let simulationPage = 1, simulationGeneration = 0,
    simulationRequest = null, simulationOwner = null;
let garagePage = 1, garageGeneration = 0,
    garageRequest = null, garageOwner = null;
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
    if (client.securityChange) throw new SessionChangedError();
    const changesSecurity = method === "POST" && ["account/second-factor/enable", "account/second-factor/disable", "account/second-factor/recovery-codes"].includes(path);
    let securityAttempt = null;
    if (changesSecurity) {
        // Fence earlier responses before the server can revoke their session.
        // Other requests wait for an explicit fresh action; none are replayed.
        client.invalidateSession();
        securityAttempt = {epoch: client.sessionEpoch};
        client.securityChange = securityAttempt;
    }
    client.token = csrf;
    try {
        const result = await client.request(path, method, data, signal);
        // A proven MFA change rotates the session and CSRF token. Accept the
        // handoff only from these current-account mutations; never replay them.
        if (changesSecurity
            && result?.reauthentication_required === false &&
            typeof result.csrf_token === "string" && /^[A-Za-z0-9]{40}$/.test(result.csrf_token)) {
            csrf = result.csrf_token;
            client.token = csrf;
        }
        return result;
    } catch (error) {
        if ([401, 419].includes(error.status) && user) {
            clearIdentity();
        }
        throw error;
    } finally {
        if (securityAttempt && client.securityChange === securityAttempt) {
            client.securityChange = null;
            // Rebind old read controls even after a failed proof. This never
            // acknowledges notices or replays a security or charging action.
            if (typeof refreshVisibleInbox === "function") setTimeout(refreshVisibleInbox, 0);
        }
    }
}
window.dispatchEvent(
    new CustomEvent("discovery-api-ready", {
        detail: (path, method = "GET", body, signal) =>
            api("discovery/" + path, method, body, signal),
    }),
);
let identityBusy = false;
// Session cookies from an anonymous startup reply must settle before login can regenerate them.
let identityInitializing = true;
function setIdentityBusy(value) {
    identityBusy = value;
    el("account").setAttribute("aria-busy", String(value || identityInitializing));
    el("account")
        .querySelectorAll("input,button")
        .forEach((n) => (n.disabled = value || identityInitializing));
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
    clearCatalogPromotions();
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
    // Public country labels may remain, but reviewed Admin drafts and
    // detached mutation callbacks must never cross a credential epoch.
    const regionForm = el("region-config");
    regionForm.reset();
    delete regionForm.dataset.expectedCountry;
    delete regionForm.dataset.expectedRevision;
    delete regionForm.dataset.expectedEpoch;
    delete regionForm.dataset.expectedValues;
    regionalConfigBusy = false;
    regionForm.inert = false;
    regionForm.querySelector("button").disabled = false;
    el("region-list").querySelectorAll("[data-access=admin]").forEach((node) => node.remove());
    inboxUnreadOnly = false;
    inboxCategory = "all";
    inboxPreferences = null;
    inboxPreferencesFor = null;
    inboxPage = 1;
    inboxGeneration++;
    resetCustomerMutationState();
    fleetRequest?.abort();
    fleetRequest = null;
    fleetGeneration++;
    fleetOwner = null;
    fleetPage = 1;
    invitationRequest?.abort();
    invitationRequest = null;
    invitationGeneration++;
    invitationOwner = null;
    invitationPage = 1;
    fleetDetailRequest?.abort();
    fleetDetailRequest = null;
    fleetDetailGeneration++;
    fleetCreatePending = false;
    const createFleetButton = el("fleet-create").querySelector("button");
    if (createFleetButton) createFleetButton.disabled = false;
    for (const id of ["fleet-list", "fleet-invitations", "fleet-detail"])
        el(id).removeAttribute("aria-busy");
    reservationRequest?.abort();
    reservationRequest = null;
    reservationGeneration++;
    reservationPage = 1;
    reservationOwner = null;
    el("reservation-list").removeAttribute("aria-busy");
    supportRequest?.abort();
    supportRequest = null;
    supportGeneration++;
    supportPage = 1;
    supportOwner = null;
    el("ticket-list").removeAttribute("aria-busy");
    ticketDetailRequest?.abort();
    ticketDetailRequest = null;
    ticketDetailGeneration++;
    el("ticket-detail").removeAttribute("aria-busy");
    simulationRequest?.abort();
    simulationRequest = null;
    simulationGeneration++;
    simulationPage = 1;
    simulationOwner = null;
    el("simulation-list").removeAttribute("aria-busy");
    garageRequest?.abort();
    garageRequest = null;
    garageGeneration++;
    garagePage = 1;
    garageOwner = null;
    el("vehicle-list").removeAttribute("aria-busy");
    riderWorkspace.clear();
    vendorWorkspace.clear();
    vehicleModelCatalog.clear();
    vehicleManualSoc.clear();
    driverPreferences.clear();
    stationInfo.clear();
    roamingWorkspace.clear();
    chargingReviews.clear();
    invoiceHistory.clear();
    commercialWorkspace.clear();
    window.EvAdminOperatorRoleHistory?.clear();
    tariffWorkspace.clear();
    window.EvAdminSessions?.clear();
    window.EvAccountSessions?.clear();
    window.EvUnifiedDiscovery?.active?.clear();
    window.EvPhoneVerification?.clear();
    window.EvAccountSecondFactor?.clear();
    commandRequest?.abort();
    connectorFleetFilter = { epoch: -1, actor: null, draftStation: '', selectedStation: '' };
    el("charging-dashboard").replaceChildren();
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
    el("unified-discovery-panel").hidden = !user;
    window.EvUnifiedDiscovery?.active?.setContext({owner:user?.id??null,customer:user?.role==='customer',favorite:async(kind,id,desired)=>{
        const result=await api((kind==='catalog'?'favorites/':'roaming/favorites/')+id,desired?'PUT':'DELETE');
        return result.is_favorite;
    }});
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
    window.EvAdminAccounts?.setActor(user, api);
    window.EvAdminOperationalHealth?.setActor(user, api);
    window.EvAdminCommandSecretStorage?.setActor(user, api);
    window.EvCompanySessionObservations?.clear();
    window.EvFleetReport?.setActor(user, api);
    window.EvAdminOperationsMap?.setActor(user, api);
    window.EvAdminKycQueue?.setActor(user, api);
    window.EvAdminStaffPermissions?.setActor(user, api);
    window.EvAdminAuditTrail?.setActor(user, api);
    window.EvPrivacyRequests?.setSelfActor(user, api);
    window.EvPrivacyRequests?.setAdminActor(user, api);
    window.EvAdminSessions?.setActor(user, api);
    window.EvAccountSessions?.setActor(user, api, () => {
        clearIdentity();
        signalSessionRevocation();
        window.location.assign("/?sessions=revoked");
    });
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
    if (error.status === 409 && [
        "The selected time is outside the station operating calendar.",
        "The operating calendar is unavailable. Refresh before booking or starting.",
    ].includes(error.message)) return error.message;
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
        // A validated existing account must retain immediate logout while its
        // private panels load. Only anonymous bootstrap can race a new login.
        if (identityInitializing) {
            identityInitializing = false;
            setIdentityBusy(false);
        }
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
    // Capture only after /me established the current actor. A user may refresh
    // history while later workspaces bootstrap; keep that newer scoped draft.
    const initialCommandRequest = commandRequest;
    await vehicleModelCatalog.setActor(user);
    if (epoch !== client.sessionEpoch) return;
    vehicleManualSoc.setActor(user);
    if (user && epoch === client.sessionEpoch) setIdentityBusy(false);
    if (!user) clearPrivateViews();
    // Company controls must not wait behind unrelated customer/support reads.
    // This remains an authenticated read with the workspace's own actor fence.
    await vendorWorkspace.setActor(user);
    if (epoch !== client.sessionEpoch) return;
    if (user) {
        await bookings();
        await simulations();
        await fleets();
        await garage();
        await support();
    }
    await window.EvPhoneVerification?.setActor(user, api);
    await window.EvAccountSecondFactor?.setActor(user, api);
    await stationInfo.setActor(user);
    await riderWorkspace.setActor(user);
    await roamingWorkspace.setActor(user);
    await chargingReviews.setActor(user);
    await invoiceHistory.setActor(user);
    await tariffWorkspace.setActor(user);
    await commercialWorkspace.setActor(user);
    window.EvAdminOperatorRoleHistory?.setActor(user, api);
    if (user?.role === "admin") await adminOverview();
    if (["vendor", "admin"].includes(user?.role) && commandRequest === initialCommandRequest)
        await commandHistory();
}
// Catalog ownership: startup must not replace an explicit page/form already rendered for this account.
let stationRequest = null,
    stationQuery = "",
    requestedCatalogQuery = "",
    renderedCatalogIdentity = null;
function catalogIdentityKey() {
    return client.sessionEpoch + ":" + (user ? user.id + ":" + user.role : "guest");
}
async function initialCatalog() {
    if (renderedCatalogIdentity !== catalogIdentityKey()) await stations(requestedCatalogQuery);
}
function reservationForm(s, mapping = null) {
    const stationZone = window.EvStationTime.validZone(s.timezone);
    const f = document.createElement("form");
    const l = document.createElement("label");
    const timeTitle = batteryNode("span", "Start (UTC, :00 or :30)", "البداية (UTC، الدقيقة 00 أو 30)");
    const i = document.createElement("input");
    i.type = "datetime-local";
    i.step = 1800;
    i.required = true;
    l.append(timeTitle, i);
    const modeLabel = document.createElement("label");
    const mode = document.createElement("select");
    mode.dataset.bookingTimeMode = "true";
    if (stationZone) {
        const local = new Option("Station local (" + stationZone + ")", "station");
        local.dataset.en = "Station local (" + stationZone + ")";
        local.dataset.ar = "توقيت المحطة (" + stationZone + ")";
        mode.append(local);
    }
    const utc = new Option("UTC (exact slot)", "utc");
    utc.dataset.en = "UTC (exact slot)";
    utc.dataset.ar = "UTC (موعد محدد)";
    mode.append(utc);
    modeLabel.append(batteryNode("span", "Time reference", "مرجع التوقيت"), mode);
    const hint = batteryNode("p", "", "");
    hint.setAttribute("role", "status");
    const showTimeMode = () => {
        i.value = "";
        // A station in a quarter-hour-offset region can have valid UTC
        // half-hour slots at :15 or :45 local. Validation remains UTC-owned.
        i.step = mode.value === "station" ? 60 : 1800;
        if (mode.value === "station") {
            batteryText(timeTitle, "Station-local start (30-min UTC slot)", "البداية بتوقيت المحطة (موعد UTC بفاصل 30 دقيقة)");
            batteryText(hint, "Uses " + stationZone + ". Some offsets require :15 or :45 locally. For repeated or missing DST times use UTC.",
                "يستخدم توقيت " + stationZone + ". عند تكرار ساعة أو غيابها بسبب التوقيت الصيفي اختر UTC.");
        } else {
            batteryText(timeTitle, "UTC start (:00 or :30)", "البداية بتوقيت UTC (00 أو 30)");
            batteryText(hint, stationZone
                ? "Enter the exact UTC time; your device timezone is not used."
                : "Station timezone unavailable. Enter the exact UTC time.",
                stationZone
                    ? "أدخل وقت UTC المحدد؛ لا يُستخدم توقيت جهازك."
                    : "توقيت المحطة غير متاح. أدخل وقت UTC المحدد.");
        }
    };
    mode.addEventListener("change", showTimeMode);
    f.append(modeLabel, l, hint);
    showTimeMode();
    const policy = s.reservation_terms;
    if (policy != null && !window.EvReservationPolicy?.valid(policy)) {
        f.replaceChildren(batteryNode('p', 'Reservation terms are unavailable. Refresh before booking.', 'شروط الحجز غير متاحة. حدّث قبل الحجز.'));
        return f;
    }
    const termsVersion = policy?.version;
    window.EvReservationPolicy?.attach(f, policy);
    const actorEpoch = client.sessionEpoch;
    const vehicleLabel = document.createElement("label");
    const vehicle = document.createElement("select");
    vehicle.name = "vehicle_id";
    const empty = new Option("", "");
    empty.dataset.en = "No vehicle association"; empty.dataset.ar = "دون ربط مركبة";
    vehicle.append(empty);
    vehicleLabel.append(batteryNode("span", "Vehicle (optional)", "المركبة (اختياري)"), vehicle);
    const loadVehicle = batteryNode("button", "Load compatible vehicles", "تحميل المركبات المتوافقة");
    loadVehicle.type = "button";
    let vehiclePage = 0, vehicleLast = 1;
    loadVehicle.onclick = async () => {
        loadVehicle.disabled = true;
        if (vehiclePage >= vehicleLast) {
            vehiclePage = 0; vehicleLast = 1;
            while (vehicle.options.length > 1) vehicle.remove(1);
        }
        try {
            const result = await api("vehicles?connector_type=" + encodeURIComponent(mapping?.connector_type || s.connector_type) + "&page=" + (vehiclePage + 1));
            if (!f.isConnected || actorEpoch !== client.sessionEpoch) return;
            for (const row of result.data) vehicle.append(new Option(row.name, row.id));
            vehiclePage = result.current_page; vehicleLast = result.last_page;
            batteryText(loadVehicle, vehiclePage < vehicleLast ? "More compatible vehicles" : "Refresh compatible vehicles",
                vehiclePage < vehicleLast ? "المزيد من المركبات المتوافقة" : "تحديث المركبات المتوافقة");
        } catch (error) { notice(error); }
        finally {loadVehicle.disabled = false;}
    };
    f.append(vehicleLabel, loadVehicle);
    let b = ui("button", "Reserve simulator slot");
    f.append(b);
    let bookingKey = crypto.randomUUID();
    f.onsubmit = async (e) => {
        e.preventDefault();
        if (!f.isConnected || actorEpoch !== client.sessionEpoch) return;
        b.disabled = true;
        try {
            await api("reservations", "POST", {
                station_id: s.id,
                ...(termsVersion ? {expected_reservation_terms_version:termsVersion} : {}),
                ...(vehicle.value ? { vehicle_id: Number(vehicle.value) } : {}),
                ...(mapping ? { connector_mapping_id: mapping.id } : {}),
                starts_at: window.EvStationTime.toUtc(
                    i.value, mode.value === "station" ? stationZone : "UTC",
                ),
                idempotency_key: bookingKey,
            });
            bookingKey = crypto.randomUUID();
            notice(
                "Simulation inventory reserved. No physical charger command was sent.",
            );
            // The canonical list is newest first. Show the freshly created
            // reservation rather than leaving the customer on an older page.
            await bookings(1);
        } catch (e) {
            notice(e);
        } finally {
            b.disabled = false;
        }
    };
    return f;
}
async function connectorChoices(station, root, page = 1) {
    const identity = client.sessionEpoch, requestStarted = performance.now();
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
            window.EvConnectorFreshness.attach(row, option.status_freshness, performance.now() - requestStarted);
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
            ["Refresh", page, true],
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

function sponsorPanel(stations, placement, catalogRequest, identity) {
    const banner = placement === "discovery_banner";
    const stationIds = new Set(stations.map(station => station.id));
    const panel = document.createElement(banner ? "section" : "details");
    const content = document.createElement("div");
    const text = (tag, en, ar) => {
        const element = node(tag, document.documentElement.lang === "ar" ? ar : en);
        element.dataset.en = en;
        element.dataset.ar = ar;
        return element;
    };
    panel.dataset.catalogPromotions = placement;
    panel.hidden = banner;
    panel.append(
        banner
            ? text("h3", "Sponsored discovery offers", "عروض الاكتشاف المموّلة")
            : text("summary", "Sponsored promotions", "العروض المموّلة"),
        content,
    );
    let revision = 0;

    async function load(page = 1) {
        const current = ++revision;
        content.replaceChildren(text("p", "Loading promotions…", "جارٍ تحميل العروض…"));
        const params = new URLSearchParams({ placement, page });
        if (banner) {
            for (const id of stationIds) params.append("station_ids[]", id);
        } else {
            params.set("country", stations[0].country);
            params.set("station_id", stations[0].id);
        }
        const alive = () => current === revision && panel.isConnected &&
            (banner || panel.open) && !catalogRequest.signal.aborted &&
            identity === client.sessionEpoch;
        try {
            const result = await api("commercial/campaign-placements?" + params, "GET",
                undefined, catalogRequest.signal);
            if (!alive()) return;
            content.replaceChildren();
            for (const item of result.placements) {
                if (!stationIds.has(item.station_id) || item.sponsored !== true ||
                    item.availability_verified !== false || item.compatibility_verified !== false ||
                    item.capability_override !== false) continue;
                content.append(text("h4", item.title, item.title_ar),
                    text("p", item.description, item.description_ar));
            }
            if (banner) panel.hidden = !content.childElementCount && result.last_page === 1;
            if (!content.childElementCount)
                content.append(text("p", "No promotions on this page.", "لا توجد عروض في هذه الصفحة."));
            for (const [allowed, target, en, ar] of [
                [result.current_page > 1, result.current_page - 1, "Previous promotions", "العروض السابقة"],
                [result.current_page < result.last_page, result.current_page + 1, "Next promotions", "العروض التالية"],
            ]) {
                if (!allowed) continue;
                const button = text("button", en, ar);
                button.type = "button";
                button.onclick = () => load(target);
                content.append(button);
            }
        } catch {
            if (alive()) content.replaceChildren(text("p", "Promotions unavailable. Reopen to retry.", "العروض غير متاحة. أعد فتح القسم للمحاولة."));
        }
    }

    if (banner) queueMicrotask(() => load());
    else panel.ontoggle = () => {
        if (panel.open) load();
        else {
            revision++;
            content.replaceChildren();
        }
    };
    return panel;
}

function clearCatalogPromotions() {
    el("discover").querySelectorAll("[data-catalog-promotions]").forEach(panel => panel.remove());
}

async function stations(query = "") {
    requestedCatalogQuery = query;
    const identity = client.sessionEpoch,
        savedMode = favoriteMode;
    stationRequest?.abort();
    clearCatalogPromotions();
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
    window.dispatchEvent(
        new CustomEvent("catalog-map-data", { detail: j.data }),
    );
    renderedCatalogIdentity = catalogIdentityKey();
    el("stations").replaceChildren();
    if (user?.role === "customer" && !savedMode && j.data.length) {
        el("stations").before(sponsorPanel(j.data, "discovery_banner", request, identity));
    }
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
        StationInformation.attachCard(a, s);
        if (user?.role === "customer") a.append(sponsorPanel([s], "station_detail", request, identity));
        const feedback = document.createElement("button");
        feedback.type = "button";
        feedback.dataset.en = "Charging feedback";
        feedback.dataset.ar = "ملاحظات الشحن";
        feedback.textContent =
            document.documentElement.lang === "ar"
                ? feedback.dataset.ar
                : feedback.dataset.en;
        feedback.onclick = () => chargingReviews.station(s.id);
        a.append(feedback);
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
async function bookings(page = reservationPage) {
    if (!user || !Number.isSafeInteger(page) || page < 1) return;
    const owner = client.sessionEpoch + ":" + user.id + ":" + user.role;
    if (reservationOwner !== owner) {
        reservationOwner = owner;
        reservationPage = 1;
        page = 1;
    }
    reservationRequest?.abort();
    const controller = new AbortController();
    reservationRequest = controller;
    const generation = ++reservationGeneration;
    const epoch = client.sessionEpoch, actor = user.id;
    const active = () => generation === reservationGeneration &&
        epoch === client.sessionEpoch && actor === user?.id &&
        reservationRequest === controller && !controller.signal.aborted;
    const list = el("reservation-list");
    list.setAttribute("aria-busy", "true");
    list.replaceChildren(batteryNode("p", "Loading reservations…", "جارٍ تحميل الحجوزات…"));
    try {
        const j = await api("reservations?page=" + page, "GET", undefined, controller.signal);
        if (!active()) return;
        if (!Array.isArray(j?.data) || !Number.isSafeInteger(j.current_page) ||
            !Number.isSafeInteger(j.last_page) || !Number.isSafeInteger(j.total) ||
            j.current_page < 1 || j.last_page < 1 || j.total < 0 ||
            j.current_page !== page)
            throw new Error("Unrecognized reservation response.");
        if (page > j.last_page) {
            await bookings(j.last_page);
            return;
        }
        reservationPage = page;
        list.replaceChildren();
        if (!j.data.length)
            list.append(batteryNode("p",
                "No reservations found on this page. / No physical charger booking is implied.",
                "لا توجد حجوزات في هذه الصفحة. هذا لا يؤكد حجز شاحن فعلي."));
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
        const stationTimeEn = window.EvStationTime.displayStationTime(r.starts_at, r.station_timezone, "en");
        if (stationTimeEn)
            a.append(batteryNode("p", "Station local · " + stationTimeEn,
                "توقيت المحطة · " + window.EvStationTime.displayStationTime(r.starts_at, r.station_timezone, "ar")));
        window.EvReservationPolicy?.attach(a, r.reservation_terms_snapshot);
        if (r.vehicle_connector_type) a.append(r.vehicle_label
            ? node("p", r.vehicle_label + " · " + r.vehicle_connector_type)
            : batteryNode("p", "Vehicle connector · " + r.vehicle_connector_type, "موصل المركبة · " + r.vehicle_connector_type));
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
            const reviewPrice = async () => {
                const epoch = client.sessionEpoch, owner = user?.id;
                return window.EvPrechargePricing.review({api, reservationId:r.id,
                    current:() => epoch === client.sessionEpoch && owner === user?.id,
                    ar:document.documentElement.lang === 'ar'});
            };
            const pricing = document.createElement('button');
            pricing.dataset.en = 'Review charging price'; pricing.dataset.ar = 'مراجعة سعر الشحن';
            pricing.textContent = document.documentElement.lang === 'ar' ? pricing.dataset.ar : pricing.dataset.en;
            pricing.onclick = async () => { pricing.disabled = true; try { await reviewPrice(); } catch (e) { notice(e); } finally { if(pricing.isConnected) pricing.disabled = false; } };
            a.append(pricing);
            start.onclick = async () => {
                start.disabled = true;
                try {
                    if (r.reservation_terms_snapshot?.price_acceptance_required === true && !await reviewPrice()) { await simulations(); return; }
                    await api(
                        "reservations/" +
                            r.id +
                            (r.csms_bound ? "/csms-start" : "/simulate-start"),
                        "POST",
                    );
                    await simulations();
                } catch (e) {
                    notice(e);
                } finally {
                    if(start.isConnected) start.disabled = false;
                }
            };
            a.append(start);
        }
            list.append(a);
        }
        const pager = document.createElement("nav");
        pager.className = "pagination";
        pager.dataset.reservationPagination = "true";
        pager.setAttribute("aria-label", "Reservation pages / صفحات الحجوزات");
        pager.append(ui("span", "Page {page} of {last} · {total} records", {
            page: j.current_page, last: j.last_page, total: j.total,
        }));
        for (const [key, label, ar, targetPage, disabled] of [
            ["previous", "Previous", "السابق", page - 1, page <= 1],
            ["next", "Next", "التالي", page + 1, page >= j.last_page],
            ["refresh", "Refresh", "تحديث", page, false],
        ]) {
            const button = batteryNode("button", label, ar);
            button.type = "button";
            button.dataset.reservationPageAction = key;
            button.disabled = disabled;
            button.addEventListener("click", () => {
                if (active()) void bookings(targetPage);
            });
            pager.append(button);
        }
        list.append(pager);
    } catch (error) {
        if (!active()) return;
        list.replaceChildren(batteryNode("p", "Reservations could not be loaded. Refresh to retry.",
            "تعذر تحميل الحجوزات. حدّث للمحاولة مرة أخرى."));
        const retry = batteryNode("button", "Retry reservations", "إعادة محاولة تحميل الحجوزات");
        retry.type = "button";
        retry.dataset.reservationRetry = "true";
        retry.onclick = () => { if (active()) void bookings(page); };
        list.append(retry);
    } finally {
        if (active()) list.removeAttribute("aria-busy");
    }
}
async function simulations(page = simulationPage) {
    if (!user || !Number.isSafeInteger(page) || page < 1) return;
    const owner = client.sessionEpoch + ":" + user.id + ":" + user.role;
    if (simulationOwner !== owner) {
        simulationOwner = owner;
        simulationPage = 1;
        page = 1;
    }
    simulationRequest?.abort();
    const controller = new AbortController();
    simulationRequest = controller;
    const generation = ++simulationGeneration,
        epoch = client.sessionEpoch, actor = user.id;
    const active = () => generation === simulationGeneration &&
        epoch === client.sessionEpoch && actor === user?.id &&
        simulationRequest === controller && !controller.signal.aborted;
    const list = el("simulation-list");
    list.setAttribute("aria-busy", "true");
    list.replaceChildren(batteryNode("p", "Loading charging sessions…",
        "جارٍ تحميل جلسات الشحن…"));
    try {
    const j = await api("simulations?page=" + page, "GET", undefined, controller.signal);
    if (!active()) return;
    if (!Array.isArray(j?.data) || !Number.isSafeInteger(j.current_page) ||
        !Number.isSafeInteger(j.last_page) || !Number.isSafeInteger(j.total) ||
        j.current_page !== page || j.last_page < 1 || j.total < 0)
        throw new Error("Unrecognized charging session response.");
    if (page > j.last_page) {
        await simulations(j.last_page);
        return;
    }
    simulationPage = page;
    list.replaceChildren();
    if (!j.data.length)
        list.append(batteryNode("p", "No charging sessions on this page.",
            "لا توجد جلسات شحن في هذه الصفحة."));
    for (let s of j.data) {
        let a = ui(
            "article",
            s.cost_projection ? "SIMULATED · #{id} · {status} · {energy} Wh · No payment processed" : "SIMULATED · #{id} · {status} · {energy} Wh · {cost} minor units {currency} · No payment processed",
            {
                id: s.id,
                status: { translate: s.status },
                energy: s.energy_wh,
                cost: s.total_minor ?? { translate: "pending" },
                currency: s.currency,
            },
        );
        window.EvSessionCost.attach(a,s.cost_projection);
        if (s.csms_linked) a.append(ui("p", "Source controls disclaimer"));
        if (s.authorization_method === "rider_token")
            a.append(ui("p", "Card source authorization"));
        if (s.awaiting_rider_transaction)
            a.append(ui("p", "Awaiting rider transaction"));
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
        if (s.start_authorization_revoked)
            a.append(ui("p", "Start authorization revoked"));
        else if (s.start_admission_blocked)
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
        list.append(a);
    }
    const pager = document.createElement("nav");
    pager.className = "pagination";
    pager.dataset.simulationPagination = "true";
    pager.setAttribute("aria-label", "Charging session pages / صفحات جلسات الشحن");
    pager.append(ui("span", "Page {page} of {last} · {total} records", {
        page: j.current_page, last: j.last_page, total: j.total,
    }));
    for (const [key, en, ar, target, disabled] of [
        ["previous", "Previous", "السابق", page - 1, page <= 1],
        ["next", "Next", "التالي", page + 1, page >= j.last_page],
        ["refresh", "Refresh", "تحديث", page, false],
    ]) {
        const button = batteryNode("button", en, ar);
        button.type = "button";
        button.dataset.simulationPageAction = key;
        button.disabled = disabled;
        button.onclick = () => { if (active()) void simulations(target); };
        pager.append(button);
    }
    list.append(pager);
    } catch (error) {
        if (!active()) return;
        list.replaceChildren(batteryNode("p",
            "Charging sessions could not be loaded. Retry to verify current source state.",
            "تعذر تحميل جلسات الشحن. أعد المحاولة للتحقق من حالة المصدر."));
        const retry = batteryNode("button", "Retry charging sessions", "إعادة تحميل جلسات الشحن");
        retry.type = "button";
        retry.dataset.simulationRetry = "true";
        retry.onclick = () => { if (active()) void simulations(page); };
        list.append(retry);
    } finally {
        if (active()) list.removeAttribute("aria-busy");
    }
}
async function regions() {
    const epoch = client.sessionEpoch;
    const rows = await api("regions");
    if (epoch !== client.sessionEpoch) return;
    const root = el("region-list");
    root.replaceChildren();
    for (const region of rows) {
        root.append(ui("p", "{country} · {currency} · {timezone} · commerce disabled", region));
        if (user?.role === "admin" && Number.isSafeInteger(region.configuration_revision) &&
            region.configuration_revision >= 1) {
            const edit = batteryNode("button", "Edit reviewed regional settings", "تعديل إعدادات المنطقة التي تمت مراجعتها");
            edit.type = "button";
            edit.dataset.access = "admin";
            edit.dataset.regionConfigurationEdit = region.country;
            edit.onclick = () => {
                if (!edit.isConnected || regionalConfigBusy || epoch !== client.sessionEpoch || user?.role !== "admin") return;
                const form = el("region-config");
                for (const key of ["country", "currency", "minor_units", "timezone", "tax_bps"]) {
                    form.elements.namedItem(key).value = region[key] ?? "";
                }
                form.dataset.expectedCountry = region.country;
                form.dataset.expectedRevision = String(region.configuration_revision);
                form.dataset.expectedEpoch = String(epoch);
                form.dataset.expectedValues = JSON.stringify({
                    currency: region.currency, minor_units: region.minor_units,
                    timezone: region.timezone, tax_bps: region.tax_bps,
                    commerce_enabled: region.commerce_enabled,
                });
                form.elements.namedItem("currency").focus();
            };
            root.append(edit);
        }
        const enabled = Boolean(region.review_intake_enabled);
        const button = enabled
            ? batteryNode("button", "Pause new charging feedback", "إيقاف استقبال ملاحظات الشحن الجديدة")
            : batteryNode("button", "Allow new charging feedback", "السماح باستقبال ملاحظات الشحن الجديدة");
        button.type = "button";
        button.dataset.access = "admin";
        button.dataset.regionReviewIntake = region.country;
        button.hidden = user?.role !== "admin";
        button.onclick = async () => {
            if (!button.isConnected || button.disabled || epoch !== client.sessionEpoch || user?.role !== "admin") return;
            button.disabled = true;
            try {
                await api("regions/" + encodeURIComponent(region.country) + "/review-intake", "PUT", {
                    enabled: !enabled,
                    expected_revision: region.review_intake_revision,
                });
                if (epoch === client.sessionEpoch) await regions();
            } catch (error) {
                if (epoch !== client.sessionEpoch) return;
                notice(error);
                if (error.status === 409) await regions().catch(notice);
            } finally {
                button.disabled = false;
            }
        };
        root.append(button);
    }
}
// The server owns tenant scope, membership status and page ordering.
function fleetPageResponse(value, expected) {
    return value && Array.isArray(value.data) &&
        Number.isSafeInteger(value.current_page) &&
        Number.isSafeInteger(value.last_page) &&
        Number.isSafeInteger(value.total) &&
        value.current_page === expected && expected >= 1 &&
        value.last_page >= 1 && value.total >= 0;
}
function fleetNavigation(list, pageData, page, prefix, label, onPage, active) {
    const nav = document.createElement("nav");
    nav.className = "pagination";
    nav.dataset[prefix + "Pagination"] = "true";
    nav.setAttribute("aria-label", label);
    nav.append(batteryNode("span",
        "Page " + pageData.current_page + " of " + pageData.last_page +
            " · " + pageData.total + " records",
        "الصفحة " + pageData.current_page + " من " + pageData.last_page +
            " · " + pageData.total + " سجل"));
    for (const [key, en, ar, target, disabled] of [
        ["previous", "Previous", "السابق", page - 1, page <= 1],
        ["next", "Next", "التالي", page + 1, page >= pageData.last_page],
        ["refresh", "Refresh", "تحديث", page, false],
    ]) {
        const button = batteryNode("button", en, ar);
        button.type = "button";
        button.dataset[prefix + "PageAction"] = key;
        button.disabled = disabled;
        button.onclick = () => { if (active()) void onPage(target); };
        nav.append(button);
    }
    list.append(nav);
}
async function loadFleetAccounts(page = fleetPage) {
    if (!user || !Number.isSafeInteger(page) || page < 1) return;
    const scope = client.sessionEpoch + ":" + user.id + ":" + user.role;
    if (fleetOwner !== scope) {
        fleetOwner = scope;
        fleetPage = 1;
        page = 1;
    }
    // Any new list read invalidates previously rendered detail authority:
    // the membership may have been revoked even if the requested page stays
    // the same, or the refresh may fail entirely.
    fleetDetailRequest?.abort();
    fleetDetailRequest = null;
    fleetDetailGeneration++;
    el("fleet-detail").replaceChildren();
    el("fleet-detail").removeAttribute("aria-busy");
    fleetRequest?.abort();
    const controller = new AbortController();
    fleetRequest = controller;
    const generation = ++fleetGeneration, epoch = client.sessionEpoch, actor = user.id;
    const active = () => fleetGeneration === generation &&
        epoch === client.sessionEpoch && user?.id === actor &&
        fleetRequest === controller && !controller.signal.aborted;
    const list = el("fleet-list");
    list.setAttribute("aria-busy", "true");
    list.replaceChildren(batteryNode("p", "Loading fleets…", "جارٍ تحميل الأساطيل…"));
    try {
        const j = await api("fleets?page=" + page, "GET", undefined, controller.signal);
        if (!active()) return;
        if (!fleetPageResponse(j, page)) throw Error("Unreadable fleet page.");
        if (page > j.last_page) { await loadFleetAccounts(j.last_page); return; }
        fleetPage = page;
        list.replaceChildren();
        if (!j.data.length)
            list.append(batteryNode("p",
                "Create a fleet or accept an invitation to get started.",
                "أنشئ أسطولاً أو اقبل دعوة للبدء."));
        for (const f of j.data) {
            if (!Number.isSafeInteger(f.id) || f.id <= 0 || typeof f.name !== "string")
                throw Error("Unreadable fleet record.");
            const b = node("button", f.name);
            b.type = "button";
            b.dataset.fleetId = String(f.id);
            b.onclick = () => {
                if (active()) void fleetDetail(f.id).catch(e => { if (active()) notice(e); });
            };
            list.append(b);
        }
        fleetNavigation(list, j, page, "fleet", "Fleet pages / صفحات الأساطيل",
            loadFleetAccounts, active);
    } catch (error) {
        if (!active()) return;
        list.replaceChildren(batteryNode("p",
            "Fleets could not be loaded. Retry to verify your memberships.",
            "تعذر تحميل الأساطيل. أعد المحاولة للتحقق من عضوياتك."));
        const retry = batteryNode("button", "Retry fleets", "إعادة تحميل الأساطيل");
        retry.type = "button";
        retry.dataset.fleetRetry = "true";
        retry.onclick = () => { if (active()) void loadFleetAccounts(page); };
        list.append(retry);
    } finally {
        if (active()) list.removeAttribute("aria-busy");
    }
}
async function loadFleetInvitations(page = invitationPage) {
    if (!user || !Number.isSafeInteger(page) || page < 1) return;
    const scope = client.sessionEpoch + ":" + user.id + ":" + user.role;
    if (invitationOwner !== scope) {
        invitationOwner = scope;
        invitationPage = 1;
        page = 1;
    }
    invitationRequest?.abort();
    const controller = new AbortController();
    invitationRequest = controller;
    const generation = ++invitationGeneration,
        epoch = client.sessionEpoch, actor = user.id;
    const active = () => invitationGeneration === generation &&
        epoch === client.sessionEpoch && user?.id === actor &&
        invitationRequest === controller && !controller.signal.aborted;
    const list = el("fleet-invitations");
    list.setAttribute("aria-busy", "true");
    list.replaceChildren(batteryNode("p", "Loading invitations…", "جارٍ تحميل الدعوات…"));
    try {
        const j = await api("fleet-invitations?page=" + page, "GET", undefined, controller.signal);
        if (!active()) return;
        if (!fleetPageResponse(j, page)) throw Error("Unreadable fleet invitation page.");
        if (page > j.last_page) { await loadFleetInvitations(j.last_page); return; }
        invitationPage = page;
        list.replaceChildren();
        if (!j.data.length)
            list.append(batteryNode("p", "No pending fleet invitations.",
                "لا توجد دعوات أسطول معلقة."));
        for (const invitation of j.data) {
            if (!Number.isSafeInteger(invitation.id) || invitation.id <= 0 ||
                !Number.isSafeInteger(invitation.fleet_id) || invitation.fleet_id <= 0)
                throw Error("Unreadable fleet invitation.");
            const button = ui("button", "Accept fleet #{id}", {id: invitation.fleet_id});
            button.type = "button";
            button.dataset.fleetInvitationId = String(invitation.id);
            button.onclick = async () => {
                if (!active() || button.disabled) return;
                button.disabled = true;
                try {
                    await api("fleet-invitations/" + invitation.id + "/accept", "POST");
                    if (!active()) return;
                    // A confirmed acceptance removes the invitation and adds
                    // the fleet membership; neither read repeats the POST.
                    await Promise.all([loadFleetInvitations(page), loadFleetAccounts(1)]);
                } catch (error) {
                    if (active()) notice(error);
                } finally {
                    if (button.isConnected) button.disabled = false;
                }
            };
            list.append(button);
        }
        fleetNavigation(list, j, page, "invitation",
            "Pending fleet invitation pages / صفحات دعوات الأساطيل المعلقة",
            loadFleetInvitations, active);
    } catch (error) {
        if (!active()) return;
        list.replaceChildren(batteryNode("p",
            "Fleet invitations could not be loaded. Retry without repeating acceptance.",
            "تعذر تحميل دعوات الأساطيل. أعد المحاولة دون تكرار القبول."));
        const retry = batteryNode("button", "Retry invitations", "إعادة تحميل الدعوات");
        retry.type = "button";
        retry.dataset.invitationRetry = "true";
        retry.onclick = () => { if (active()) void loadFleetInvitations(page); };
        list.append(retry);
    } finally {
        if (active()) list.removeAttribute("aria-busy");
    }
}
async function fleets() {
    await Promise.all([loadFleetAccounts(), loadFleetInvitations()]);
}
async function fleetDetail(id) {
    if (!user || !Number.isSafeInteger(id) || id <= 0) return;
    fleetDetailRequest?.abort();
    const controller = new AbortController();
    fleetDetailRequest = controller;
    const generation = ++fleetDetailGeneration,
        epoch = client.sessionEpoch, actor = user.id;
    const active = () => generation === fleetDetailGeneration &&
        epoch === client.sessionEpoch && actor === user?.id &&
        fleetDetailRequest === controller && !controller.signal.aborted;
    const d = el("fleet-detail");
    d.setAttribute("aria-busy", "true");
    d.replaceChildren(batteryNode("p", "Loading fleet details…",
        "جارٍ تحميل تفاصيل الأسطول…"));
    try {
    const j = await api("fleets/" + id, "GET", undefined, controller.signal);
    if (!active()) return;
    // Never leave a stale "Loading" pane on return from a background tab.
    if (document.hidden) { d.replaceChildren(); return; }
    if (j?.fleet?.id !== id || !Array.isArray(j.members) ||
        !Array.isArray(j.cost_centers) || !Array.isArray(j.vehicles))
        throw Error("Unreadable fleet details.");
    d.replaceChildren(node("h3", j.fleet.name));
    let owner = j.fleet.owner_id === user.id;
    if (owner) {
        const report = document.createElement("section");
        d.append(report);
        window.EvFleetReport?.mount(report, id);
        for (let m of j.members) {
            let row = ui("p", "Member #{id} · {status}", {
                id: m.user_id,
                status: { translate: m.status },
            });
            if (m.status !== "revoked") {
                let b = ui("button", "Revoke");
                b.type = "button";
                b.onclick = async () => {
                    if (!active() || b.disabled) return;
                    b.disabled = true;
                    try {
                        await api("fleets/" + id + "/members/" + m.id, "DELETE");
                        if (active()) await fleetDetail(id);
                    } catch (e) {
                        if (active()) notice(e);
                    } finally {
                        if (b.isConnected && active()) b.disabled = false;
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
        const inviteButton = ui("button", "Invite existing account");
        inviteButton.type = "submit";
        f.append(i, inviteButton);
        f.onsubmit = async (e) => {
            e.preventDefault();
            if (!active() || inviteButton.disabled) return;
            inviteButton.disabled = true;
            try {
                const result = await api("fleets/" + id + "/invitations",
                    "POST", {email: i.value});
                if (!active()) return;
                notice(result.message);
                await fleetDetail(id);
            } catch (e) {
                if (active()) notice(e);
            } finally {
                if (inviteButton.isConnected && active()) inviteButton.disabled = false;
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
        const createCenter = ui("button", "Add cost center");
        createCenter.type = "submit";
        cf.append(code, name, createCenter);
        cf.onsubmit = async (e) => {
            e.preventDefault();
            if (!active() || createCenter.disabled) return;
            createCenter.disabled = true;
            try {
                await api("fleets/" + id + "/cost-centers", "POST", {
                    code: code.value,
                    name: name.value,
                });
                if (active()) await fleetDetail(id);
            } catch (e) {
                if (active()) notice(e);
            } finally {
                if (createCenter.isConnected && active()) createCenter.disabled = false;
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
        const own = await api("vehicles?page=1", "GET", undefined, controller.signal);
        if (!active()) return;
        if (document.hidden) { d.replaceChildren(); return; }
        if (!fleetPageResponse(own, 1)) throw Error("Unreadable vehicle selection.");
        if (own.data.length) {
            const form = document.createElement("form");
            form.id = "fleet-assign";
            const vehicleLabel = ui("label", "Your vehicle / مركبتك"),
                vehicle = document.createElement("select");
            vehicle.name = "vehicle_id";
            const seen = new Set();
            const addVehicles = (rows) => {
                for (const v of rows) {
                    if (!Number.isSafeInteger(v.id) || v.id <= 0 ||
                        typeof v.name !== "string") throw Error("Unreadable owned vehicle.");
                    if (seen.has(v.id)) continue;
                    seen.add(v.id);
                    vehicle.append(new Option(v.name, String(v.id)));
                }
            };
            addVehicles(own.data);
            vehicleLabel.append(vehicle);
            const centerLabel = ui("label", "Cost center / مركز التكلفة"),
                center = document.createElement("select");
            center.name = "cost_center_id";
            for (const c of j.cost_centers)
                center.append(new Option(c.name, c.id));
            centerLabel.append(center);
            form.append(vehicleLabel, centerLabel);
            let vehiclePage = 1, vehicleLast = own.last_page;
            if (vehiclePage < vehicleLast) {
                const more = batteryNode("button", "More of your vehicles",
                    "المزيد من مركباتك");
                more.type = "button";
                more.dataset.fleetMoreVehicles = "true";
                more.onclick = async () => {
                    if (!active() || more.disabled || vehiclePage >= vehicleLast) return;
                    more.disabled = true;
                    const target = vehiclePage + 1;
                    try {
                        const result = await api("vehicles?page=" + target,
                            "GET", undefined, controller.signal);
                        if (!active() || !more.isConnected) return;
                        if (!fleetPageResponse(result, target))
                            throw Error("Unreadable vehicle selection page.");
                        addVehicles(result.data);
                        vehiclePage = target;
                        vehicleLast = result.last_page;
                        if (vehiclePage >= vehicleLast) {
                            batteryText(more, "All your vehicles loaded",
                                "تم تحميل جميع مركباتك");
                        }
                    } catch (error) {
                        if (active()) notice(error);
                    } finally {
                        if (more.isConnected && active())
                            more.disabled = vehiclePage >= vehicleLast;
                    }
                };
                form.append(more);
            }
            const submit = ui("button", "Assign vehicle");
            submit.type = "submit";
            form.append(submit);
            form.onsubmit = async (event) => {
                event.preventDefault();
                if (!active() || submit.disabled) return;
                submit.disabled = true;
                try {
                    await api("fleets/" + id + "/vehicles", "POST",
                        Object.fromEntries(new FormData(form)));
                    if (active()) await fleetDetail(id);
                } catch (error) {
                    if (active()) notice(error);
                } finally {
                    if (submit.isConnected) submit.disabled = false;
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
    } catch (error) {
        if (!active()) return;
        d.replaceChildren(batteryNode("p",
            "Fleet details could not be loaded. Retry before changing memberships.",
            "تعذر تحميل تفاصيل الأسطول. أعد المحاولة قبل تغيير العضويات."));
        const retry = batteryNode("button", "Retry fleet details", "إعادة تحميل تفاصيل الأسطول");
        retry.type = "button";
        retry.dataset.fleetDetailRetry = "true";
        retry.onclick = () => { if (active()) void fleetDetail(id); };
        d.append(retry);
    } finally {
        if (active()) d.removeAttribute("aria-busy");
    }
}
el("fleet-create").onsubmit = async (e) => {
    e.preventDefault();
    if (!user || fleetCreatePending) return;
    const epoch = client.sessionEpoch, actor = user.id;
    const active = () => epoch === client.sessionEpoch && actor === user?.id;
    const submit = e.target.querySelector("button");
    fleetCreatePending = true;
    if (submit) submit.disabled = true;
    try {
        const created = await api("fleets", "POST", Object.fromEntries(new FormData(e.target)));
        if (!active()) return;
        if (!Number.isSafeInteger(created?.id) || created.id <= 0)
            throw Error("Fleet creation could not be confirmed.");
        fleetPage = 1;
        fleetDetailRequest?.abort();
        fleetDetailGeneration++;
        el("fleet-detail").replaceChildren();
        e.target.reset();
        await fleets();
    } catch (e) {
        if (active()) notice(e);
    } finally {
        if (active()) {
            fleetCreatePending = false;
            if (submit) submit.disabled = false;
        }
    }
};
function verifyRegionalConfigurationReceipt(saved, country, values, reviewed) {
    const expected = values.expected_revision;
    const normalized = {
        currency: values.currency, minor_units: Number(values.minor_units),
        timezone: values.timezone, tax_bps: values.tax_bps === null ? null : Number(values.tax_bps),
    };
    const fail = () => { throw Error("Regional configuration receipt cannot be verified."); };
    if (!Number.isSafeInteger(expected) || expected < 0 ||
        expected >= Number.MAX_SAFE_INTEGER || (expected > 0 && !reviewed)) fail();
    const same = expected > 0 && [false, 0].includes(reviewed.commerce_enabled) &&
        Object.keys(normalized).every(key => reviewed[key] === normalized[key]);
    const revision = expected + (same ? 0 : 1);
    if (!saved || saved.country !== country || saved.configuration_revision !== revision ||
        !Object.keys(normalized).every(key => saved[key] === normalized[key]) ||
        ![false, 0].includes(saved.commerce_enabled)) fail();
    return {...normalized, commerce_enabled: saved.commerce_enabled};
}
el("region-config").onsubmit = async (e) => {
    e.preventDefault();
    if (regionalConfigBusy || user?.role !== "admin") return;
    const form = e.target, epoch = client.sessionEpoch;
    const submit = form.querySelector("button");
    const ticket = {epoch};
    regionalConfigBusy = ticket;
    submit.disabled = true;
    form.inert = true;
    try {
        const v = Object.fromEntries(new FormData(form)), country = v.country;
        delete v.country;
        v.tax_bps = v.tax_bps === "" ? null : v.tax_bps;
        v.expected_revision = form.dataset.expectedCountry === country &&
            form.dataset.expectedEpoch === String(epoch) ? Number(form.dataset.expectedRevision) : 0;
        const reviewed = v.expected_revision > 0 ? JSON.parse(form.dataset.expectedValues ?? "null") : null;
        const saved = await api("regions/" + encodeURIComponent(country), "PUT", v);
        if (regionalConfigBusy !== ticket || epoch !== client.sessionEpoch || user?.role !== "admin") return;
        const accepted = verifyRegionalConfigurationReceipt(saved, country, v, reviewed);
        form.dataset.expectedCountry = country;
        form.dataset.expectedRevision = String(saved.configuration_revision);
        form.dataset.expectedEpoch = String(epoch);
        form.dataset.expectedValues = JSON.stringify(accepted);
        await regions();
        if (epoch === client.sessionEpoch)
            notice("Configuration saved. Commercial activation remains disabled.");
    } catch (error) {
        if (regionalConfigBusy !== ticket || epoch !== client.sessionEpoch) return;
        // A lost response, malformed receipt or conflict can conceal a
        // committed update. Never retain its reviewed authority for retry.
        delete form.dataset.expectedRevision;
        delete form.dataset.expectedCountry;
        delete form.dataset.expectedEpoch;
        delete form.dataset.expectedValues;
        notice(error);
        if (error.status === 409) await regions().catch(notice);
    } finally {
        if (regionalConfigBusy === ticket) {
            regionalConfigBusy = false;
            form.inert = false;
            submit.disabled = false;
        }
    }
};
el("account").onsubmit = async (e) => {
    e.preventDefault();
    if (identityBusy || identityInitializing) return;
    const mode = e.submitter.value,
        values = Object.fromEntries(new FormData(e.target));
    if (!values.second_factor_code?.trim()) delete values.second_factor_code;
    const factorField = e.target.elements.namedItem("second_factor_code");
    if (factorField) factorField.value = "";
    const epoch = clearIdentity();
    setIdentityBusy(true);
    try {
        csrf = (await api("csrf")).token;
        const signedIn = await api(mode, "POST", values);
        if (signedIn?.admin_second_factor_required === true) {
            window.location.assign("/admin/second-factor");
            return;
        }
        pendingLogout(false);
        el("logout-retry").hidden = true;
        e.target.reset();
        csrf = (await api("csrf")).token;
        await identity();
        if (epoch !== client.sessionEpoch) return;
        await stations();
        if (epoch !== client.sessionEpoch) return;
        await regions();
        if (epoch === client.sessionEpoch) notice("Signed in.");
    } catch (error) {
        if (error.status === 422 && error.errors?.second_factor_code) {
            const options = document.querySelector('[data-second-factor-login]');
            if (options) options.open = true;
            notice(document.documentElement.lang === 'ar' ? 'أدخل رمز تحقق جديداً أو رمز استرداد غير مستخدم.' : 'Enter a fresh authenticator code or an unused recovery code.');
        } else notice(error);
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
        signalSessionRevocation();
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
const priceInputs = ["max_rate_per_kwh", "price_currency"].map((name) =>
    el("filters").elements.namedItem(name),
);
for (const input of priceInputs) {
    input.addEventListener("input", () => {
        const any = priceInputs.some((field) => field.value !== "");
        for (const field of priceInputs) field.required = any;
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
        await stationInfo.reload();
        notice("Station registered.");
    } catch (e) {
        notice(e);
    }
};
const vehicleModelCatalog = VehicleModelCatalog.create({
    adminRoot: el("vehicle-model-admin"),
    customerRoot: el("vehicle-model-customer"),
    api,
    actor: () => user,
    epoch: () => client.sessionEpoch,
});
const stationInfo = StationInformation.createVendorWorkspace({
    root: el("station-information-vendor"),
    api,
    actor: () => user,
    epoch: () => client.sessionEpoch,
});
const vehicleManualSoc=VehicleManualSoc.create({
    root:el("vehicle-manual-soc"),
    api,actor:()=>user,epoch:()=>client.sessionEpoch,
});
const driverPreferences = VehicleDriverPreferences.create({
    root: el("driver-preferences-editor"), api, actor: () => user,
    epoch: () => client.sessionEpoch,
});
const vendorWorkspace = createVendorWorkspace({
    root: el("vendor-operations"),
    api,
    ui,
    actor: () => user,
    csrf: () => csrf,
    notice,
    expired: clearIdentity,
});
const riderWorkspace = createRiderAccessWorkspace({
    root: el("rider-access"),
    api,
    ui,
    notice,
    expired: clearIdentity,
});
const chargingReviews = createChargingReviews({
    root: el("charging-reviews"),
    api,
    actor: () => user,
});
const roamingWorkspace = createRoamingWorkspace({
    publicRoot: el("roaming-discovery"),
    privateRoot: el("roaming-operations"),
    api,
    ui,
    actor: () => user,
    notice,
    openInvoice: (id) => invoiceHistory.open(id),
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
setIdentityBusy(false);
(async () => {
    // Resolve the initial account once before rendering its public/private catalog.
    // A second late startup load otherwise replaces a page the visitor selected.
    if (pendingLogout()) {
        await signOut();
        await roamingWorkspace.catalog();
    } else await identity();
    await initialCatalog();
    await regions();
})().catch((e) => notice(e)).finally(() => {
    identityInitializing = false;
    setIdentityBusy(identityBusy);
});

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

function batteryText(element, en, ar) {
    element.dataset.en = en;
    element.dataset.ar = ar;
    element.textContent = EvLocale.language === "ar" ? ar : en;
    return element;
}
function batteryNode(tag, en, ar) { return batteryText(node(tag, ""), en, ar); }
function batteryNotification(message) {
    const row = node("p", "");
    const suffix = message.metadata.vehicle_id + " · " + message.metadata.battery_percent + "%";
    row.append(batteryNode("span", "Simulated low battery · Vehicle " + suffix, "محاكاة بطارية منخفضة · المركبة " + suffix));
    return row;
}


async function discoverBatteryVehicle(id) {
    const preference = await api(`vehicles/${id}/battery-preferences`);
    if (!["CCS2", "Type2", "CHAdeMO"].includes(preference.connector_type)) return;
    el("filters").reset();
    el("filters").elements.namedItem("connector_type").value = preference.connector_type;
    favoriteMode = false;
    renderCatalogMode();
    await stations("?connector_type=" + encodeURIComponent(preference.connector_type));
    location.hash = "#discover";
}
async function batteryEditor(id, row) {
    const epoch = client.sessionEpoch;
    const data = await api(`vehicles/${id}/battery-preferences`);
    if (!row.isConnected || epoch !== client.sessionEpoch) return;
    row.querySelector("[data-battery-editor]")?.remove();
    const box = node("section", ""); box.dataset.batteryEditor = "";
    box.append(batteryNode("p", "Simulator-only alerts. Choose location explicitly in discovery for nearby results.", "تنبيهات محاكاة فقط. اختر الموقع صراحةً في الدليل للنتائج القريبة."));
    let version = data.version, pending = null;
    const enabled = document.createElement("input"); enabled.type = "checkbox"; enabled.checked = data.alerts_enabled;
    const threshold = document.createElement("input"); threshold.type = "number"; threshold.required = true; threshold.min = "1"; threshold.max = "50"; threshold.value = data.threshold_percent;
    const label = (en, ar, input) => {const l = node("label", ""); l.append(batteryNode("span", en, ar), input); box.append(l);};
    label("Enable low-battery notifications", "تفعيل تنبيهات البطارية المنخفضة", enabled);
    label("Threshold % (1–50)", "عتبة التنبيه ٪ (1–50)", threshold);
    const save = batteryNode("button", "Save battery preferences", "حفظ تفضيلات البطارية");
    save.onclick = async () => {
        if (!threshold.reportValidity()) return;
        save.disabled = true;
        try {
            const value = await api(`vehicles/${id}/battery-preferences`, "PUT", {
                alerts_enabled: enabled.checked, threshold_percent: Number(threshold.value), expected_version: version,
            });
            if (epoch === client.sessionEpoch) version = value.version;
        } catch (e) { notice(e); }
        finally { save.disabled = pending !== null; }
    };
    box.append(save);
    if (data.can_simulate) {
        const percent = document.createElement("input"); percent.type = "number"; percent.required = true; percent.min = "0"; percent.max = "100"; percent.value = "15";
        label("Simulated battery % (0–100)", "بطارية محاكاة ٪ (0–100)", percent);
        const simulate = batteryNode("button", "Record simulator observation", "تسجيل قراءة محاكاة");
        const result = node("p", "");
        simulate.onclick = async () => {
            if (pending === null && !percent.reportValidity()) return;
            pending ??= {operation_key: crypto.randomUUID(), battery_percent: Number(percent.value)};
            simulate.disabled = true; save.disabled = enabled.disabled = threshold.disabled = percent.disabled = true;
            try {
                const value = await api(`vehicles/${id}/battery-simulation`, "POST", pending);
                if (epoch !== client.sessionEpoch) return;
                if (value.alerted) batteryText(result, "Simulated alert saved in Notifications.", "تم حفظ تنبيه المحاكاة في الإشعارات.");
                else batteryText(result, "Simulator observation saved; preferences did not trigger an alert.", "تم حفظ قراءة المحاكاة؛ لم تؤد التفضيلات إلى تنبيه.");
                pending = null;
            } catch (e) { notice(e); }
            finally {
                simulate.disabled = false;
                save.disabled = enabled.disabled = threshold.disabled = percent.disabled = pending !== null;
                if (pending) batteryText(simulate, "Retry same simulator observation", "إعادة محاولة قراءة المحاكاة نفسها");
                else batteryText(simulate, "Record simulator observation", "تسجيل قراءة محاكاة");
            }
        };
        box.append(simulate, result);
    }
    const discover = batteryNode("button", "Find compatible chargers", "البحث عن شواحن متوافقة");
    discover.onclick = () => discoverBatteryVehicle(id).catch(notice);
    box.append(discover);
    row.append(box);
}

function resetCustomerMutationState() {
    // Increment tokens before unlocking the new account's form. Late
    // acknowledgements from the previous actor cannot release a newer write.
    vehicleCreateGeneration++;
    profileUpdateGeneration++;
    ticketCreateGeneration++;
    vehicleCreatePending = false;
    profileUpdatePending = false;
    ticketCreatePending = false;
    pendingTicketCreate = null;
    for (const id of ["vehicle-create", "profile-form", "support-create"]) {
        const submit = el(id).querySelector("button");
        if (submit) submit.disabled = false;
    }
}
async function garage(page = garagePage, showNewest = false) {
    if (!user || !Number.isSafeInteger(page) || page < 1) return;
    const owner = client.sessionEpoch + ":" + user.id + ":" + user.role;
    if (garageOwner !== owner) {
        garageOwner = owner;
        garagePage = 1;
        page = 1;
    }
    garageRequest?.abort();
    const controller = new AbortController();
    garageRequest = controller;
    const generation = ++garageGeneration,
        epoch = client.sessionEpoch, actor = user.id;
    const active = () => generation === garageGeneration &&
        epoch === client.sessionEpoch && actor === user?.id &&
        garageRequest === controller && !controller.signal.aborted;
    const list = el("vehicle-list");
    list.setAttribute("aria-busy", "true");
    list.replaceChildren(batteryNode("p", "Loading vehicles…", "جارٍ تحميل المركبات…"));
    try {
    const j = await api("vehicles?page=" + page, "GET", undefined, controller.signal);
    if (!active()) return;
    if (!Array.isArray(j?.data) || !Number.isSafeInteger(j.current_page) ||
        !Number.isSafeInteger(j.last_page) || !Number.isSafeInteger(j.total) ||
        j.current_page !== page || j.last_page < 1 || j.total < 0)
        throw new Error("Unrecognized garage response.");
    if (page > j.last_page || (showNewest && page < j.last_page)) {
        await garage(j.last_page);
        return;
    }
    garagePage = page;
    list.replaceChildren();
    if (!j.data.length)
        list.append(batteryNode("p", "No vehicles on this page.",
            "لا توجد مركبات في هذه الصفحة."));
    for (const v of j.data) {
        const row = node("div", "#" + v.id + " · " + v.name + " · " + v.connector_type);
        if (user.role === "customer") {
            const preferences = batteryNode("button", "Battery preferences", "تفضيلات البطارية");
            preferences.onclick = () => batteryEditor(v.id, row).catch(notice);
            row.append(preferences);
        }
        if (user?.role === "customer") {
            const button = document.createElement("button");
            button.type = "button";
            button.dataset.en = "Driver charging preferences";
            button.dataset.ar = "تفضيلات الشحن";
            button.textContent = document.documentElement.lang === "ar"
                ? button.dataset.ar : button.dataset.en;
            button.onclick = () => driverPreferences.open(v);
            row.append(button);
        }
        row.dataset.garageVehicleId = String(v.id);
        list.append(row);
    }
    vehicleModelCatalog.renderGarage(j.data);
    vehicleManualSoc.renderGarage(j.data);
    el("profile-form").elements.name.value = user.name;
    el("profile-form").elements.locale.value = user.locale;
    const pager = document.createElement("nav");
    pager.className = "pagination";
    pager.dataset.garagePagination = "true";
    pager.setAttribute("aria-label", "Vehicle garage pages / صفحات مرآب المركبات");
    pager.append(ui("span", "Page {page} of {last} · {total} records", {
        page: j.current_page, last: j.last_page, total: j.total,
    }));
    for (const [key, en, ar, target, disabled] of [
        ["previous", "Previous", "السابق", page - 1, page <= 1],
        ["next", "Next", "التالي", page + 1, page >= j.last_page],
        ["refresh", "Refresh", "تحديث", page, false],
    ]) {
        const b = batteryNode("button", en, ar);
        b.type = "button";
        b.dataset.garagePageAction = key;
        b.disabled = disabled;
        b.onclick = () => { if (active()) void garage(target); };
        pager.append(b);
    }
    list.append(pager);
    } catch (error) {
        if (!active()) return;
        list.replaceChildren(batteryNode("p",
            "Garage could not be loaded. Retry before using vehicle-specific tools.",
            "تعذر تحميل المركبات. أعد المحاولة قبل استخدام أدوات المركبة."));
        // Do not retain model or manually reported battery controls from an
        // unverified previous page: the newly requested scope is unknown.
        vehicleModelCatalog.renderGarage([]);
        vehicleManualSoc.renderGarage([]);
        const retry = batteryNode("button", "Retry garage", "إعادة تحميل المركبات");
        retry.type = "button";
        retry.dataset.garageRetry = "true";
        retry.onclick = () => { if (active()) void garage(page, showNewest); };
        list.append(retry);
    } finally {
        if (active()) list.removeAttribute("aria-busy");
    }
}
el("vehicle-create").onsubmit = async (e) => {
    e.preventDefault();
    if (!user || vehicleCreatePending) return;
    const epoch = client.sessionEpoch, actor = user.id;
    const active = () => epoch === client.sessionEpoch && actor === user?.id;
    const token = ++vehicleCreateGeneration;
    // The existing garage form uses the implicit submit type.
    const submit = e.target.querySelector("button");
    vehicleCreatePending = true;
    if (submit) submit.disabled = true;
    try {
        await api(
            "vehicles",
            "POST",
            Object.fromEntries(new FormData(e.target)),
        );
        if (active()) await garage(1, true);
    } catch (error) {
        if (active()) notice(error);
    } finally {
        if (token === vehicleCreateGeneration) {
            vehicleCreatePending = false;
            if (submit?.isConnected) submit.disabled = false;
        }
    }
};
el("profile-form").onsubmit = async (e) => {
    e.preventDefault();
    if (!user || profileUpdatePending) return;
    const epoch = client.sessionEpoch, actor = user.id;
    const active = () => epoch === client.sessionEpoch && actor === user?.id;
    const token = ++profileUpdateGeneration;
    const submit = e.target.querySelector("button");
    profileUpdatePending = true;
    if (submit) submit.disabled = true;
    try {
        const updated = await api(
            "profile",
            "PATCH",
            Object.fromEntries(new FormData(e.target)),
        );
        // A previous account's PATCH must never overwrite a newer login,
        // even if the server completed it after the user switched accounts.
        if (!active()) return;
        user = updated;
        EvLocale.set(user.locale);
        notice("Profile updated.");
        await identity();
    } catch (error) {
        if (active()) notice(error);
    } finally {
        if (token === profileUpdateGeneration) {
            profileUpdatePending = false;
            if (submit?.isConnected) submit.disabled = false;
        }
    }
};
async function refreshSupportTickets(page = supportPage) {
    if (!user || !Number.isSafeInteger(page) || page < 1) return;
    const owner = client.sessionEpoch + ":" + user.id + ":" + user.role;
    if (supportOwner !== owner) {
        supportOwner = owner;
        supportPage = 1;
        page = 1;
    }
    supportRequest?.abort();
    const controller = new AbortController();
    supportRequest = controller;
    const generation = ++supportGeneration, epoch = client.sessionEpoch, actor = user.id;
    const active = () => generation === supportGeneration &&
        epoch === client.sessionEpoch && actor === user?.id &&
        supportRequest === controller && !controller.signal.aborted;
    const list = el("ticket-list");
    list.setAttribute("aria-busy", "true");
    list.replaceChildren(batteryNode("p", "Loading support tickets…", "جارٍ تحميل طلبات الدعم…"));
    try {
        const j = await api("support?page=" + page, "GET", undefined, controller.signal);
        if (!active()) return;
        if (!Array.isArray(j?.data) || !Number.isSafeInteger(j.current_page) ||
            !Number.isSafeInteger(j.last_page) || !Number.isSafeInteger(j.total) ||
            j.current_page !== page || j.last_page < 1 || j.total < 0)
            throw new Error("Unrecognized support ticket response.");
        if (page > j.last_page) {
            await refreshSupportTickets(j.last_page);
            return;
        }
        supportPage = page;
        list.replaceChildren();
        if (!j.data.length)
            list.append(batteryNode("p", "No support tickets on this page.",
                "لا توجد طلبات دعم في هذه الصفحة."));
        for (const t of j.data) {
            const b = ui("button", "#{id} · {subject} · {status}", {
                id: t.id, subject: t.subject, status: { translate: t.status },
            });
            b.dataset.supportTicketId = String(t.id);
            b.type = "button";
            b.onclick = () => {
                if (active()) void ticketDetail(t.id).catch((error) => {
                    if (active()) notice(error);
                });
            };
            list.append(b);
        }
        const pager = document.createElement("nav");
        pager.className = "pagination";
        pager.dataset.supportPagination = "true";
        pager.setAttribute("aria-label", "Support ticket pages / صفحات طلبات الدعم");
        pager.append(ui("span", "Page {page} of {last} · {total} records", {
            page: j.current_page, last: j.last_page, total: j.total,
        }));
        for (const [key, en, ar, target, disabled] of [
            ["previous", "Previous", "السابق", page - 1, page <= 1],
            ["next", "Next", "التالي", page + 1, page >= j.last_page],
            ["refresh", "Refresh", "تحديث", page, false],
        ]) {
            const button = batteryNode("button", en, ar);
            button.type = "button";
            button.dataset.supportPageAction = key;
            button.disabled = disabled;
            button.onclick = () => { if (active()) void refreshSupportTickets(target); };
            pager.append(button);
        }
        list.append(pager);
    } catch (error) {
        if (!active()) return;
        list.replaceChildren(batteryNode("p",
            "Support tickets could not be loaded. Retry without resubmitting any request.",
            "تعذر تحميل طلبات الدعم. أعد المحاولة دون إرسال طلب جديد."));
        const retry = batteryNode("button", "Retry support tickets", "إعادة تحميل طلبات الدعم");
        retry.type = "button";
        retry.dataset.supportRetry = "true";
        retry.onclick = () => { if (active()) void refreshSupportTickets(page); };
        list.append(retry);
    } finally {
        if (active()) list.removeAttribute("aria-busy");
    }
}
async function support() {
    const session = client.sessionEpoch, account = user?.id;
    if (!account) return;
    await refreshSupportTickets();
    if (session !== client.sessionEpoch || account !== user?.id) return;
    if (inboxPreferencesFor !== account) {
        try {
            const prefs = await api("notifications/preferences");
            if (session !== client.sessionEpoch || account !== user?.id) return;
            if (prefs && ["all", "support", "vehicle", "charging", "fleet", "other"].includes(
                prefs.default_category
            ) && typeof prefs.default_unread_only === "boolean" &&
                typeof prefs.remind_unresolved_simulator_faults === "boolean" &&
                prefs.in_app_only === true &&
                prefs.external_delivery_available === false) {
                inboxPreferences = prefs;
                inboxPreferencesFor = account;
                inboxCategory = prefs.default_category;
                inboxUnreadOnly = prefs.default_unread_only;
                inboxPage = 1;
            }
        } catch (error) {
            if (session !== client.sessionEpoch || account !== user?.id) return;
            notice(error);
            // Inbox remains usable even if preferences fail to load.
        }
    }
    await refreshInbox();
}

async function refreshInbox() {
    const session = client.sessionEpoch, account = user?.id;
    const requestGeneration = ++inboxGeneration;
    if (!account) return;
    const notificationFilters = [
        "page=" + inboxPage,
        inboxUnreadOnly ? "unread_only=1" : null,
        inboxCategory !== "all" ? "category=" + encodeURIComponent(inboxCategory) : null,
    ].filter(Boolean).join("&");
    const [n, inbox] = await Promise.all([
        api("notifications" + (notificationFilters ? "?" + notificationFilters : "")),
        api("notifications/summary"),
    ]);
    if (session !== client.sessionEpoch || account !== user?.id ||
        requestGeneration !== inboxGeneration) return;
    const view = el("notifications");
    view.replaceChildren();
    const refreshButton = ui("button", "Refresh notifications");
    refreshButton.id = "notification-refresh";
    refreshButton.type = "button";
    refreshButton.onclick = async () => {
        if (session !== client.sessionEpoch || account !== user?.id) return;
        refreshButton.disabled = true;
        try {
            await refreshInbox();
        } catch (error) {
            if (session === client.sessionEpoch && account === user?.id) notice(error);
        } finally {
            refreshButton.disabled = false;
        }
    };
    view.append(refreshButton);
    const filterButton = ui("button", inboxUnreadOnly ? "Show all notifications" : "Show unread only");
    filterButton.type = "button";
    filterButton.id = "notification-unread-filter";
    filterButton.onclick = async () => {
        if (session !== client.sessionEpoch || account !== user?.id) return;
        inboxUnreadOnly = !inboxUnreadOnly;
        inboxPage = 1;
        filterButton.disabled = true;
        try {
            await refreshInbox();
        } catch (error) {
            if (session === client.sessionEpoch && account === user?.id) notice(error);
        } finally {
            filterButton.disabled = false;
        }
    };
    view.append(filterButton);
    const categorySelect = document.createElement("select");
    categorySelect.id = "notification-category";
    EvLocale.attribute(categorySelect, "aria-label", "Notification category");
    for (const [value, label] of [
        ["all", "All notification categories"],
        ["support", "Support notifications"],
        ["vehicle", "Vehicle notifications"],
        ["charging", "Charging notifications"],
        ["fleet", "Fleet notifications"],
        ["other", "Other notifications"],
    ]) {
        const option = ui("option", label);
        option.value = value;
        categorySelect.append(option);
    }
    categorySelect.value = inboxCategory;
    categorySelect.onchange = async () => {
        if (session !== client.sessionEpoch || account !== user?.id) return;
        inboxCategory = categorySelect.value;
        inboxPage = 1;
        categorySelect.disabled = true;
        try {
            await refreshInbox();
        } catch (error) {
            if (session === client.sessionEpoch && account === user?.id) notice(error);
        } finally {
            categorySelect.disabled = false;
        }
    };
    view.append(categorySelect);
    const emailSettings = ui("button", "Email activity notices");
    emailSettings.type = "button";
    emailSettings.onclick = () => window.EvNotificationEmail.open({host: view, api,
        current: () => session === client.sessionEpoch && account === user?.id});
    view.append(emailSettings);
    if (inboxPreferencesFor === account && inboxPreferences) {
        const prefs = inboxPreferences;
        const settings = document.createElement("form");
        settings.id = "notification-preferences";
        settings.append(ui("h3", "Notification preferences"));
        const defaultLabel = document.createElement("label");
        defaultLabel.append(ui("span", "Default inbox category"));
        const defaultCategory = document.createElement("select");
        defaultCategory.id = "notification-default-category";
        for (const [value, label] of [
            ["all", "All notification categories"],
            ["support", "Support notifications"],
            ["vehicle", "Vehicle notifications"],
            ["charging", "Charging notifications"],
            ["fleet", "Fleet notifications"],
            ["other", "Other operational notifications"],
        ]) {
            const option = ui("option", label);
            option.value = value;
            defaultCategory.append(option);
        }
        defaultCategory.value = prefs.default_category;
        defaultLabel.append(defaultCategory);
        settings.append(defaultLabel);
        const checkLabel = (id, title, enabled) => {
            const label = document.createElement("label");
            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.id = id;
            checkbox.checked = enabled;
            label.append(checkbox, ui("span", title));
            settings.append(label);
            return checkbox;
        };
        const unreadDefault = checkLabel(
            "notification-default-unread", "Start with unread only",
            prefs.default_unread_only,
        );
        const reminders = checkLabel(
            "notification-repeat-reminders",
            "Repeat unresolved simulator fault reminders",
            prefs.remind_unresolved_simulator_faults,
        );
        const delayLabel = document.createElement("label");
        delayLabel.append(ui("span", "Simulated fault reminder delay"));
        const delaySelect = document.createElement("select");
        delaySelect.id = "notification-fault-delay";
        for (const minutes of [15, 30, 60]) {
            const option = ui("option", "After {minutes} minutes", { minutes });
            option.value = String(minutes);
            delaySelect.append(option);
        }
        const currentDelay = prefs.fault_reminder_delay_minutes ?? 15;
        if (![15, 30, 60].includes(currentDelay)) {
            throw new Error("Invalid simulator fault reminder delay");
        }
        delaySelect.value = String(currentDelay);
        delayLabel.append(delaySelect);
        settings.append(delayLabel);
        settings.append(ui("p", "Critical in-app notifications always remain visible. Email notices require separate consent and server configuration."));
        const save = ui("button", "Save notification preferences");
        save.id = "notification-preference-save";
        save.type = "submit";
        settings.append(save);
        settings.onsubmit = async (event) => {
            event.preventDefault();
            if (session !== client.sessionEpoch || account !== user?.id) return;
            save.disabled = true;
            const desired = {
                default_category: defaultCategory.value,
                default_unread_only: unreadDefault.checked,
                remind_unresolved_simulator_faults: reminders.checked,
                fault_reminder_delay_minutes: Number(delaySelect.value),
            };
            try {
                const accepted = await api("notifications/preferences", "PUT", desired);
                if (session !== client.sessionEpoch || account !== user?.id) return;
                if (!accepted || accepted.default_category !== desired.default_category ||
                    accepted.default_unread_only !== desired.default_unread_only ||
                    accepted.remind_unresolved_simulator_faults !== desired.remind_unresolved_simulator_faults ||
                    accepted.fault_reminder_delay_minutes !== desired.fault_reminder_delay_minutes) {
                    throw new Error("Notification preference update could not be confirmed.");
                }
                inboxPreferences = accepted;
                inboxCategory = accepted.default_category;
                inboxUnreadOnly = accepted.default_unread_only;
                inboxPage = 1;
                await refreshInbox();
            } catch (error) {
                if (session === client.sessionEpoch && account === user?.id) notice(error);
            } finally {
                save.disabled = false;
            }
        };
        view.append(settings);
    }
    if (Number.isSafeInteger(inbox.unread_count) && Number.isSafeInteger(inbox.latest_id)) {
        view.append(ui("p", "{count} unread notifications", { count: inbox.unread_count }));
        const byCategory = inbox.unread_by_category;
        if (byCategory && ["support", "vehicle", "charging", "fleet", "other"].every(
            (key) => Number.isSafeInteger(byCategory[key]) && byCategory[key] >= 0
        )) {
            view.append(ui("p", "Unread: Support {support} · Vehicle {vehicle} · Charging {charging} · Fleet {fleet} · Other {other}", byCategory));
        }
        if (inbox.unread_count > 0 && inbox.latest_id > 0) {
            const throughId = inbox.latest_id;
            const button = ui("button", "Mark all current notifications as read");
            button.type = "button";
            button.id = "notification-read-through";
            button.onclick = async () => {
                button.disabled = true;
                try {
                    await api("notifications/read-through", "POST", { through_id: throughId });
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    inboxPage = 1;
                    await refreshInbox();
                } catch (error) {
                    if (session === client.sessionEpoch && account === user?.id) notice(error);
                } finally {
                    button.disabled = false;
                }
            };
            view.append(button);
        }
    }
    for (const m of n.data) {
        const securityLabels = {"account.security.enabled": "Authenticator enabled for your account. If this was not you, change your password and contact support.", "account.security.disabled": "Authenticator disabled for your account. If this was not you, change your password and contact support.", "account.security.recovery_rotated": "Authenticator recovery codes replaced. If this was not you, change your password and contact support.", "account.security.recovery_used": "An authenticator recovery code was used. If this was not you, change your password and contact support."};
        const row = Object.hasOwn(securityLabels, m.kind)
            ? ui("p", securityLabels[m.kind])
            : m.kind === "payment.provider_dispute.test"
            ? ui("p", "Test-provider dispute updated · invoice #{id}", {id: m.metadata?.invoice_id ?? "?"})
            : m.kind === "vehicle.battery.simulated"
            ? batteryNotification(m)
            : m.kind === "charging.fault.simulated"
                ? ui("p", "Observed simulated charger fault · station #{id}", {
                    id: m.metadata?.station_id ?? "?",
                })
                : m.kind === "charging.fault.recovered.simulated"
                    ? ui("p", "Simulator source reported recovery · station #{id}", {
                        id: m.metadata?.station_id ?? "?",
                    })
                    : m.kind === "charging.fault.reminder.simulated"
                        ? ui("p", "Unacknowledged simulated charger fault reminder · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.fault.escalated.simulated"
                        ? ui("p", "Unresolved simulated charger fault escalated to on-call Admin · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.fault.escalation_closed.simulated"
                        ? ui("p", m.metadata?.resolution_source === "source_recovered"
                            ? "Simulator source fault escalation resolved · station #{id}"
                            : "Operator acknowledged simulator fault escalation · station #{id}", {
                                id: m.metadata?.station_id ?? "?",
                            })
                    : m.kind === "charging.repair.opened.simulated"
                        ? ui("p", "Simulated charger repair opened · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.repair.work_started.simulated"
                        ? ui("p", "Simulated charger repair work started · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.repair.reopened.simulated"
                        ? ui("p", "Simulated charger repair reopened · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.repair.review_requested.simulated"
                        ? ui("p", "Simulated charger repair review requested · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.repair.review_closed.simulated"
                        ? ui("p", "Simulator source repair review closed · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                    : m.kind === "charging.operational_case.opened"
                        ? ui("p", "Charging command requires operator recovery · station #{id}", {
                            id: m.metadata?.station_id ?? "?",
                        })
                        : m.kind === "charging.operational_case.source_observed"
                            ? ui("p", "Charging command source evidence recorded · station #{id}", {
                                id: m.metadata?.station_id ?? "?",
                            })
                            : m.kind === "charging.operational_case.review_resolved"
                                ? ui("p", "Source-based operator review closed · station #{id}", {
                                    id: m.metadata?.station_id ?? "?",
                                })
                            : m.kind === "charging.monitor.requires_review.simulated"
                                ? ui("p", "Simulator charger monitor requires review · station #{id}", {
                                    id: m.metadata?.station_id ?? "?",
                                })
                        : m.kind === "charging.monitor.resumed.simulated"
                            ? ui("p", "Simulator charger monitor caught up · station #{id}", {
                                id: m.metadata?.station_id ?? "?",
                            })
                    : m.kind === "fleet.invitation"
                        ? ui("p", "Fleet invitation · fleet #{id}", {
                            id: m.metadata?.fleet_id ?? "?",
                        })
                        : m.kind === "fleet.invitation.accepted"
                            ? ui("p", "Fleet invitation accepted · fleet #{id}", {
                                id: m.metadata?.fleet_id ?? "?",
                            })
                            : m.kind === "fleet.membership.revoked"
                                ? ui("p", "Fleet access revoked · fleet #{id}", {
                                    id: m.metadata?.fleet_id ?? "?",
                                })
                                : m.kind === "fleet.invitation.withdrawn"
                                    ? ui("p", "Fleet invitation withdrawn · fleet #{id}", {
                                        id: m.metadata?.fleet_id ?? "?",
                                    })
                                : m.kind === "fleet.vehicle.assigned"
                                    ? ui("p", "Vehicle assigned to fleet #{id}", {
                                        id: m.metadata?.fleet_id ?? "?",
                                    })
                                    : m.kind === "fleet.vehicle.cost_center_changed"
                                        ? ui("p", "Fleet vehicle cost center changed · fleet #{id}", {
                                            id: m.metadata?.fleet_id ?? "?",
                                        })
                                        : (typeof m.kind === "string" &&
                                            m.kind.startsWith("support."))
                                            ? ui("p", "{kind} · ticket #{id}", {
                                                kind: { translate: m.kind },
                                                id: m.support_ticket_id,
                                            })
                                            : ui("p", "Other operational notification");
        if (m.kind === "vehicle.battery.simulated") {
            const discover = batteryNode("button", "Find compatible chargers", "البحث عن شواحن متوافقة");
            discover.onclick = () => {
                if (session !== client.sessionEpoch || account !== user?.id) return;
                discoverBatteryVehicle(m.metadata.vehicle_id).catch(notice);
            };
            row.append(discover);
        }

        if (Number.isSafeInteger(m.id) && m.id > 0) {
            row.dataset.notificationId = String(m.id);
        }
        if (["charging.fault.simulated", "charging.fault.recovered.simulated",
             "charging.fault.reminder.simulated",
             "charging.fault.escalated.simulated",
             "charging.fault.escalation_closed.simulated"].includes(m.kind)) {
            const meta = m.metadata;
            if (meta?.source === "simulator" && meta?.physical_verification === false &&
                ["company_id", "charger_id", "alert_id"].every(
                    (key) => Number.isSafeInteger(meta[key]) && meta[key] > 0,
                ) && ["vendor", "admin"].includes(user?.role)) {
                const review = ui("button", "Review simulated charger fault");
                review.type = "button";
                review.onclick = async () => {
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    review.disabled = true;
                    try {
                        await vendorWorkspace.openFaultNotice(
                            meta.company_id, meta.charger_id, meta.alert_id,
                        );
                        if (session !== client.sessionEpoch || account !== user?.id) return;
                        el("vendor-operations").scrollIntoView({
                            behavior: "smooth", block: "center",
                        });
                    } catch (error) {
                        if (session === client.sessionEpoch && account === user?.id)
                            notice(error);
                    } finally {
                        review.disabled = false;
                    }
                };
                row.append(review);
            }
        }
        if (["charging.repair.opened.simulated",
             "charging.repair.work_started.simulated",
             "charging.repair.reopened.simulated",
             "charging.repair.review_requested.simulated",
             "charging.repair.review_closed.simulated"].includes(m.kind)) {
            const meta = m.metadata;
            if (meta?.source === "simulator" && meta?.physical_verification === false &&
                meta?.review_only === true &&
                ["company_id", "charger_id", "repair_order_id", "alert_id"].every(
                    (key) => Number.isSafeInteger(meta[key]) && meta[key] > 0,
                ) && ["vendor", "admin"].includes(user?.role)) {
                const review = ui("button", "Review simulated repair work orders");
                review.type = "button";
                review.onclick = async () => {
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    review.disabled = true;
                    try {
                        await vendorWorkspace.openRepairNotice(
                            meta.company_id, meta.charger_id, meta.repair_order_id,
                        );
                        if (session !== client.sessionEpoch || account !== user?.id) return;
                        el("vendor-operations").scrollIntoView({
                            behavior: "smooth", block: "center",
                        });
                    } catch (error) {
                        if (session === client.sessionEpoch && account === user?.id)
                            notice(error);
                    } finally {
                        review.disabled = false;
                    }
                };
                row.append(review);
            }
        }
        if (m.kind === "payment.provider_dispute.test" && m.metadata?.review_only === true &&
            m.metadata?.evidence === "STRIPE_TEST_CONTRACT" && m.metadata?.real_money_moved === false &&
            Number.isSafeInteger(m.metadata?.external_dispute_id) && m.metadata.external_dispute_id > 0) {
            const review = ui("button", "Review provider dispute");
            review.type = "button";
            review.onclick = () => {
                if (session !== client.sessionEpoch || account !== user?.id) return;
                location.hash = "commercial-finance";
                el("commercial-finance").scrollIntoView({behavior: "smooth", block: "center"});
            };
            row.append(review);
        }
        if (["charging.monitor.requires_review.simulated",
             "charging.monitor.resumed.simulated"].includes(m.kind)) {
            const meta = m.metadata;
            if (meta?.source === "simulator" && meta?.physical_verification === false &&
                ["company_id", "charger_id"].every(
                    (key) => Number.isSafeInteger(meta[key]) && meta[key] > 0,
                ) && ["vendor", "admin"].includes(user?.role)) {
                const review = ui("button", "Review simulator source monitor");
                review.type = "button";
                review.onclick = async () => {
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    review.disabled = true;
                    try {
                        await vendorWorkspace.openMonitorNotice(meta.company_id, meta.charger_id);
                        if (session !== client.sessionEpoch || account !== user?.id) return;
                        el("vendor-operations").scrollIntoView({
                            behavior: "smooth", block: "center",
                        });
                    } catch (error) {
                        if (session === client.sessionEpoch && account === user?.id)
                            notice(error);
                    } finally {
                        review.disabled = false;
                    }
                };
                row.append(review);
            }
        }
        if (["charging.operational_case.opened",
             "charging.operational_case.source_observed",
             "charging.operational_case.review_resolved"].includes(m.kind)) {
            const meta = m.metadata;
            if (meta?.physical_verification === false &&
                Number.isSafeInteger(meta.command_id) && meta.command_id > 0 &&
                Number.isSafeInteger(meta.case_id) && meta.case_id > 0 &&
                ["vendor", "admin"].includes(user?.role)) {
                const review = ui("button", "Review charging recovery case");
                review.type = "button";
                review.onclick = async () => {
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    review.disabled = true;
                    try {
                        const actor = user;
                        commandRequest?.abort();
                        const request = new AbortController();
                        commandRequest = request;
                        const list = el("command-list");
                        list.replaceChildren();
                        const card = document.createElement("article");
                        list.append(card);
                        card.append(ui("h3", "Command #{id}", { id: meta.command_id }));
                        // Reads the existing owner-authorized case endpoint.
                        // Any subsequent review or source-inspect action needs
                        // a separate explicit operator form/button interaction.
                        await openOperationalCase(
                            { id: meta.command_id }, card, actor, request.signal,
                        );
                        if (session !== client.sessionEpoch ||
                            account !== user?.id || request.signal.aborted) return;
                        el("command-history").scrollIntoView({
                            behavior: "smooth", block: "center",
                        });
                    } catch (error) {
                        if (session === client.sessionEpoch && account === user?.id)
                            notice(error);
                    } finally {
                        review.disabled = false;
                    }
                };
                row.append(review);
            }
        }
        if (m.kind === "fleet.invitation") {
            const review = ui("button", "Review fleet invitation");
            review.type = "button";
            review.onclick = async () => {
                review.disabled = true;
                try {
                    await fleets();
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    el("fleet-invitations").scrollIntoView({ behavior: "smooth", block: "center" });
                } catch (error) {
                    if (session === client.sessionEpoch && account === user?.id) notice(error);
                } finally {
                    review.disabled = false;
                }
            };
            row.append(review);
        }
        if (Number.isSafeInteger(m.id) && m.id > 0) {
            const markRead = !m.read_at;
            const b = ui("button", markRead ? "Mark read" : "Mark unread");
            b.type = "button";
            b.onclick = async () => {
                if (session !== client.sessionEpoch || account !== user?.id) return;
                b.disabled = true;
                try {
                    await api("notifications/" + m.id + (markRead ? "/read" : "/unread"), "POST");
                    if (session !== client.sessionEpoch || account !== user?.id) return;
                    await refreshInbox();
                } catch (error) {
                    if (session === client.sessionEpoch && account === user?.id) notice(error);
                } finally {
                    b.disabled = false;
                }
            };
            row.append(b);
        }
        view.append(row);
    }
    const pageNumber = Number(n.current_page);
    const lastPage = Number(n.last_page);
    if (Number.isSafeInteger(pageNumber) && pageNumber > 0 &&
        Number.isSafeInteger(lastPage) && lastPage > 0) {
        const pages = document.createElement("nav");
        pages.id = "notification-pagination";
        EvLocale.attribute(pages, "aria-label", "Notification pages");
        const move = async (target, button) => {
            if (session !== client.sessionEpoch || account !== user?.id) return;
            inboxPage = target;
            button.disabled = true;
            try {
                await refreshInbox();
            } catch (error) {
                if (session === client.sessionEpoch && account === user?.id) notice(error);
            } finally {
                button.disabled = false;
            }
        };
        if (pageNumber > 1) {
            const previous = ui("button", "Previous notifications");
            previous.type = "button";
            previous.onclick = () => move(pageNumber - 1, previous);
            pages.append(previous);
        }
        pages.append(ui("span", "Notifications page {page} of {last}", {
            page: pageNumber,
            last: lastPage,
        }));
        if (pageNumber < lastPage) {
            const next = ui("button", "Next notifications");
            next.type = "button";
            next.onclick = () => move(pageNumber + 1, next);
            pages.append(next);
        }
        view.append(pages);
    }
}
async function ticketDetail(id, page = 1, showNewest = false) {
    if (!user || !Number.isSafeInteger(id) || id < 1 ||
        !Number.isSafeInteger(page) || page < 1) return;
    ticketDetailRequest?.abort();
    const controller = new AbortController();
    ticketDetailRequest = controller;
    const generation = ++ticketDetailGeneration,
        epoch = client.sessionEpoch, owner = user.id;
    const active = () => generation === ticketDetailGeneration &&
        epoch === client.sessionEpoch && owner === user?.id &&
        ticketDetailRequest === controller && !controller.signal.aborted;
    const d = el("ticket-detail");
    d.setAttribute("aria-busy", "true");
    d.replaceChildren(batteryNode("p", "Loading ticket messages…", "جارٍ تحميل رسائل الدعم…"));
    try {
    const j = await api("support/" + id + "?page=" + page, "GET", undefined, controller.signal);
    if (!active()) return;
    const messages = j?.messages;
    if (j?.ticket?.id !== id || !Array.isArray(messages?.data) ||
        !Number.isSafeInteger(messages.current_page) ||
        !Number.isSafeInteger(messages.last_page) ||
        !Number.isSafeInteger(messages.total) ||
        messages.current_page !== page || messages.last_page < 1 ||
        messages.total < 0)
        throw new Error("Unrecognized support conversation response.");
    if (page > messages.last_page || (showNewest && page < messages.last_page)) {
        await ticketDetail(id, messages.last_page);
        return;
    }
    d.replaceChildren(
        ui("h3", "{subject} · {status}", {
            subject: j.ticket.subject,
            status: { translate: j.ticket.status },
        }),
    );
    if (!messages.data.length) d.append(batteryNode("p",
        "No visible messages on this page.", "لا توجد رسائل ظاهرة في هذه الصفحة."));
    for (const m of messages.data)
        d.append(node("p", (m.internal ? "INTERNAL · " : "") + m.body));
    const pager = document.createElement("nav");
    pager.className = "pagination";
    pager.dataset.supportMessagePagination = "true";
    pager.setAttribute("aria-label", "Support conversation pages / صفحات محادثة الدعم");
    pager.append(ui("span", "Page {page} of {last} · {total} records", {
        page: messages.current_page, last: messages.last_page, total: messages.total,
    }));
    for (const [key, en, ar, target, disabled] of [
        ["previous", "Previous", "السابق", page - 1, page <= 1],
        ["next", "Next", "التالي", page + 1, page >= messages.last_page],
        ["latest", "Newest messages", "أحدث الرسائل", messages.last_page, page === messages.last_page],
    ]) {
        const b = batteryNode("button", en, ar);
        b.type = "button";
        b.disabled = disabled;
        b.dataset.supportMessagePageAction = key;
        b.onclick = () => { if (active()) void ticketDetail(id, target); };
        pager.append(b);
    }
    d.append(pager);
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
        // Retain the same key across a lost acknowledgement, but generate a
        // fresh key when the user changes the reply body or internal flag.
        let pendingReply = null;
        f.onsubmit = async (e) => {
            e.preventDefault();
            if (!active()) return;
            const payload = {
                body: body.value,
                internal: !!f.elements.internal?.checked,
            };
            const signature = JSON.stringify(payload);
            if (!pendingReply || pendingReply.signature !== signature) {
                pendingReply = {
                    signature,
                    key: crypto.randomUUID().replace(/-/g, ""),
                };
            }
            try {
                await api("support/" + id + "/messages", "POST", {
                    ...payload,
                    idempotency_key: pendingReply.key,
                });
                if (!active()) return;
                pendingReply = null;
                // The API accepted the message. If the subsequent refresh
                // fails, do not leave the just-sent body ready to submit again.
                body.value = "";
                await support();
                await ticketDetail(id, 1, true);
            } catch (e) {
                if (active()) notice(e);
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
        let pendingStatus = null;
        f.onsubmit = async (e) => {
            e.preventDefault();
            if (!active()) return;
            const payload = { status: select.value, reason: reason.value };
            const signature = JSON.stringify(payload);
            if (!pendingStatus || pendingStatus.signature !== signature) {
                pendingStatus = {
                    signature,
                    key: crypto.randomUUID().replace(/-/g, ""),
                };
            }
            try {
                await api("support/" + id + "/status", "PATCH", {
                    ...payload,
                    idempotency_key: pendingStatus.key,
                });
                if (!active()) return;
                pendingStatus = null;
                reason.value = "";
                await support();
                await ticketDetail(id, 1, true);
            } catch (e) {
                if (active()) notice(e);
            }
        };
        d.append(f);
    }
    } catch (error) {
        if (!active()) return;
        d.replaceChildren(batteryNode("p",
            "Support messages could not be loaded. Retry to check the current conversation.",
            "تعذر تحميل رسائل الدعم. أعد المحاولة لعرض المحادثة الحالية."));
        const retry = batteryNode("button", "Retry support messages", "إعادة تحميل رسائل الدعم");
        retry.type = "button";
        retry.dataset.supportMessageRetry = "true";
        retry.onclick = () => { if (active()) void ticketDetail(id, page, showNewest); };
        d.append(retry);
    } finally {
        if (active()) d.removeAttribute("aria-busy");
    }
}
el("support-create").onsubmit = async (e) => {
    e.preventDefault();
    if (!user || ticketCreatePending) return;
    const epoch = client.sessionEpoch, actor = user.id;
    const active = () => epoch === client.sessionEpoch && actor === user?.id;
    const token = ++ticketCreateGeneration;
    const submit = e.target.querySelector("button");
    const payload = Object.fromEntries(new FormData(e.target));
    const signature = JSON.stringify(payload);
    if (!pendingTicketCreate || pendingTicketCreate.signature !== signature ||
        pendingTicketCreate.actor !== actor || pendingTicketCreate.epoch !== epoch) {
        pendingTicketCreate = {
            signature, actor, epoch,
            key: crypto.randomUUID().replace(/-/g, ""),
        };
    }
    const request = pendingTicketCreate;
    ticketCreatePending = true;
    if (submit) submit.disabled = true;
    try {
        const t = await api("support", "POST", {
            ...payload,
            idempotency_key: request.key,
        });
        if (!active()) return;
        if (!Number.isSafeInteger(t?.id) || t.id <= 0) {
            throw new Error("Ticket creation was not confirmed");
        }
        if (pendingTicketCreate === request) pendingTicketCreate = null;
        e.target.reset();
        supportPage = 1;
        await support();
        if (active()) await ticketDetail(t.id);
    } catch (error) {
        if (active()) notice(error);
    } finally {
        if (token === ticketCreateGeneration) {
            ticketCreatePending = false;
            if (submit?.isConnected) submit.disabled = false;
        }
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
    const backupCodes = document.createElement("a");
    backupCodes.href = "/admin/second-factor?manage=1";
    backupCodes.textContent = "Manage authenticator backup codes / إدارة رموز الاسترداد";
    const security = document.createElement("p");
    security.append(backupCodes);
    container.append(metrics, security, tools, scroll);
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
// The connector filter is ephemeral and bound to this exact authenticated
// client epoch and actor object. Recreating the dashboard for a locale or
// history refresh must not convert a typed station filter to a global read.
async function commandHistory(page = 1) {
    if (!["vendor", "admin"].includes(user?.role)) return;
    if (connectorFleetFilter.epoch !== client.sessionEpoch || connectorFleetFilter.actor !== user) {
        connectorFleetFilter = { epoch: client.sessionEpoch, actor: user, draftStation: '', selectedStation: '' };
    }
    const actorFilter = connectorFleetFilter;
    commandRequest?.abort();
    const request = new AbortController(),
        actor = user;
    commandRequest = request;
    el("commands-refresh").disabled = true;
    el("command-list").setAttribute("aria-busy", "true");
    EvLocale.bind(el("commands-state"), "Loading command history…");
    el("charging-dashboard").replaceChildren();
    try {
        const dashboard = await api(
            "charging-operations/dashboard",
            "GET",
            undefined,
            request.signal,
        );
        if (request.signal.aborted || user !== actor) return;
        const metrics = el("charging-dashboard");
        window.renderConnectorFleetAction?.(metrics, { api, actor: () => user, filterState: actorFilter, openDiagnostics: (companyId, chargerId) => vendorWorkspace.openMonitorNotice(companyId, chargerId) });
        const ar = document.documentElement.lang === "ar";
        const line = (en, arabic, value) => {
            const item = document.createElement("p");
            item.dataset.en = `${en}: ${value}`;
            item.dataset.ar = `${arabic}: ${value}`;
            item.textContent = `${ar ? arabic : en}: ${value}`;
            metrics.append(item);
        };
        line("Stations", "المحطات", dashboard.station_count);
        line(
            "Open source-linked sessions",
            "جلسات مرتبطة بالمصدر لم تنته",
            dashboard.active_session_count,
        );
        line(
            "Sessions without source evidence in the last 5 minutes",
            "جلسات بلا أدلة مصدر خلال آخر ٥ دقائق",
            dashboard.missing_recent_evidence_count,
        );
        line(
            "Unresolved recovery cases",
            "حالات الاسترداد غير المعالجة",
            dashboard.unresolved_case_count,
        );
        line(
            "Recorded simulator monitor review flags",
            "سجلات مراقبة المحاكاة التي تتطلب مراجعة",
            dashboard.recorded_monitor_review_count ?? 0,
        );
        line(
            "Unacknowledged recorded charger fault alerts",
            "تنبيهات أعطال الشواحن المسجلة وغير المقَرّة",
            dashboard.unacknowledged_fault_alert_count ?? 0,
        );
        line(
            "Recorded source-recovered faults still awaiting acknowledgment",
            "أعطال سُجل تعافي مصدرها وما زالت تنتظر الإقرار",
            dashboard.source_recovered_unacknowledged_fault_count ?? 0,
        );
        const details = document.createElement("details");
        const summary = document.createElement("summary");
        summary.dataset.en = "Recorded command states";
        summary.dataset.ar = "حالات الأوامر المسجلة";
        summary.textContent = ar
            ? "حالات الأوامر المسجلة"
            : "Recorded command states";
        details.append(summary);
        for (const [status, count] of Object.entries(
            dashboard.command_counts,
        )) {
            const row = document.createElement("p");
            row.textContent = `${status}: ${count}`;
            details.append(row);
        }
        metrics.append(details);
        const warning = document.createElement("p");
        warning.dataset.en =
            "Recorded business evidence, not charger connectivity or verified physical charging. Recent evidence does not prove connector availability.";
        warning.dataset.ar =
            "هذه أدلة أعمال مسجلة، وليست حالة اتصال الشاحن أو إثبات شحن فعلي. حداثة الأدلة لا تثبت توفر الموصل.";
        warning.textContent = ar
            ? "هذه أدلة أعمال مسجلة، وليست حالة اتصال الشاحن أو إثبات شحن فعلي. حداثة الأدلة لا تثبت توفر الموصل."
            : "Recorded business evidence, not charger connectivity or verified physical charging. Recent evidence does not prove connector availability.";
        metrics.append(warning);
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


// One bounded inbox refresh loop, registered once for the page lifetime.
// Never acknowledge messages and never poll a hidden tab or signed-out user.
const NOTIFICATION_REFRESH_INTERVAL_MS = 60000;
function eligibleForInboxRefresh() {
    return !!user && !identityBusy && !pendingLogout() &&
        document.visibilityState === "visible" &&
        !el("support").hidden;
}
function refreshVisibleInbox() {
    if (!eligibleForInboxRefresh()) return;
    refreshInbox().catch((error) => {
        // Existing EvApi prevents late requests from crossing account sessions.
        // Poll failures should not overwrite errors from an explicit action.
        if (user && error?.name !== "SessionChangedError" &&
            error?.status === 401) clearIdentity();
    });
}
setInterval(refreshVisibleInbox, NOTIFICATION_REFRESH_INTERVAL_MS);
document.addEventListener("visibilitychange", refreshVisibleInbox);

// Same-origin tabs share the browser cookie. Broadcast only a disposable signal,
// never account IDs, credentials or private data. Server epochs remain authoritative.
function signalSessionRevocation() {
    try {
        localStorage.setItem('ev.session.revoked', crypto.randomUUID());
        localStorage.removeItem('ev.session.revoked');
    } catch { /* Storage can be unavailable; server revocation still applies. */ }
}
window.addEventListener('storage', event => {
    if (event.key === 'ev.session.revoked' && event.newValue && user) {
        clearIdentity();
        window.location.assign('/?sessions=revoked');
    }
});

window.addEventListener('unified-catalog-open', event => {
    if (!Number.isSafeInteger(event.detail) || event.detail < 1) return;
    favoriteMode = false;
    el('filters').reset();
    stations('?station_id=' + event.detail).then(() => el('stations').scrollIntoView({block:'start'})).catch(error => { el("catalog-state").textContent = error.message; });
});
