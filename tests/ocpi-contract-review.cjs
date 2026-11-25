const { chromium } = require('playwright');
const assert = require('node:assert/strict');

// Browser-only, source-safe fixture: no contract upload, provider request,
// charging command, payment or remote authorization takes place here.
(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.EV_CHROMIUM_PATH || undefined,
        args: ['--no-sandbox'],
    });
    try {
        for (const arabic of [false, true]) {
            const page = await browser.newPage();
            const language = arabic ? 'ar' : 'en';
            await page.setContent('<html lang="' + language +
                '"><body><section id="public"></section><section id="private"></section></body></html>');
            await page.addScriptTag({ path: 'services/business-api/public/roaming.js' });
            await page.evaluate(async () => {
                window.writeCount = 0;
                window.viewer = { id: 45, role: 'admin' };
                window.EvLocale = {
                    text: value => value,
                    attribute: (node, key, value) => node.setAttribute(key, value),
                };
                window.syntheticReview = {
                    state: 'missing', revision: 0, review_valid_for_current_owner: false,
                    valid_until: null, source: 'INTERNAL_MANUAL_DOCUMENT_DIGEST_ONLY',
                    external_partner_acceptance_verified: false,
                    contract_legally_executed_verified: false,
                    live_roaming_enabled: false, payment_authority: false,
                    automatic_partner_requests: 0,
                };
                const paged = data => ({
                    data, current_page: 1, last_page: 1, total: data.length,
                });
                window.workspace = window.createRoamingWorkspace({
                    publicRoot: document.querySelector('#public'),
                    privateRoot: document.querySelector('#private'),
                    actor: () => window.viewer,
                    notice: error => { throw error; },
                    ui: (tag, text) => {
                        const node = document.createElement(tag);
                        node.textContent = text;
                        return node;
                    },
                    api: async (path, method = 'GET', body) => {
                        if (path === 'ocpi/connection-options')
                            return { data: [], external_requests_made: false };
                        if (path === 'ocpi/owner-options?page=1')
                            return paged([]);
                        if (path.includes('/contract-review')) {
                            if (path.endsWith('/history')) {
                                return {
                                    scope: 'private_ocpi_contract_review_history',
                                    current_revision: window.syntheticReview.revision,
                                    history: {
                                        total: window.syntheticReview.revision,
                                        data: window.syntheticReview.revision ? [{
                                            revision: window.syntheticReview.revision,
                                            decision: window.syntheticReview.state === 'revoked' ? 'revoke' : 'review',
                                            recorded_at: '2026-11-23T09:00:00Z',
                                            ...(window.viewer?.role === 'admin' ? {
                                                reason: 'Synthetic internal document check, not external partner approval.',
                                                reference_id: 'LEGAL-SYNTHETIC-CASE-001',
                                                evidence_sha256: 'a'.repeat(64),
                                            } : {}),
                                        }] : [],
                                    },
                                    external_partner_acceptance_verified: false,
                                    contract_legally_executed_verified: false,
                                    live_roaming_enabled: false,
                                    payment_authority: false,
                                };
                            }
                            if (method === 'POST') {
                                if (window.viewer?.role !== 'admin' ||
                                    !body.idempotency_key ||
                                    body.expected_revision !== window.syntheticReview.revision)
                                    throw Error('Invalid synthetic contract decision');
                                window.writeCount++;
                                const reviewed = body.decision === 'review';
                                window.syntheticReview = {
                                    ...window.syntheticReview,
                                    state: reviewed ? 'internally_reviewed' : 'revoked',
                                    revision: window.syntheticReview.revision + 1,
                                    review_valid_for_current_owner: reviewed,
                                    valid_until: reviewed ? body.valid_until : null,
                                };
                            }
                            return { ...window.syntheticReview };
                        }
                        if (method !== 'GET') throw Error('Unexpected network mutation');
                        if (path.startsWith('ocpi/providers'))
                            return paged([{
                                id: 7, owner_id: 27, name: 'Private synthetic OCPI partner',
                                authorized: false, version: null,
                            }]);
                        return paged([]);
                    },
                });
                await window.workspace.setActor();
            });
            await page.getByRole('button', { name: 'Partner contract review', exact: true }).click();
            await page.getByText(arabic ? 'مفقودة' : 'Missing', { exact: false }).waitFor();
            assert.equal(await page.getByText(arabic
                ? 'هذه مراجعة داخلية لبصمة المستند، وليست عقداً موقعاً أو قبولاً من الشريك أو تفويضاً للشحن المباشر أو الدفع.'
                : 'This is an internal document digest review, NOT a signed contract, partner acceptance or authorization for live charging or payment.',
            { exact: true }).count(), 1);

            await page.getByLabel(arabic ? 'مرجع القضية الداخلية' : 'Internal case reference')
                .fill('LEGAL-SYNTHETIC-CASE-001');
            await page.getByLabel(arabic ? 'بصمة SHA-256 للمستند المراجع' : 'Reviewed document SHA-256 digest')
                .fill('a'.repeat(64));
            await page.getByLabel(arabic ? 'تاريخ صلاحية المراجعة (UTC)' : 'Review validity date (UTC)')
                .fill('2027-11-20');
            const form = page.locator('form[data-contract-review-action="review"]');
            await form.locator('textarea').fill('Synthetic internal document check, not external partner approval.');
            page.once('dialog', dialog => dialog.accept());
            await form.locator('button[type=submit]').click();
            await page.getByText(arabic ? 'تمت مراجعته داخلياً فقط' : 'Internally reviewed only',
                { exact: false }).waitFor();
            assert.equal(await page.evaluate(() => window.writeCount), 1);
            assert.equal(await page.locator('form[data-contract-review-action="revoke"]').count(), 1);
            await page.getByRole('button', { name: arabic
                ? 'قراءة سجل مراجعة العقد' : 'Read contract review history' }).click();
            await page.getByText('Synthetic internal document check, not external partner approval.',
                { exact: true }).waitFor();
            assert.equal(await page.getByText('LEGAL-SYNTHETIC-CASE-001', { exact: true }).count(), 1);

            await page.evaluate(async () => {
                window.viewer = { id: 27, role: 'vendor' };
                await window.workspace.setActor();
            });
            await page.getByRole('button', { name: 'Partner contract review' }).click();
            await page.getByText(arabic ? 'تمت مراجعته داخلياً فقط' : 'Internally reviewed only',
                { exact: false }).waitFor();
            assert.equal(await page.locator('form[data-contract-review-action]').count(), 0);
            await page.getByRole('button', { name: arabic
                ? 'قراءة سجل مراجعة العقد' : 'Read contract review history' }).click();
            assert.equal(await page.getByText('Synthetic internal document check, not external partner approval.',
                { exact: true }).count(), 0);
            assert.equal(await page.getByText('LEGAL-SYNTHETIC-CASE-001', { exact: true }).count(), 0);
            assert.equal(await page.evaluate(() => window.writeCount), 1);

            await page.evaluate(async () => {
                window.viewer = { id: 45, role: 'admin' };
                window.syntheticReview.live_roaming_enabled = true;
                await window.workspace.setActor();
            });
            await page.getByRole('button', { name: 'Partner contract review' }).click();
            await page.getByText(arabic
                ? 'دليل مراجعة العقد غير متسق؛ حدّث للمراجعة.'
                : 'Inconsistent contract review evidence; refresh for review.', { exact: true }).waitFor();
            assert.equal(await page.locator('form[data-contract-review-action]').count(), 0);

            await page.evaluate(async () => {
                window.viewer = null;
                await window.workspace.setActor();
            });
            assert.equal(await page.getByText('LEGAL-SYNTHETIC-CASE-001').count(), 0);
            assert.equal(await page.evaluate(() => window.writeCount), 1);
            await page.close();
        }
        console.log('OCPI internal contract review browser: EN/AR safe rendering, explicit decision, tenant and forged-authorization fences PASS.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
