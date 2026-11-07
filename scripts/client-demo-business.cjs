#!/usr/bin/env node
// Connect existing source-completed demo records to canonical MOCK finance.
// Never creates charging sessions, meter events, invoices or real PSP receipts.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { BusinessClient } = require('../tests/helpers/business-client.cjs');

async function main() {
  const args = process.argv.slice(2);
  assert(args.includes('--confirm-mock-payment'),
    'Usage: scripts/client-demo business --invoice ID --confirm-mock-payment [--publish-test-policy]');
  const index = args.indexOf('--invoice');
  const invoiceId = Number(args[index + 1]);
  assert(index >= 0 && Number.isSafeInteger(invoiceId) && invoiceId > 0, 'Select one existing invoice.');
  const base = path.resolve('artifacts/client-demo');
  const file = fs.realpathSync(process.env.EV_CLIENT_DEMO_MANIFEST ||
    JSON.parse(fs.readFileSync(path.join(base, 'current.json'))).manifest);
  assert(file.startsWith(base + path.sep) && (fs.statSync(file).mode & 0o077) === 0);
  const fixture = JSON.parse(fs.readFileSync(file));
  assert(fixture.fixture_only && !fixture.real_money && fixture.base === 'http://127.0.0.1:8000');
  const config = JSON.parse(execFileSync('docker', ['exec','-u','1000:1000','-w',
    '/app/services/business-api','ev-business-api','php','-r',
    'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(["local"=>app()->environment("local","testing"),"provider"=>config("payments.provider"),"loyalty"=>config("loyalty.enabled")]);'
  ], {encoding:'utf8'}));
  assert(config.local && config.provider === 'mock' && config.loyalty === true,
    'Requires local/testing, mock provider and explicitly enabled LOYALTY_TEST_ENABLED.');
  const clients = {};
  for (const role of ['customer','vendor','admin']) {
    const client = new BusinessClient();
    client.csrf = (await client.request('csrf')).token;
    await client.request('login','POST',fixture.accounts[role]);
    client.csrf = (await client.request('csrf')).token;
    clients[role] = client;
  }
  const customer = clients.customer;
  const before = await customer.request(`finance/invoices/${invoiceId}`);
  assert.equal(before.invoice.evidence, 'SIMULATED');
  assert(before.ledger_balanced && before.real_payment_processed === false);
  assert.equal(before.refunded_minor, 0, 'Refunded demo invoices require separate reconciliation.');
  async function find(client, route, id) {
    for (let page=1;page<=20;page++) {
      const data = await client.request(`${route}?page=${page}`);
      const row = data.data.find(x=>x.id===id);
      if (row) return row;
      if (page >= data.last_page) break;
    }
    throw Error('Record not in the bounded owned demo history.');
  }
  const session = await find(customer, 'simulations', before.invoice.charging_session_id);
  assert(session.csms_linked && session.evidence === 'SIMULATED_CSMS' &&
    session.status === 'stopped' && session.energy_wh > 0 &&
    session.stop_command_status === 'source_observed', 'Requires completed OCPP simulator source evidence.');
  assert(session.authorization_method === 'rider_token' || session.start_command_status === 'source_observed');
  const reservation = await find(customer, 'reservations', session.reservation_id);
  assert(fixture.stations.some(x=>x.id === reservation.station_id || x.station_id === reservation.station_id),
    'Invoice is outside the selected fixture stations.');
  assert.equal(before.invoice.energy_wh, session.energy_wh);
  const stamp = value => Date.parse(/(Z|[+-]\d\d:\d\d)$/.test(value) ? value : value.replace(' ', 'T') + 'Z');
  const policies = await customer.request('commercial/loyalty/policies');
  if (!policies.data.some(x=>x.currency === before.invoice.currency && x.minor_units === before.invoice.minor_units && x.enabled &&
      stamp(x.effective_from) <= stamp(before.invoice.created_at))) {
    assert(args.includes('--publish-test-policy'), 'No policy effective at invoice creation; use a new eligible source session. No payment attempted.');
    await clients.admin.request('commercial/loyalty/policies','POST', {
      currency:before.invoice.currency, minor_units:before.invoice.minor_units, enabled:true,
      points_numerator:1, minor_denominator:1, gold_threshold:1000, platinum_threshold:5000,
      gold_multiplier_bps:10000, platinum_multiplier_bps:10000, invoice_cap:10000,
      daily_cap:100000, expiry_days:365, daily_redemption_limit:3, effective_from:new Date().toISOString(),
      idempotency_key:`demo-source-policy-${invoiceId}`,
    });
    throw Error('Test policy published for future invoices only. Complete a new eligible source session; existing invoice economics were not backdated.');
  }
  let hosted;
  if (before.amount_due_minor > 0) {
    assert.equal(before.paid_minor, 0, 'Partially paid invoices require explicit owner reconciliation.');
    hosted = (await customer.request('commercial/hosted-checkouts','POST', {
      scope:'charging', invoice_id:invoiceId, consent:true,
      idempotency_key:`demo-source-payment-${invoiceId}`,
    })).checkout;
    assert.equal(hosted.evidence,'MOCK_HOSTED');
    assert.equal(hosted.real_money_moved,false);
    if (hosted.status === 'open') hosted = (await customer.request(
      `commercial/hosted-checkouts/${hosted.id}/simulate`,'POST',
      {outcome:'authorized',consent:true})).checkout;
    if (hosted.status === 'authorized') hosted = (await customer.request(
      `commercial/hosted-checkouts/${hosted.id}/capture`,'POST',{})).checkout;
    assert.equal(hosted.status,'captured', 'Uncertain payments must be inspected, never reset.');
  }
  const after = await customer.request(`finance/invoices/${invoiceId}`);
  assert.equal(after.amount_due_minor,0);
  assert.equal(after.payments.length,1);
  assert(after.ledger_balanced && !after.real_payment_processed);
  const earned = await customer.request(`commercial/loyalty/invoices/${invoiceId}/earn`,'POST',{});
  const replay = await customer.request(`commercial/loyalty/invoices/${invoiceId}/earn`,'POST',{});
  assert.equal(earned.award.id,replay.award.id);
  const reports = {};
  for (const role of ['vendor','admin']) {
    const report = await clients[role].request(`finance/invoices/${invoiceId}`);
    assert.equal(report.invoice.charging_session_id,session.id);
    assert.equal(report.paid_minor,after.paid_minor);
    assert.equal(report.invoice.commission_minor,after.invoice.commission_minor);
    assert(report.ledger_balanced && !report.real_payment_processed);
    reports[role] = {same_invoice:true, same_session:true, ledger_balanced:true};
  }
  const proof = {invoice_id:invoiceId, session_id:session.id,
    source:'OCPP_SIMULATOR', payment:'MOCK_HOSTED', external_provider_verified:false,
    physical_charging_verified:false, real_money_moved:false,
    one_payment:true, loyalty_award_id:earned.award.id, loyalty_award_replayed_once:true,
    roles:reports, recorded_at:new Date().toISOString()};
  const output = path.join(path.dirname(file),`business-invoice-${invoiceId}.json`);
  fs.writeFileSync(output,JSON.stringify(proof,null,2)+'\n',{mode:0o600});
  console.log(`Source session -> mock hosted payment -> single loyalty award -> vendor/Admin canonical report: ${output}`);
}
main().catch(e=>{console.error(e.message); console.error('Stopped: inspect retained canonical state before resuming. No hardware commands were sent.');process.exitCode=1;});
