"use strict";
window.EvReservationPolicy = (() => {
    function valid(terms) {
        return terms?.version === 1 && terms.slot_minutes === 30 && terms.arrival_before_minutes === 15 && terms.unused_expiry_minutes === 30 &&
            terms.no_show_fee_minor === 0 && terms.cancellation_fee_minor === 0 && terms.cancellation_rule === 'BEFORE_ADMITTED_OR_UNCERTAIN_START' &&
            terms.occupancy_basis === 'CANONICAL_LOGICAL_SESSION_ONLY' && terms.occupancy_enforcement === 'START_ADMISSION' && terms.physical_occupancy_verified === false &&
            terms.unresolved_session_requires_reconciliation === true && terms.automatic_stop === false && terms.evidence === 'SIMULATED_BUSINESS_POLICY';
    }
    function attach(root, terms) {
        if (!valid(terms)) return;
        const details = document.createElement('details'), summary = document.createElement('summary'), text = document.createElement('p');
        summary.dataset.en = '30-minute slot; no cancellation/no-show fee; physical space is unverified.';
        summary.dataset.ar = 'موعد 30 دقيقة؛ دون رسوم إلغاء أو عدم حضور؛ المكان الفعلي غير مؤكد.';
        text.dataset.en = 'Self-reported arrival opens 15 minutes before the slot. An unused booking expires at the slot end, unless an uncertain source operation needs review. Cancellation is available before an admitted or uncertain charging start. Active/uncertain logical sessions block starting another session on the same resource until completed or reconciled. No automatic physical stop or financial penalty occurs. A booking does not guarantee physical access or live charger availability.';
        text.dataset.ar = 'يبدأ تسجيل الوصول الذاتي قبل الموعد بـ15 دقيقة. ينتهي الحجز غير المستخدم بنهاية الموعد، إلا إذا احتاجت عملية مصدر غير مؤكدة للمراجعة. يتاح الإلغاء قبل بدء شحن مقبول أو غير مؤكد. تمنع الجلسات الجارية أو غير المؤكدة بدء جلسة أخرى لنفس المورد حتى اكتمالها أو تسويتها. لا يحدث إيقاف فعلي تلقائي أو غرامة مالية. الحجز لا يضمن الدخول الفعلي أو توفر الشاحن مباشرة.';
        for (const node of [summary,text]) node.textContent = document.documentElement.lang === 'ar' ? node.dataset.ar : node.dataset.en;
        details.append(summary,text); root.append(details);
    }
    return {valid, attach};
})();
