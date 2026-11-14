/* Separate consent; no recipient editing, background sends or automatic write retries. */
window.EvNotificationEmail = {
    async open({host, api, current}) {
        host.querySelector('[data-email-notices]')?.remove();
        const root = document.createElement('section'); root.dataset.emailNotices = ''; host.append(root);
        let generation = 0, busy = false;
        const t = (en, ar) => document.documentElement.lang === 'ar' ? ar : en;
        const node = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
        const valid = n => current() && root.isConnected && !document.hidden && n === generation;
        const base = 'notifications/email';
        const failed = n => {
            if (valid(n)) { root.replaceChildren(node('p', t('Request outcome unavailable. Refresh before making another change.', 'نتيجة الطلب غير متاحة. حدّث قبل إجراء تغيير آخر.'))); button(t('Refresh', 'تحديث'), () => load()); }
        };
        function button(text, action) {
            const b = node('button', text); b.type = 'button'; root.append(b);
            b.onclick = async () => { if (busy || !valid(generation)) return; busy = true; b.disabled = true; const n = generation;
                try { await action(); } catch { failed(n); } finally { busy = false; if (b.isConnected) b.disabled = false; }
            }; return b;
        }
        async function load(page = 1) {
            const n = ++generation; root.replaceChildren();
            const [data, history] = await Promise.all([api(base), api(`${base}/history?page=${page}`)]);
            if (!valid(n)) return;
            if (typeof data.revision !== 'string' || !/^(0|[1-9][0-9]{0,18})$/.test(data.revision) || history.delivery_confirmed !== false) throw Error('Invalid response');
            root.append(node('h3', t('Email activity notices', 'إشعارات النشاط بالبريد')), node('p', t('Optional generic notices to your verified account email. New unread activity only, at most once every 15 minutes. Message details stay in your account. Transport acceptance does not confirm delivery.', 'إشعارات عامة اختيارية إلى بريد حسابك الموثق للنشاط الجديد غير المقروء فقط، بحد أقصى مرة كل 15 دقيقة. تبقى التفاصيل في الحساب. قبول النقل لا يؤكد التسليم.')));
            root.append(node('p', data.consent_current ? t('Enabled with current consent', 'مفعّل بالموافقة الحالية') : t('No current delivery consent', 'لا توجد موافقة إرسال حالية')));
            if (!data.configured) root.append(node('p', t('Delivery is paused: server transport is not configured.', 'الإرسال متوقف: النقل غير مهيأ على الخادم.')));
            if (data.verified_email_required) root.append(node('p', t('Verify your account email before enabling.', 'وثّق بريد حسابك قبل التفعيل.')));
            button(t('Refresh', 'تحديث'), () => load(page));
            if (data.enabled) button(t('Disable email notices', 'تعطيل إشعارات البريد'), async () => {
                await api(base, 'PUT', {enabled: false, expected_revision: data.revision}); if (valid(n)) await load();
            });
            if (!data.consent_current && data.configured && !data.verified_email_required) {
                const label = node('label'), consent = node('input'); consent.type = 'checkbox';
                label.append(consent, document.createTextNode(t('I consent to generic email activity notices.', 'أوافق على إشعارات النشاط العامة بالبريد.'))); root.append(label);
                const enable = button(t('Enable with consent', 'تفعيل بالموافقة'), async () => {
                    if (!consent.checked || !valid(n)) return;
                    await api(base, 'PUT', {enabled: true, confirmed: true, expected_revision: data.revision}); if (valid(n)) await load();
                }); enable.disabled = true; consent.onchange = () => enable.disabled = !consent.checked || busy;
            }
            root.append(node('h4', t('Handoff history — delivery unconfirmed', 'سجل النقل — التسليم غير مؤكد')));
            const labels = {queued: t('Queued', 'في الانتظار'), claimed: t('Claimed; review if interrupted', 'تم الحجز؛ راجع عند الانقطاع'), handed_off: t('Transport accepted; delivery unconfirmed', 'قبله النقل؛ التسليم غير مؤكد'), outcome_unknown: t('Outcome unknown; no automatic retry', 'نتيجة غير معروفة؛ لا إعادة تلقائية'), cancelled: t('Cancelled', 'ملغى')};
            for (const row of history.receipts.data) root.append(node('p', `${row.created_at} · ${labels[row.state] || t('Review required', 'تلزم المراجعة')} · ${row.evidence || ''}`));
            if (page > 1) button(t('Previous', 'السابق'), () => load(page - 1));
            if (history.receipts.current_page < history.receipts.last_page) button(t('Next', 'التالي'), () => load(page + 1));
        }
        const hide = () => { if (document.hidden || !current()) { generation++; busy = false; root.replaceChildren(); } if (!root.isConnected) document.removeEventListener('visibilitychange', hide); };
        document.addEventListener('visibilitychange', hide);
        try { await load(); } catch { failed(generation); }
    },
};
