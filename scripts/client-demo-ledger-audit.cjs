#!/usr/bin/env node
'use strict';
// Agent 10 only: read-only financial ledger evidence for two LOCAL synthetic OCPP sessions.
// Never creates a journal, initiates money transfer, books chargers, or touches production.
const {load,jsonRequest}=require('./staging-synthetic-role-smoke.cjs');
const {validateCompleted,validateMockInvoices,page}=require('./client-demo-session-evidence.cjs');
const {checkOutputDestination,writeEvidence}=require('./staging-acceptance.cjs');

const SCHEMA='ev-local-csms-ledger-audit-v1';
const PROTOCOLS=['1.6','2.0.1'];
function parseArgs(argv){
  if(!Array.isArray(argv)||argv.length!==4||argv[0]!=='--manifest'||
    !argv[1]||argv[1].startsWith('--')||argv[2]!=='--output'||!argv[3]||
    argv[3].startsWith('--'))throw Error('Use --manifest private fixture --output new owner-only JSON');
  return {manifest:argv[1],output:argv[3]};
}
function safeAmount(value){
  return Number.isSafeInteger(value)&&value>=0&&value<=2000000000;
}
function validateInvoiceJournal(invoice,customerId,response){
  if(!invoice||!Number.isSafeInteger(invoice.id)||invoice.id<=0||
    !Number.isSafeInteger(customerId)||customerId<=0 ||
    invoice.user_id!==customerId || invoice.currency!=='AED' ||
    invoice.country!=='AE' || invoice.evidence!=='SIMULATED' ||
    !Number.isSafeInteger(invoice.vendor_id)||invoice.vendor_id<=0 ||
    !['subtotal_minor','tax_minor','commission_minor','vendor_minor','total_minor']
      .every(key=>safeAmount(invoice[key])))throw Error('Unexpected private demo invoice');
  if(invoice.total_minor!==invoice.subtotal_minor+invoice.tax_minor ||
    invoice.vendor_minor!==invoice.subtotal_minor-invoice.commission_minor ||
    invoice.commission_minor>invoice.subtotal_minor)throw Error('Synthetic invoice totals do not reconcile');
  if(!response || !Array.isArray(response.journals) || response.journals.length!==1 ||
    !Array.isArray(response.entries) || response.entries.length<2 ||
    response.entries.length>4)throw Error('Expected one balanced synthetic invoice journal');
  const journal=response.journals[0];
  if(!Number.isSafeInteger(journal?.id)||journal.id<=0 ||
    journal.invoice_id!==invoice.id || journal.kind!=='invoice'||
    journal.currency!=='AED'||journal.amount_minor!==invoice.total_minor ||
    journal.idempotency_key!=='invoice:'+invoice.id){
    throw Error('Journal is not the exact simulated invoice posting');
  }
  const amounts=new Map([
    ['receivable:user:'+customerId,{debit:invoice.total_minor,credit:0}],
    ['vendor_payable:'+invoice.vendor_id,{debit:0,credit:invoice.vendor_minor}],
    ['platform_commission',{debit:0,credit:invoice.commission_minor}],
    ['tax_payable:AE',{debit:0,credit:invoice.tax_minor}],
  ]);
  let debit=0,credit=0;const seen=new Set();
  for(const item of response.entries){
    if(item?.journal_id!==journal.id || typeof item.account!=='string'||
      !amounts.has(item.account)||seen.has(item.account)||
      !safeAmount(item.debit_minor)||!safeAmount(item.credit_minor) ||
      (item.debit_minor===0 && item.credit_minor===0)){
      throw Error('Unknown or duplicated private synthetic journal entry');
    }
    const expected=amounts.get(item.account);
    if(item.debit_minor!==expected.debit || item.credit_minor!==expected.credit){
      throw Error('Synthetic journal account distribution does not match invoice');
    }
    seen.add(item.account);debit+=item.debit_minor;credit+=item.credit_minor;
  }
  for(const [account,amount] of amounts){
    if(amount.debit||amount.credit){
      if(!seen.has(account))throw Error('Missing credited or debited ledger account');
    }else if(seen.has(account))throw Error('Zero-value account must not be journaled');
  }
  if(debit!==credit || debit!==invoice.total_minor || !debit ||
    !Number.isSafeInteger(debit)||!Number.isSafeInteger(credit)){
    throw Error('Local synthetic invoice posting is not balanced');
  }
  return {posting_verified:true,ledger_balanced:true,simulated_only:true,
    no_cash_entry:true,real_payment_verified:false};
}
async function authorize(role,fixture,send,jar){
  const csrf=await send('/api/csrf','GET',undefined,jar);
  if(csrf.status!==200||typeof csrf.body?.token!=='string'||csrf.body.token.length<12)
    throw Error('Synthetic browser CSRF failed');
  jar.set('__csrf_token',csrf.body.token);
  const account=fixture.accounts[role];
  const login=await send('/api/login','POST',{email:account.email,password:account.password},jar);
  // A successful but incorrect identity still requires logout; caller sets flag first.
  return {loggedIn:login.status===200,identity:login.body,id:login.body?.id};
}
async function revoke(jar,send){
  try {
    const logout=await send('/api/logout','POST',undefined,jar);
    const state=await send('/api/me','GET',undefined,jar);
    return logout.status===204 && [401,403].includes(state.status);
  }catch{return false;}
}
async function inspect(manifest,send=jsonRequest,clock=()=>new Date().toISOString()){
  const result={schema:SCHEMA,checked_from_utc:clock(),status:'INCOMPLETE',
    source:'LOCAL_SYNTHETIC_ONLY',simulated_protocols:[],balanced_invoice_postings:0,
    payment_processor_verified:false,real_money_moved:false,physical_charging_verified:false,
    production_accepted:false,customer_contract_accepted:false};
  const fixture=load(manifest);
  let customerOk=false,vendorOk=false,adminOk=false;
  const customerJar=new Map();
  const vendorJar=new Map();
  const adminJar=new Map();
  try {
    const customer=await authorize('customer',fixture,send,customerJar);
    customerOk=customer.loggedIn;
    if(!customerOk || customer.identity?.role!=='customer' ||
      customer.identity?.email!==fixture.accounts.customer.email ||
      !Number.isSafeInteger(customer.id))throw Error('Wrong local customer identity');
    const [r,s,i]=await Promise.all([
      send('/api/reservations','GET',undefined,customerJar),
      send('/api/simulations','GET',undefined,customerJar),
      send('/api/finance/invoices','GET',undefined,customerJar),
    ]);
    if(r.status!==200||s.status!==200||i.status!==200)throw Error('Read-only customer ledgers unavailable');
    const completed=validateCompleted(fixture,customer.id,page(r.body),page(s.body));
    const references=validateMockInvoices(completed,customer.id,page(i.body));
    // The full private journal is deliberately inaccessible to customer.
    for(const reference of references){
      const prohibited=await send('/api/finance/invoices/'+reference.invoice_id+'/journal','GET',undefined,customerJar);
      if(prohibited.status!==403)throw Error('Customer could access private journal');
    }
    if(!await revoke(customerJar,send))throw Error('Local customer session remained authenticated');
    customerOk=false;

    const invoices=page(i.body);
    const vendor=await authorize('vendor',fixture,send,vendorJar);
    vendorOk=vendor.loggedIn;
    if(!vendorOk || vendor.identity?.role!=='vendor' ||
      vendor.identity?.email!==fixture.accounts.vendor.email ||
      !Number.isSafeInteger(vendor.id))throw Error('Wrong local vendor identity');
    const vendorListing=await send('/api/finance/invoices','GET',undefined,vendorJar);
    if(vendorListing.status!==200 || !Array.isArray(vendorListing.body?.data) ||
      vendorListing.body.data.some(x=>x.vendor_id!==vendor.id)) {
      throw Error('Private vendor invoice scope mismatch');
    }
    for(const reference of references) {
      const invoice=invoices.find(x=>x.id===reference.invoice_id);
      if(!invoice || invoice.vendor_id!==vendor.id ||
        !vendorListing.body.data.some(x=>x.id===invoice.id)) {
        throw Error('Simulated invoice not owned by synthetic vendor');
      }
      const response=await send('/api/finance/invoices/'+invoice.id+'/journal','GET',undefined,vendorJar);
      if(response.status!==200)throw Error('Vendor cannot review its simulated journal');
      validateInvoiceJournal(invoice,customer.id,response.body);
    }
    if(!await revoke(vendorJar,send))throw Error('Local vendor session remained authenticated');
    vendorOk=false;

    const admin=await authorize('admin',fixture,send,adminJar);
    adminOk=admin.loggedIn;
    if(!adminOk || admin.identity?.role!=='admin'||
      admin.identity?.email!==fixture.accounts.admin.email ||
      !Number.isSafeInteger(admin.id))throw Error('Wrong synthetic Admin identity');
    for(const reference of references){
      const invoice=invoices.find(x=>x.id===reference.invoice_id);
      if(!invoice)throw Error('Source invoice disappeared from customer listing');
      const journal=await send('/api/finance/invoices/'+reference.invoice_id+'/journal','GET',undefined,adminJar);
      if(journal.status!==200)throw Error('Admin read-only journal unavailable');
      validateInvoiceJournal(invoice,customer.id,journal.body);
    }
    if(!await revoke(adminJar,send))throw Error('Local admin browser logout not confirmed');
    adminOk=false;
    result.status='LOCAL_SIMULATED_LEDGER_VERIFIED';
    result.simulated_protocols=PROTOCOLS;
    result.balanced_invoice_postings=2;
  }catch{
    result.status='INCOMPLETE';result.simulated_protocols=[];result.balanced_invoice_postings=0;
  }finally{
    if(customerOk||vendorOk||adminOk){
      let cleared=true;
      if(customerOk)cleared=(await revoke(customerJar,send))&&cleared;
      if(vendorOk)cleared=(await revoke(vendorJar,send))&&cleared;
      if(adminOk)cleared=(await revoke(adminJar,send))&&cleared;
      if(!cleared){result.status='INCOMPLETE';result.simulated_protocols=[];result.balanced_invoice_postings=0;}
    }
  }
  result.checked_until_utc=clock();
  return result;
}
async function main(argv=process.argv.slice(2),deps={}){
  try{
    const config=parseArgs(argv);
    checkOutputDestination(config.output);
    const proof=await inspect(config.manifest,deps.send||jsonRequest,deps.clock);
    writeEvidence(config.output,proof);
    if(proof.status!=='LOCAL_SIMULATED_LEDGER_VERIFIED'){
      process.stderr.write('FAIL: private synthetic double-entry ledger or customer/vendor/Admin authorization evidence incomplete; no details disclosed.\n');
      return 1;
    }
    process.stdout.write('PASS: two independent simulated OCPP invoice postings balanced, with no cash ledger, real payment or physical charger acceptance.\n');
    return 0;
  }catch{
    process.stderr.write('FAIL: strict local-only manifest or private report destination unavailable; details withheld.\n');
    return 2;
  }
}
if(require.main===module)main().then(code=>{process.exitCode=code;});
module.exports={SCHEMA,PROTOCOLS,parseArgs,safeAmount,validateInvoiceJournal,authorize,revoke,inspect,main};
