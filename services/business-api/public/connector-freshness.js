"use strict";
window.EvConnectorFreshness = (() => {
    const statuses = ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'];
    function strictUtc(raw) {
        const m = typeof raw === 'string' && /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z$/.exec(raw);
        if (!m || m[0] !== raw) return null;
        const p = m.slice(1, 7).map(Number), d = new Date(0);
        d.setUTCFullYear(p[0], p[1] - 1, p[2]);
        d.setUTCHours(p[3], p[4], p[5], 0);
        if (d.getUTCFullYear() !== p[0] || d.getUTCMonth() + 1 !== p[1] ||
            d.getUTCDate() !== p[2] || d.getUTCHours() !== p[3] ||
            d.getUTCMinutes() !== p[4] || d.getUTCSeconds() !== p[5]) return null;
        const time = Date.parse(raw);
        // Preserve the source's sub-millisecond remainder separately from epoch milliseconds.
        return Number.isFinite(time) ? {milliseconds: time, remainder: Number((m[7] || '').padEnd(6, '0').slice(3))} : null;
    }
    const difference = (end, start) => end.milliseconds - start.milliseconds + (end.remainder - start.remainder) / 1000;
    function observation(value, elapsed) {
        const unknown = {state: 'unknown', observedAt: null};
        if (!value || value.evidence !== 'SIMULATED_SOURCE' || value.physical_availability_verified !== false
            || value.max_age_seconds !== 300 || !Number.isFinite(elapsed) || elapsed < 0) return unknown;
        if (value.state === 'stale' ? value.reported_status !== null :
            (value.state !== 'recent' || !statuses.includes(value.reported_status))) return unknown;
        const checked = strictUtc(value.checked_at), observed = strictUtc(value.observed_at), until = strictUtc(value.valid_until);
        if (!checked || !observed || !until || difference(observed, checked) > 0 || difference(until, observed) !== 300000) return unknown;
        const remaining = difference(until, checked);
        if ((value.state === 'stale' && remaining > 0) ||
            (value.state === 'recent' && remaining <= 0)) return unknown;
        return {state: remaining <= elapsed ? 'stale' : value.reported_status, observedAt: value.observed_at};
    }
    const state = (value, elapsed = 0) => observation(value, elapsed).state;
    const arabic = {Available:'متاح بحسب المصدر', Occupied:'مشغول', Preparing:'قيد التحضير', Charging:'جارٍ الشحن', SuspendedEV:'معلق من المركبة', SuspendedEVSE:'معلق من الشاحن', Finishing:'قيد الإنهاء', Reserved:'محجوز', Unavailable:'غير متاح', Faulted:'عطل', stale:'قديم', unknown:'غير معروف'};
    function attach(root, value, requestAge = 0) {
        const p = document.createElement('p'), received = performance.now() - requestAge;
        const render = () => {
            const {state: label, observedAt: observed} = observation(value, performance.now() - received);
            const display = language => observed
                ? window.EvStationTime?.serverEventTime(observed, language) ||
                    (language === 'ar' ? 'وقت الحدث غير متاح' : 'Event time unavailable')
                : language === 'ar' ? 'غير معروف' : 'unknown';
            p.dataset.en = `Simulated source status: ${label}. Physical availability is unverified. Observed: ${display('en')}`;
            p.dataset.ar = `حالة مصدر المحاكاة: ${arabic[label]}. التوفر الفعلي غير مؤكد. وقت الرصد: ${display('ar')}`;
            p.textContent = document.documentElement.lang === 'ar' ? p.dataset.ar : p.dataset.en;
        };
        root.append(p); render();
        const timer = setInterval(() => { if (!p.isConnected) clearInterval(timer); else render(); }, 1000);
    }
    return {state, attach, statuses, label:(status,ar=false)=>ar?(arabic[status]||arabic.unknown):status};
})();
