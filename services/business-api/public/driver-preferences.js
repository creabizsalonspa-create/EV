/* Customer-owned driver/vehicle preferences: explicit search filters, never charger control. */
(function () {
    "use strict";
    const words = (en, ar, tag = "span") => {
        const node = document.createElement(tag);
        node.dataset.en = en;
        node.dataset.ar = ar;
        node.textContent = document.documentElement.lang === "ar" ? ar : en;
        return node;
    };
    function create({root, api, actor, epoch}) {
        let seq = 0, renderVersion = 0, owner = null, session = 0, vehicleId = null, data = null;
        const current = ticket =>
            seq === ticket && actor() === owner && epoch() === session && owner?.role === "customer";
        function clear() {
            seq++;renderVersion++;
            owner = data = vehicleId = null;
            root.replaceChildren();
            root.removeAttribute("aria-busy");
        }
        function status(message, isError = false) {
            let box = root.querySelector("[data-preference-status]");
            if (!box) {
                box = document.createElement("p");
                box.dataset.preferenceStatus = "1";
                box.setAttribute("role", "status");
                box.setAttribute("aria-live", "polite");
                root.prepend(box);
            }
            box.textContent = message;
            box.dataset.error = isError ? "true" : "false";
        }
        function label(en, ar, control) {
            const wrapper = document.createElement("label");
            wrapper.append(words(en, ar), control);
            return wrapper;
        }
        function render(vehicle) {
            const renderTicket = seq, renderVehicleId = vehicleId;
            const thisRender = ++renderVersion;
            const renderedForCurrentVehicle = () =>
                current(renderTicket) && vehicleId === renderVehicleId &&
                renderVersion === thisRender;
            root.replaceChildren();
            root.append(words(
                "Driver preferences · " + vehicle.name,
                "تفضيلات السائق · " + vehicle.name, "h3",
            ));
            root.append(words(
                "Preferences are for planning only and do not override charger capability, pricing or live availability.",
                "هذه التفضيلات للتخطيط فقط ولا تغير قدرات الشاحن أو الأسعار أو التوفر المباشر.",
                "p",
            ));
            if (!data) {
                root.append(words("Unable to load preferences.", "تعذر تحميل التفضيلات.", "p"));
                const retry = document.createElement("button");
                retry.type = "button";
                retry.append(words("Retry", "إعادة المحاولة"));
                retry.onclick = () => {
                    if (renderedForCurrentVehicle()) return open(vehicle);
                };
                root.append(retry);
                return;
            }
            const form = document.createElement("form");
            form.className = "vehicle-driver-preferences-form";
            const power = document.createElement("input");
            power.type = "number";
            power.name = "preferred_power_kw";
            power.min = "1";
            power.max = "1000";
            power.step = "1";
            power.value = data.preferred_power_kw ?? "";
            power.placeholder = "50";
            form.append(label("Preferred minimum charger power (kW, optional)", "القدرة المفضلة للشاحن بالكيلوواط (اختياري)", power));
            const goal = document.createElement("select");
            goal.name = "charging_goal";
            for (const [value, en, ar] of [
                ["balanced", "Balanced", "متوازن"],
                ["fastest", "Fastest", "الأسرع"],
                ["lowest_cost", "Prefer lower cost", "تكلفة أقل"],
            ]) {
                const option = document.createElement("option");
                option.value = value;
                option.dataset.en = en;
                option.dataset.ar = ar;
                option.textContent = document.documentElement.lang === "ar" ? ar : en;
                goal.append(option);
            }
            goal.value = data.charging_goal;
            form.append(label("Charging priority", "أولوية الشحن", goal));
            const accessible = document.createElement("input");
            accessible.type = "checkbox";
            accessible.name = "accessible_site_required";
            accessible.checked = data.accessible_site_required === true;
            form.append(label("Prefer accessible sites", "أفضل المواقع المهيأة لذوي الاحتياجات", accessible));
            const parking = document.createElement("input");
            parking.type = "checkbox";
            parking.name = "avoid_paid_parking";
            parking.checked = data.avoid_paid_parking === true;
            form.append(label("Prefer stations without paid parking", "أفضل المحطات دون مواقف مدفوعة", parking));
            const submit = document.createElement("button");
            submit.type = "submit";
            submit.append(words("Save preferences", "حفظ التفضيلات"));
            form.append(submit);
            const apply = document.createElement("button");
            apply.type = "button";
            apply.append(words("Use preferred kW in station search", "استخدم القدرة المفضلة في بحث المحطات"));
            apply.disabled = !(data.preferred_power_kw > 0);
            apply.onclick = () => {
                if (!renderedForCurrentVehicle()) return;
                const input = document.querySelector('#filters [name="min_power_kw"]');
                const filters = document.getElementById("filters");
                if (!input || !filters || !(data.preferred_power_kw > 0)) return;
                input.value = data.preferred_power_kw;
                filters.requestSubmit();
                document.getElementById("discover")?.scrollIntoView({behavior:"smooth"});
            };
            form.append(apply);
            const dismiss = document.createElement("button");
            dismiss.type = "button";
            dismiss.append(words("Close", "إغلاق"));
            dismiss.onclick = () => {
                if (renderedForCurrentVehicle()) clear();
            };
            form.append(dismiss);
            root.append(form);
            status("");
            form.onsubmit = async event => {
                event.preventDefault();
                if (!renderedForCurrentVehicle() || submit.disabled) return;
                const raw = power.value.trim();
                const value = raw === "" ? null : Number(raw);
                if (value !== null && (!Number.isInteger(value) || value < 1 || value > 1000)) {
                    status(document.documentElement.lang === "ar" ? "اختر قدرة من 1 إلى 1000." : "Choose 1–1000 kW.", true);
                    return;
                }
                const payload = {
                    revision: data.revision,
                    preferred_power_kw: value,
                    charging_goal: goal.value,
                    accessible_site_required: accessible.checked,
                    avoid_paid_parking: parking.checked,
                };
                const ticket = seq, original = owner, ownerId = vehicleId, ownerEpoch = session;
                submit.disabled = true;
                status(document.documentElement.lang === "ar" ? "جارٍ الحفظ..." : "Saving…");
                try {
                    const saved = await api("vehicles/" + ownerId + "/driver-preferences", "PUT", payload);
                    if (!current(ticket) || owner !== original || session !== ownerEpoch) return;
                    data = saved;
                    render(vehicle);
                    status(document.documentElement.lang === "ar" ? "تم حفظ التفضيلات." : "Preferences saved.");
                } catch (error) {
                    if (!current(ticket)) return;
                    status(error.status === 409
                        ? (document.documentElement.lang === "ar"
                            ? "تغيرت التفضيلات في جهاز آخر. حدث قبل الحفظ."
                            : "Preferences changed on another device. Reload before saving.")
                        : String(error.message || error), true);
                } finally {
                    if (current(ticket)) submit.disabled = false;
                }
            };
        }
        async function open(vehicle) {
            if (actor()?.role !== "customer") return;
            const ticket = ++seq;
            owner = actor();
            session = epoch();
            vehicleId = Number(vehicle.id);
            data = null;
            root.replaceChildren();
            root.setAttribute("aria-busy", "true");
            status(document.documentElement.lang === "ar" ? "جارٍ تحميل تفضيلات المركبة..." : "Loading vehicle preferences…");
            try {
                const response = await api("vehicles/" + vehicleId + "/driver-preferences");
                if (!current(ticket)) return;
                data = response;
                render(vehicle);
            } catch (error) {
                if (!current(ticket)) return;
                render(vehicle);
                status(String(error.message || error), true);
            } finally {
                if (current(ticket)) root.removeAttribute("aria-busy");
            }
        }
        return {clear, open};
    }
    window.VehicleDriverPreferences = {create};
})();
