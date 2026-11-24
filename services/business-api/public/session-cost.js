"use strict";
window.EvSessionCost = (() => {
    // Carbon emits a fixed-width UTC instant with six fractional digits.
    // Do not let Date.parse normalize impossible calendar fields.
    function utcInstant(value) {
        if (typeof value !== 'string') return false;
        const m=/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.\d{6}Z$/.exec(value);
        if (!m || m[0].length !== value.length) return false;
        const [year,month,day,hour,minute,second]=m.slice(1).map(Number);
        const leap=year%4===0 && (year%100!==0 || year%400===0);
        const days=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31];
        return year>0 && month>=1 && month<=12 && day>=1 && day<=days[month-1] && hour<=23 && minute<=59 && second<=59;
    }
    function validMeterTime(v) {
        if (!utcInstant(v.as_of)) return false;
        const at=v.source_meter_at;
        if (v.meter_freshness_at_read==='APPLICATION_SIMULATOR') return at===null;
        if (v.meter_freshness_at_read==='UNKNOWN') return at===null || utcInstant(at);
        // The fixed-width UTC format permits exact microsecond ordering.
        return utcInstant(at) && at<=v.as_of;
    }
    function valid(v) {
        if (!v || v.evidence !== 'SIMULATED' || v.scope !== 'CANONICAL_SESSION_QUOTE' || v.physical_charging_verified !== false || v.real_payment_processed !== false || v.external_fees_included !== false || v.spending_cap !== false || v.automatic_stop !== false || !['estimated','invoiced'].includes(v.state)) return false;
        if (typeof v.currency!=='string' || !/^[A-Z]{3}$/.test(v.currency) || !Number.isInteger(v.minor_units) || v.minor_units < 0 || v.minor_units > 3 || !Number.isInteger(v.tax_bps) || v.tax_bps < 0 || v.tax_bps > 10000) return false;
        for (const key of ['energy_wh','rate_minor_per_kwh','subtotal_minor','tax_minor','total_minor']) if (typeof v[key] !== 'string' || !/^(0|[1-9][0-9]{0,18})$/.test(v[key])) return false;
        if (BigInt(v.rate_minor_per_kwh)>1000000n || BigInt(v.total_minor)>9223372036854775807n || !['APPLICATION_SIMULATOR','RECENT','STALE','UNKNOWN'].includes(v.meter_freshness_at_read) || !validMeterTime(v)) return false;
        const subtotal=(BigInt(v.energy_wh)*BigInt(v.rate_minor_per_kwh)+500n)/1000n, tax=(subtotal*BigInt(v.tax_bps)+5000n)/10000n;
        return subtotal===BigInt(v.subtotal_minor) && tax===BigInt(v.tax_minor) && subtotal+tax===BigInt(v.total_minor) && (v.state!=='invoiced' || Number.isSafeInteger(v.invoice_id) && v.invoice_id>0);
    }
    function amount(s,d) {const text=s.padStart(d+1,'0');return d?text.slice(0,-d)+'.'+text.slice(-d):text;}
    function text(v,ar) {
        if (v?.state === 'awaiting_meter') return ar ? 'بانتظار دليل العداد؛ لا يتوفر تقدير تكلفة.' : 'Waiting for meter evidence; no cost estimate.';
        if (!valid(v)) return ar ? 'تقدير التكلفة غير متاح؛ حدّث السجل وراجع دليل العداد.' : 'Cost estimate unavailable; refresh and inspect meter evidence.';
        const cost=`${v.currency} ${amount(v.total_minor,v.minor_units)}`, tax=`${v.currency} ${amount(v.tax_minor,v.minor_units)}`;
        const label=v.state==='invoiced'?(ar?'إجمالي فاتورة المحاكاة':'Simulated invoice total'):(ar?'تقدير التكلفة المسجل':'Recorded cost estimate');
        const source=v.meter_freshness_at_read==='APPLICATION_SIMULATOR'?(ar?'عداد محاكاة يدوي':'Manual simulator meter'):(ar?'وقت رصد عداد المصدر':'Source meter recorded at')+': '+(v.source_meter_at||'—');
        const warning=v.meter_freshness_at_read==='STALE'?(ar?' آخر دليل مصدر قديم عند القراءة.':' Source evidence was stale at this read.'):v.meter_freshness_at_read==='UNKNOWN'?(ar?' حداثة المصدر غير معروفة.':' Source freshness is unknown.'):'';
        return `${label}: ${cost} · ${ar?'الضريبة':'Tax'}: ${tax} · ${v.energy_wh} Wh\n${source}${warning}\n${ar?'وقت القراءة':'As of'}: ${v.as_of || '—'}\n${ar?'التقدير ليس حداً للإنفاق ولا يثبت دفعاً حقيقياً. الرسوم الخارجية غير مشمولة.':'An estimate is not a spending cap or proof of real payment. External fees are excluded.'}`;
    }
    function attach(root,value) {const p=document.createElement('p');p.dataset.en=text(value,false);p.dataset.ar=text(value,true);p.textContent=document.documentElement.lang==='ar'?p.dataset.ar:p.dataset.en;root.append(p);}
    return {valid,text,attach};
})();
