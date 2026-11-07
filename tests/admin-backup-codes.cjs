'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    const dir = path.resolve(__dirname, '../services/business-api');
    const base = 'http://127.0.0.1:8128';
    const codes = Array.from({length: 8}, (_, i) => 'abcdef0123456789ab' + String(i).padStart(2, '0'));

    async function open({manage = true, verified = true, recovery = false, denied = false} = {}) {
        const page = await browser.newPage();
        const calls = [], errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/admin/second-factor') {
                const html = fs.readFileSync(path.join(dir,
                    'resources/views/admin-second-factor.blade.php'), 'utf8')
                    .replace('{{ csrf_token() }}', 'synthetic-csrf');
                return route.fulfill({body: html, contentType: 'text/html'});
            }
            if (url.pathname === '/admin-second-factor.js') {
                return route.fulfill({
                    body: fs.readFileSync(path.join(dir, 'public/admin-second-factor.js'), 'utf8'),
                    contentType: 'application/javascript'
                });
            }
            if (url.pathname === '/platform.css' || url.pathname === '/admin-second-factor.css') {
                return route.fulfill({body: '', contentType: 'text/css'});
            }
            if (url.pathname === '/api/admin/second-factor/status') {
                return route.fulfill({json: {
                    enrolled: true, verified, recovery_reset_available: recovery
                }});
            }
            if (url.pathname === '/api/admin/second-factor/rotate-recovery-codes') {
                calls.push({method: route.request().method(), data: route.request().postDataJSON()});
                if (denied) return route.fulfill({status: 422, json: {
                    message: 'secret internal detail', errors: {code: ['invalid']}
                }});
                return route.fulfill({json: {
                    recovery_codes: codes, old_codes_invalidated: true, authenticator_changed: false
                }});
            }
            if (url.pathname === '/' || url.pathname === '/favicon.ico') {
                return route.fulfill({body: '<!doctype html><title>Fresh anonymous view</title>',
                    contentType: 'text/html'});
            }
            throw Error('Unexpected request: ' + route.request().url());
        });
        await page.goto(base + '/admin/second-factor' + (manage ? '?manage=1' : ''));
        return {page, calls, errors};
    }

    try {
        const verified = await open();
        await verified.page.locator('#factor-rotate:not([hidden])').waitFor();
        assert.equal(await verified.page.locator('#factor-verify').isVisible(), false);
        await verified.page.locator('#factor-rotate input[name=password]').fill('private-password');
        await verified.page.locator('#factor-rotate input[name=code]').fill('123456');
        await verified.page.locator('#factor-rotate button').click();
        await verified.page.locator('#factor-recovery-codes:not([hidden])').waitFor();
        assert.equal(verified.calls.length, 1);
        assert.equal(verified.calls[0].method, 'POST');
        assert.equal(verified.calls[0].data.password, 'private-password');
        assert.equal(verified.calls[0].data.code, '123456');
        assert.equal(await verified.page.locator('#factor-rotate').isVisible(), false);
        const display = await verified.page.locator('#factor-recovery-list').textContent();
        for (const code of codes) assert.ok(display.includes(code));
        assert.deepEqual(await verified.page.evaluate(() => [
            Object.keys(localStorage).length, Object.keys(sessionStorage).length
        ]), [0, 0]);
        await verified.page.locator('#factor-codes-saved').click();
        await verified.page.waitForURL(base + '/');
        assert.equal(verified.errors.length, 0);
        await verified.page.close();

        const notVerified = await open({verified: false});
        await notVerified.page.locator('#factor-verify:not([hidden])').waitFor();
        assert.equal(await notVerified.page.locator('#factor-rotate').isVisible(), false);
        assert.equal(notVerified.calls.length, 0);
        assert.equal(notVerified.errors.length, 0);
        await notVerified.page.close();

        const recovered = await open({verified: false, recovery: true});
        await recovered.page.locator('#factor-reset:not([hidden])').waitFor();
        assert.equal(await recovered.page.locator('#factor-rotate').isVisible(), false);
        assert.equal(recovered.errors.length, 0);
        await recovered.page.close();

        const ordinary = await open({manage: false});
        await ordinary.page.waitForURL(base + '/');
        assert.equal(ordinary.calls.length, 0);
        assert.equal(ordinary.errors.length, 0);
        await ordinary.page.close();

        const failed = await open({denied: true});
        await failed.page.locator('#factor-rotate:not([hidden])').waitFor();
        await failed.page.locator('#factor-rotate input[name=password]').fill('private-password');
        await failed.page.locator('#factor-rotate input[name=code]').fill('654321');
        await failed.page.locator('#factor-rotate button').click();
        await failed.page.getByText('Could not verify. Check your details and try again.').waitFor();
        assert.equal(failed.calls.length, 1);
        assert.equal(await failed.page.locator('#factor-recovery-list').textContent(), '');
        assert.equal(await failed.page.locator('#factor-rotate input[name=password]').inputValue(), '');
        assert.equal(await failed.page.locator('#factor-rotate input[name=code]').inputValue(), '');
        assert.equal(failed.errors.length, 0);
        await failed.page.close();

        console.log('PASS: Chromium backup code management, verified-only access, one-time display, recovery-only isolation, no storage and safe errors.');
    } finally {
        await browser.close();
    }
})().catch(e => { console.error(e); process.exitCode = 1; });
