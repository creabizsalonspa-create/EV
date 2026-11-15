(() => {
    // datetime-local has no timezone. Resolve a station wall clock to one real UTC instant.
    // Reject gaps and repeated DST minutes rather than guessing the wrong slot.
    function validZone(value) {
        if (typeof value !== "string" || !value.trim() || value.length > 100) return null;
        try {
            return new Intl.DateTimeFormat("en-GB", { timeZone: value.trim() })
                .resolvedOptions().timeZone || null;
        } catch { return null; }
    }
    function wall(value) {
        const match = typeof value === "string"
            ? /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value) : null;
        if (!match) throw new Error("Choose a complete date and time. / اختر التاريخ والوقت بالكامل.");
        const [, y, m, d, h, minute] = match.map(Number);
        const epoch = Date.UTC(y, m - 1, d, h, minute);
        const date = new Date(epoch);
        if (date.getUTCFullYear() !== y || date.getUTCMonth() + 1 !== m ||
            date.getUTCDate() !== d || date.getUTCHours() !== h ||
            date.getUTCMinutes() !== minute)
            throw new Error("Choose a valid calendar date. / اختر تاريخاً صحيحاً.");
        return epoch;
    }
    function toUtc(value, timezone) {
        const desired = wall(value);
        const zone = validZone(timezone);
        if (!zone) throw new Error("The station timezone is unavailable. Select UTC. / توقيت المحطة غير متاح، اختر UTC.");
        let candidates;
        if (zone === "UTC") candidates = [desired];
        else {
            const fmt = new Intl.DateTimeFormat("en-GB", {
                timeZone: zone, year: "numeric", month: "2-digit", day: "2-digit",
                hour: "2-digit", minute: "2-digit", hourCycle: "h23",
            });
            function localMillis(utcMillis) {
                const parts = Object.fromEntries(fmt.formatToParts(new Date(utcMillis))
                    .filter((part) => part.type !== "literal")
                    .map((part) => [part.type, Number(part.value)]));
                return Date.UTC(parts.year, parts.month - 1, parts.day,
                    parts.hour, parts.minute);
            }
            const offsets = new Set();
            // A UTC instant is within 24h of local time. Sampling both sides
            // of clock changes covers standard, 30-minute and 1-hour offsets.
            for (let hours = -36; hours <= 36; hours += 6) {
                const instant = desired + hours * 3600000;
                offsets.add(localMillis(instant) - instant);
            }
            candidates = [...offsets].map((offset) => desired - offset)
                .filter((instant) => localMillis(instant) === desired);
        }
        candidates = [...new Set(candidates)];
        if (!candidates.length) throw new Error("This station-local time does not exist because clocks changed. Select another time or UTC. / هذا الوقت المحلي غير موجود؛ اختر وقتاً آخر أو UTC.");
        if (candidates.length > 1) throw new Error("This station-local time occurs twice because clocks changed. Select UTC to choose the exact slot. / هذا الوقت المحلي يتكرر؛ اختر UTC لتحديد الموعد.");
        if (new Date(candidates[0]).getUTCMinutes() % 30 !== 0) throw new Error("Choose a 30-minute UTC slot using UTC time. / اختر وقت UTC بفاصل 30 دقيقة.");
        return new Date(candidates[0]).toISOString().replace(".000Z", "Z");
    }
    window.EvStationTime = Object.freeze({ validZone, toUtc });
})();
