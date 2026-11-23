"use strict";
window.EvPhoneVerification = (() => {
    const root = document.getElementById('phone-verification');
    let generation = 0, request, current, challenge, intent, busy = false;
    const visible = () => document.visibilityState !== 'hidden' && document.hidden !== true;
    const text = (tag, en, ar) => {
        const node = document.createElement(tag);
        node.dataset.en = en; node.dataset.ar = ar;
        node.textContent = document.documentElement.lang === 'ar' ? ar : en;
        return node;
    };
    function verifiedStatus(data) {
        if (!data || typeof data.available !== 'boolean' || typeof data.verified !== 'boolean')
            throw Error('Invalid private phone status');
        if (data.phone_mask != null &&
            (typeof data.phone_mask !== 'string' || data.phone_mask.length > 40))
            throw Error('Invalid private phone mask');
        const item = data.challenge;
        if (item != null &&
            (typeof item !== 'object' || Array.isArray(item) ||
             typeof item.id !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(item.id) ||
             !['pending', 'uncertain'].includes(item.status) ||
             (item.attempts_remaining != null &&
                (!Number.isSafeInteger(item.attempts_remaining) ||
                 item.attempts_remaining < 0 || item.attempts_remaining > 5))))
            throw Error('Unverified private challenge');
        return data;
    }
    function clear() {
        generation++; request = current = challenge = intent = null; busy = false;
        root.replaceChildren(); root.hidden = true;
    }
    function showClosed(unavailable = false) {
        if (!request) return;
        root.hidden = false;
        root.replaceChildren(text('p',
            unavailable
                ? 'Phone verification status is unavailable. Refresh before another request.'
                : 'Private phone verification is closed. Return to this tab and refresh before continuing.',
            unavailable
                ? 'حالة التحقق من الهاتف غير متاحة. حدّث قبل أي طلب جديد.'
                : 'أُغلق التحقق الخاص بالهاتف. ارجع إلى الصفحة وحدّث الحالة قبل المتابعة.'));
        const refresh = text('button', 'Refresh verification status', 'تحديث حالة التحقق');
        refresh.type = 'button';
        const version = generation;
        refresh.onclick = async () => {
            if (!visible() || !request || busy || version !== generation) return;
            busy = true; refresh.disabled = true;
            try {
                const data = verifiedStatus(await request('account/phone'));
                if (version !== generation || !visible()) return;
                current = data; challenge = data.challenge ?? null;
                render();
            } catch {
                if (version === generation && visible()) showClosed(true);
            } finally {
                if (version === generation) { busy = false; refresh.disabled = false; }
            }
        };
        root.append(refresh);
    }
    function field(form, name, en, ar, type, max) {
        const label = text('label', en, ar), input = document.createElement('input');
        input.name = name; input.type = type; input.required = true; input.maxLength = max;
        input.autocomplete = type === 'password' ? 'current-password' : name === 'phone' ? 'tel' : 'one-time-code';
        label.append(input); form.append(label); return input;
    }
    function render() {
        if (!visible() || !current) { showClosed(); return; }
        root.replaceChildren();
        root.append(text('h2', 'Verify your phone', 'التحقق من رقم الهاتف'));
        root.append(text('p', 'Phone verification confirms contact ownership. It does not enable SMS sign-in or two-factor authentication.',
            'يؤكد التحقق ملكية رقم الاتصال. ولا يفعّل تسجيل الدخول بالرسائل أو المصادقة الثنائية.'));
        if (current.verified) root.append(text('p', 'Verified phone: ' + current.phone_mask, 'رقم مؤكد: ' + current.phone_mask));
        const notice = text('p', '', ''); notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite');
        root.append(notice);
        const version = generation;
        async function act(operation) {
            if (busy || version !== generation || !visible()) return;
            busy = true;
            root.querySelectorAll('button').forEach(button => button.disabled = true);
            try {
                await operation();
            } catch {
                if (version === generation) {
                    const message = text('p', 'Request was not confirmed. Refresh status before retrying. Check your credentials, code and request limits.',
                        'لم يتأكد الطلب. حدّث الحالة قبل إعادة المحاولة وتحقق من بياناتك والرمز وحدود الطلبات.');
                    notice.replaceChildren(message);
                }
            } finally {
                if (version === generation) { busy = false; root.querySelectorAll('button').forEach(button => button.disabled = false); }
            }
        }
        const refresh = text('button', 'Refresh verification status', 'تحديث حالة التحقق'); refresh.type = 'button';
        refresh.onclick = () => act(async () => {
            let data;
            try { data = verifiedStatus(await request('account/phone')); }
            catch {
                if (version === generation && visible()) {
                    // The previous form was based on an unverified state.
                    // A retained submit callback must not be usable after
                    // the page closes, even if it is still referenced in JS.
                    generation++; busy = false;
                    current = challenge = intent = null;
                    root.querySelectorAll('input').forEach(n => {
                        n.value = ''; if (n.type === 'checkbox') n.checked = false;
                    });
                    showClosed(true);
                }
                return;
            }
            if (version !== generation || !visible()) return;
            current = data; challenge = data.challenge ?? null; render();
        }); root.append(refresh);
        if (!current.available) root.append(text('p', 'SMS verification delivery is unavailable.', 'إرسال رسائل التحقق غير متاح.'));
        if (current.available) {
            const form = document.createElement('form');
            const phone = field(form, 'phone', 'Phone in international format (+country code)', 'رقم الهاتف بالصيغة الدولية (+رمز الدولة)', 'tel', 16);
            phone.pattern = '[+][1-9][0-9]{7,14}'; phone.dir = 'ltr';
            const password = field(form, 'password', 'Current password', 'كلمة المرور الحالية', 'password', 128);
            const consentLabel = text('label', 'I request a verification SMS to this number.', 'أطلب إرسال رسالة تحقق إلى هذا الرقم.');
            const consent = document.createElement('input'); consent.type = 'checkbox'; consent.required = true;
            consentLabel.prepend(consent); form.append(consentLabel);
            const send = text('button', 'Request verification SMS', 'طلب رسالة تحقق'); send.type = 'submit'; form.append(send);
            form.onsubmit = event => {
                event.preventDefault();
                if (!consent.checked) return;
                const value = phone.value.trim(), credential = password.value; password.value = '';
                if (intent && intent.phone !== value) { notice.replaceChildren(text('p', 'Refresh the page before changing an uncertain request.', 'حدّث الصفحة قبل تغيير طلب غير مؤكد.')); return; }
                intent ||= {phone: value, key: crypto.randomUUID()};
                act(async () => {
                    const data = await request('account/phone/challenges', 'POST', {phone: intent.phone, password: credential, consent: true,
                        locale: document.documentElement.lang === 'ar' ? 'ar' : 'en', idempotency_key: intent.key});
                    if (version !== generation) return;
                    if (!data.id || !['pending','uncertain'].includes(data.status)) throw Error('Invalid challenge response');
                    challenge = data; phone.value = ''; intent = null; render();
                });
            }; root.append(form);
        }
        if (challenge?.status === 'uncertain') root.append(text('p', 'SMS request outcome is uncertain. No automatic resend will occur.', 'نتيجة طلب الرسالة غير مؤكدة. لن يعاد إرسالها تلقائياً.'));
        if (current.available && challenge?.status === 'pending') {
            const form = document.createElement('form');
            const code = field(form, 'code', 'SMS verification code', 'رمز التحقق من الرسالة', 'text', 10);
            code.dir = 'ltr'; code.inputMode = 'numeric'; code.pattern = '[0-9]{4,10}';
            const confirm = text('button', 'Confirm phone', 'تأكيد رقم الهاتف'); confirm.type = 'submit'; form.append(confirm);
            form.onsubmit = event => { event.preventDefault(); const value = code.value; code.value = '';
                const id = challenge.id;
                act(async () => {
                    const reply = await request('account/phone/challenges/' + encodeURIComponent(id) + '/confirm', 'POST', {code: value});
                    if (version !== generation) return;
                    if (reply.verified) { const updated = await request('account/phone'); if (version !== generation) return; current = verifiedStatus(updated); challenge = null; render(); }
                    else { challenge = {...challenge, status: reply.status}; notice.replaceChildren(text('p', 'Phone has not been verified. Check the code or refresh status.', 'لم يتم تأكيد الرقم. تحقق من الرمز أو حدّث الحالة.')); }
                });
            }; root.append(form);
        }
        if (current.verified) {
            const form = document.createElement('form');
            const password = field(form, 'remove-password', 'Password to remove saved phone', 'كلمة المرور لإزالة الرقم المحفوظ', 'password', 128);
            const remove = text('button', 'Remove saved phone', 'إزالة الرقم المحفوظ'); remove.type = 'submit'; form.append(remove);
            form.onsubmit = event => { event.preventDefault(); const credential = password.value; password.value = '';
                act(async () => { await request('account/phone', 'DELETE', {password: credential}); if (version !== generation) return;
                    const updated = await request('account/phone'); if (version !== generation) return; current = verifiedStatus(updated); challenge = intent = null; render(); });
            }; root.append(form);
        }
    }
    async function setActor(actor, api) {
        clear(); if (!['customer', 'vendor'].includes(actor?.role)) return;
        request = api; const version = generation;
        if (!visible()) { showClosed(); return; }
        try {
            const data = verifiedStatus(await api('account/phone'));
            if (version !== generation || !visible()) return;
            current = data; challenge = data.challenge ?? null;
            root.hidden = false; render();
        } catch {
            if (version === generation && visible()) showClosed(true);
        }
    }
    function obscure() {
        // Visibility and BFCache navigation both invalidate old private
        // phone, OTP, password, consent and in-flight status responses.
        generation++; busy = false; current = challenge = intent = null;
        root.querySelectorAll('input').forEach(n => {
            n.value = ''; if (n.type === 'checkbox') n.checked = false;
        });
        showClosed();
    }
    document.addEventListener?.('visibilitychange', () => {
        if (!visible()) obscure();
    });
    window.addEventListener?.('pagehide', obscure);
    return {setActor, clear};
})();
