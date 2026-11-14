const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.EV_CHROMIUM_PATH || undefined, args:['--no-sandbox']});
    try {
        for (const ar of [false, true]) {
            const page = await browser.newPage();
            await page.setContent(`<html lang="${ar ? 'ar' : 'en'}"><body><main id="host"></main></body></html>`);
            await page.addScriptTag({path:'services/business-api/public/vendor-agreements.js'});
            await page.evaluate(async () => {
                window.posts = []; window.accepted = false; window.authorized = true;
                const version = {id:'v1', country:'AE', revision:1, sha256:'reviewed-sha', title_en:'Reviewed agreement', title_ar:'الاتفاقية المراجعة', body_en:'<img src=x onerror="window.injected=true">', body_ar:'<img src=x onerror="window.injected=true">'};
                window.agreementApi = async (path, method='GET', body) => {
                    if (method === 'POST') {
                        window.posts.push(body); window.accepted = true;
                        // A committed write whose response is lost must be recovered by a read.
                        throw Object.assign(new Error('PRIVATE LOST RESPONSE'), {status:503});
                    }
                    return {version, can_accept:!window.accepted, can_publish:false, acceptance:window.accepted ? {id:'receipt-1', created_at:'2026-11-14'} : null, history:{data:[],current_page:1,last_page:1}};
                };
                await window.EvVendorAgreements.open({host:document.querySelector('#host'), api:window.agreementApi, company:{id:1}, current:()=>window.authorized, notice:()=>{}});
            });
            const label = ar ? 'الموافقة على الاتفاقية المراجعة' : 'Accept reviewed agreement';
            assert.equal(await page.getByRole('button',{name:label,exact:true}).isDisabled(),true);
            assert.equal(await page.locator('img').count(),0);
            await page.getByRole('checkbox').check();
            await page.getByRole('button',{name:label,exact:true}).click();
            assert.deepEqual(await page.evaluate(()=>window.posts),[{version_id:'v1',sha256:'reviewed-sha',accepted:true}]);
            assert.equal(await page.getByText('PRIVATE LOST RESPONSE').count(),0);
            await page.getByRole('button',{name:ar?'تحديث':'Refresh',exact:true}).click();
            assert.equal(await page.getByText(/receipt-1/).count(),1);
            assert.equal(await page.evaluate(()=>window.posts.length),1);
            await page.close();
        }
        const admin = await browser.newPage();
        await admin.setContent('<html lang="en"><body><main id="host"></main></body></html>');
        await admin.addScriptTag({path:'services/business-api/public/vendor-agreements.js'});
        await admin.evaluate(async () => {
            window.decisions = []; window.revision = 1;
            window.version = {id:'v1',country:'AE',revision:1,sha256:'sha1',title_en:'Terms',body_en:'Synthetic terms'};
            await window.EvVendorAgreements.open({host:document.querySelector('#host'),company:{id:1},current:()=>true,notice:()=>{},api:async (path,method='GET',body) => {
                if (method === 'POST') {
                    window.decisions.push({path,body}); window.revision++;
                    window.version = path.endsWith('/withdraw') ? null : {id:'v2',country:'AE',revision:2,sha256:'sha2',...body};
                    return {};
                }
                return {version:window.version,revision:window.revision,can_accept:false,can_publish:true,history:{data:[],current_page:1,last_page:1}};
            }});
        });
        for (const [name,value] of Object.entries({title_en:'New reviewed terms',title_ar:'شروط جديدة',body_en:'Synthetic reviewed agreement text for UI test only.',body_ar:'شروط تجريبية تمت مراجعتها لاختبار الواجهة فقط.',publication_reference:'SYNTHETIC-APPROVAL',password:'synthetic-password'})) await admin.locator(`[name="${name}"]`).fill(value);
        await admin.getByRole('button',{name:'Publish new version',exact:true}).click();
        assert.equal(await admin.evaluate(()=>window.decisions.length),0);
        await admin.locator('form input[type=checkbox]').check();
        await admin.getByRole('button',{name:'Publish new version',exact:true}).click();
        assert.equal(await admin.evaluate(()=>window.decisions[0].body.expected_revision),1);
        assert.equal(await admin.locator('[name=password]').inputValue(),'');
        await admin.locator('[name=password]').fill('synthetic-password');
        await admin.locator('[data-vendor-agreements] > label input[type=checkbox]').check();
        await admin.getByRole('button',{name:'Withdraw current agreement',exact:true}).click();
        assert.equal(await admin.evaluate(()=>window.decisions[1].body.expected_revision),2);
        assert.equal(await admin.getByText('No agreement is published for this country. Contact the administrator.').count(),1);
        await admin.close();
        const page = await browser.newPage();
        await page.setContent('<main id="host"></main>');
        await page.addScriptTag({path:'services/business-api/public/vendor-agreements.js'});
        await page.evaluate(() => {
            window.active = true; window.resolver = null;
            window.opening = window.EvVendorAgreements.open({host:document.querySelector('#host'),company:{id:1},current:()=>window.active,notice:()=>{},api:()=>new Promise(r=>window.resolver=r)});
        });
        await page.evaluate(async () => {
            window.active = false;
            window.resolver({version:{title_en:'PRIVATE LATE AGREEMENT'},history:{data:[]}});
            await window.opening;
        });
        assert.equal(await page.getByText('PRIVATE LATE AGREEMENT').count(),0);
        await page.close();
        console.log('Vendor agreement English/Arabic consent, receipt recovery, safe text and stale-account reads passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
