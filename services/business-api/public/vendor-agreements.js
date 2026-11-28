/* Private, explicit agreement review. No browser persistence or operational authority. */
window.EvVendorAgreements = {
    async open({host, api, company, current, notice}) {
        host.querySelector('[data-vendor-agreements]')?.remove();
        const root = document.createElement('section');
        root.dataset.vendorAgreements = '';
        host.append(root);
        let generation = 0, busy = false;
        const valid = epoch => current() && root.isConnected && epoch === generation;
        const ar = () => document.documentElement.lang === 'ar';
        const t = (en, arabic) => ar() ? arabic : en;
        const node = (tag, text) => {
            const n = document.createElement(tag);
            if (text !== undefined) n.textContent = text;
            return n;
        };
        function receiptSummary(before, createdAt, after) {
            const summary = node('p');
            summary.append(...before, window.EvStationTime.bindServerEventTime(node('span'), createdAt), ...after);
            return summary;
        }
        function translated(tag, en, arabic) {
            const english = en ?? '';
            const localized = arabic ?? english;
            const element = node(tag, t(english, localized));
            element.dataset.en = english;
            element.dataset.ar = localized;
            return element;
        }
        const base = `vendor-ops/companies/${company.id}/agreement`;
        const error = () => {
            const status = translated('p',
                'Unable to complete this request. Refresh the recorded state before continuing.',
                'تعذر إكمال الطلب. حدّث الحالة المسجلة قبل المتابعة.');
            status.setAttribute('role', 'status');
            root.append(status);
        };
        function button(en, arabic, action) {
            const b = translated('button', en, arabic); b.type = 'button';
            b.onclick = async () => {
                if (busy || !current()) return;
                busy = true; b.disabled = true;
                try { await action(); } catch (e) { if (current() && root.isConnected) { error(); notice(e); } }
                finally { busy = false; if (b.isConnected) b.disabled = false; }
            };
            root.append(b);
            return b;
        }
        function version(v) {
            root.append(translated('h4', v.title_en, v.title_ar), node('p', `${v.country} · ${v.revision} · ${v.sha256}`));
            const body = translated('pre', v.body_en, v.body_ar);
            body.style.whiteSpace = 'pre-wrap';
            body.style.overflowWrap = 'anywhere';
            root.append(body);
        }
        async function load(page = 1) {
            const epoch = ++generation;
            root.replaceChildren(translated('h3', 'Vendor agreement', 'اتفاقية الشركة'));
            const data = await api(`${base}?page=${page}`);
            if (!valid(epoch)) return;
            root.append(translated('p', 'Recorded agreement evidence does not approve a company, authorize charging or payments, or establish legal acceptance. Only the company owner can accept.', 'دليل الاتفاقية المسجل لا يعتمد الشركة أو يفوض الشحن أو الدفع ولا يثبت القبول القانوني. يمكن لمالك الشركة فقط الموافقة.'));
            button('Refresh', 'تحديث', () => load(page));
            if (data.version) {
                version(data.version);
                if (data.acceptance) {
                    root.append(receiptSummary(
                        [translated('span', 'Accepted', 'تمت الموافقة'), ' · '],
                        data.acceptance.created_at,
                        [' · ', node('bdi', data.acceptance.id)]
                    ));
                }
                if (data.can_accept) {
                    const label = node('label'), consent = node('input'); consent.type = 'checkbox';
                    label.append(consent, translated('span', 'I reviewed this exact version and am authorized to accept for this company.', 'راجعت هذه النسخة بالتحديد وأنا مخول بالموافقة عن هذه الشركة.'));
                    root.append(label);
                    const accept = button('Accept reviewed agreement', 'الموافقة على الاتفاقية المراجعة', async () => {
                        if (!consent.checked || !valid(epoch)) return;
                        await api(base + '/accept', 'POST', {version_id: data.version.id, sha256: data.version.sha256, accepted: true});
                        if (valid(epoch)) await load();
                    });
                    accept.disabled = true;
                    consent.onchange = () => accept.disabled = !consent.checked || busy;
                }
            } else root.append(translated('p', 'No agreement is published for this country. Contact the administrator.', 'لا توجد اتفاقية منشورة لهذا البلد. تواصل مع المسؤول.'));
            root.append(translated('h4', 'Acceptance history', 'سجل الموافقات'));
            for (const record of data.history.data) {
                root.append(receiptSummary(
                    [node('bdi', `${record.country} · ${record.revision}`), ' · '],
                    record.created_at,
                    []
                ));
                button('Read immutable receipt', 'قراءة الإيصال الثابت', async () => {
                    const receipt = await api(`${base}/receipts/${record.id}`);
                    if (!valid(epoch)) return;
                    const heading = translated('h3', 'Agreement receipt', 'إيصال الاتفاقية');
                    heading.tabIndex = -1;
                    root.replaceChildren(heading);
                    version(receipt.version);
                    root.append(receiptSummary(
                        [node('bdi', receipt.receipt.id), ' · '],
                        receipt.receipt.created_at,
                        [' · ', node('bdi', receipt.receipt.version_sha256)]
                    ));
                    button('Back', 'رجوع', () => load(page));
                    heading.focus();
                });
            }
            if (data.history.current_page > 1) button('Previous', 'السابق', () => load(page - 1));
            if (data.history.current_page < data.history.last_page) button('Next', 'التالي', () => load(page + 1));
            if (data.can_publish) {
                root.append(translated('h4', 'Publish a country agreement', 'نشر اتفاقية للبلد'),
                    translated('p', 'This replaces the current version for every company in this country. Supply approved bilingual text and its publication reference. Existing receipts are preserved.', 'يستبدل هذا النسخة الحالية لجميع الشركات في هذا البلد. أدخل النص المعتمد باللغتين ومرجع النشر. تبقى الإيصالات السابقة محفوظة.'));
                const form = node('form'), fields = {};
                for (const [name, en, arabic] of [
                    ['title_en', 'English title', 'العنوان الإنجليزي'], ['title_ar', 'Arabic title', 'العنوان العربي'],
                    ['body_en', 'English agreement', 'الاتفاقية الإنجليزية'], ['body_ar', 'Arabic agreement', 'الاتفاقية العربية'],
                    ['publication_reference', 'Publication approval reference', 'مرجع اعتماد النشر'], ['password', 'Current Admin password', 'كلمة مرور المسؤول الحالية']
                ]) {
                    const label = node('label'), field = node(name.startsWith('body_') ? 'textarea' : 'input');
                    label.append(translated('span', en, arabic));
                    field.name = name; field.required = true; field.maxLength = name.startsWith('body_') ? 32000 : name.startsWith('title_') ? 200 : name === 'password' ? 128 : 100;
                    if (name === 'password') { field.type = 'password'; field.autocomplete = 'current-password'; }
                    fields[name] = field; label.append(field); form.append(label);
                }
                const approval = node('input'); approval.type = 'checkbox'; approval.required = true;
                const label = node('label');
                label.append(approval, translated('span', 'I confirm this text is approved for publication to this country.', 'أؤكد أن هذا النص معتمد للنشر لهذا البلد.'));
                form.append(label);
                const publish = translated('button', 'Publish new version', 'نشر نسخة جديدة'); publish.type = 'submit'; form.append(publish); root.append(form);
                form.onsubmit = async event => {
                    event.preventDefault(); if (busy || !valid(epoch) || !form.reportValidity()) return;
                    busy = true; publish.disabled = true;
                    const body = {expected_revision: data.revision, approved_for_publication: approval.checked};
                    for (const [name, field] of Object.entries(fields)) body[name] = field.value;
                    fields.password.value = '';
                    try { await api(base + '/publish', 'POST', body); if (valid(epoch)) await load(); }
                    catch (e) { if (valid(epoch)) { error(); notice(e); } }
                    finally { delete body.password; busy = false; if (publish.isConnected) publish.disabled = false; }
                };
                if (data.version) {
                    const withdrawLabel = node('label'), confirmed = node('input'); confirmed.type = 'checkbox';
                    withdrawLabel.append(confirmed, translated('span', 'I confirm withdrawal for all companies in this country. Existing receipts remain.', 'أؤكد سحب الاتفاقية لجميع الشركات في هذا البلد. تبقى الإيصالات السابقة.')); root.append(withdrawLabel);
                    const withdraw = button('Withdraw current agreement', 'سحب الاتفاقية الحالية', async () => {
                        if (!confirmed.checked || !fields.password.value || !valid(epoch)) return;
                        const body = {password: fields.password.value, expected_revision: data.revision, version_id: data.version.id, confirmed: true};
                        fields.password.value = '';
                        try { await api(base + '/withdraw', 'POST', body); if (valid(epoch)) await load(); }
                        finally { delete body.password; }
                    });
                    const enabled = () => withdraw.disabled = busy || !confirmed.checked || !fields.password.value;
                    confirmed.onchange = enabled;
                    fields.password.addEventListener('input', enabled);
                    enabled();
                }
            }
        }
        function privateClear() { generation++; root.replaceChildren(); }
        const observer = new MutationObserver(() => { if (!root.isConnected) { document.removeEventListener('visibilitychange', visibility); observer.disconnect(); } });
        const visibility = () => { if (document.hidden) privateClear(); };
        document.addEventListener('visibilitychange', visibility);
        observer.observe(document.body, {childList: true, subtree: true});
        try { await load(); } catch (e) { if (current() && root.isConnected) { error(); notice(e); } }
    }
};
