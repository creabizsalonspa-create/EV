'use strict';
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({
        headless:true,
        args:['--no-sandbox'],
    });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const local = 'http://127.0.0.1:8129/';
        // Browser-only fixture; even this loopback origin is fulfilled before
        // a network connection. No user login, database or live service.
        await page.route('**/8129/**', route => route.fulfill({
            contentType:'text/html',
            body:'<!doctype html><html><body><section id="vendor-test"></section></body></html>',
        }));
        await page.route(local, route => route.fulfill({
            contentType:'text/html',
            body:'<!doctype html><html><body><section id="vendor-test"></section></body></html>',
        }));
        await page.goto(local, {waitUntil:'domcontentloaded'});
        await page.addScriptTag({path:path.resolve(__dirname, '../services/business-api/public/vendor-ops.js')});

        await page.evaluate(async () => {
            const owner = {id:3,name:'Synthetic Owner',role:'vendor'};
            const company = {
                id:11,owner_id:3,legal_name:'Synthetic Company',
                country:'AE',status:'approved',license_expires_on:'2027-12-01',
                capabilities:{
                    owner:true,company_read:true,inventory_read:true,
                    inventory_write:true,private_evidence:true,
                    inventory_operational:true,
                },
            };
            const membership = {
                id:22,user_id:8,company_id:11,status:'active',
                invitation_version:1,
                permissions:['inventory.read'],admin_revoked_at:null,
            };
            window.fixture = {membership,company,owner,calls:[],confirming:false,notices:[]};
            window.confirm = () => window.fixture.confirming;
            window.EvLocale = {
                text:x=>String(x),
                attribute:(node,name,value)=>node.setAttribute(name, String(value)),
                bind:(node,value,args)=>{
                    node.textContent=String(value).replace(/\{([a-z_]+)\}/gi,(_,k)=>{
                        const val=args?.[k];
                        return typeof val==='object' && val!==null && 'translate' in val
                            ? val.translate : (val??'');
                    });
                },
            };
            const ui=(tag,txt,args)=>{
                const item=document.createElement(tag);
                window.EvLocale.bind(item,txt,args);
                return item;
            };
            const pageData=(list)=>({data:list,current_page:1,last_page:1,total:list.length});
            const api=async (p,method='GET',data)=>{
                window.fixture.calls.push({path:p,method,body:data});
                if (p==='regions')return[{country:'AE',timezone:'Asia/Dubai'}];
                if (p==='vendor-ops/companies?page=1')return pageData([company]);
                if (p==='vendor-ops/staff-invitations?page=1')return pageData([]);
                if (p==='vendor-ops/companies/11/staff?page=1')return pageData([membership]);
                if(p==='vendor-ops/companies/11/staff/22/permissions' && method==='PATCH'){
                    membership.permissions=data.permissions;
                    membership.invitation_version++;
                    membership.status='invited';
                    return{id:22,status:'invited',permissions:membership.permissions,
                        invitation_version:membership.invitation_version,requires_acceptance:true};
                }
                return pageData([]);
            };
            window.workspace = window.createVendorWorkspace({
                root:document.getElementById('vendor-test'),
                api,ui,actor:()=>owner,
                csrf:()=>null,
                notice:e=>window.fixture.notices.push(String(e)),
                expired:()=>false,
            });
            await window.workspace.setActor(owner);
        });
        await page.getByRole('button',{name:'Open company'}).click();
        const editor=page.locator('form[data-vendor-form="Change company staff permissions"]');
        await editor.waitFor();
        assert.equal(await editor.locator('input[value="inventory.read"]').isChecked(),true);
        assert.equal(await editor.locator('input[value="inventory.write"]').isChecked(),false);
        await editor.locator('input[value="inventory.write"]').check();
        await editor.locator('button[type=submit]').click();
        assert.equal(await page.evaluate(()=>window.fixture.calls.filter(x=>x.method==='PATCH').length),0,
            'A denied browser confirmation must not issue a privilege PATCH');
        await page.evaluate(()=>{window.fixture.confirming=true});
        await editor.locator('button[type=submit]').click();
        await page.waitForFunction(()=>window.fixture.calls.some(x=>x.method==='PATCH'));
        const result=await page.evaluate(()=>{
            const patch=window.fixture.calls.find(x=>x.method==='PATCH');
            return{
                method:patch.method,path:patch.path,body:patch.body,
                status:window.fixture.membership.status,
                version:window.fixture.membership.invitation_version,
                notes:window.fixture.notices,
            };
        });
        assert.equal(result.path,'vendor-ops/companies/11/staff/22/permissions');
        assert.equal(result.method,'PATCH');
        assert.equal(result.body.expected_version,1);
        assert.deepEqual(result.body.permissions,['inventory.read','inventory.write']);
        assert.equal(result.status,'invited');
        assert.equal(result.version,2);
        assert.match(result.notes.join(' '),/Staff access is suspended until/);

        await page.evaluate(async()=>{
            const member=window.fixture.membership;
            member.status='revoked';
            member.admin_revoked_at='2026-11-07T10:00:00Z';
            await window.workspace.setActor(window.fixture.owner);
        });
        await page.getByRole('button',{name:'Open company'}).click();
        assert.equal(await editor.count(),0,
            'Admin-held revoked grants must never expose permission editing controls');
        assert.equal(errors.length,0,'Browser page errors: '+errors.join('; '));
        console.log('PASS: synthetic vendor permission editor preserves scopes, confirms owner PATCH, rotates invitation version, suspends access, hides Admin-held grant; zero browser errors.');
    } finally {
        await browser.close();
    }
})().catch(error=>{console.error(error);process.exitCode=1});
