/* Read-time catalog-age evidence only; never a charger or payment capability. */
window.EvPartnerCatalogPolicy = {
    text(value, ar = false) {
        const t = (en, arabic) => ar ? arabic : en;
        const unknown = () => t('Catalog-age policy unavailable. Physical availability unverified.', 'سياسة عمر الكتالوج غير متاحة. التوفر الفعلي غير مؤكد.');
        if (!value || value.evidence !== 'PARTNER_CATALOG_TIMESTAMPS_ONLY' || value.physical_availability_verified !== false || value.remote_control !== false || value.billing_authority !== false || typeof value.checked_at !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(value.checked_at) || !Number.isSafeInteger(value.max_age_seconds) || value.max_age_seconds < 60 || value.max_age_seconds > 604800) return unknown();
        const state = {within_policy: t('within limit', 'ضمن الحد'), stale: t('limit exceeded', 'تم تجاوز الحد'), unknown: t('age unknown', 'العمر غير معروف')}[value.state];
        if (!Object.hasOwn({within_policy:true,stale:true,unknown:true}, value.state)) return unknown();
        return t(`Catalog-age policy at search: ${state} (${value.max_age_seconds} seconds). Physical availability unverified.`, `سياسة عمر الكتالوج عند البحث: ${state} (${value.max_age_seconds} ثانية). التوفر الفعلي غير مؤكد.`);
    },
};
