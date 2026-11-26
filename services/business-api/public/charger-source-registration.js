/* Owned source metadata registration. No credentials or operation state in storage. */
window.mountChargerSourceRegistration = ({
    root,
    endpoint,
    actor,
    api,
    valid,
    signal,
    refreshAdmission,
    expired,
}) => {
    const owner = actor()?.id,
        role = actor()?.role;
    let generation = 0,
        busy = false,
        frozen = null;
    const lifetime = new AbortController();
    const t = (en, ar) =>
        document.documentElement.lang.startsWith("ar") ? ar : en;
    const node = (tag, en, ar = en) => {
        const n = document.createElement(tag);
        n.textContent = t(en, ar);
        return n;
    };
    const live = () =>
        valid() &&
        root.isConnected &&
        !signal?.aborted &&
        !lifetime.signal.aborted &&
        !document.hidden &&
        actor()?.id === owner &&
        actor()?.role === role;
    const states = {
        not_prepared: ["Not prepared", "لم يتم الإعداد"],
        prepared: [
            "Prepared; no source request sent",
            "تم الإعداد؛ لم يرسل طلب إلى المصدر",
        ],
        registering: [
            "Outcome unresolved; operator inspection required",
            "النتيجة غير محسومة؛ يلزم فحص المشغّل",
        ],
        registered: ["Source record observed", "تمت ملاحظة سجل المصدر"],
        requires_inspection: [
            "Operator inspection required; do not resend",
            "يلزم فحص المشغّل؛ لا تُعد الإرسال",
        ],
    };
    function clear() {
        generation++;
        frozen = null;
        root.replaceChildren();
    }
    function button(parent, en, ar, action) {
        const b = node("button", en, ar);
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            if (busy || !live()) return;
            busy = true;
            b.disabled = true;
            try {
                await action();
            } catch (error) {
                if (!ownedExpiry(error) && live()) failure();
            } finally {
                busy = false;
                if (b.isConnected) b.disabled = false;
            }
        };
        return b;
    }
    function ownedExpiry(error) {
        if (live() && [401, 419].includes(error?.status)) {
            dispose();
            expired?.();
            return true;
        }
        return false;
    }
    function failure() {
        root.replaceChildren(
            node(
                "p",
                "Registration could not be confirmed. Refresh the retained receipt before taking another action.",
                "تعذر تأكيد التسجيل. حدّث الإيصال المحفوظ قبل إجراء آخر.",
            ),
        );
        button(root, "Refresh registration", "تحديث التسجيل", load);
    }
    function checked(data) {
        if (
            !data ||
            !Object.hasOwn(states, data.state) ||
            data.device_commands_sent !== 0 ||
            data.charging_capability_granted !== false ||
            data.charger_hardware_verified !== false ||
            data.source_snapshot_only !== true ||
            data.live_connection_verified !== false ||
            data.automatic_retry_allowed !== false ||
            data.upstream_registration_verified !==
                (data.state === "registered") ||
            data.evidence !==
                (data.state === "registered"
                    ? "authenticated_source_record_created_and_read_back"
                    : "no_verified_registration")
        )
            throw Error("Invalid registration snapshot");
        for (const key of [
            "registration_id",
            "binding_id",
            "profile_id",
            "source_station_id",
        ])
            if (
                data[key] !== null &&
                (!Number.isSafeInteger(data[key]) || data[key] < 1)
            )
                throw Error("Invalid registration identity");
        if (data.state !== "not_prepared" && data.registration_id === null)
            throw Error("Missing registration identity");
        if (
            data.verified_at !== null &&
            (typeof data.verified_at !== "string" ||
                !/^\d{4}-\d{2}-\d{2}T[\d:.]+Z$/.test(data.verified_at) ||
                !Number.isFinite(Date.parse(data.verified_at)))
        )
            throw Error("Invalid source timestamp");
        return data;
    }
    async function load() {
        if (!live()) return;
        const own = ++generation;
        root.replaceChildren(
            node(
                "p",
                "Loading source registration…",
                "جارٍ تحميل تسجيل المصدر…",
            ),
        );
        let data;
        try {
            data = checked(await api(endpoint, "GET", undefined, signal));
        } catch (error) {
            if (live() && own === generation && !ownedExpiry(error)) failure();
            return;
        }
        if (!live() || own !== generation) return;
        root.replaceChildren(
            node(
                "h4",
                "Upstream charger registration",
                "تسجيل الشاحن في النظام المصدر",
            ),
            node("p", ...states[data.state]),
            node(
                "p",
                "A retained source metadata snapshot only. Registration does not send device commands, enable charging, verify live availability or prove physical charger acceptance.",
                "لقطة محفوظة لبيانات المصدر فقط. التسجيل لا يرسل أوامر للجهاز ولا يفعّل الشحن ولا يتحقق من التوفر المباشر أو اعتماد الشاحن الفعلي.",
            ),
        );
        if (data.verified_at) {
            const at = node("time", data.verified_at);
            at.dateTime = data.verified_at;
            at.dir = "ltr";
            root.append(at);
        }
        if (
            data.state === "registered" &&
            data.current_inventory_matches_registration !== true
        )
            root.append(
                node(
                    "p",
                    "Inventory or source configuration requires review. This historical receipt grants no current operational authority.",
                    "يلزم مراجعة المخزون أو إعداد المصدر. هذا الإيصال التاريخي لا يمنح صلاحية تشغيلية حالية.",
                ),
            );
        button(root, "Refresh registration", "تحديث التسجيل", load);
        if (role !== "admin") return;
        if (data.state === "not_prepared" && data.can_prepare === true) {
            const form = document.createElement("form"),
                label = node(
                    "label",
                    "Charger source identity (maximum 36 characters)",
                    "هوية الشاحن في المصدر (36 حرفًا كحد أقصى)",
                ),
                input = document.createElement("input");
            input.value = frozen?.identity ?? "";
            input.readOnly = Boolean(frozen);
            input.required = true;
            input.maxLength = 36;
            input.pattern = "[A-Za-z0-9][A-Za-z0-9_-]{0,35}";
            input.autocomplete = "off";
            input.dir = "ltr";
            label.append(input);
            form.append(label);
            const submit = node(
                "button",
                "Prepare source registration",
                "إعداد تسجيل المصدر",
            );
            submit.type = "submit";
            form.append(submit);
            root.append(form);
            form.onsubmit = async (event) => {
                event.preventDefault();
                if (busy || !live() || !form.reportValidity()) return;
                const identity = input.value;
                if (frozen && frozen.identity !== identity) return;
                frozen ??= { identity, idempotency_key: crypto.randomUUID() };
                const payload = { ...frozen };
                busy = true;
                submit.disabled = true;
                input.disabled = true;
                try {
                    await api(endpoint, "POST", payload, signal);
                    if (live()) await load();
                } catch (error) {
                    if (!ownedExpiry(error) && live()) await load();
                } finally {
                    busy = false;
                    if (submit.isConnected) submit.disabled = false;
                    if (input.isConnected) input.disabled = false;
                }
            };
        }
        if (data.state === "prepared" && data.can_register === true) {
            const label = node(
                    "label",
                    "I confirm creating source metadata only; no charger command or charging activation.",
                    "أؤكد إنشاء بيانات المصدر فقط؛ دون أوامر للشاحن أو تفعيل للشحن.",
                ),
                consent = document.createElement("input");
            consent.type = "checkbox";
            label.prepend(consent);
            root.append(label);
            button(
                root,
                "Register source metadata once",
                "تسجيل بيانات المصدر مرة واحدة",
                async () => {
                    if (!consent.checked) return;
                    const id = data.registration_id;
                    let confirmed = false;
                    try {
                        await api(
                            endpoint + "/" + id + "/register",
                            "POST",
                            undefined,
                            signal,
                        );
                        confirmed = true;
                    } catch (error) {
                        if (ownedExpiry(error)) return;
                    }
                    if (live()) await load();
                    if (confirmed && live() && refreshAdmission)
                        await refreshAdmission();
                },
            );
        }
    }
    const dispose = () => {
        if (lifetime.signal.aborted) return;
        signal?.removeEventListener("abort", dispose);
        clear();
        lifetime.abort();
        observer?.disconnect();
    };
    const observer =
        typeof MutationObserver === "function"
            ? new MutationObserver(() => {
                  if (!root.isConnected) dispose();
              })
            : null;
    observer?.observe(document.documentElement, {
        childList: true,
        subtree: true,
    });
    signal?.addEventListener("abort", dispose, { once: true });
    document.addEventListener(
        "visibilitychange",
        () => {
            if (document.hidden) clear();
            else if (live()) void load();
        },
        { signal: lifetime.signal },
    );
    window.addEventListener("pagehide", dispose, {
        once: true,
        signal: lifetime.signal,
    });
    window.addEventListener(
        "ev:locale",
        () => {
            if (live()) void load();
        },
        { signal: lifetime.signal },
    );
    void load();
    return { dispose };
};
