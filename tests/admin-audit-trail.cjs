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
        this.attributes = {};
        this.value = '';
        this._text = '';
        this.disabled = false;
    }
    get textContent() { return this._text + this.children.map(x => x.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    append(...nodes) {
        for (const n of nodes) { n.parent = this; this.children.push(n); }
    }
    replaceChildren(...nodes) {
        this._text = '';
        this.children = [];
        this.append(...nodes);
    }
    setAttribute(key, value) { this.attributes[key] = String(value); }
    getAttribute(key) { return this.attributes[key] ?? null; }
    remove() {
        if (this.parent) this.parent.children = this.parent.children.filter(x => x !== this);
        this.parent = null;
    }
}
function fixture() {
    const root = new Element('div'), window = {};
    const document = {
        getElementById(id) { assert.equal(id, 'admin-audit-trail'); return root; },
        createElement(tag) { return new Element(tag); },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,
        '../services/business-api/public/admin-audit-trail.js'), 'utf8'), {
        window, document, AbortController, URLSearchParams,
    });
    return {root, setActor:window.EvAdminAuditTrail.setActor};
}
function nodes(root, tag) {
    const items = [];
    function visit(n) {
        if (n.tag === tag) items.push(n);
        n.children.forEach(visit);
    }
    visit(root);
    return items;
}
const tick = () => new Promise(resolve => setImmediate(resolve));
const event = {
    id:31, action:'admin.account.suspended',
    actor_id:7, actor_name:'Admin One', subject:'account:25',
    created_at:'2026-11-07T08:00:00Z',
};
const page = (events = [event], more = false, before = null) => ({
    events, has_more:more, next_before_id:before, limit:25,
});

test('only Admin actor can request and view audit history', async () => {
    const ui = fixture(), paths = [];
    ui.setActor({role:'vendor'}, async p => { paths.push(p); return page(); });
    await tick();
    assert.equal(paths.length, 0);
    assert.equal(ui.root.children.length, 0);
    ui.setActor({role:'admin'}, async p => { paths.push(p); return page(); });
    await tick();
    assert.equal(paths.length, 1);
    assert.match(paths[0], /admin\/audit-events\?limit=25/);
    assert.match(ui.root.textContent, /admin.account.suspended/);
    ui.setActor(null, async () => { throw Error('cannot fetch'); });
    assert.equal(ui.root.children.length, 0);
    assert.equal(ui.root.textContent, '');
});

test('filters and cursor paging do not duplicate records or mutate the ledger', async () => {
    const ui = fixture(), requests = [];
    ui.setActor({role:'admin'}, async (p, method) => {
        requests.push({p, method});
        if (p.includes('before_id=31')) {
            return page([{...event,id:26,subject:'account:20'}], false);
        }
        return page([event], true, 31);
    });
    await tick();
    const form = nodes(ui.root, 'form')[0];
    const fields = nodes(form, 'input');
    fields[0].value = 'admin.account.';
    fields[1].value = '7';
    form.onsubmit({preventDefault() {}});
    await tick();
    assert.match(requests[1].p, /action_prefix=admin.account./);
    assert.match(requests[1].p, /actor_id=7/);
    const loadOlder = nodes(ui.root, 'button')
        .find(x => x.textContent.includes('Load older audit events'));
    assert.ok(loadOlder);
    await loadOlder.onclick();
    await tick();
    assert.match(requests[2].p, /before_id=31/);
    assert.equal(requests.every(x=>x.method === 'GET'), true);
    assert.equal(nodes(ui.root, 'article').length, 2);
    assert.equal(nodes(ui.root, 'button').filter(x=>x.textContent.includes('Load older audit events')).length, 0);
});

test('audit descriptions remain text-only even when they contain malicious-looking markup', async () => {
    const ui = fixture();
    ui.setActor({role:'admin'}, async () => page([{
        ...event, action:'<img src=x onerror=alert(1)>',
        subject:'<script>alert(2)</script>',
    }]));
    await tick();
    assert.match(ui.root.textContent, /<img src=x onerror=alert\(1\)>/);
    assert.match(ui.root.textContent, /<script>alert\(2\)<\/script>/);
    assert.equal(nodes(ui.root, 'img').length, 0);
    assert.equal(nodes(ui.root, 'script').length, 0);
});

test('a delayed audit reply cannot restore records after logout or actor replacement', async () => {
    const ui = fixture();
    let release;
    ui.setActor({role:'admin'}, () => new Promise(resolve => { release = resolve; }));
    assert.equal(typeof release, 'function');
    ui.setActor({role:'customer'}, async () => { throw Error('cannot fetch'); });
    release(page([{...event,subject:'PRIVATE-SUBJECT'}]));
    await tick();
    await tick();
    assert.equal(ui.root.textContent, '');
    assert.equal(ui.root.children.length, 0);
});

test('invalid server responses do not inject records and show safe retry controls', async () => {
    const ui = fixture();
    ui.setActor({role:'admin'}, async () => { throw Error('private connection detail'); });
    await tick();
    assert.match(ui.root.textContent, /Audit history unavailable/);
    assert.equal(ui.root.textContent.includes('private connection detail'), false);
});
