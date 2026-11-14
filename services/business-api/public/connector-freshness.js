"use strict";
window.EvConnectorFreshness = (() => {
    const statuses = ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'];
    function state(value, elapsed = 0) {
        if (!value || value.evidence !== 'SIMULATED_SOURCE' || value.physical_availability_verified !== false || value.max_age_seconds !== 300 || !Number.isFinite(elapsed) || elapsed < 0) return 'unknown';
        if (!['checked_at','observed_at','valid_until'].every(key => typeof value[key] === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(value[key]))) return 'unknown';
        const checked = Date.parse(value.checked_at), observed = Date.parse(value.observed_at), until = Date.parse(value.valid_until);
        if (![checked, observed, until].every(Number.isFinite) || observed > checked || until - observed !== 300000) return 'unknown';
        if (value.state === 'stale' || until <= checked + elapsed) return 'stale';
        return value.state === 'recent' && statuses.includes(value.reported_status) ? value.reported_status : 'unknown';
    }
    const arabic = {Available:'متاح بحسب المصدر', Occupied:'مشغول', Preparing:'قيد التحضير', Charging:'جارٍ الشحن', SuspendedEV:'معلق من المركبة', SuspendedEVSE:'معلق من الشاحن', Finishing:'قيد الإنهاء', Reserved:'محجوز', Unavailable:'غير متاح', Faulted:'عطل', stale:'قديم', unknown:'غير معروف'};
    function attach(root, value, requestAge = 0) {
        const p = document.createElement('p'), received = performance.now() - requestAge;
        const render = () => {
            const label = state(value, performance.now() - received);
            p.dataset.en = `Simulated source status: ${label}. Physical availability is unverified. Observed: ${value?.observed_at || 'unknown'}`;
            p.dataset.ar = `حالة مصدر المحاكاة: ${arabic[label]}. التوفر الفعلي غير مؤكد. وقت الرصد: ${value?.observed_at || 'غير معروف'}`;
            p.textContent = document.documentElement.lang === 'ar' ? p.dataset.ar : p.dataset.en;
        };
        root.append(p); render();
        const timer = setInterval(() => { if (!p.isConnected) clearInterval(timer); else render(); }, 1000);
    }
    return {state, attach};
})();
