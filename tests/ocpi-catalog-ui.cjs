const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({headless:true,executablePath:process.env.EV_CHROMIUM_PATH || undefined,args:['--no-sandbox']});
    try {
        for (const ar of [false,true]) {
            const page = await browser.newPage();
            await page.setContent(`<html lang="${ar?'ar':'en'}"><body><section id="public"></section><section id="private"></section></body></html>`);
            await page.addScriptTag({path:'services/business-api/public/roaming.js'});
            await page.evaluate(async () => {
                window.writes = 0;
                window.currentActor = {id:1,role:'vendor'};
                window.EvLocale = {text:value=>value,attribute:(n,k,v)=>n.setAttribute(k,v)};
                const paged = data => ({data,current_page:1,last_page:1,total:data.length});
                window.workspace = window.createRoamingWorkspace({
                    publicRoot:document.querySelector('#public'),privateRoot:document.querySelector('#private'),
                    actor:()=>window.currentActor,notice:error=>{throw error;},
                    ui:(tag,text)=>{const n=document.createElement(tag);n.textContent=text;return n;},
                    api:async (path,method='GET')=>{
                        if (method!=='GET') { window.writes++; throw Error('Unexpected write'); }
                        if (path.includes('/credentials')) return {configured:true,state:'active',configured_receiver_modules:['locations','tariffs'],active_receiver_modules:[],available_actions:[],operations:paged([])};
                        if (path.includes('/records/tariffs')) return paged([{id:4,external_id:'retired-1',catalog_retired:true,normalized:{currency:'EUR',elements:[]},payload:{token:'PRIVATE-PARTNER-TOKEN'}}]);
                        if (path.startsWith('ocpi/providers')) return paged([{id:7,name:'Approved partner',authorized:true,version:'2.2.1'}]);
                        return paged([]);
                    }
                });
                await window.workspace.setActor();
            });
            await page.getByRole('button',{name:'Roaming credentials',exact:true}).click();
            assert.equal(await page.getByText(ar?'استقبال الشريك المهيأ: locations, tariffs':'Configured partner reception: locations, tariffs',{exact:true}).count(),1);
            assert.equal(await page.getByText(ar?'استقبال التفويض النشط: —':'Active credential reception: —',{exact:true}).count(),1);
            await page.getByRole('button',{name:'Roaming tariffs',exact:true}).click();
            assert.equal(await page.getByText(ar?'تعرفة مسحوبة من الشريك. محفوظة للسجل فقط وليست عرضاً حالياً.':'Withdrawn partner tariff. Historical source only; not a current offer.',{exact:true}).count(),1);
            assert.equal(await page.getByText('PRIVATE-PARTNER-TOKEN').count(),0);
            assert.equal(await page.evaluate(()=>window.writes),0);
            await page.evaluate(async ()=>{window.currentActor=null;await window.workspace.setActor();});
            assert.equal(await page.getByText('retired-1',{exact:true}).count(),0);
            await page.close();
        }
        console.log('OCPI configured/active reception and withdrawn historical tariff web workflows passed in English/Arabic.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
