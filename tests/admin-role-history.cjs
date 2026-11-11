'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {test} = require('node:test');

class Element {
    constructor(tag) {
        this.tag = tag;
        this.children = [];
        this.parent = null;
        this._text = '';
        this.attributes = {};
        this.value = '';
        this.disabled = false;
    }
    get textContent() {
        return this._text + this.children.map(x => x.textContent).join('');
    }
    set textContent(value) { this._text = String(value); this.children = []; }
    append(...values) {
        for (const value of values) {
            value.parent = this;
            this.children.push(value);
        }
    }
    replaceChildren(...values) {
        this._text = '';
        this.children = [];
        this.append(...values);
    }
    getAttribute(key) { return this.attributes[key] ?? null; }
    setAttribute(key, value) { this.attributes[key] = String(value); }
    remove() {
        if (this.parent)
            this.parent.children = this.parent.children.filter(x => x !== this);
        this.parent = null;
    }
}
function fixture() {
    const root = new Element('div');
    const window = {};
    const document = {
        getElementById(id) {
            assert.equal(id, 'admin-role-history');
            return root;
        },
        createElement(tag) { return new Element(tag); },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,
        '../services/business-api/public/admin-role-history.js'), 'utf8'), {
        window, document, AbortController, URLSearchParams,
    });
    return {root, ...window.EvAdminOperatorRoleHistory};
}
function nodes(root, tag) {
    const result = [];
    function visit(item) {
        if (item.tag === tag) result.push(item);
        item.children.forEach(visit);
    }
    visit(root);
    return result;
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const event = {
    id:17, target_user_id:7, target_name:'Synthetic Account',
    target_email:'synthetic@example.test',
    previous_role:'customer', assigned_role:'vendor',
    source:'platform:role', ticket_reference:'SEC-2026:17',
    created_at:'2026-11-07T08:00:00Z'
};
const page = (events = [event], more = false, before = null) => ({
    events, has_more:more, next_before_id:before,
    limit:25, operator_identity_available:false,
});

test('only Admin loads privileged role history and logout removes all private information', async () => {
    const ui = fixture(), calls = [];
    ui.setActor(null, async p => { calls.push(p); return page(); });
    ui.setActor({role:'vendor'}, async p => { calls.push(p); return page(); });
    await tick();
    assert.equal(calls.length, 0);
    assert.equal(ui.root.children.length, 0);
    ui.setActor({role:'admin'}, async p => { calls.push(p); return page(); });
    await tick();
    assert.equal(calls.length, 1);
    assert.match(ui.root.textContent, /Synthetic Account/);
    assert.match(ui.root.textContent, /SEC-2026:17/);
    ui.clear();
    assert.equal(ui.root.children.length, 0);
    assert.equal(ui.root.textContent, '');
});

test('role filter, account ID filter, and stable cursor do not write roles', async () => {
    const ui = fixture(), requests = [];
    ui.setActor({role:'admin'}, async (p, method) => {
        requests.push({p, method});
        if (p.includes('before_id=17'))
            return page([{...event,id:13,previous_role:'vendor',assigned_role:'admin'}]);
        return page([event], true, 17);
    });
    await tick();
    const f = nodes(ui.root, 'form')[0];
    nodes(f, 'input')[0].value = '7';
    nodes(f, 'select')[0].value = 'vendor';
    f.onsubmit({preventDefault(){}});
    await tick();
    assert.match(requests[1].p, /target_user_id=7/);
    assert.match(requests[1].p, /assigned_role=vendor/);
    const more = nodes(ui.root, 'button').find(x => x.textContent.includes('Load older role changes'));
    assert.ok(more);
    await more.onclick();
    await tick();
    assert.match(requests[2].p, /before_id=17/);
    assert.equal(requests.every(x => x.method === 'GET'), true);
    assert.equal(nodes(ui.root,'article').length,2);
    assert.equal(nodes(ui.root,'button').filter(x => x.textContent.includes('Load older role changes')).length,0);
});

test('operator identity is not invented and ticket remains text-only', async () => {
    const ui = fixture();
    ui.setActor({role:'admin'}, async () => page([{
        ...event,
        target_name:'<script>alert(1)</script>',
        ticket_reference:'<img src=x onerror=alert(2)>',
    }]));
    await tick();
    assert.match(ui.root.textContent, /shell operator identity must be verified from privileged host logs/);
    assert.match(ui.root.textContent, /<img src=x onerror=alert\(2\)>/);
    assert.equal(nodes(ui.root,'img').length,0);
    assert.equal(nodes(ui.root,'script').length,0);
});

test('stale API reply cannot reinstate private history after identity changes', async () => {
    const ui = fixture();
    let release;
    ui.setActor({role:'admin'}, () => new Promise(resolve => { release = resolve; }));
    assert.equal(typeof release,'function');
    ui.setActor({role:'customer'}, async () => { throw Error('must not fetch'); });
    release(page([{...event,target_email:'private+stale@example.test'}]));
    await tick();
    await tick();
    assert.equal(ui.root.children.length,0);
    assert.equal(ui.root.textContent,'');
});

test('private API errors produce bounded retry UI without exposing exception details', async () => {
    const ui = fixture();
    ui.setActor({role:'admin'}, async () => { throw Error('private-auth-key'); });
    await tick();
    assert.match(ui.root.textContent,/Role change history unavailable/);
    assert.equal(ui.root.textContent.includes('private-auth-key'),false);
    assert.ok(nodes(ui.root,'button').some(x => x.textContent.includes('Retry')));
});
