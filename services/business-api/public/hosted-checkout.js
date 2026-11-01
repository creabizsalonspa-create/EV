/* Hosted payment controls consume canonical backend state; return URLs prove nothing. */
export function mountHostedCheckout({ container, api, ui, actor }) {
    const owner = actor()?.id,
        abort = new AbortController();
    let busy = false,
        epoch = 0,
        selected = null,
        page = 1;
    const alive = () => container.isConnected && actor()?.id === owner;
    const n = (tag, value) => {
        const e = document.createElement(tag);
        e.textContent = String(value ?? "");
        return e;
    };
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const status = n("p", ""),
        detail = n("div", ""),
        history = n("div", "");
    status.setAttribute("role", "status");
    container.dataset.hostedCheckout = "true";
    container.append(
        t(
            "p",
            "Hosted TEST checkout · authorization and payment are confirmed only by the backend/provider. Returning from a browser does not mean paid. No real money is verified.",
            "دفع مستضاف اختباري · يؤكد الخادم والمزود التفويض والدفع فقط. العودة من المتصفح لا تعني نجاح الدفع. لا يُثبت تحويل أموال حقيقية.",
        ),
        status,
        detail,
        history,
    );
    const guard = new MutationObserver(() => {
        if (!alive()) {
            epoch++;
            abort.abort();
            guard.disconnect();
        }
    });
    guard.observe(container.parentNode || container, { childList: true });
    function button(parent, en, ar, action) {
        const b = t("button", en, ar);
        b.type = "button";
        b.onclick = () => perform(action);
        parent.append(b);
        return b;
    }
    async function perform(action) {
        if (busy || !alive()) return;
        busy = true;
        container.inert = true;
        container.setAttribute("aria-busy", "true");
        status.replaceChildren(
            t(
                "span",
                "Checking authoritative payment state…",
                "جار التحقق من حالة الدفع لدى المصدر…",
            ),
        );
        try {
            await action();
            if (alive()) status.textContent = "";
        } catch (e) {
            if (
                alive() &&
                !["AbortError", "SessionChangedError"].includes(e.name)
            )
                status.replaceChildren(
                    t(
                        "span",
                        "Payment was not confirmed. Refresh the original checkout; never start a replacement while its outcome is uncertain.",
                        "لم يُؤكد الدفع. حدّث عملية الدفع الأصلية ولا تنشئ بديلاً أثناء عدم وضوح النتيجة.",
                    ),
                );
        } finally {
            busy = false;
            if (alive()) {
                container.inert = false;
                container.removeAttribute("aria-busy");
            }
        }
    }
    const keyName = (context) =>
        `creabiz-hosted-v1:${location.origin}:${owner}:${context.scope}:${context.invoice_id}:${context.subscription_id || 0}`;
    function intent(context, retryRow = null) {
        const key = keyName(context),
            raw = localStorage.getItem(key);
        if (raw && !retryRow) {
            const value = JSON.parse(raw);
            if (
                value.scope !== context.scope ||
                value.invoice_id !== context.invoice_id ||
                value.subscription_id !== context.subscription_id ||
                typeof value.idempotency_key !== "string"
            )
                throw Error("Stored checkout identity conflicts.");
            return value;
        }
        if (raw && retryRow && JSON.parse(raw).checkout_id !== retryRow.id)
            throw Error(
                "Recover the latest saved identity before a new attempt.",
            );
        const value = { ...context, idempotency_key: crypto.randomUUID() };
        localStorage.setItem(key, JSON.stringify(value));
        return value;
    }
    async function create(context, retryRow = null) {
        const payload = intent(context, retryRow);
        const row = validate(
            await api(
                "commercial/hosted-checkouts",
                "POST",
                {
                    scope: payload.scope,
                    invoice_id: payload.invoice_id,
                    ...(payload.subscription_id
                        ? { subscription_id: payload.subscription_id }
                        : {}),
                    idempotency_key: payload.idempotency_key,
                    consent: true,
                },
                abort.signal,
            ),
        );
        if (!alive()) return;
        if (
            row.scope !== context.scope ||
            row.invoice_id !== context.invoice_id
        )
            throw Error("Wrong checkout acknowledgement.");
        const saved = JSON.parse(
            localStorage.getItem(keyName(context)) || "null",
        );
        if (saved?.idempotency_key === payload.idempotency_key)
            localStorage.setItem(
                keyName(context),
                JSON.stringify({ ...saved, checkout_id: row.id }),
            );
        draw(row);
        await list();
    }
    function validate(raw) {
        const row = raw.hosted_checkout || raw.checkout || raw;
        if (
            !Number.isSafeInteger(row.id) ||
            row.id < 1 ||
            !Number.isSafeInteger(row.amount_minor) ||
            row.amount_minor < 1 ||
            !Number.isInteger(row.minor_units) ||
            row.minor_units < 0 ||
            row.minor_units > 3 ||
            !/^[A-Z]{3}$/.test(row.currency) ||
            !["charging", "subscription"].includes(row.scope) ||
            row.real_money_moved !== false ||
            row.external_provider_verified !== false ||
            !["MOCK_HOSTED", "STRIPE_TEST_CONTRACT"].includes(row.evidence)
        )
            throw Error("Unsupported hosted payment evidence.");
        return row;
    }
    function draw(row) {
        if (!alive()) return;
        selected = row.id;
        if (document.hidden) return;
        detail.replaceChildren();
        const card = n("article", "");
        card.className = "finance-row";
        card.dataset.hostedId = row.id;
        card.append(
            n("h4", `#${row.id} · ${row.scope} · ${row.status}`),
            n(
                "p",
                `${row.currency} ${EvMoney.format(row.amount_minor, row.minor_units)}`,
            ),
            n("p", `${row.evidence} · ${row.expires_at || "—"}`),
        );
        if (row.hosted_url) {
            const url = new URL(row.hosted_url);
            if (
                url.protocol !== "https:" ||
                url.hostname !== "checkout.stripe.com" ||
                url.username ||
                url.password ||
                url.port ||
                url.search ||
                !/^\/c\/pay\/cs_test_[A-Za-z0-9]+$/.test(url.pathname)
            )
                throw Error("Unapproved hosted destination.");
            const a = t(
                "a",
                "Open secure provider checkout",
                "فتح الدفع الآمن لدى المزود",
            );
            a.href = url.href;
            a.target = "_blank";
            a.rel = "noopener noreferrer";
            a.referrerPolicy = "no-referrer";
            card.append(a);
        }
        if (row.simulation_url && row.can_simulate) {
            const u = new URL(row.simulation_url, location.origin);
            if (
                u.origin !== location.origin ||
                !/^\/payments\/hosted-simulation\/\d+$/.test(u.pathname) ||
                u.search ||
                u.hash
            )
                throw Error("Unapproved simulation route.");
            const link = t(
                "a",
                "Open clearly labeled local simulation",
                "فتح المحاكاة المحلية المميزة",
            );
            link.href = u.href;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            card.append(link);
        }
        button(
            card,
            "Refresh source status",
            "تحديث الحالة من المصدر",
            async () =>
                draw(
                    validate(
                        await api(
                            `commercial/hosted-checkouts/${row.id}`,
                            "GET",
                            undefined,
                            abort.signal,
                        ),
                    ),
                ),
        );
        button(
            card,
            "Inspect provider outcome",
            "فحص النتيجة لدى المزود",
            async () =>
                draw(
                    validate(
                        await api(
                            `commercial/hosted-checkouts/${row.id}/inspect`,
                            "POST",
                            {},
                            abort.signal,
                        ),
                    ),
                ),
        );
        if (row.can_retry_preparation)
            button(
                card,
                "Retry original session preparation",
                "إعادة محاولة إعداد الجلسة الأصلية",
                async () => {
                    if (
                        !confirm(
                            document.documentElement.lang === "ar"
                                ? "إعادة إرسال إعداد الجلسة الأصلية بنفس الهوية؟ لن يتم إنشاء محاولة دفع بديلة."
                                : "Retry the original session preparation with its existing identity? This does not create a replacement payment attempt.",
                        )
                    )
                        return;
                    draw(
                        validate(
                            await api(
                                `commercial/hosted-checkouts/${row.id}/retry-preparation`,
                                "POST",
                                {},
                                abort.signal,
                            ),
                        ),
                    );
                },
            );
        if (row.can_capture)
            button(
                card,
                "Confirm capture of authorized amount",
                "تأكيد تحصيل المبلغ المفوض",
                async () => {
                    if (
                        !confirm(
                            document.documentElement.lang === "ar"
                                ? "تأكيد تحصيل المبلغ المفوض الأصلي الاختباري؟"
                                : "Capture the original authorized TEST amount?",
                        )
                    )
                        return;
                    draw(
                        validate(
                            await api(
                                `commercial/hosted-checkouts/${row.id}/capture`,
                                "POST",
                                {},
                                abort.signal,
                            ),
                        ),
                    );
                },
            );
        if (row.can_cancel)
            button(
                card,
                "Cancel original checkout",
                "إلغاء عملية الدفع الأصلية",
                async () => {
                    if (
                        !confirm(
                            document.documentElement.lang === "ar"
                                ? "إلغاء عملية الدفع الأصلية؟ الإلغاء غير المؤكد يتطلب الفحص."
                                : "Cancel the original checkout? An uncertain cancellation still requires inspection.",
                        )
                    )
                        return;
                    draw(
                        validate(
                            await api(
                                `commercial/hosted-checkouts/${row.id}/cancel`,
                                "POST",
                                {},
                                abort.signal,
                            ),
                        ),
                    );
                },
            );
        if (row.can_retry)
            button(
                card,
                "Start an explicitly confirmed new attempt",
                "بدء محاولة جديدة مؤكدة صراحةً",
                async () => {
                    const fresh = validate(
                        await api(
                            `commercial/hosted-checkouts/${row.id}`,
                            "GET",
                            undefined,
                            abort.signal,
                        ),
                    );
                    if (!fresh.can_retry)
                        throw Error("Original checkout is not terminal.");
                    if (
                        !confirm(
                            document.documentElement.lang === "ar"
                                ? "أكد الخادم انتهاء المحاولة السابقة دون دفع. بدء محاولة اختبار جديدة؟"
                                : "The backend confirms the original attempt ended without payment. Start a new TEST attempt?",
                        )
                    )
                        return;
                    await create(
                        {
                            scope: fresh.scope,
                            invoice_id: fresh.invoice_id,
                            ...(fresh.subscription_id
                                ? { subscription_id: fresh.subscription_id }
                                : {}),
                        },
                        fresh,
                    );
                },
            );
        detail.append(card);
    }
    async function list(number = 1) {
        const version = ++epoch;
        const data = await api(
            `commercial/hosted-checkouts?page=${number}`,
            "GET",
            undefined,
            abort.signal,
        );
        if (!alive() || version !== epoch) return;
        page = number;
        history.replaceChildren(
            t("h4", "Hosted checkout history", "سجل الدفع المستضاف"),
        );
        for (const raw of data.data) {
            const row = validate(raw);
            button(
                history,
                `#${row.id} · ${row.currency} · ${row.status}`,
                `#${row.id} · ${row.currency} · ${row.status}`,
                async () =>
                    draw(
                        validate(
                            await api(
                                `commercial/hosted-checkouts/${row.id}`,
                                "GET",
                                undefined,
                                abort.signal,
                            ),
                        ),
                    ),
            );
        }
        if (!data.data.length)
            history.append(
                t("p", "No hosted checkouts.", "لا توجد عمليات دفع مستضافة."),
            );
        if (page > 1)
            button(history, "Previous", "السابق", () => list(page - 1));
        if (page < data.last_page)
            button(history, "Next", "التالي", () => list(page + 1));
    }
    async function open(context) {
        container.scrollIntoView({ block: "start", behavior: "smooth" });
        return perform(async () => {
            await list();
            if (!context) return;
            detail.replaceChildren(
                t(
                    "h4",
                    "Review original invoice checkout",
                    "مراجعة دفع الفاتورة الأصلية",
                ),
            );
            const source = await api(
                context.scope === "charging"
                    ? `finance/invoices/${context.invoice_id}`
                    : `commercial/subscriptions/${context.subscription_id}`,
                "GET",
                undefined,
                abort.signal,
            );
            if (!alive()) return;
            const invoice =
                context.scope === "charging"
                    ? source.invoice
                    : source.invoices.find((i) => i.id === context.invoice_id);
            const due =
                context.scope === "charging"
                    ? source.amount_due_minor
                    : invoice?.paid
                      ? 0
                      : invoice?.total_minor;
            if (
                !invoice ||
                !Number.isSafeInteger(due) ||
                due < 0 ||
                !Number.isInteger(invoice.minor_units) ||
                invoice.minor_units < 0 ||
                invoice.minor_units > 3
            )
                throw Error("Unsupported source invoice.");
            detail.append(
                n("p", invoice.number),
                n(
                    "p",
                    `${invoice.currency} ${EvMoney.format(due, invoice.minor_units)}`,
                ),
            );
            if (!due) {
                detail.append(
                    t(
                        "p",
                        "No amount is due. Use the original checkout history.",
                        "لا يوجد مبلغ مستحق. استخدم سجل عملية الدفع الأصلية.",
                    ),
                );
                return;
            }

            const l = t(
                "label",
                "I authorize opening the provider TEST checkout for this invoice. Prices and taxes come from the server.",
                "أوافق على فتح دفع اختباري لهذه الفاتورة لدى المزود. يحدد الخادم الأسعار والضرائب.",
            );
            const consent = document.createElement("input");
            consent.type = "checkbox";
            l.prepend(consent);
            detail.append(l);
            const start = button(
                detail,
                "Create or recover original hosted checkout",
                "إنشاء أو استعادة الدفع المستضاف الأصلي",
                async () => {
                    if (!consent.checked) return;
                    await create(context);
                },
            );
            start.disabled = true;
            consent.onchange = () => {
                start.disabled = !consent.checked;
            };
        });
    }
    document.addEventListener(
        "visibilitychange",
        () => {
            if (document.hidden) {
                epoch++;
                detail.replaceChildren();
            }
        },
        { signal: abort.signal },
    );
    window.addEventListener(
        "focus",
        () => {
            if (alive() && selected && !busy)
                perform(async () =>
                    draw(
                        validate(
                            await api(
                                `commercial/hosted-checkouts/${selected}`,
                                "GET",
                                undefined,
                                abort.signal,
                            ),
                        ),
                    ),
                );
        },
        { signal: abort.signal },
    );
    button(
        history,
        "Load hosted checkout history",
        "تحميل سجل الدفع المستضاف",
        () => list(),
    );
    return { open };
}
