(() => {
    function stationTimeError(en, ar) {
        const language = window.EvLocale?.language ||
            (typeof document !== "undefined" ? document.documentElement?.lang : "en");
        // Do not combine two languages in one alert. Errors use the language
        // selected at the instant of rejection, including after a locale toggle.
        return new Error(language === "ar" ? ar : en);
    }
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
        if (!match) throw stationTimeError("Choose a complete date and time.", "اختر التاريخ والوقت بالكامل.");
        const [, y, m, d, h, minute] = match.map(Number);
        const epoch = Date.UTC(y, m - 1, d, h, minute);
        const date = new Date(epoch);
        if (date.getUTCFullYear() !== y || date.getUTCMonth() + 1 !== m ||
            date.getUTCDate() !== d || date.getUTCHours() !== h ||
            date.getUTCMinutes() !== minute)
            throw stationTimeError("Choose a valid calendar date.", "اختر تاريخاً صحيحاً.");
        return epoch;
    }
    function toUtc(value, timezone) {
        const desired = wall(value);
        const zone = validZone(timezone);
        if (!zone) throw stationTimeError("The station timezone is unavailable. Select UTC.", "توقيت المحطة غير متاح، اختر UTC.");
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
        if (!candidates.length) throw stationTimeError("This station-local time does not exist because clocks changed. Select another time or UTC.", "هذا الوقت المحلي غير موجود؛ اختر وقتاً آخر أو UTC.");
        if (candidates.length > 1) throw stationTimeError("This station-local time occurs twice because clocks changed. Select UTC to choose the exact slot.", "هذا الوقت المحلي يتكرر؛ اختر UTC لتحديد الموعد.");
        if (new Date(candidates[0]).getUTCMinutes() % 30 !== 0) throw stationTimeError("Choose a 30-minute UTC slot using UTC time.", "اختر وقت UTC بفاصل 30 دقيقة.");
        return new Date(candidates[0]).toISOString().replace(".000Z", "Z");
    }
    function displayStationTime(value, timezone, language = "en") {
        const zone = validZone(timezone);
        if (!zone || typeof value !== "string") return null;
        // Laravel may serialize its UTC timestamp with a space and no suffix.
        // An explicit offset must never be interpreted in the device timezone.
        const utc = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)
            ? value.replace(" ", "T") + "Z" : value;
        // Date.parse silently rolls over impossible calendar dates on some
        // platforms (e.g., February 30 becomes March 2). Never fabricate a
        // published station time from an invalid source timestamp.
        const parts = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/i.exec(utc);
        if (!parts) return null;
        const year = Number(parts[1]), month = Number(parts[2]),
            day = Number(parts[3]), hour = Number(parts[4]),
            minute = Number(parts[5]), second = Number(parts[6]);
        const calendar = new Date(0);
        calendar.setUTCFullYear(year, month - 1, day);
        calendar.setUTCHours(hour, minute, second, 0);
        if (calendar.getUTCFullYear() !== year ||
            calendar.getUTCMonth() + 1 !== month ||
            calendar.getUTCDate() !== day ||
            calendar.getUTCHours() !== hour ||
            calendar.getUTCMinutes() !== minute ||
            calendar.getUTCSeconds() !== second) return null;
        const offset = parts[8];
        if (offset.toUpperCase() !== "Z") {
            const offsetHours = Number(offset.slice(1, 3));
            const offsetMinutes = Number(offset.slice(4, 6));
            // UTC offsets may be at most 14 hours, with no extra minutes.
            if (offsetHours > 14 || offsetMinutes > 59 ||
                (offsetHours === 14 && offsetMinutes !== 0)) return null;
        }
        const instant = new Date(utc);
        if (Number.isNaN(instant.getTime())) return null;
        return new Intl.DateTimeFormat(language === "ar" ? "ar" : "en-GB", {
            timeZone: zone, dateStyle: "medium", timeStyle: "short", hourCycle: "h23",
        }).format(instant) + " (" + zone + ")";
    }
    window.EvStationTime = Object.freeze({ validZone, toUtc, displayStationTime });
})();
