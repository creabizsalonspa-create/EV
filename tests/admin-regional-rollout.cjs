'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { test } = require('node:test');
const vm = require('node:vm');

class Element {
    constructor(tag, textContent = '') {
        this.tag = tag;
        this.textContent = textContent;
        this.dataset = {};
        this.children = [];
        this.isConnected = true;
    }

    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) {
        for (const child of this.children) child.isConnected = false;
        this.children = nodes;
    }
    setAttribute() {}
}

const find = (node, tag) => [node, ...node.children.flatMap(child => find(child, tag))]
    .filter(element => element.tag === tag);
const flush = () => new Promise(resolve => setImmediate(resolve));
const source = fs.readFileSync('services/business-api/public/platform.js', 'utf8');
const helper = source.slice(source.indexOf('function regionalRolloutReview('), source.indexOf('async function regions()'));

function fixture(api) {
    const sandbox = {
        document: {createElement: tag => new Element(tag)},
        batteryNode: (tag, en) => new Element(tag, en), api,
        client: {sessionEpoch: 1}, user: {role: 'admin'},
    };
    const panel = vm.runInNewContext(helper + '\nregionalRolloutReview({country: "AE", configuration_revision: 1}, 1)', sandbox);
    return {panel, sandbox};
}

function report(changes = {}) {
    const checks = ['legal', 'payments', 'tax', 'telecom', 'charging_partners'].map(domain => ({
        domain, review_id: null, decision: 'pending', evidence_reference: null,
    }));
    return {country: 'AE', configuration_revision: 1, checks,
        external_evidence_verified: false, rollout_authorized: false, ...changes};
}

test('review forms submit a revision-bound evidence reference and reject a mismatched receipt', async () => {
    const calls = [];
    const {panel} = fixture(async (...args) => { calls.push(args); return report(); });
    await find(panel, 'button')[0].onclick();
    const [form] = find(panel, 'form');
    find(form, 'input')[0].value = 'LEGAL-AE-001';
    form.onsubmit({preventDefault() {}});
    await flush();

    assert.equal(calls[0][0], 'admin/regions/AE/rollout');
    assert.equal(calls[1][1], 'POST');
    assert.deepEqual(JSON.parse(JSON.stringify(calls[1][2])), {
        configuration_revision: 1, domain: 'legal', decision: 'reviewed',
        evidence_reference: 'LEGAL-AE-001', expected_review_id: null,
    });
    assert.equal(find(panel, 'form').length, 0);
    assert.match(find(panel, 'span').at(-1).textContent, /could not be confirmed/);
});

test('stale account results cannot render private rollout evidence', async () => {
    let finish;
    const {panel, sandbox} = fixture(() => new Promise(resolve => { finish = resolve; }));
    const pending = find(panel, 'button')[0].onclick();
    sandbox.client.sessionEpoch++;
    finish(report());
    await pending;
    assert.equal(find(panel, 'form').length, 0);
});
