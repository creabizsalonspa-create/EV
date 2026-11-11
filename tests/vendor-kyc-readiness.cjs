'use strict';

// Synthetic browser-only KYC readiness regression; no real users or evidence.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox'],
    });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const origin = 'http://127.0.0.1:8137/';
        await page.route('**/8137/**', route => route.fulfill({
            contentType: 'text/html',
            body: '<!doctype html><html><body><section id="vendor-test"></section></body></html>',
        }));
        await page.route(origin, route => route.fulfill({
            contentType: 'text/html',
            body: '<!doctype html><html><body><section id="vendor-test"></section></body></html>',
        }));
        await page.goto(origin, { waitUntil: 'domcontentloaded' });
        await page.addScriptTag({
            path: path.resolve(__dirname, '../services/business-api/public/vendor-ops.js'),
        });

        await page.evaluate(async () => {
            const owner = { id: 3, name: 'Synthetic Owner', role: 'vendor' };
            const company = {
                id: 11,
                owner_id: 3,
                legal_name: 'Synthetic Company',
                country: 'AE',
                status: 'draft',
                license_expires_on: '2028-12-01',
                capabilities: {
                    owner: true,
                    company_read: true,
                    inventory_read: true,
                    inventory_write: true,
                    private_evidence: true,
                    inventory_operational: false,
                },
            };
            const data = items => ({
                data: items, current_page: 1, last_page: 1, total: items.length,
            });
            window.fixture = {
                company,
                owner,
                calls: [],
                hold: true,
                resolveReadiness: null,
                ready: {
                    editable: true,
                    license_valid: true,
                    trade_license_present: true,
                    trade_license_integrity_valid: false,
                    can_submit: false,
                },
                errors: [],
            };
            window.actorUser = owner;
            window.EvLocale = {
                text: text => String(text),
                attribute: (node, name, value) => node.setAttribute(name, String(value)),
                bind: (node, text, args) => {
                    node.textContent = String(text).replace(
                        /\{([a-z_]+)\}/gi,
                        (_, key) => args?.[key]?.translate || args?.[key] || '',
                    );
                },
            };
            const ui = (tag, text, args) => {
                const node = document.createElement(tag);
                window.EvLocale.bind(node, text, args);
                return node;
            };
            const api = async (p, method = 'GET') => {
                window.fixture.calls.push({ path: p, method });
                if (p === 'regions') return [{ country: 'AE', timezone: 'Asia/Dubai' }];
                if (p === 'vendor-ops/companies?page=1') return data([company]);
                if (p === 'vendor-ops/staff-invitations?page=1') return data([]);
                if (p === 'vendor-ops/companies/11' && method === 'GET') {
                    if (window.fixture.hold) {
                        return new Promise(resolve => {
                            window.fixture.resolveReadiness = resolve;
                        });
                    }
                    return { ...window.fixture.company,
                        submission_readiness: { ...window.fixture.ready } };
                }
                if (p === 'vendor-ops/companies/11/submit' && method === 'POST') {
                    window.fixture.company.status = 'submitted';
                    return { ...window.fixture.company };
                }
                return data([]);
            };
            window.workspace = window.createVendorWorkspace({
                root: document.getElementById('vendor-test'),
                api,
                ui,
                actor: () => window.actorUser,
                csrf: () => 'synthetic',
                notice: error => window.fixture.errors.push(String(error)),
                expired: () => false,
            });
            await window.workspace.setActor(owner);
        });

        await page.getByRole('button', { name: 'Open company' }).click();
        const submit = page.getByRole('button', { name: 'Submit for internal review' });
        await submit.waitFor();
        assert.equal(await submit.isDisabled(), true,
            'Submit must remain disabled until the authenticated readiness request resolves.');
        await page.waitForFunction(() => typeof window.fixture.resolveReadiness === 'function');
        await page.evaluate(() => {
            window.fixture.resolveReadiness({
                ...window.fixture.company,
                submission_readiness: { ...window.fixture.ready },
            });
            window.fixture.hold = false;
        });
        await page.getByText(/failed integrity verification/).waitFor();
        assert.equal(await submit.isDisabled(), true,
            'Invalid encrypted evidence must not permit a submission click.');
        assert.equal(await page.evaluate(() => window.fixture.calls
            .filter(c => c.path.endsWith('/submit')).length), 0);

        await page.evaluate(() => {
            window.fixture.ready.trade_license_integrity_valid = true;
            window.fixture.ready.can_submit = true;
        });
        await page.getByRole('button', { name: 'Refresh company' }).click();
        await page.getByText('Trade-license evidence is ready for manual review.').waitFor();
        assert.equal(await submit.isEnabled(), true,
            'Valid server-verified evidence must permit normal manual submission.');
        await submit.click();
        await page.waitForFunction(() => window.fixture.calls
            .filter(c => c.path.endsWith('/submit') && c.method === 'POST').length === 1);
        assert.equal(await page.evaluate(() => window.fixture.company.status), 'submitted');
        assert.equal(errors.length, 0, 'Unexpected browser errors: ' + errors.join('; '));
        console.log('PASS: vendor KYC submission stays disabled during load and invalid evidence, enables with server readiness, submits once.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
