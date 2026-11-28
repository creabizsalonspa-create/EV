"use strict";
window.EvPrechargePricing = (() => {
    function validExpiry(raw) {
        const match = typeof raw === 'string' &&
            /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.(\d{6})Z$/.exec(raw);
        if (!match) return false;
        const parts = match.slice(1, 7).map(Number);
        const utc = new Date(0);
        utc.setUTCFullYear(parts[0], parts[1] - 1, parts[2]);
        utc.setUTCHours(parts[3], parts[4], parts[5], 0);
        // Date.parse can silently roll February 30 over into March.
        if (utc.getUTCFullYear() !== parts[0] ||
            utc.getUTCMonth() + 1 !== parts[1] ||
            utc.getUTCDate() !== parts[2] ||
            utc.getUTCHours() !== parts[3] ||
            utc.getUTCMinutes() !== parts[4] ||
            utc.getUTCSeconds() !== parts[5]) return false;
        return Number.isFinite(Date.parse(raw));
    }
    function receiptInstant(value) {
        if (typeof value !== 'string') return null;
        // UTC SQL replay and ISO UTC receipts share one six-digit representation.
        const match = /^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?|T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z)$/.exec(value);
        if (!match) return null;
        const iso = match[1] + 'T' + (match[2] ?? match[4]) + '.' +
            (match[3] ?? match[5] ?? '').padEnd(6, '0') + 'Z';
        return validExpiry(iso) ? iso : null;
    }
    function confirmedReceipt(receipt, estimate) {
        if (!receipt || !Number.isSafeInteger(receipt.id) || receipt.id < 1) return false;
        const accepted = receiptInstant(receipt.accepted_at);
        const until = receiptInstant(receipt.valid_until);
        // Compare canonical strings, not Date.parse's millisecond-truncated values.
        return accepted !== null && until !== null &&
            until === estimate.expires_at && accepted < until;
    }
    function valid(e) {
        if (!e || e.version !== 1 || e.evidence !== 'SIMULATED' || !/^[A-Z]{3}$/.test(e.currency) || !Number.isInteger(e.minor_units) || e.minor_units < 0 || e.minor_units > 3 || !Number.isInteger(e.energy_wh) || e.energy_wh < 1 || e.energy_wh > 1000000 || !Number.isInteger(e.tax_bps) || e.tax_bps < 0 || e.tax_bps > 10000 || e.external_fees_included !== false || e.automatic_stop !== false || e.final_total_depends_on_meter !== true || e.session_fee_minor !== '0' || e.idle_fee_minor !== '0') return false;
        if (!Object.prototype.hasOwnProperty.call(e,'tariff_version_id') ||
            (e.tariff_version_id !== null &&
              (!Number.isSafeInteger(e.tariff_version_id) || e.tariff_version_id < 1))) return false;
        for (const key of ['rate_minor_per_kwh','subtotal_minor','tax_minor','total_minor']) if (typeof e[key] !== 'string' || !/^(0|[1-9][0-9]{0,15})$/.test(e[key])) return false;
        const subtotal = (BigInt(e.energy_wh) * BigInt(e.rate_minor_per_kwh) + 500n) / 1000n;
        const tax = (subtotal * BigInt(e.tax_bps) + 5000n) / 10000n;
        return BigInt(e.rate_minor_per_kwh) <= 1000000n && BigInt(e.subtotal_minor) === subtotal && BigInt(e.tax_minor) === tax && BigInt(e.total_minor) === subtotal + tax && validExpiry(e.expires_at);
    }
    function amount(value, digits) {
        const s = value.padStart(digits + 1, '0');
        return digits ? s.slice(0,-digits) + '.' + s.slice(-digits) : s;
    }
    function text(e, ar) {
        if (!valid(e)) throw new Error('Unsupported pricing estimate');
        const money = x => `${e.currency} ${amount(x,e.minor_units)}`;
        const version = e.tariff_version_id === null
            ? (ar ? 'تعرفة المحاكاة الافتراضية' : 'Default simulator tariff')
            : (ar ? 'إصدار التعرفة #' : 'Tariff version #') + e.tariff_version_id;
        return ar
            ? `${version}\nتقدير محاكاة للشحن: ${e.energy_wh} Wh\nالسعر لكل kWh: ${money(e.rate_minor_per_kwh)}\nالطاقة: ${money(e.subtotal_minor)}\nالضريبة: ${money(e.tax_minor)}\nالإجمالي التقديري: ${money(e.total_minor)}\nرسوم الجلسة والخمول في المحاكاة: 0. لا تشمل رسوم المواقف أو الدخول الخارجية. يعتمد المبلغ النهائي على العداد الفعلي للجلسة؛ هذا ليس حداً للإنفاق ولا يوقف الشحن تلقائياً. لا يحدث دفع حقيقي.\nتنتهي صلاحية التأكيد: ${e.expires_at}\nقبول هذا السعر؟`
            : `${version}\nSimulated charging estimate: ${e.energy_wh} Wh\nRate per kWh: ${money(e.rate_minor_per_kwh)}\nEnergy: ${money(e.subtotal_minor)}\nTax: ${money(e.tax_minor)}\nEstimated total: ${money(e.total_minor)}\nSimulator session/idle fees: 0. External parking/access fees are excluded. Final cost depends on the session meter; this is not a spending cap and does not automatically stop charging. No real payment occurs.\nConfirmation expires: ${e.expires_at}\nAccept this price?`;
    }
    async function review({api, reservationId, current, ar = false}) {
        if (!Number.isSafeInteger(reservationId) || reservationId < 1)
            throw new Error('Invalid reservation for price review');
        // An OS-hidden tab or a retired actor must not display prompts or
        // send a private estimate POST before checking consent to review.
        if (!current() || document.visibilityState !== 'visible') return false;
        const energy = window.prompt(ar ? 'الطاقة المتوقعة بالواط ساعة (1 إلى 1000000)' : 'Estimated energy in Wh (1 to 1000000)', '10000');
        if (energy === null || !current() || document.visibilityState !== 'visible') return false;
        const amount = window.EvMoney.parse(energy,0,1000000);
        if (amount === null || amount < 1) throw new Error(ar ? 'أدخل طاقة صحيحة بالواط ساعة' : 'Enter valid energy in Wh');
        const reply = await api(`reservations/${reservationId}/price-estimate`, 'POST', {energy_wh:amount});
        if (!current() || document.visibilityState !== 'visible') return false;
        if (reply.start_already_recorded === true) return false;
        if (!valid(reply.estimate) || reply.estimate.energy_wh !== amount ||
            typeof reply.quote_token !== 'string' ||
            !reply.quote_token.length || reply.quote_token.length > 16384)
            throw new Error('Unsupported pricing estimate');
        const expiry = Date.parse(reply.estimate.expires_at);
        if (Date.now() >= expiry)
            throw new Error('Price confirmation expired. Review a fresh estimate.');
        if (!window.confirm(text(reply.estimate, ar)) ||
            !current() || document.visibilityState !== 'visible') return false;
        // The human confirmation may be delayed past the server's 5-minute
        // quote lifetime. Do not issue a stale approval automatically.
        if (Date.now() >= expiry)
            throw new Error('Price confirmation expired. Review a fresh estimate.');
        const receipt = await api(`reservations/${reservationId}/price-consent`,
            'POST', {quote_token:reply.quote_token,accepted:true});
        if (!current() || document.visibilityState !== 'visible') return false;
        if (!confirmedReceipt(receipt, reply.estimate))
            throw new Error('Price consent not confirmed. Check session history before retrying.');
        // A valid acknowledgement can arrive after the quote expires. The
        // recorded consent is not permission to start with an expired price.
        if (Date.now() >= expiry)
            throw new Error('Price confirmation expired. Review a fresh estimate.');
        return true;
    }
    return {valid, amount, text, review};
})();
