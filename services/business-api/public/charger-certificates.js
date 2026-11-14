/* Managed public certificates. Never stores PEM, keys or reasons in browser storage. */
window.mountChargerCertificates = ({
    root,
    endpoint,
    profileId,
    actor,
    api,
    valid,
    signal,
    refreshAdmission,
}) => {
    const owner = actor()?.id;
    const scope = `ev-charger-certificate:${location.origin}:${owner}:${profileId}`;
    let generation = 0,
        busy = false,
        frozen = null,
        data = null;
    const t = (en, ar) =>
        document.documentElement.lang.startsWith("ar") ? ar : en;
    const el = (tag, text) => {
        const node = document.createElement(tag);
        node.textContent = text;
        return node;
    };
    const live = () =>
        valid() &&
        root.isConnected &&
        !signal.aborted &&
        !document.hidden &&
        actor()?.id === owner;
    const pending = () => {
        const raw = sessionStorage.getItem(scope);
        if (!raw) return null;
        const value = JSON.parse(raw);
        if (
            !/^[a-f0-9-]{36}$/.test(value.key) ||
            !["enroll", "revoke"].includes(value.action)
        )
            throw Error("Invalid operation");
        return value;
    };
    function button(parent, en, ar, fn) {
        const b = el("button", t(en, ar));
        b.type = "button";
        parent.append(b);
        b.onclick = async () => {
            if (busy || !live()) return;
            busy = true;
            b.disabled = true;
            try {
                await fn();
            } catch {
                if (live()) {
                    if (pending()) await load();
                    if (!live()) return;
                    root.append(
                        el(
                            "p",
                            t(
                                "Operation unconfirmed. Retry the exact saved request or resolve/cancel it before another action.",
                                "العملية غير مؤكدة. أعد نفس الطلب المحفوظ أو تحقق منه وألغه قبل إجراء آخر.",
                            ),
                        ),
                    );
                }
            } finally {
                busy = false;
                if (b.isConnected) b.disabled = false;
            }
        };
        return b;
    }
    async function checked(reply, p, body = null) {
        if (
            reply?.actor_id !== owner ||
            reply.profile_id !== profileId ||
            reply.request_key !== p.key ||
            reply.action !== p.action ||
            reply.device_commands_sent !== false ||
            reply.connection_close_requested !== false ||
            reply.gateway_recheck_may_disconnect !== true ||
            !Number.isInteger(reply.result_version)
        )
            throw Error("Unverified certificate receipt");
        if (reply.state === "completed") {
            if (
                reply.certificate?.profile_id !== profileId ||
                reply.certificate.identity !== data.identity ||
                reply.certificate_id !== reply.certificate.id ||
                !/^[a-f0-9]{64}$/.test(reply.certificate.fingerprint)
            )
                throw Error("Unverified certificate");
            if (
                body &&
                (reply.result_version < body.expected_version ||
                    (p.action === "revoke" &&
                        reply.certificate_id !== body.certificate_id))
            )
                throw Error("Changed certificate context");
            if (body && p.action === "enroll") {
                const base64 = body.public_certificate
                    .replace("-----BEGIN CERTIFICATE-----", "")
                    .replace("-----END CERTIFICATE-----", "")
                    .replace(/\s/g, "");
                const der = Uint8Array.from(atob(base64), (char) =>
                    char.charCodeAt(0),
                );
                const hash = [
                    ...new Uint8Array(
                        await crypto.subtle.digest("SHA-256", der),
                    ),
                ]
                    .map((byte) => byte.toString(16).padStart(2, "0"))
                    .join("");
                if (reply.certificate.fingerprint !== hash)
                    throw Error("Wrong certificate bytes");
            }
        } else if (reply.state !== "cancelled")
            throw Error("Unknown operation result");
    }
    async function finish(reply, p, body) {
        await checked(reply, p, body);
        if (!live()) return;
        sessionStorage.removeItem(scope);
        frozen = null;
        await refreshAdmission();
    }
    async function send() {
        const p = pending();
        if (!p || !frozen || frozen.idempotency_key !== p.key)
            throw Error("Resolve saved request");
        const body = structuredClone(frozen);
        const reply = await api(
            `${endpoint}/${p.action}`,
            "POST",
            body,
            signal,
        );
        if (live()) await finish(reply, p, body);
    }
    async function load(page = 1) {
        const epoch = ++generation;
        root.replaceChildren(
            el("h4", t("Charger certificates", "شهادات الشاحن")),
        );
        root.dataset.chargerCertificates = "";
        root.append(
            el(
                "p",
                t(
                    "Public client certificates only. Enrollment changes the pin and invalidates handshake credentials. The gateway may disconnect at its next policy recheck. No charger installation or charging-stop command is sent. Local revocation does not publish a CA CRL/OCSP response.",
                    "شهادات عميل عامة فقط. يغيّر التسجيل بصمة الشهادة ويبطل بيانات الاتصال؛ قد تقطع البوابة الاتصال عند إعادة التحقق. لا يُرسل أمر تثبيت للشاحن أو إيقاف شحن. لا ينشر الإبطال المحلي استجابة إبطال من جهة الإصدار.",
                ),
            ),
        );
        try {
            const result = await api(
                `${endpoint}?page=${page}`,
                "GET",
                undefined,
                signal,
            );
            if (!live() || epoch !== generation) return;
            if (
                result.schema_version !== 1 ||
                result.profile_id !== profileId ||
                !Number.isInteger(result.credential_version) ||
                !Array.isArray(result.data) ||
                result.device_commands_sent !== false
            )
                throw Error("Unverified certificate list");
            data = result;
            const p = pending();
            if (p) {
                root.append(
                    el(
                        "p",
                        t(
                            "Saved certificate operation needs resolution. No automatic resubmission.",
                            "تحتاج عملية الشهادة المحفوظة إلى التحقق. لا إعادة إرسال تلقائية.",
                        ),
                    ),
                );
                if (frozen)
                    button(
                        root,
                        "Retry saved request",
                        "إعادة الطلب المحفوظ",
                        send,
                    );
                button(
                    root,
                    "Resolve result or cancel unissued request",
                    "التحقق من النتيجة أو إلغاء الطلب غير المنفذ",
                    async () => {
                        const reply = await api(
                            endpoint + "/resolve",
                            "POST",
                            {
                                idempotency_key: p.key,
                                expected_actor_id: owner,
                                expected_profile_id: profileId,
                                expected_action: p.action,
                                cancel_unissued: true,
                            },
                            signal,
                        );
                        if (live()) await finish(reply, p);
                    },
                );
            }
            const reason = document.createElement("textarea");
            reason.maxLength = 1000;
            reason.minLength = 10;
            reason.setAttribute(
                "aria-label",
                t("Certificate operation reason", "سبب عملية الشهادة"),
            );
            const consent = document.createElement("input");
            consent.type = "checkbox";
            const label = el(
                "label",
                t(
                    "I confirm the local admission change and credential invalidation.",
                    "أؤكد تغيير سياسة الاتصال المحلية وإبطال بيانات الاتصال.",
                ),
            );
            label.prepend(consent);
            if (result.can_manage && !p) {
                root.append(reason, label);
            }
            async function prepare(action, extra) {
                if (
                    pending() ||
                    !consent.checked ||
                    reason.value.trim().length < 10
                )
                    throw Error("Explicit intent required");
                const value = { key: crypto.randomUUID(), action };
                frozen = {
                    idempotency_key: value.key,
                    expected_actor_id: owner,
                    expected_profile_id: profileId,
                    expected_version: result.credential_version,
                    reason: reason.value.trim(),
                    consent: true,
                    ...extra,
                };
                sessionStorage.setItem(scope, JSON.stringify(value));
                await send();
            }
            if (result.can_manage && result.enrollment_enabled && !p) {
                const pem = document.createElement("textarea");
                pem.maxLength = 16384;
                pem.autocomplete = "off";
                pem.spellcheck = false;
                pem.setAttribute(
                    "aria-label",
                    t(
                        "Public PEM client certificate",
                        "شهادة العميل العامة بصيغة PEM",
                    ),
                );
                const chain = document.createElement("textarea");
                chain.maxLength = 49152;
                chain.autocomplete = "off";
                chain.spellcheck = false;
                chain.setAttribute(
                    "aria-label",
                    t(
                        "Intermediate issuer certificates (optional)",
                        "شهادات جهات الإصدار الوسيطة (اختيارية)",
                    ),
                );
                root.append(
                    pem,
                    chain,
                    el(
                        "p",
                        t(
                            "Submitted issuers must link to a server-pinned root; they do not add trust. Issuer blocks are checked locally without contacting CA revocation services.",
                            "يجب أن ترتبط جهات الإصدار المرسلة بجذر موثوق مهيأ على الخادم؛ لا تضيف ثقة جديدة. تُطبق سياسة الحظر محلياً دون الاتصال بخدمات إبطال جهة الإصدار.",
                        ),
                    ),
                );
                button(
                    root,
                    "Enroll or renew public certificate",
                    "تسجيل الشهادة العامة أو تجديدها",
                    async () => {
                        const certificate = pem.value.trim();
                        if (
                            !certificate.startsWith(
                                "-----BEGIN CERTIFICATE-----",
                            ) ||
                            certificate.includes("PRIVATE KEY")
                        ) {
                            pem.value = "";
                            throw Error("Public certificate only");
                        }
                        const issuers = chain.value.trim();
                        if (issuers.includes("PRIVATE KEY")) {
                            chain.value = "";
                            throw Error("Public issuers only");
                        }
                        await prepare("enroll", {
                            public_certificate: certificate,
                            ...(issuers ? { issuer_chain: issuers } : {}),
                        });
                    },
                );
            }
            const states = {
                registered: ["Registered", "مسجلة"],
                current: ["Current", "حالية"],
                expiring: ["Expiring soon", "تنتهي قريباً"],
                expired: ["Expired", "منتهية"],
                revoked: ["Revoked locally", "مبطلة محلياً"],
                superseded: ["Superseded locally", "استُبدلت محلياً"],
                requires_review: [
                    "Trust requires review",
                    "الثقة تتطلب مراجعة",
                ],
            };
            for (const cert of result.data) {
                const row = document.createElement("article");
                row.append(
                    el(
                        "p",
                        `${cert.identity} · ${t(...(states[cert.state] || states.requires_review))}`,
                    ),
                    el("p", cert.fingerprint),
                    el(
                        "p",
                        `${t("Issuer", "جهة الإصدار")}: ${cert.issuer_fingerprint}`,
                    ),
                    el(
                        "p",
                        `${t("Pinned root", "الجذر الموثوق")}: ${cert.trust_anchor_fingerprint || cert.issuer_fingerprint}`,
                    ),
                    el(
                        "p",
                        `${t("Intermediate certificates", "الشهادات الوسيطة")}: ${cert.chain_length || 0}`,
                    ),
                    el("p", `${cert.not_before} → ${cert.not_after} UTC`),
                );
                if (result.can_manage && !p && cert.state !== "revoked")
                    button(
                        row,
                        "Revoke certificate locally",
                        "إبطال الشهادة محلياً",
                        () => prepare("revoke", { certificate_id: cert.id }),
                    );
                root.append(row);
            }
            if (page > 1)
                button(root, "Previous", "السابق", () => load(page - 1));
            if (page < result.last_page)
                button(root, "Next", "التالي", () => load(page + 1));
        } catch {
            if (live() && epoch === generation) {
                root.replaceChildren(
                    el(
                        "p",
                        t(
                            "Certificate workspace unavailable. No action was confirmed.",
                            "واجهة الشهادات غير متاحة. لم تُؤكد أية عملية.",
                        ),
                    ),
                );
                button(root, "Refresh", "تحديث", () => load(page));
            }
        }
    }
    document.addEventListener(
        "visibilitychange",
        () => {
            if (document.hidden) {
                generation++;
                frozen = null;
                data = null;
                root.replaceChildren();
            } else if (live())
                button(root, "Refresh certificates", "تحديث الشهادات", () =>
                    load(),
                );
        },
        { signal },
    );
    signal.addEventListener(
        "abort",
        () => {
            generation++;
            frozen = null;
            data = null;
            root.replaceChildren();
        },
        { once: true },
    );
    load();
};
