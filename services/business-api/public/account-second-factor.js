"use strict";
window.EvAccountSecondFactor = (() => {
    const root = document.getElementById('account-second-factor');
    let generation = 0, request, state, seed, codes = [], busy = false;
    const label = (tag, en, ar) => {
        const node = document.createElement(tag); node.dataset.en = en; node.dataset.ar = ar;
        node.textContent = document.documentElement.lang === 'ar' ? ar : en; return node;
    };
    function clear() { generation++; request = state = seed = null; codes = []; busy = false; root.replaceChildren(); root.hidden = true; }
    function input(form, name, en, ar) {
        const row = label('label', en, ar), field = document.createElement('input');
        field.name = name; field.type = 'password'; field.required = true; field.maxLength = 128;
        field.autocomplete = name === 'password' ? 'current-password' : 'one-time-code';
        row.append(field); form.append(row); return field;
    }
    function valid(data) { if (!data || typeof data.enabled !== 'boolean' || typeof data.pending !== 'boolean') throw Error('Invalid factor status'); return data; }
    function render() {
        root.replaceChildren(); root.append(label('h2', 'Authenticator two-factor authentication', 'المصادقة الثنائية بتطبيق التحقق'));
        root.append(label('p', state.enabled ? 'Authenticator is enabled.' : 'Authenticator is not enabled.', state.enabled ? 'تطبيق التحقق مفعّل.' : 'تطبيق التحقق غير مفعّل.'));
        if (state.enabled && Number.isInteger(state.recovery_remaining)) root.append(label('p', 'Recovery codes remaining: ' + state.recovery_remaining, 'رموز الاسترداد المتبقية: ' + state.recovery_remaining));
        const notice = label('p', '', ''); notice.setAttribute('role', 'status'); root.append(notice);
        if (seed) {
            root.append(label('p', 'Add this secret to your authenticator, then confirm a fresh code. Never share the secret.', 'أضف هذا المفتاح إلى تطبيق التحقق ثم أكّد برمز جديد. لا تشارك المفتاح.'));
            const value = document.createElement('pre'); value.textContent = seed; root.append(value);
        }
        if (codes.length) {
            root.append(label('p', 'Save these one-time recovery codes now. They cannot be displayed again.', 'احفظ رموز الاسترداد الآن. كل رمز يستخدم مرة واحدة ولن يعرض مجدداً.'));
            const value = document.createElement('pre'); value.textContent = codes.join('\n'); root.append(value);
        }
        const version = generation;
        async function act(action) {
            if (busy || version !== generation) return;
            busy = true; root.querySelectorAll('button').forEach(n => n.disabled = true);
            try { await action(); }
            catch { if (version === generation) notice.replaceChildren(label('p', 'Action not confirmed. Refresh status and use your current password and a fresh authenticator or unused recovery code.', 'لم يتأكد الإجراء. حدّث الحالة واستخدم كلمة مرورك الحالية ورمز تحقق جديداً أو رمز استرداد غير مستخدم.')); }
            finally { if (version === generation) { busy = false; root.querySelectorAll('button').forEach(n => n.disabled = false); } }
        }
        const refresh = label('button', 'Refresh authenticator status', 'تحديث حالة تطبيق التحقق'); refresh.type = 'button';
        refresh.onclick = () => act(async () => { const data = valid(await request('account/second-factor')); if (version !== generation) return; state = data; seed = null; codes = []; render(); }); root.append(refresh);
        function form(action, en, ar, needsCode) {
            const form = document.createElement('form'), password = input(form, 'password', 'Current password', 'كلمة المرور الحالية');
            const code = needsCode ? input(form, 'code', action === 'enable' ? 'Fresh authenticator code' : 'Fresh authenticator or unused recovery code', action === 'enable' ? 'رمز تحقق جديد' : 'رمز تحقق جديد أو رمز استرداد غير مستخدم') : null;
            const button = label('button', en, ar); button.type = 'submit'; form.append(button); root.append(form);
            form.onsubmit = event => { event.preventDefault(); if (busy) return;
                const body = {password: password.value}; password.value = '';
                if (code) { body.code = code.value.trim(); code.value = ''; }
                act(async () => {
                    const reply = await request('account/second-factor/' + action, 'POST', body);
                    if (version !== generation) return;
                    if (action === 'prepare') { if (typeof reply.secret !== 'string' || !/^[A-Z2-7]{32}$/.test(reply.secret)) throw Error('Invalid seed'); seed = reply.secret; codes = []; }
                    else { if (!Array.isArray(reply.recovery_codes)) throw Error('Invalid recovery codes'); seed = null; codes = reply.recovery_codes; }
                    const updated = valid(await request('account/second-factor')); if (version !== generation) return;
                    state = updated; render();
                });
            };
        }
        if (!state.enabled) {
            form('prepare', 'Prepare authenticator setup', 'إعداد تطبيق التحقق', false);
            if (state.pending) form('enable', 'Enable authenticator', 'تفعيل تطبيق التحقق', true);
        } else {
            form('recovery-codes', 'Replace recovery codes', 'استبدال رموز الاسترداد', true);
            form('disable', 'Disable authenticator', 'إيقاف تطبيق التحقق', true);
        }
    }
    async function setActor(actor, api) {
        clear(); if (!['customer', 'vendor'].includes(actor?.role)) return;
        request = api; const version = generation;
        try { const data = valid(await api('account/second-factor')); if (version !== generation) return; state = data; root.hidden = false; render(); }
        catch { if (version === generation) { root.hidden = false; root.append(label('p', 'Authenticator status is unavailable.', 'حالة تطبيق التحقق غير متاحة.')); } }
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' && state) { generation++; busy = false; seed = null; codes = []; root.querySelectorAll('input').forEach(n => n.value = ''); render(); }
    });
    return {setActor, clear};
})();
