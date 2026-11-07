/* Source-qualified vendor feedback summaries. No customer identities or charging claims. */
(function () {
    "use strict";
    const tr = (en, ar, tag = "span") => {
        const node = document.createElement(tag);
        node.dataset.en = en; node.dataset.ar = ar;
        node.textContent = document.documentElement.lang === "ar" ? ar : en;
        return node;
    };
    const row = (en, ar) => tr(en, ar, "p");
    const evidenceNames = {
        SIMULATED_APP_ONLY: ["App simulator", "محاكاة التطبيق"],
        SIMULATED_SOURCE_OBSERVED: ["Observed simulator", "محاكاة مرصودة"],
    };
    function create({root, api, actor, epoch}) {
        let owner = null, request = 0, generation = 0, days = 30;
        const current = (identity, ticket, session) =>
            owner === identity && actor() === identity &&
            generation === ticket && epoch() === session;

        function clear() {
            generation++;
            owner = null;
            root.replaceChildren();
            root.removeAttribute("aria-busy");
        }

        function status(message) {
            let node = root.querySelector("[data-feedback-status]");
            if (!node) {
                node = document.createElement("p");
                node.dataset.feedbackStatus = "1";
                node.setAttribute("role", "status");
                node.setAttribute("aria-live", "polite");
                root.prepend(node);
            }
            node.textContent = message;
        }

        function render(info) {
            if (info.physical_charging_verified !== false ||
                info.source !== "MODERATED_SIMULATOR_REVIEWS_ONLY" ||
                !Number.isInteger(info.total) || !Array.isArray(info.stations))
                throw new Error("Unqualified feedback analytics response");

            root.replaceChildren();
            root.append(tr("Customer feedback analytics", "تحليلات ملاحظات العملاء", "h3"));
            root.append(row(
                "Moderated simulator feedback only. Not physical charging, live station reliability or customer identities.",
                "ملاحظات محاكاة خضعت للمراجعة فقط؛ ليست إثبات شحن فعلي أو اعتمادية مباشرة أو هوية العملاء.",
            ));
            const filter = document.createElement("label");
            filter.append(tr("Latest review event window", "فترة أحداث الملاحظات الأخيرة"));
            const select = document.createElement("select");
            select.setAttribute("aria-label", "Feedback analytics window");
            for (const count of [7, 30, 90]) {
                const option = document.createElement("option");
                option.value = String(count);
                option.textContent = document.documentElement.lang === "ar"
                    ? String(count) + " يومًا" : String(count) + " days";
                select.append(option);
            }
            select.value = String(days);
            select.onchange = () => { days = Number(select.value); load(); };
            filter.append(select);
            root.append(filter);

            root.append(row(
                "Published reviews: " + info.total,
                "الملاحظات المنشورة: " + info.total,
            ));
            root.append(row(
                "Average score: " + (info.average_rating === null ? "—" : info.average_rating + " / 5"),
                "متوسط التقييم: " + (info.average_rating === null ? "—" : info.average_rating + " / 5"),
            ));

            const dist = document.createElement("ul");
            for (let stars = 1; stars <= 5; stars++) {
                const count = Number(info.star_distribution?.[String(stars)] ?? 0);
                dist.append(tr(stars + " stars: " + count, stars + " نجوم: " + count, "li"));
            }
            root.append(dist);

            const source = document.createElement("ul");
            for (const [key, names] of Object.entries(evidenceNames)) {
                const count = Number(info.evidence_counts?.[key] ?? 0);
                source.append(tr(names[0] + ": " + count, names[1] + ": " + count, "li"));
            }
            root.append(source);

            root.append(tr("Top stations by published feedback", "أبرز المحطات حسب الملاحظات المنشورة", "h4"));
            if (!info.stations.length)
                root.append(row("No published feedback in this time window.", "لا توجد ملاحظات منشورة خلال هذه الفترة."));
            const list = document.createElement("ul");
            for (const item of info.stations) {
                if (!Number.isSafeInteger(item.station_id) || !Number.isInteger(item.total)) continue;
                list.append(tr(
                    item.station_name + " — " + item.total + " reviews · " + item.average_rating + " / 5",
                    item.station_name + " — " + item.total + " ملاحظات · " + item.average_rating + " / 5", "li",
                ));
            }
            root.append(list);
            root.append(row(
                "Review data is historical and moderated. It is not a real-time charger availability measurement.",
                "بيانات الملاحظات تاريخية وخضعت للمراجعة؛ ولا تمثل قياسًا مباشرًا لتوفر الشواحن.",
            ));
            const refresh = document.createElement("button");
            refresh.type = "button";
            refresh.append(tr("Refresh feedback", "تحديث الملاحظات"));
            refresh.onclick = load;
            root.append(refresh);
        }

        async function load() {
            if (actor()?.role !== "vendor" || !owner) return;
            const identity = owner, ticket = ++generation, session = epoch();
            request++;
            root.setAttribute("aria-busy", "true");
            status(document.documentElement.lang === "ar" ? "جارٍ تحميل التحليلات..." : "Loading analytics…");
            try {
                const result = await api("vendor/feedback-analytics?days=" + days);
                if (!current(identity, ticket, session)) return;
                render(result);
            } catch (e) {
                if (current(identity, ticket, session))
                    status((e && e.message) || String(e));
            } finally {
                if (current(identity, ticket, session)) root.removeAttribute("aria-busy");
            }
        }
        async function setActor(value) {
            if (value?.role !== "vendor") {
                clear(); return;
            }
            if (owner === value) return;
            clear(); owner = value; await load();
        }
        return {clear, setActor, load};
    }
    window.VendorFeedbackAnalytics = {create};
})();
