/* Read-time catalog-age evidence only; never a charger or payment capability. */
window.EvPartnerCatalogPolicy = (() => {
    // Strict UTC calendar validation, preserving the full six-digit source precision.
    function instant(raw) {
        const m = typeof raw === 'string' && /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?Z$/.exec(raw);
        if (!m || m[0] !== raw) return null;
        const p = m.slice(1, 7).map(Number), d = new Date(0);
        d.setUTCFullYear(p[0], p[1] - 1, p[2]);
        d.setUTCHours(p[3], p[4], p[5], 0);
        if (p[0] === 0 || d.getUTCFullYear() !== p[0] || d.getUTCMonth() + 1 !== p[1] ||
            d.getUTCDate() !== p[2] || d.getUTCHours() !== p[3] ||
            d.getUTCMinutes() !== p[4] || d.getUTCSeconds() !== p[5]) return null;
        return BigInt(d.getTime()) * 1000n + BigInt((m[7] || '').padEnd(6, '0'));
    }
    function verifiedAge(raw, checked, age) {
        const observed = instant(raw);
        if (checked === null || observed === null || observed > checked ||
            !Number.isSafeInteger(age) || age < 0 || (checked - observed) / 60000000n !== BigInt(age)) return null;
        return observed;
    }
    function project(row) {
        const unknown = {sourceTime: null, sourceAge: null, receiptAge: null, policy: null};
        if (!row || row.evidence !== 'PARTNER_REPORTED_UNVERIFIED') return unknown;
        const checked = instant(row.catalog_checked_at);
        const source = row.source_timestamp_evidence === 'PARTNER_REPORTED_ONLY' &&
            row.source_clock_evidence === 'PARTNER_REPORTED_TIME'
            ? verifiedAge(row.source_updated_at, checked, row.reported_source_age_minutes) : null;
        const receipt = row.received_timestamp_evidence === 'LOCAL_INGESTION_TIME'
            ? verifiedAge(row.received_at, checked, row.received_age_minutes) : null;
        const result = {
            sourceTime: source === null ? null : row.source_updated_at,
            sourceAge: source === null ? null : row.reported_source_age_minutes,
            receiptAge: receipt === null ? null : row.received_age_minutes,
            policy: null,
        };
        const v = row.catalog_age_policy;
        if (!v || v.evidence !== 'PARTNER_CATALOG_TIMESTAMPS_ONLY' ||
            v.physical_availability_verified !== false || v.remote_control !== false || v.billing_authority !== false ||
            checked === null || instant(v.checked_at) !== checked ||
            !Number.isSafeInteger(v.max_age_seconds) || v.max_age_seconds < 60 || v.max_age_seconds > 604800) return result;
        const limit = BigInt(v.max_age_seconds) * 1000000n;
        const expected = source === null || receipt === null ? 'unknown' :
            checked - source >= limit || checked - receipt >= limit ? 'stale' : 'within_policy';
        if (v.state === expected) result.policy = v;
        return result;
    }
    return {project,
    text(value, ar = false) {
        const t = (en, arabic) => ar ? arabic : en;
        const unknown = () => t('Catalog-age policy unavailable. Physical availability unverified.', 'سياسة عمر الكتالوج غير متاحة. التوفر الفعلي غير مؤكد.');
        if (!value || value.evidence !== 'PARTNER_CATALOG_TIMESTAMPS_ONLY' || value.physical_availability_verified !== false || value.remote_control !== false || value.billing_authority !== false || instant(value.checked_at) === null || !Number.isSafeInteger(value.max_age_seconds) || value.max_age_seconds < 60 || value.max_age_seconds > 604800) return unknown();
        const state = {within_policy: t('within limit', 'ضمن الحد'), stale: t('limit exceeded', 'تم تجاوز الحد'), unknown: t('age unknown', 'العمر غير معروف')}[value.state];
        if (!Object.hasOwn({within_policy:true,stale:true,unknown:true}, value.state)) return unknown();
        return t(`Catalog-age policy at search: ${state} (${value.max_age_seconds} seconds). Physical availability unverified.`, `سياسة عمر الكتالوج عند البحث: ${state} (${value.max_age_seconds} ثانية). التوفر الفعلي غير مؤكد.`);
    },
    };
})();
