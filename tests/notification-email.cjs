const {chromium} = require('playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.EV_CHROMIUM_PATH || undefined,args:['--no-sandbox']});
    try {
        for (const ar of [false,true]) {
            const page = await browser.newPage();
            await page.setContent(`<html lang="${ar?'ar':'en'}"><body><main id="host"></main></body></html>`);
            await page.addScriptTag({path:'services/business-api/public/notification-email.js'});
            await page.evaluate(async () => {
                window.writes=[]; window.enabled=false; window.active=true;
                window.api = async (path,method='GET',body) => {
                    if (method==='PUT') { window.writes.push(body); window.enabled=body.enabled; throw Error('PRIVATE LOST SMTP RESPONSE'); }
                    if (path.includes('/history')) return {delivery_confirmed:false,automatic_retry:false,receipts:{data:[{state:'outcome_unknown',created_at:'<img src=x onerror=alert(1)>',evidence:'SIMULATED_EMAIL_HANDOFF'}],current_page:1,last_page:1}};
                    return {enabled:window.enabled,consent_current:window.enabled,configured:true,verified_email_required:false,revision:'9007199254740993'};
                };
                await window.EvNotificationEmail.open({host:document.querySelector('#host'),api:window.api,current:()=>window.active});
            });
            const enable=page.getByRole('button',{name:ar?'تفعيل بالموافقة':'Enable with consent',exact:true});
            assert.equal(await enable.isDisabled(),true); assert.equal(await page.locator('img').count(),0);
            await page.getByRole('checkbox').check(); await enable.click();
            await page.getByRole('button',{name:ar?'تحديث':'Refresh',exact:true}).waitFor();
            assert.deepEqual(await page.evaluate(()=>window.writes),[{enabled:true,confirmed:true,expected_revision:'9007199254740993'}]);
            assert.equal(await page.getByText('PRIVATE LOST SMTP RESPONSE').count(),0);
            await page.getByRole('button',{name:ar?'تحديث':'Refresh',exact:true}).click();
            const disable=page.getByRole('button',{name:ar?'تعطيل إشعارات البريد':'Disable email notices',exact:true}); await disable.waitFor();
            assert.equal(await page.evaluate(()=>window.writes.length),1);
            await page.evaluate(() => { window.retained=document.querySelector('[data-email-notices] button:nth-of-type(2)').onclick; window.active=false; });
            await page.evaluate(()=>window.retained()); assert.equal(await page.evaluate(()=>window.writes.length),1);
            await page.close();
        }
        for (const ar of [false, true]) {
            const page = await browser.newPage();
            await page.setContent(`<html lang="${ar ? 'ar' : 'en'}"><body><main id="host"></main></body></html>`);
            await page.addScriptTag({path:'services/business-api/public/notification-email.js'});
            await page.evaluate(async () => {
                window.writes = [];
                window.requestedPages = [];
                window.active = true;
                window.failNextHistory = false;
                window.malformedHistory = false;
                window.api = async (path, method = 'GET', body) => {
                    if (method === 'PUT') { window.writes.push(body); return {enabled:body.enabled}; }
                    if (path.includes('/history')) {
                        const requested = Number(new URLSearchParams(path.split('?')[1] || '').get('page') || '1');
                        window.requestedPages.push(requested);
                        if (window.failNextHistory) { window.failNextHistory = false; throw Error('PRIVATE HISTORY DIAGNOSTIC'); }
                        if (window.malformedHistory) return {delivery_confirmed:false,automatic_retry:false,receipts:{data:'not-an-array',current_page:requested,last_page:2}};
                        return {delivery_confirmed:false,automatic_retry:false,receipts:{data:[],current_page:requested,last_page:2}};
                    }
                    return {enabled:true,consent_current:true,configured:true,verified_email_required:false,revision:'4'};
                };
                await window.EvNotificationEmail.open({host:document.querySelector('#host'),api:window.api,current:()=>window.active});
                window.staleDisable = [...document.querySelectorAll('[data-email-notices] button')].find(button => button.textContent.includes('Disable') || button.textContent.includes('تعطيل')).onclick;
            });
            const refresh = page.getByRole('button',{name:ar?'تحديث':'Refresh',exact:true});
            const disable = page.getByRole('button',{name:ar?'تعطيل إشعارات البريد':'Disable email notices',exact:true});
            assert.equal(await disable.count(),1);
            assert.equal(await page.getByText(ar?'لا توجد عمليات نقل إشعارات بالبريد في هذه الصفحة.':'No email notice handoffs on this page.').count(),1);
            await page.evaluate(()=>window.failNextHistory=true);
            await refresh.click();
            await page.getByRole('alert').waitFor();
            assert.equal(await page.getByText('PRIVATE HISTORY DIAGNOSTIC').count(),0);
            assert.equal(await disable.count(),0);
            assert.equal(await refresh.count(),1);
            await refresh.click();
            await disable.waitFor();
            await page.evaluate(()=>window.staleDisable());
            assert.equal(await page.evaluate(()=>window.writes.length),0,'stale detached action must not submit');
            await page.evaluate(()=>window.malformedHistory=true);
            await refresh.click();
            await page.getByRole('alert').waitFor();
            assert.equal(await disable.count(),0);
            assert.equal(await refresh.count(),1,'malformed history must remain explicitly retryable');
            await page.evaluate(()=>window.malformedHistory=false);
            await refresh.click();
            await disable.waitFor();
            assert.equal(await page.getByRole('alert').count(),0);
            await page.getByRole('button',{name:ar?'التالي':'Next',exact:true}).click();
            await page.getByRole('button',{name:ar?'السابق':'Previous',exact:true}).waitFor();
            await page.evaluate(()=>window.failNextHistory=true);
            await refresh.click();
            await page.getByRole('alert').waitFor();
            await refresh.click();
            await disable.waitFor();
            assert.deepEqual(await page.evaluate(()=>window.requestedPages.slice(-2)),[2,2], 'retry must retain the failed page');
            await page.close();
        }
        console.log('PASS email notice refresh failure and malformed-history recovery with EN/AR retry and stale-action fence');
        console.log('PASS email notice EN/AR consent, exact revision, unknown response recovery, safe rendering and account fence');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
