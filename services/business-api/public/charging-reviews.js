/* Moderated feedback; simulator/source evidence never means physical charging. */
window.createChargingReviews = ({ root, api, actor }) => {
    let generation = 0,
        pending = null,
        version = 0,
        mode = "mine";
    const text = (tag, en, ar) => {
        // Translate only the label caption; replacing a label's textContent
        // would destroy its input/select whenever the locale changes.
        if (tag === "label") {
            const label = document.createElement("label");
            label.append(text("span", en, ar));
            return label;
        }
        const n = document.createElement(tag);
        n.dataset.en = en;
        n.dataset.ar = ar;
        n.textContent = document.documentElement.lang === "ar" ? ar : en;
        return n;
    };
    const raw = (tag, value) => {
        const n = document.createElement(tag);
        n.textContent = value ?? "—";
        return n;
    };
    const button = (parent, en, ar, action, disabled = false) => {
        const b = text("button", en, ar);
        b.type = "button";
        b.disabled = disabled;
        b.onclick = async () => {
            b.disabled = true;
            try {
                await action();
            } finally {
                if (b.isConnected) b.disabled = disabled;
            }
        };
        parent.append(b);
        return b;
    };
    const grade = (value) =>
        value === "SIMULATED_SOURCE_OBSERVED"
            ? text(
                  "p",
                  "Feedback from an observed simulator session. Physical charging unverified.",
                  "ملاحظات من جلسة محاكاة مرصودة. الشحن الفعلي غير متحقق منه.",
              )
            : text(
                  "p",
                  "App simulator feedback. No device or physical charging verification.",
                  "ملاحظات محاكاة التطبيق. لا يوجد تحقق من جهاز أو شحن فعلي.",
              );
    const clear = () => {
        generation++;
        version++;
        pending = null;
        root.replaceChildren();
        root.hidden = true;
    };
    const contract = (row) => {
        if (
            !["SIMULATED_SOURCE_OBSERVED", "SIMULATED_APP_ONLY"].includes(
                row.evidence,
            ) ||
            row.physical_charging_verified !== false ||
            !Number.isInteger(row.rating) ||
            row.rating < 1 ||
            row.rating > 5 ||
            typeof row.body !== "string"
        )
            throw new Error("Invalid review capability");
    };
    const sendPending = async () => {
        if (!pending || pending.actorId !== actor()?.id) return;
        const frozen = pending,
            epoch = generation;
        root.querySelectorAll("button").forEach((b) => (b.disabled = true));
        try {
            const row = await api(frozen.path, frozen.method, frozen.body);
            if (
                epoch !== generation ||
                pending !== frozen ||
                actor()?.id !== frozen.actorId
            )
                return;
            contract(row);
            pending = null;
            await show(mode);
        } catch {
            if (epoch !== generation) return;
            root.replaceChildren(
                text(
                    "p",
                    "Feedback response uncertain. Review history or retry the same request.",
                    "استجابة الملاحظات غير مؤكدة. راجع السجل أو أعد نفس الطلب.",
                ),
            );
            button(
                root,
                "Retry same feedback request",
                "إعادة نفس طلب الملاحظات",
                sendPending,
            );
            button(root, "My feedback", "ملاحظاتي", () => show("mine"));
        }
    };
    const mutate = async (path, method, body) => {
        if (pending || !actor()) return;
        pending = {
            path,
            method,
            body: Object.freeze({ ...body }),
            actorId: actor().id,
        };
        await sendPending();
    };
    function editor(parent, row, terms) {
        root.querySelectorAll("[data-review-editor]").forEach((n) =>
            n.remove(),
        );
        const form = document.createElement("form");
        form.dataset.reviewEditor = "";
        const heading = text(
            "h4",
            "Submit feedback for Admin review",
            "إرسال ملاحظات لمراجعة الإدارة",
        );
        const rating = document.createElement("select");
        rating.required = true;
        rating.name = "rating";
        for (let i = 0; i <= 5; i++) {
            const o = i
                ? raw("option", i + " / 5")
                : text("option", "Choose a rating", "اختر تقييماً");
            o.value = i ? String(i) : "";
            rating.append(o);
        }
        if (row.rating) rating.value = row.rating;
        const score = text("label", "Rating", "التقييم");
        score.append(rating);
        const body = document.createElement("textarea");
        body.required = true;
        body.minLength = 3;
        body.maxLength = 2000;
        body.name = "body";
        body.value = row.body || "";
        const comment = text(
            "label",
            "Feedback (no personal or payment details)",
            "الملاحظات (دون تفاصيل شخصية أو دفع)",
        );
        comment.append(body);
        const agree = document.createElement("input");
        agree.type = "checkbox";
        agree.required = true;
        const consent = text(
            "label",
            "I consent to anonymous public publication after moderation. This is simulator feedback, not physical charging verification.",
            "أوافق على النشر العام دون اسم بعد المراجعة. هذه ملاحظات محاكاة وليست إثبات شحن فعلي.",
        );
        consent.prepend(agree);
        const submit = text("button", "Send for moderation", "إرسال للمراجعة");
        submit.type = "submit";
        submit.disabled = !!pending;
        form.append(heading, score, comment, consent, submit);
        const epoch = generation,
            viewer = actor()?.id;
        form.onsubmit = async (e) => {
            e.preventDefault();
            if (
                epoch !== generation ||
                viewer !== actor()?.id ||
                !agree.checked ||
                pending
            )
                return;
            submit.disabled = true;
            await mutate(
                row.id
                    ? `charging-reviews/${row.id}`
                    : `charging-reviews/sessions/${row.session_id}`,
                row.id ? "PUT" : "POST",
                {
                    request_key: crypto.randomUUID(),
                    ...(row.id ? { expected_version: row.version } : {}),
                    rating: Number(rating.value),
                    body: body.value,
                    consent: true,
                    terms_version: terms,
                },
            );
        };
        parent.append(form);
    }
    async function show(next = "mine", id = null, page = 1) {
        mode = next;
        const epoch = generation,
            request = ++version,
            viewer = actor();
        if (next !== "public" && !viewer) return;
        const current = () =>
            epoch === generation &&
            request === version &&
            actor()?.id === viewer?.id;
        root.hidden = false;
        root.replaceChildren(
            text("h3", "Charging feedback", "ملاحظات الشحن"),
            text("p", "Loading…", "جار التحميل…"),
        );
        try {
            const path =
                next === "public"
                    ? `stations/${id}/reviews?page=${page}`
                    : next === "history"
                      ? `charging-reviews/${id}/history?page=${page}`
                      : `charging-reviews/${next}?page=${page}`;
            const data = await api(path, "GET");
            if (!current()) return;
            root.replaceChildren(
                text("h3", "Charging feedback", "ملاحظات الشحن"),
            );
            if (next === "public") {
                if (data.physical_charging_verified !== false)
                    throw new Error("Unknown review evidence");
                root.append(raw("h4", data.station_name));
                for (const group of data.groups) {
                    root.append(
                        grade(group.evidence),
                        raw(
                            "p",
                            `${group.count} · ${group.count ? (group.rating_sum / group.count).toFixed(1) : "—"} / 5`,
                        ),
                    );
                }
            }
            if (viewer) {
                button(root, "My feedback", "ملاحظاتي", () => show("mine"));
                if (viewer.role === "customer")
                    button(
                        root,
                        "Completed sessions for feedback",
                        "الجلسات المكتملة للملاحظات",
                        () => show("eligible"),
                    );
            }
            if (["vendor", "admin"].includes(viewer?.role))
                button(root, "Feedback operations", "إدارة الملاحظات", () =>
                    show("operations"),
                );
            if (pending && pending.actorId === viewer?.id) {
                root.append(
                    text(
                        "p",
                        "Review history before clearing an uncertain form. Clearing never resends a request.",
                        "راجع السجل قبل مسح نموذج غير مؤكد. المسح لا يعيد إرسال الطلب.",
                    ),
                );
                button(
                    root,
                    "Retry same feedback request",
                    "إعادة نفس طلب الملاحظات",
                    sendPending,
                );
                if (next === "mine" || next === "history")
                    button(
                        root,
                        "Clear pending form after reviewing history",
                        "مسح النموذج المعلق بعد مراجعة السجل",
                        async () => {
                            pending = null;
                            await show("mine");
                        },
                    );
            }
            for (const row of data.data) {
                const card = document.createElement("article");
                card.className = "station-card";
                if (next === "eligible") {
                    card.append(raw("h4", row.station_name));
                    if (row.evidence !== "INELIGIBLE")
                        card.append(grade(row.evidence));
                    if (row.can_review === true && !pending)
                        button(card, "Write feedback", "كتابة ملاحظات", () =>
                            editor(card, row, data.terms_version),
                        );
                    else
                        card.append(
                            text(
                                "p",
                                "No new feedback available. Review existing feedback or source completion.",
                                "لا تتوفر ملاحظات جديدة. راجع الملاحظات الحالية أو اكتمال المصدر.",
                            ),
                        );
                } else if (next === "history") {
                    card.append(raw("p", `${row.version} · ${row.action}`));
                    if (row.body) card.append(raw("p", row.body));
                    if (row.reason) card.append(raw("p", row.reason));
                } else {
                    contract(row);
                    card.append(
                        grade(row.evidence),
                        raw("p", `${row.rating} / 5`),
                        raw("p", row.body),
                        raw("p", row.created_at),
                    );
                    if (row.state) card.append(raw("p", row.state));
                    if (next === "mine") {
                        button(card, "Feedback history", "سجل الملاحظات", () =>
                            show("history", row.id),
                        );
                        if (viewer?.role === "customer")
                            button(
                                card,
                                "Revise feedback",
                                "تعديل الملاحظات",
                                () => editor(card, row, data.terms_version),
                                !!pending,
                            );
                        button(
                            card,
                            "Withdraw publication",
                            "سحب النشر",
                            () =>
                                mutate(`charging-reviews/${row.id}`, "DELETE", {
                                    request_key: crypto.randomUUID(),
                                    expected_version: row.version,
                                }),
                            !!pending,
                        );
                    }
                    if (next === "operations" && data.can_moderate === true) {
                        const reason = document.createElement("textarea");
                        reason.minLength = 5;
                        reason.maxLength = 500;
                        reason.placeholder =
                            document.documentElement.lang === "ar"
                                ? "سبب المراجعة"
                                : "Moderation reason";
                        card.append(reason);
                        for (const action of row.state === "pending"
                            ? ["publish", "reject"]
                            : row.state === "published"
                              ? ["hide"]
                              : []) {
                            const title = {
                                publish: ["Publish", "نشر"],
                                reject: ["Reject", "رفض"],
                                hide: ["Hide publication", "إخفاء النشر"],
                            }[action];
                            button(
                                card,
                                ...title,
                                async () => {
                                    if (reason.value.trim().length < 5) {
                                        reason.focus();
                                        return;
                                    }
                                    await mutate(
                                        `charging-reviews/${row.id}/moderate`,
                                        "POST",
                                        {
                                            request_key: crypto.randomUUID(),
                                            expected_version: row.version,
                                            action,
                                            reason: reason.value.trim(),
                                        },
                                    );
                                },
                                !!pending,
                            );
                        }
                        button(card, "Feedback history", "سجل الملاحظات", () =>
                            show("history", row.id),
                        );
                    }
                }
                root.append(card);
            }
            if (!data.data.length)
                root.append(
                    text(
                        "p",
                        "No feedback in this view.",
                        "لا توجد ملاحظات في هذا العرض.",
                    ),
                );
            button(root, "Refresh", "تحديث", () => show(next, id, page));
            if (page > 1)
                button(root, "Previous", "السابق", () =>
                    show(next, id, page - 1),
                );
            if (page < data.last_page)
                button(root, "Next", "التالي", () => show(next, id, page + 1));
        } catch {
            if (current()) {
                root.replaceChildren(
                    text(
                        "p",
                        "Feedback unavailable. Refresh before another action.",
                        "الملاحظات غير متاحة. حدّث قبل إجراء آخر.",
                    ),
                );
                button(root, "Refresh", "تحديث", () => show(next, id, page));
                if (pending)
                    button(
                        root,
                        "Retry same feedback request",
                        "إعادة نفس طلب الملاحظات",
                        sendPending,
                    );
            }
        }
    }
    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            generation++;
            version++;
            root.replaceChildren();
            root.hidden = true;
        }
    });
    return {
        clear,
        station: (id) => show("public", id),
        setActor: async (user) => {
            clear();
            if (user)
                await show(user.role === "customer" ? "mine" : "operations");
        },
    };
};
