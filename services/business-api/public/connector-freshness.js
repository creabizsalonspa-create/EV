"use strict";
window.EvConnectorFreshness = (() => {
    const statuses = ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'];
    const utcInstant = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z$/;
    function parseInstant(value) {
        const match = typeof value === 'string' && utcInstant.exec(value);
        if (!match || match[0] !== value) return null;
        const milliseconds = Date.parse(value);
        if (!Number.isFinite(milliseconds) || new Date(milliseconds).toISOString().slice(0, 19) !== match[1]) return null;
        // Keep the source's sub-millisecond precision without multiplying epoch milliseconds.
        const remainder = Number((match[2] || '').padEnd(6, '0').slice(3));
        return {milliseconds, remainder};
    }
    const difference = (end, start) =>
        end.milliseconds - start.milliseconds + (end.remainder - start.remainder) / 1000;
    function observation(value, elapsed) {
        const unknown = {state: 'unknown', observedAt: null};
        if (!value || value.evidence !== 'SIMULATED_SOURCE' || value.physical_availability_verified !== false
            || value.max_age_seconds !== 300 || !Number.isFinite(elapsed) || elapsed < 0) return unknown;
        if (value.state !== 'stale' && (value.state !== 'recent' || !statuses.includes(value.reported_status))) return unknown;
        const checked = parseInstant(value.checked_at), observed = parseInstant(value.observed_at), until = parseInstant(value.valid_until);
        if (!checked || !observed || !until || difference(observed, checked) > 0 || difference(until, observed) !== 300000) return unknown;
        const remaining = difference(until, checked);
        if (value.state === 'stale' && remaining > 0) return unknown;
        return {state: remaining <= elapsed ? 'stale' : value.reported_status, observedAt: value.observed_at};
    }
    const state = (value, elapsed = 0) => observation(value, elapsed).state;
    const arabic = {Available:'متاح بحسب المصدر', Occupied:'مشغول', Preparing:'قيد التحضير', Charging:'جارٍ الشحن', SuspendedEV:'معلق من المركبة', SuspendedEVSE:'معلق من الشاحن', Finishing:'قيد الإنهاء', Reserved:'محجوز', Unavailable:'غير متاح', Faulted:'عطل', stale:'قديم', unknown:'غير معروف'};
    function attach(root, value, requestAge = 0) {
        const p = document.createElement('p'), received = performance.now() - requestAge;
        const render = () => {
            const {state: label, observedAt} = observation(value, performance.now() - received);
            p.dataset.en = `Simulated source status: ${label}. Physical availability is unverified. Observed: ${observedAt ?? 'unknown'}`;
            p.dataset.ar = `حالة مصدر المحاكاة: ${arabic[label]}. التوفر الفعلي غير مؤكد. وقت الرصد: ${observedAt ?? 'غير معروف'}`;
            p.textContent = document.documentElement.lang === 'ar' ? p.dataset.ar : p.dataset.en;
        };
        root.append(p); render();
        const timer = setInterval(() => { if (!p.isConnected) clearInterval(timer); else render(); }, 1000);
    }
    return {state, attach, statuses, label:(status,ar=false)=>ar?(arabic[status]||arabic.unknown):status};
})();
