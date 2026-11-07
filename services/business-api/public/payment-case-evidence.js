/* Private internal case collaboration. Provider submission is a separate workflow. */
window.mountPaymentCaseEvidence = ({ container, api, ui, actor }) => {
    const owner = actor()?.id,
        controller = new AbortController();
    let epoch = 0,
        busy = false,
        target = 1;
    const drafts = new Map();
    const alive = () => container.isConnected && actor()?.id === owner;
    const observer = new MutationObserver(() => {
        if (!alive()) {
            epoch++;
            controller.abort();
            drafts.clear();
            container.replaceChildren();
            observer.disconnect();
        }
    });
    observer.observe(container.parentNode || container, { childList: true });
    const t = (tag, en, ar) => {
        window.EV_MESSAGES[en] ||= { en, ar };
        return ui(tag, en);
    };
    const literal = (tag, value) => {
        const e = document.createElement(tag);
        e.textContent = String(value ?? "");
        return e;
    };
    const content = document.createElement("div"),
        status = literal("p", "");
    status.setAttribute("role", "status");
    container.dataset.paymentCaseEvidence = "true";
    container.append(
        t(
            "p",
            "Private internal evidence only. Files are shared with the invoice customer, vendor and authorized reviewers. This does not submit a card-network chargeback or prove a refund.",
            "أدلة داخلية خاصة فقط. تُشارك الملفات مع عميل الفاتورة والمورد والمراجعين المصرح لهم. لا تُرسل اعتراضاً لشبكة بطاقات ولا تثبت استرداداً.",
        ),
        status,
        content,
    );
    const button = (parent, en, ar, action) => {
        const b = t("button", en, ar);
        b.type = "button";
        b.addEventListener("click", () => perform(action));
        parent.append(b);
        return b;
    };
    async function request(path, method = "GET", data) {
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        const r = await api(path, method, data, controller.signal);
        if (!alive()) throw new DOMException("Session changed", "AbortError");
        return r;
    }
    async function perform(action) {
        if (busy || !alive()) return;
        busy = true;
        container.inert = true;
        container.setAttribute("aria-busy", "true");
        try {
            status.textContent = "";
            await action();
        } catch (e) {
            if (
                alive() &&
                !["AbortError", "SessionChangedError"].includes(e.name)
            )
                status.textContent =
                    e.status === 401 || e.status === 419
                        ? "Please sign in again. / يرجى تسجيل الدخول مجدداً."
                        : "Request unconfirmed or unavailable. Refresh before retrying. / الطلب غير مؤكد أو غير متاح. حدّث قبل إعادة المحاولة.";
        } finally {
            busy = false;
            if (alive()) {
                container.inert = false;
                container.removeAttribute("aria-busy");
            }
        }
    }
    const fields = ["kind", "mime_type", "size_bytes", "sha256"];
    const valid = (r, id) =>
        r &&
        Number.isSafeInteger(r.id) &&
        r.case_id === id &&
        r.evidence_status === "PRIVATE_REVIEW_ONLY" &&
        r.provider_submitted === false &&
        /^[a-f0-9]{64}$/.test(r.sha256) &&
        ["application/pdf", "image/png", "image/jpeg"].includes(r.mime_type) &&
        Number.isSafeInteger(r.size_bytes) &&
        r.size_bytes > 0 &&
        r.size_bytes <= 4194304;
    async function evidence(id, number = 1) {
        const version = ++epoch;
        const page = await request(
            `commercial/cases/${id}/evidence?page=${number}`,
        );
        if (!alive() || version !== epoch) return;
        if (!Array.isArray(page.data) || page.data.some((r) => !valid(r, id)))
            throw new Error("Invalid private metadata");
        content.replaceChildren();
        button(content, "Back to disputes", "العودة إلى النزاعات", () =>
            load(target),
        );
        content.append(literal("h4", `#${id}`));
        for (const r of page.data) {
            const card = document.createElement("div");
            card.className = "finance-row";
            card.append(
                literal("p", `#${r.id} · ${r.kind} · ${r.created_at}`),
                t(
                    "p",
                    "Private review only; not submitted to provider",
                    "مراجعة خاصة فقط؛ لم تُرسل للمزود",
                ),
            );
            button(
                card,
                "Download verified private evidence",
                "تنزيل دليل خاص متحقق منه",
                async () => {
                    const response = await fetch(
                        `/api/commercial/cases/${id}/evidence/${r.id}`,
                        {
                            credentials: "same-origin",
                            cache: "no-store",
                            redirect: "error",
                            headers: { Accept: "application/octet-stream" },
                            signal: controller.signal,
                        },
                    );
                    if (!alive() || version !== epoch) return;
                    if ([401, 419].includes(response.status)) {
                        content.replaceChildren();
                        drafts.clear();
                        epoch++;
                        await request("me");
                        throw Object.assign(new Error("Session expired"), {
                            status: response.status,
                        });
                    }
                    if (!response.ok)
                        throw new Error("Private download unavailable");
                    if (
                        response.headers.get("content-type")?.split(";")[0] !==
                        r.mime_type
                    )
                        throw new Error("Wrong type");
                    const declared = Number(
                        response.headers.get("content-length"),
                    );
                    if (declared > 4194304) throw new Error("Too large");
                    const reader = response.body.getReader(),
                        chunks = [];
                    let size = 0;
                    while (true) {
                        const item = await reader.read();
                        if (item.done) break;
                        size += item.value.length;
                        if (size > 4194304) {
                            await reader.cancel();
                            throw new Error("Too large");
                        }
                        chunks.push(item.value);
                    }
                    const bytes = new Uint8Array(size);
                    let at = 0;
                    for (const c of chunks) {
                        bytes.set(c, at);
                        at += c.length;
                    }
                    const hash = [
                        ...new Uint8Array(
                            await crypto.subtle.digest("SHA-256", bytes),
                        ),
                    ]
                        .map((n) => n.toString(16).padStart(2, "0"))
                        .join("");
                    if (!alive() || version !== epoch) return;
                    if (size !== r.size_bytes || hash !== r.sha256)
                        throw new Error("Integrity failure");
                    const url = URL.createObjectURL(
                            new Blob([bytes], { type: r.mime_type }),
                        ),
                        a = document.createElement("a");
                    a.href = url;
                    a.download = `case-evidence-${r.id}.${{ "application/pdf": "pdf", "image/png": "png", "image/jpeg": "jpg" }[r.mime_type]}`;
                    a.rel = "noopener";
                    document.body.append(a);
                    a.click();
                    a.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                },
            );
            content.append(card);
        }
        const form = document.createElement("form"),
            select = document.createElement("select"),
            file = document.createElement("input"),
            consent = document.createElement("input");
        select.name = "kind";
        for (const [key, en, ar] of [
            ["service_record", "Service record", "سجل الخدمة"],
            ["receipt", "Receipt", "إيصال"],
            ["correspondence", "Correspondence", "مراسلات"],
            ["other", "Other evidence", "دليل آخر"],
        ]) {
            const o = t("option", en, ar);
            o.value = key;
            select.append(o);
        }
        file.type = "file";
        file.name = "document";
        file.accept = "application/pdf,image/png,image/jpeg";
        file.required = true;
        consent.type = "checkbox";
        consent.name = "consent";
        consent.required = true;
        const category = t("label", "Evidence category", "فئة الدليل");
        category.append(select);
        const choose = t(
            "label",
            "Choose private evidence up to4 MiB",
            "اختيار دليل خاص حتى4 MiB",
        );
        choose.append(file);
        const permission = t(
            "label",
            "I consent to sharing this file with the invoice parties and authorized reviewers.",
            "أوافق على مشاركة الملف مع أطراف الفاتورة والمراجعين المصرح لهم.",
        );
        permission.append(consent);
        form.append(category, choose, permission);
        const original = drafts.get(id);
        if (original) {
            select.value = original.kind;
            select.disabled = true;
            form.append(
                t(
                    "p",
                    "Unconfirmed upload: select the original file and explicitly retry its saved identity.",
                    "رفع غير مؤكد: اختر الملف الأصلي وأعد المحاولة صراحةً بمعرفه المحفوظ.",
                ),
            );
        }
        const submit = t("button", "Upload private evidence", "رفع دليل خاص");
        submit.type = "submit";
        form.append(submit);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            perform(async () => {
                if (!consent.checked) throw new Error("Consent required");
                const selected = file.files?.[0];
                if (
                    !selected ||
                    !selected.size ||
                    selected.size > 4194304 ||
                    !["application/pdf", "image/png", "image/jpeg"].includes(
                        selected.type,
                    )
                )
                    throw new Error("Unsupported file");
                const snapshot = await selected.arrayBuffer();
                const digest = [
                    ...new Uint8Array(
                        await crypto.subtle.digest("SHA-256", snapshot),
                    ),
                ]
                    .map((n) => n.toString(16).padStart(2, "0"))
                    .join("");
                if (!alive() || version !== epoch) return;
                const meta = {
                    kind: select.value,
                    mime_type: selected.type,
                    size_bytes: selected.size,
                    sha256: digest,
                };
                let intent = drafts.get(id);
                if (intent && fields.some((k) => intent[k] !== meta[k]))
                    throw new Error("Original file required");
                if (!intent) {
                    intent = { ...meta, key: crypto.randomUUID() };
                    drafts.set(id, intent);
                }
                select.disabled = true;
                const data = new FormData();
                data.set("kind", intent.kind);
                data.set("consent", "1");
                data.set("idempotency_key", intent.key);
                data.set(
                    "document",
                    new Blob([snapshot], { type: selected.type }),
                    "evidence." +
                        {
                            "application/pdf": "pdf",
                            "image/png": "png",
                            "image/jpeg": "jpg",
                        }[selected.type],
                );
                const result = await request(
                    `commercial/cases/${id}/evidence`,
                    "POST",
                    data,
                );
                if (
                    !valid(result, id) ||
                    fields.some((k) => result[k] !== intent[k])
                )
                    throw new Error("Invalid receipt");
                drafts.delete(id);
                await evidence(id, number);
            });
        });
        if (page.can_upload === true || drafts.has(id)) content.append(form);
        if (number > 1)
            button(content, "Previous", "السابق", () =>
                evidence(id, number - 1),
            );
        if (number < page.last_page)
            button(content, "Next", "التالي", () => evidence(id, number + 1));
    }
    async function load(number = 1) {
        target = number;
        const version = ++epoch;
        const page = await request(`commercial/cases?page=${number}`);
        if (!alive() || version !== epoch) return;
        content.replaceChildren();
        for (const row of page.data) {
            const card = document.createElement("div");
            card.className = "finance-row";
            card.dataset.caseId = String(row.id);
            card.append(
                literal("p", `#${row.id} · ${row.status}`),
                literal("p", row.reason),
            );
            button(
                card,
                "Open private case evidence",
                "فتح أدلة النزاع الخاصة",
                () => evidence(row.id),
            );
            content.append(card);
        }
        if (!page.data.length)
            content.append(t("p", "No records.", "لا توجد سجلات."));
        if (number > 1)
            button(content, "Previous", "السابق", () => load(number - 1));
        if (number < page.last_page)
            button(content, "Next", "التالي", () => load(number + 1));
    }
    button(
        container,
        "Open internal dispute evidence",
        "فتح أدلة النزاعات الداخلية",
        () => load(),
    );
};
