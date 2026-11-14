"use strict";
window.EvPrechargePricing = (() => {
    function valid(e) {
        if (!e || e.version !== 1 || e.evidence !== 'SIMULATED' || !/^[A-Z]{3}$/.test(e.currency) || !Number.isInteger(e.minor_units) || e.minor_units < 0 || e.minor_units > 3 || !Number.isInteger(e.energy_wh) || e.energy_wh < 1 || e.energy_wh > 1000000 || !Number.isInteger(e.tax_bps) || e.tax_bps < 0 || e.tax_bps > 10000 || e.external_fees_included !== false || e.automatic_stop !== false || e.final_total_depends_on_meter !== true || e.session_fee_minor !== '0' || e.idle_fee_minor !== '0') return false;
        for (const key of ['rate_minor_per_kwh','subtotal_minor','tax_minor','total_minor']) if (typeof e[key] !== 'string' || !/^(0|[1-9][0-9]{0,15})$/.test(e[key])) return false;
        const subtotal = (BigInt(e.energy_wh) * BigInt(e.rate_minor_per_kwh) + 500n) / 1000n;
        const tax = (subtotal * BigInt(e.tax_bps) + 5000n) / 10000n;
        return BigInt(e.rate_minor_per_kwh) <= 1000000n && BigInt(e.subtotal_minor) === subtotal && BigInt(e.tax_minor) === tax && BigInt(e.total_minor) === subtotal + tax && typeof e.expires_at === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(e.expires_at) && Number.isFinite(Date.parse(e.expires_at));
    }
    function amount(value, digits) {
        const s = value.padStart(digits + 1, '0');
        return digits ? s.slice(0,-digits) + '.' + s.slice(-digits) : s;
    }
    function text(e, ar) {
        if (!valid(e)) throw new Error('Unsupported pricing estimate');
        const money = x => `${e.currency} ${amount(x,e.minor_units)}`;
        return ar
            ? `تقدير محاكاة للشحن: ${e.energy_wh} Wh\nالسعر لكل kWh: ${money(e.rate_minor_per_kwh)}\nالطاقة: ${money(e.subtotal_minor)}\nالضريبة: ${money(e.tax_minor)}\nالإجمالي التقديري: ${money(e.total_minor)}\nرسوم الجلسة والخمول في المحاكاة: 0. لا تشمل رسوم المواقف أو الدخول الخارجية. يعتمد المبلغ النهائي على العداد الفعلي للجلسة؛ هذا ليس حداً للإنفاق ولا يوقف الشحن تلقائياً. لا يحدث دفع حقيقي.\nتنتهي صلاحية التأكيد: ${e.expires_at}\nقبول هذا السعر؟`
            : `Simulated charging estimate: ${e.energy_wh} Wh\nRate per kWh: ${money(e.rate_minor_per_kwh)}\nEnergy: ${money(e.subtotal_minor)}\nTax: ${money(e.tax_minor)}\nEstimated total: ${money(e.total_minor)}\nSimulator session/idle fees: 0. External parking/access fees are excluded. Final cost depends on the session meter; this is not a spending cap and does not automatically stop charging. No real payment occurs.\nConfirmation expires: ${e.expires_at}\nAccept this price?`;
    }
    async function review({api, reservationId, current, ar = false}) {
        const energy = window.prompt(ar ? 'الطاقة المتوقعة بالواط ساعة (1 إلى 1000000)' : 'Estimated energy in Wh (1 to 1000000)', '10000');
        if (energy === null || !current()) return false;
        const amount = window.EvMoney.parse(energy,0,1000000);
        if (amount === null || amount < 1) throw new Error(ar ? 'أدخل طاقة صحيحة بالواط ساعة' : 'Enter valid energy in Wh');
        const reply = await api(`reservations/${reservationId}/price-estimate`, 'POST', {energy_wh:amount});
        if (!current() || document.visibilityState !== 'visible') return false;
        if (reply.start_already_recorded === true) return false;
        if (!valid(reply.estimate) || reply.estimate.energy_wh !== amount || typeof reply.quote_token !== 'string' || !reply.quote_token.length || reply.quote_token.length > 16384) throw new Error('Unsupported pricing estimate');
        if (!window.confirm(text(reply.estimate, ar)) || !current() || document.visibilityState !== 'visible') return false;
        await api(`reservations/${reservationId}/price-consent`, 'POST', {quote_token:reply.quote_token,accepted:true});
        return current() && document.visibilityState === 'visible';
    }
    return {valid, amount, text, review};
})();
