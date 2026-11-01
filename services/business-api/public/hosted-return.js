/* A navigation hint is never payment confirmation. Every amount/state comes from authenticated API. */
(() => {
    const root = document.querySelector("[data-hosted-return]");
    if (!root) return;
    const strings = {
        en: {
            title: "Payment review",
            appearance: "Appearance",
            system: "System",
            light: "Light",
            dark: "Dark",
            notice: "Returning from a payment page does not confirm payment. Only authoritative backend/provider state can confirm the result.",
            refresh: "Refresh source",
            inspect: "Inspect provider outcome",
            capture: "Confirm capture of original authorization",
            synthetic:
                "LOCAL MOCK SIMULATION ONLY. No PSP, card authentication or real payment is verified.",
            consent: "I authorize this synthetic test action.",
            simulate: "Apply synthetic outcome",
            back: "Back to your account",
            app: "Return to application",
            checking: "Checking authoritative source…",
            error: "Could not confirm the original payment. Sign in or refresh the original record; never create a replacement for an uncertain outcome.",
            noReference:
                "No valid original checkout reference. Return to your account and use payment history.",
            captureConfirm: "Capture the original authorized TEST amount?",
            authorized: "Authorized, not yet paid",
            captured: "Captured in TEST accounting",
            open: "Awaiting provider authorization",
            uncertain: "Outcome uncertain; inspect original attempt",
            prepared: "Prepared",
            expired: "Expired",
            cancelled: "Cancelled",
            failed: "Failed",
            needs_review: "Review required",
            declined: "Declined",
            creating: "Contacting provider",
            abandoned: "Abandoned, not paid",
        },
        ar: {
            title: "مراجعة الدفع",
            appearance: "المظهر",
            system: "النظام",
            light: "فاتح",
            dark: "داكن",
            notice: "العودة من صفحة الدفع لا تؤكد نجاحه. تؤكد حالة الخادم والمزود النتيجة فقط.",
            refresh: "تحديث المصدر",
            inspect: "فحص النتيجة لدى المزود",
            capture: "تأكيد تحصيل التفويض الأصلي",
            synthetic:
                "محاكاة محلية فقط. لا يتم التحقق من مزود دفع أو مصادقة بطاقة أو تحويل أموال حقيقية.",
            consent: "أوافق على تنفيذ هذا الإجراء الاختباري المحاكى.",
            simulate: "تطبيق النتيجة المحاكاة",
            back: "العودة إلى الحساب",
            app: "العودة إلى التطبيق",
            checking: "جار التحقق من المصدر…",
            error: "تعذر تأكيد الدفع الأصلي. سجّل الدخول أو حدّث السجل الأصلي ولا تنشئ بديلاً لنتيجة غير مؤكدة.",
            noReference:
                "لا يوجد مرجع دفع أصلي صالح. عد إلى حسابك واستخدم سجل الدفع.",
            captureConfirm: "تأكيد تحصيل المبلغ المفوض الأصلي الاختباري؟",
            authorized: "مفوض ولم يُحصّل بعد",
            captured: "محصل في محاسبة الاختبار",
            open: "بانتظار التفويض لدى المزود",
            uncertain: "النتيجة غير مؤكدة؛ افحص المحاولة الأصلية",
            prepared: "مُعد",
            expired: "منتهٍ",
            cancelled: "ملغى",
            failed: "فشل",
            needs_review: "تتطلب المراجعة",
            declined: "مرفوض",
            creating: "جار الاتصال بالمزود",
            abandoned: "متروك ولم يُدفع",
        },
    };
    let lang = "en",
        row = null,
        busy = false,
        generation = 0,
        controller = new AbortController();
    try {
        if (localStorage.getItem("creabiz.locale") === "ar") lang = "ar";
    } catch {}
    const status = document.getElementById("return-status"),
        source = document.getElementById("return-source");
    const t = (k) => strings[lang][k] || k;
    function translate() {
        document.documentElement.lang = lang;
        document.documentElement.dir = lang === "ar" ? "rtl" : "ltr";
        for (const e of root.querySelectorAll("[data-message]"))
            e.textContent = t(e.dataset.message);
        document.getElementById("return-language").textContent =
            lang === "ar" ? "English" : "العربية";
        if (row) draw(row);
    }
    const simulation = root.dataset.simulationCheckout;
    const reference = new URLSearchParams(location.search).get(
        "hosted_reference",
    );
    const validReference = reference && /^[a-f0-9-]{36}$/i.test(reference);
    history.replaceState(
        null,
        "",
        location.pathname +
            (validReference
                ? "?hosted_reference=" + encodeURIComponent(reference)
                : ""),
    );
    let id = simulation && /^\d+$/.test(simulation) ? Number(simulation) : null;
    function check(data) {
        const r = data.checkout || data;
        if (
            !Number.isSafeInteger(r.id) ||
            r.id < 1 ||
            !Number.isSafeInteger(r.amount_minor) ||
            r.amount_minor < 1 ||
            !/^[A-Z]{3}$/.test(r.currency) ||
            !Number.isInteger(r.minor_units) ||
            r.minor_units < 0 ||
            r.minor_units > 3 ||
            r.real_money_moved !== false
        )
            throw Error("Invalid source");
        if (id && id !== r.id) throw Error("Wrong source");
        id = r.id;
        return r;
    }
    function amount(r) {
        const text = String(r.amount_minor).padStart(r.minor_units + 1, "0");
        return (
            r.currency +
            " " +
            (r.minor_units
                ? text.slice(0, -r.minor_units) +
                  "." +
                  text.slice(-r.minor_units)
                : text)
        );
    }
    function draw(r) {
        row = r;
        if (document.hidden) return;
        source.replaceChildren();
        for (const text of [`#${r.id}`, t(r.status), amount(r), r.evidence]) {
            const e = document.createElement("p");
            e.textContent = text;
            source.append(e);
        }
        document.getElementById("return-capture").hidden = !r.can_capture;
        document.getElementById("return-inspect").disabled = !r.can_inspect;
        document.getElementById("return-simulation").hidden = !(
            simulation && r.can_simulate
        );
    }
    async function request(path, method = "GET", body) {
        const res = await fetch("/api/" + path, {
            method,
            credentials: "same-origin",
            redirect: "error",
            signal: controller.signal,
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector("meta[name=csrf-token]")
                    .content,
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!res.ok) throw Error("Source unavailable");
        return check(await res.json());
    }
    async function run(action) {
        if (busy) return;
        const epoch = ++generation;
        busy = true;
        root.setAttribute("aria-busy", "true");
        for (const b of root.querySelectorAll("button")) b.disabled = true;
        status.textContent = t("checking");
        try {
            const r = await action();
            if (epoch !== generation) return;
            draw(r);
            status.textContent = "";
        } catch {
            if (epoch === generation) {
                source.replaceChildren();
                row = null;
                document.getElementById("return-capture").hidden = true;
                document.getElementById("return-simulation").hidden = true;
                status.textContent = t("error");
            }
        } finally {
            busy = false;
            if (epoch === generation) {
                root.removeAttribute("aria-busy");
                for (const b of root.querySelectorAll("button"))
                    b.disabled = false;
                document.getElementById("return-simulate").disabled =
                    !document.getElementById("return-consent").checked;
                if (row)
                    document.getElementById("return-inspect").disabled =
                        !row.can_inspect;
            }
        }
    }
    function refresh() {
        if (!id && !validReference) {
            status.textContent = t("noReference");
            return;
        }
        return run(() =>
            request(
                id
                    ? `commercial/hosted-checkouts/${id}`
                    : `commercial/hosted-checkouts/by-reference/${reference}`,
            ),
        );
    }
    document.getElementById("return-refresh").onclick = refresh;
    document.getElementById("return-inspect").onclick = () =>
        id &&
        run(() =>
            request(`commercial/hosted-checkouts/${id}/inspect`, "POST", {}),
        );
    document.getElementById("return-capture").onclick = () =>
        id &&
        row?.can_capture &&
        confirm(t("captureConfirm")) &&
        run(() =>
            request(`commercial/hosted-checkouts/${id}/capture`, "POST", {}),
        );
    document.getElementById("return-consent").onchange = (e) => {
        document.getElementById("return-simulate").disabled = !e.target.checked;
    };
    document.getElementById("return-simulate").onclick = () =>
        id &&
        row?.can_simulate &&
        document.getElementById("return-consent").checked &&
        run(() =>
            request(`commercial/hosted-checkouts/${id}/simulate`, "POST", {
                consent: true,
                outcome: document.getElementById("return-outcome").value,
            }),
        );
    document.getElementById("return-language").onclick = () => {
        lang = lang === "en" ? "ar" : "en";
        try {
            localStorage.setItem("creabiz.locale", lang);
        } catch {}
        translate();
    };
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            generation++;
            controller.abort();
            controller = new AbortController();
            source.replaceChildren();
            busy = false;
        } else refresh();
    });
    translate();
    refresh();
})();
