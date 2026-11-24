"use strict";
window.EvConnectorFreshness = (() => {
    const statuses = ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'];
    function strictUtc(raw) {
        const m = typeof raw === 'string' && /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z$/.exec(raw);
        if (!m) return null;
        const p = m.slice(1, 7).map(Number), d = new Date(0);
        d.setUTCFullYear(p[0], p[1] - 1, p[2]);
        d.setUTCHours(p[3], p[4], p[5], 0);
        if (d.getUTCFullYear() !== p[0] || d.getUTCMonth() + 1 !== p[1] ||
            d.getUTCDate() !== p[2] || d.getUTCHours() !== p[3] ||
            d.getUTCMinutes() !== p[4] || d.getUTCSeconds() !== p[5]) return null;
        const time = Date.parse(raw);
        return Number.isFinite(time) ? time : null;
    }
    function state(value, elapsed = 0) {
        if (!value || value.evidence !== 'SIMULATED_SOURCE' || value.physical_availability_verified !== false || value.max_age_seconds !== 300 || !Number.isFinite(elapsed) || elapsed < 0) return 'unknown';
        const checked = strictUtc(value.checked_at), observed = strictUtc(value.observed_at), until = strictUtc(value.valid_until);
        if (checked === null || observed === null || until === null || observed > checked || until - observed !== 300000) return 'unknown';
        if (value.state === 'stale' || until <= checked + elapsed) return 'stale';
        return value.state === 'recent' && statuses.includes(value.reported_status) ? value.reported_status : 'unknown';
    }
    const arabic = {Available:'متاح بحسب المصدر', Occupied:'مشغول', Preparing:'قيد التحضير', Charging:'جارٍ الشحن', SuspendedEV:'معلق من المركبة', SuspendedEVSE:'معلق من الشاحن', Finishing:'قيد الإنهاء', Reserved:'محجوز', Unavailable:'غير متاح', Faulted:'عطل', stale:'قديم', unknown:'غير معروف'};
    function attach(root, value, requestAge = 0) {
        const p = document.createElement('p'), received = performance.now() - requestAge;
        const render = () => {
            const label = state(value, performance.now() - received);
            const observed = value?.evidence === 'SIMULATED_SOURCE' && value.physical_availability_verified === false && strictUtc(value.observed_at) !== null ? value.observed_at : null;
            p.dataset.en = `Simulated source status: ${label}. Physical availability is unverified. Observed: ${observed || 'unknown'}`;
            p.dataset.ar = `حالة مصدر المحاكاة: ${arabic[label]}. التوفر الفعلي غير مؤكد. وقت الرصد: ${observed || 'غير معروف'}`;
            p.textContent = document.documentElement.lang === 'ar' ? p.dataset.ar : p.dataset.en;
        };
        root.append(p); render();
        const timer = setInterval(() => { if (!p.isConnected) clearInterval(timer); else render(); }, 1000);
    }
    return {state, attach, statuses, label:(status,ar=false)=>ar?(arabic[status]||arabic.unknown):status};
})();
