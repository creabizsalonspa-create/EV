/* Nonfinancial peer trust only. No source fetch, activation, command or payment. */
window.EvOcpiAgreements = (() => {
  const viewers = new WeakMap();
  const label = (tag, en, ar) => {
    if (tag === 'label') { const node = document.createElement('label'); node.append(label('span', en, ar)); return node; }
    const node = document.createElement(tag);
    node.dataset.en = en; node.dataset.ar = ar;
    node.textContent = document.documentElement.lang === 'ar' ? ar : en;
    return node;
  };
  const literal = (tag, value) => { const node = document.createElement(tag); node.textContent = String(value ?? ''); return node; };
  const safe = n => Number.isSafeInteger(n) && n >= 0;
  const uuid = value => typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
  function valid(value, id) {
    if (!value || value.provider_id !== id || !safe(value.revision) || value.revision > 2147483647 ||
        typeof value.required !== 'boolean' || value.external_requests_made !== false || value.partner_acceptance_verified !== false ||
        value.commands_enabled !== false || value.billing_eligible !== false || !value.agreements || !Array.isArray(value.agreements.data) ||
        value.agreements.data.length > 10 || !safe(value.agreements.current_page) || value.agreements.current_page < 1 ||
        !safe(value.agreements.last_page) || value.agreements.last_page < value.agreements.current_page ||
        (value.active_agreement_id !== null && (!safe(value.active_agreement_id) || value.active_agreement_id < 1))) return false;
    const seen = new Set();
    return value.agreements.data.every(row => {
      if (!row || !safe(row.id) || !row.id || seen.has(row.id) || !safe(row.revision) || !row.revision || row.revision > value.revision ||
          !uuid(row.request_key) || typeof row.current !== 'boolean' || typeof row.configuration_current !== 'boolean' ||
          !['proposed', 'approved', 'rejected', 'revoked'].includes(row.state) ||
          !['reference', 'terms_en', 'terms_ar', 'evidence_sha256', 'valid_from', 'valid_until'].every(k => typeof row[k] === 'string') ||
          !/^[a-f0-9]{64}$/.test(row.evidence_sha256) || !Array.isArray(row.allowed_modules) || !row.allowed_modules.length ||
          row.allowed_modules.length > 4 || new Set(row.allowed_modules).size !== row.allowed_modules.length ||
          !row.allowed_modules.every(m => ['locations', 'tariffs', 'sessions', 'cdrs'].includes(m)) ||
          !Array.isArray(row.decisions) || row.decisions.length > 2 ||
          !row.decisions.every(e => ['approved', 'rejected', 'revoked'].includes(e.action) && uuid(e.request_key) && typeof e.reason === 'string')) return false;
      seen.add(row.id); return true;
    });
  }
  async function open({host, providerId, api, current, onAuthorityChange}) {
    viewers.get(host)?.();
    if (!safe(providerId) || !providerId || document.hidden || !current()) return;
    const root = document.createElement('section'); root.dataset.ocpiAgreements = ''; root.style.overflowWrap = 'anywhere';
    host.replaceChildren(root);
    let sequence = 0, request, busy = false, pending = null, pendingKey = null, page = 1;
    const active = n => n === sequence && !document.hidden && current() && root.isConnected;
    const button = (parent, en, ar, action) => {
      const b = label('button', en, ar); b.type = 'button';
      b.onclick = async () => { if (!busy && active(sequence)) await action(); };
      parent.append(b); return b;
    };
    const erase = () => {
      sequence++; request?.abort(); request = null; busy = false; pending = null;
      for (const field of root.querySelectorAll('input,textarea')) { field.value = ''; if (field.type === 'checkbox') field.checked = false; }
      root.replaceChildren(label('p', 'Private agreement view cleared. Reload explicitly to review current evidence.', 'تم مسح عرض الاتفاقية الخاص. أعد التحميل يدوياً لمراجعة الأدلة الحالية.'));
      button(root, 'Reload agreement history', 'إعادة تحميل سجل الاتفاقيات', () => load(page));
    };
    const visibility = () => { if (document.hidden) erase(); };
    const observer = new MutationObserver(() => { if (!root.isConnected) cleanup(); });
    function cleanup() { sequence++; request?.abort(); pending = null; pendingKey = null; observer.disconnect(); document.removeEventListener('visibilitychange', visibility); window.removeEventListener('pagehide', erase); }
    observer.observe(document.body, {childList: true, subtree: true});
    document.addEventListener('visibilitychange', visibility); window.addEventListener('pagehide', erase);
    viewers.set(host, cleanup);
    async function write(path, body) {
      if (busy || !active(sequence)) return;
      const n = sequence; busy = true;
      pending = {path, body}; pendingKey = body.request_key;
      root.querySelectorAll('button,input,textarea').forEach(e => e.disabled = true);
      try {
        const response = await api(path, 'POST', body);
        if (!active(n)) return;
        if (!safe(response?.agreement_id) || response.agreement_id < 1 || response.external_requests_made !== false || response.provider_activated !== false) throw Error('Unexpected agreement result');
        pending = null; pendingKey = null; busy = false;
        if (response.credentials_require_separate_review === true && onAuthorityChange) { await onAuthorityChange(); return; }
        await load(1);
      } catch {
        if (!active(n)) return;
        busy = false;
        root.replaceChildren(label('p', 'No confirmed outcome. Review recorded history or retry exactly the same action; nothing is retried automatically.', 'لم تتأكد النتيجة. راجع السجل أو أعد نفس الإجراء تماماً؛ لا تتم أي إعادة تلقائية.'));
        button(root, 'Retry exact same action', 'إعادة نفس الإجراء تماماً', () => { const saved = pending; if (saved) return write(saved.path, saved.body); });
        button(root, 'Review recorded history', 'مراجعة السجل المسجل', () => load(page));
      }
    }
    function field(form, key, en, ar, {area = false, max = 120, direction} = {}) {
      const l = label('label', en, ar), input = document.createElement(area ? 'textarea' : 'input');
      input.name = key; input.required = true; input.maxLength = max; input.autocomplete = 'off';
      if (direction) input.dir = direction;
      l.append(input); form.append(l); return input;
    }
    function render(data) {
      root.replaceChildren(label('h3', 'OCPI technical agreements', 'اتفاقيات OCPI التقنية'),
        label('p', 'Administrative evidence only. Approval withdraws existing provider and credential authority; separate authorization and enrollment are required. No commands, billing or live partner acceptance are granted.', 'أدلة إدارية فقط. تسحب الموافقة صلاحيات المزود وبيانات الاعتماد الحالية؛ يلزم تصريح وتسجيل منفصلان. لا تُمنح صلاحيات أوامر أو فوترة أو قبول شريك فعلي.'));
      root.append(label('p', data.active_agreement_id === null ? 'No effective approved agreement at this read.' : `Effective approved agreement: ${data.active_agreement_id}`, data.active_agreement_id === null ? 'لا توجد اتفاقية معتمدة نافذة عند هذه القراءة.' : `الاتفاقية المعتمدة النافذة: ${data.active_agreement_id}`));
      if (data.configured_identity) root.append(literal('p', `${data.configured_identity.local_country}/${data.configured_identity.local_party} ${data.configured_identity.local_role} ↔ ${data.configured_identity.peer_country}/${data.configured_identity.peer_party} ${data.configured_identity.peer_role} · OCPI 2.2.1`));
      else root.append(label('p', 'Configure the bilateral peer identity on the server before proposing terms.', 'هيّئ هوية الطرفين على الخادم قبل اقتراح الشروط.'));
      const recorded = data.agreements.data.some(row => row.request_key === pendingKey || row.decisions.some(e => e.request_key === pendingKey));
      if (recorded) { pending = null; pendingKey = null; }
      if (pendingKey) {
        root.append(label('p', 'An earlier outcome remains unconfirmed. The server revision guard still applies. Review before a new action.', 'ما زالت نتيجة إجراء سابق غير مؤكدة. تبقى حماية إصدار الخادم فعالة. راجع قبل إجراء جديد.'));
        if (pending) button(root, 'Retry exact same action', 'إعادة نفس الإجراء تماماً', () => write(pending.path, pending.body));
        button(root, 'Discard local draft and reload', 'حذف المسودة المحلية وإعادة التحميل', () => { pending = null; pendingKey = null; return load(page); });
      }
      button(root, 'Refresh agreements', 'تحديث الاتفاقيات', () => load(page));
      if (!pendingKey && data.configured_identity) {
        const form = document.createElement('form'); form.autocomplete = 'off'; form.dataset.agreementPropose = '';
        form.append(label('h4', 'Propose a new immutable version', 'اقتراح إصدار جديد غير قابل للتعديل'));
        const reference = field(form, 'reference', 'Evidence review reference', 'مرجع مراجعة الأدلة');
        const evidence = field(form, 'evidence_sha256', 'Approved document SHA-256 (not a signature check)', 'بصمة SHA-256 للمستند المعتمد (ليست تحققاً من التوقيع)', {max: 64, direction: 'ltr'});
        evidence.pattern = '[a-fA-F0-9]{64}';
        const english = field(form, 'terms_en', 'Technical terms (English)', 'الشروط التقنية (الإنجليزية)', {area: true, max: 6000, direction: 'ltr'});
        const arabic = field(form, 'terms_ar', 'Technical terms (Arabic)', 'الشروط التقنية (العربية)', {area: true, max: 6000, direction: 'rtl'});
        english.minLength = arabic.minLength = 20;
        const from = field(form, 'valid_from', 'Effective from (UTC, YYYY-MM-DDTHH:mm:ssZ)', 'سارية من (UTC، YYYY-MM-DDTHH:mm:ssZ)', {max: 20, direction: 'ltr'});
        const until = field(form, 'valid_until', 'Expires at (UTC, YYYY-MM-DDTHH:mm:ssZ)', 'تنتهي عند (UTC، YYYY-MM-DDTHH:mm:ssZ)', {max: 20, direction: 'ltr'});
        const modules = [];
        for (const name of ['locations', 'tariffs', 'sessions', 'cdrs']) {
          const l = literal('label', name), checked = document.createElement('input'); checked.type = 'checkbox'; checked.name = 'module'; checked.value = name; l.prepend(checked); form.append(l); modules.push(checked);
        }
        const error = document.createElement('p'); error.setAttribute('role', 'status'); form.append(error);
        const save = label('button', 'Record proposed terms', 'تسجيل الشروط المقترحة'); save.type = 'submit'; form.append(save);
        form.onsubmit = async event => {
          event.preventDefault(); if (busy || pendingKey || !active(sequence)) return;
          const allowed = modules.filter(m => m.checked).map(m => m.value);
          if (!allowed.length) { error.replaceChildren(label('span', 'Select at least one data module.', 'اختر وحدة بيانات واحدة على الأقل.')); return; }
          return write(`ocpi/providers/${providerId}/technical-agreements`, {request_key: crypto.randomUUID(), expected_revision: data.revision,
            reference: reference.value.trim(), evidence_sha256: evidence.value.trim().toLowerCase(), terms_en: english.value, terms_ar: arabic.value,
            allowed_modules: allowed, valid_from: from.value.trim(), valid_until: until.value.trim()});
        };
        root.append(form);
      }
      for (const row of data.agreements.data) {
        const card = document.createElement('article'); card.dataset.agreementId = String(row.id);
        card.append(literal('h4', `${row.revision} · ${row.reference}`), label('p', {proposed:'Proposed',approved:'Approved',rejected:'Rejected',revoked:'Revoked'}[row.state], {proposed:'مقترحة',approved:'معتمدة',rejected:'مرفوضة',revoked:'ملغاة'}[row.state]),
          literal('p', `${row.valid_from} → ${row.valid_until} (UTC)`), literal('p', row.allowed_modules.join(', ')), literal('p', row.evidence_sha256));
        const en = literal('p', row.terms_en), ar = literal('p', row.terms_ar); en.dir = 'ltr'; ar.dir = 'rtl'; en.style.whiteSpace = ar.style.whiteSpace = 'pre-wrap'; card.append(en, ar);
        if (!row.configuration_current) card.append(label('p', 'Configured peer identity changed; this version cannot approve new access.', 'تغيرت هوية الشريك المهيأة؛ لا يمكن لهذا الإصدار اعتماد وصول جديد.'));
        for (const event of row.decisions) card.append(literal('p', `${event.action} · ${event.created_at} · ${event.reason}`));
        if (!pendingKey && ((row.state === 'proposed' && row.revision === data.revision) || (row.current && row.state === 'approved'))) {
          const form = document.createElement('form'); form.autocomplete = 'off';
          const reason = field(form, 'reason', 'Review / revocation reason', 'سبب المراجعة / الإلغاء', {area: true, max: 2000}); reason.minLength = 10;
          const ack = label('label', 'I reviewed the evidence, bilateral identity, scope and effective dates.', 'راجعت الأدلة وهوية الطرفين والنطاق وتواريخ السريان.'), checked = document.createElement('input'); checked.type = 'checkbox'; checked.required = true; ack.prepend(checked); form.append(ack);
          for (const action of row.state === 'proposed' ? ['approved', 'rejected'] : ['revoked']) {
            const b = label('button', {approved:'Approve technical evidence',rejected:'Reject proposed terms',revoked:'Revoke current agreement'}[action], {approved:'اعتماد الأدلة التقنية',rejected:'رفض الشروط المقترحة',revoked:'إلغاء الاتفاقية الحالية'}[action]); b.type = 'button';
            b.disabled = action === 'approved' && !row.configuration_current;
            b.onclick = () => { if (!busy && !pendingKey && active(sequence) && form.reportValidity()) return write(`ocpi/providers/${providerId}/technical-agreements/${row.id}/decision`, {request_key: crypto.randomUUID(), expected_revision: data.revision, action, reason: reason.value}); };
            form.append(b);
          }
          card.append(form);
        }
        root.append(card);
      }
      for (const [en, ar, target] of [['Previous','السابق',page-1],['Next','التالي',page+1]]) {
        const b = button(root, en, ar, () => load(target)); b.disabled = target < 1 || target > data.agreements.last_page;
      }
    }
    async function load(target) {
      if (busy || document.hidden || !current()) return;
      request?.abort(); request = new AbortController(); const n = ++sequence; busy = true; page = target;
      root.replaceChildren(label('p', 'Loading private agreement history…', 'جارٍ تحميل سجل الاتفاقيات الخاص…'));
      try {
        const data = await api(`ocpi/providers/${providerId}/technical-agreements?page=${target}`, 'GET', undefined, request.signal);
        if (!active(n)) return;
        if (!valid(data, providerId) || data.agreements.current_page !== target) throw Error('Unsupported agreement contract');
        const recordedAuthority = pendingKey && data.agreements.data.some(row => row.decisions.some(e => e.request_key === pendingKey && ['approved', 'revoked'].includes(e.action)));
        if (recordedAuthority && onAuthorityChange) { pending = null; pendingKey = null; await onAuthorityChange(); return; }
        render(data);
      } catch {
        if (!active(n)) return;
        root.replaceChildren(label('p', 'Agreement history unavailable. No provider action was taken.', 'سجل الاتفاقيات غير متاح. لم يُتخذ أي إجراء للمزود.'));
        button(root, 'Retry history read', 'إعادة قراءة السجل', () => load(page));
      } finally { if (n === sequence) busy = false; }
    }
    await load(1);
    return cleanup;
  }
  return {open, valid};
})();
