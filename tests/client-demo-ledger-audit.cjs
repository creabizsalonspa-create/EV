'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const audit=require('../scripts/client-demo-ledger-audit.cjs');
const RUN='a1b2c3d4',CUSTOMER_ID=70,ADMIN_ID=90,VENDOR_ID=80;
const roles=['customer','vendor','admin'];
function manifestData(){
  const accounts={};
  for(const role of roles)accounts[role]={email:'client-demo-'+role+'-'+RUN+'@example.test',
    password:'b'.repeat(48)+'aA9!'};
  return {fixture_only:true,run:RUN,base:'http://127.0.0.1:8000',
    accounts,company_id:1,stations:['1.6','2.0.1'].map((protocol,i)=>({
      protocol,station_id:i+11,identity:'ev-test-demo-'+RUN+'-'+(i===0?'16':'201'),
      charger_id:30+i,binding_id:40+i,connector_mapping_id:50+i,reservation_id:null
    })),qa_current_bookings:false,physical_charging_verified:false,real_money:false};
}
function entries(invoice,journalId=1000+invoice.id){
  const debit=invoice.total_minor;
  return [
    {journal_id:journalId,account:'receivable:user:'+CUSTOMER_ID,debit_minor:debit,credit_minor:0},
    {journal_id:journalId,account:'vendor_payable:'+VENDOR_ID,debit_minor:0,credit_minor:invoice.vendor_minor},
    {journal_id:journalId,account:'platform_commission',debit_minor:0,credit_minor:invoice.commission_minor},
    {journal_id:journalId,account:'tax_payable:AE',debit_minor:0,credit_minor:invoice.tax_minor},
  ].filter(x=>x.debit_minor||x.credit_minor);
}
function invoiceFor(i){
  const subtotal_minor=100+i*30, tax_minor=5+i,commission_minor=3+i;
  return {id:301+i,charging_session_id:201+i,user_id:CUSTOMER_ID,
    vendor_id:VENDOR_ID,evidence:'SIMULATED',country:'AE',currency:'AED',energy_wh:250+i*75,
    subtotal_minor,tax_minor,commission_minor,vendor_minor:subtotal_minor-commission_minor,
    total_minor:subtotal_minor+tax_minor};
}
const invoices=()=>[invoiceFor(0),invoiceFor(1)];
function records(){
  return {
    reservations:[{id:101,user_id:CUSTOMER_ID,station_id:11},{id:102,user_id:CUSTOMER_ID,station_id:12}],
    sessions:[{id:201,reservation_id:101,status:'stopped',evidence:'SIMULATED_CSMS',
      csms_linked:true,payment_status:'not_processed',energy_wh:250,total_minor:100,currency:'AED'},
      {id:202,reservation_id:102,status:'stopped',evidence:'SIMULATED_CSMS',
      csms_linked:true,payment_status:'not_processed',energy_wh:325,total_minor:130,currency:'AED'}],
    invoices:invoices(),
  };
}
async function sandbox(fn){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'ev-ledger-audit-'));
  fs.chmodSync(root,0o700);
  const base=path.join(root,'client-demo'),dir=path.join(base,RUN);
  fs.mkdirSync(base,{mode:0o700});fs.mkdirSync(dir,{mode:0o700});
  const file=path.join(dir,'access.json');
  fs.writeFileSync(file,JSON.stringify(manifestData()),{mode:0o600});
  try{return await fn({root,file,output:path.join(root,'report.json')});}
  finally{fs.rmSync(root,{recursive:true,force:true});}
}
function sender({journalFault=null,denyCustomerJournal=true,adminJournalStatus=200,
  customerBadRole=false,vendorBadRole=false,adminBadRole=false,
  vendorJournalStatus=200,vendorForeignInvoice=false,
  badAdminLogout=false,rows=records(),trace=[]}={}){
  let counter=0;
  const stage=new Map();
  return async(route,method,payload,jar)=>{
    trace.push({route,method,role:stage.get(jar)||'none'});
    if(route==='/api/csrf'){
      counter++;
      jar.set('laravel_session','session'+counter);
      return {status:200,body:{token:'0123456789abcdef1234'}};
    }
    if(route==='/api/login'){
      const role=roles.find(r=>payload.email===manifestData().accounts[r].email);
      stage.set(jar,role);
      return {status:200,body:{id:role==='customer'?CUSTOMER_ID:
        role==='vendor'?VENDOR_ID:ADMIN_ID,
        role:role==='customer'&&customerBadRole?'admin':
          role==='vendor'&&vendorBadRole?'customer':
          role==='admin'&&adminBadRole?'vendor':role,email:payload.email}};
    }
    if(route==='/api/reservations')return {status:200,body:{data:rows.reservations,total:rows.reservations.length}};
    if(route==='/api/simulations')return {status:200,body:{data:rows.sessions,total:rows.sessions.length}};
    if(route==='/api/finance/invoices'){
      const list=stage.get(jar)==='vendor'&&vendorForeignInvoice?
        [...rows.invoices,{id:999,vendor_id:999}]:rows.invoices;
      return {status:200,body:{data:list,total:list.length}};
    }
    if(/^\/api\/finance\/invoices\/[0-9]+\/journal$/.test(route)){
      if(stage.get(jar)==='customer')
        return {status:denyCustomerJournal?403:200,body:{}};
      if(stage.get(jar)==='vendor' && vendorJournalStatus!==200)
        return {status:vendorJournalStatus,body:{}};
      if(stage.get(jar)==='admin' && adminJournalStatus!==200)
        return {status:adminJournalStatus,body:{}};
      const id=Number(route.split('/').at(-2)),inv=rows.invoices.find(x=>x.id===id);
      if(!inv)return {status:404,body:{}};
      const journalId=1000+id;
      const body={journals:[{id:journalId,invoice_id:id,kind:'invoice',currency:'AED',
        amount_minor:inv.total_minor,idempotency_key:'invoice:'+id}],
        entries:entries(inv,journalId)};
      if(journalFault)journalFault(body,inv);
      return {status:200,body};
    }
    if(route==='/api/logout')return {status:stage.get(jar)==='admin'&&badAdminLogout?500:204,body:null};
    if(route==='/api/me')return {status:stage.get(jar)==='admin'&&badAdminLogout?200:401,body:null};
    throw Error('Unknown or unsafe read-only journal route '+route);
  };
}
test('balanced simulated invoice verifies only prescribed noncash journal accounts',()=>{
  const inv=invoiceFor(0),journalId=1000+inv.id;
  const response={journals:[{id:journalId,invoice_id:inv.id,kind:'invoice',
    currency:'AED',amount_minor:inv.total_minor,idempotency_key:'invoice:'+inv.id}],
    entries:entries(inv,journalId)};
  const result=audit.validateInvoiceJournal(inv,CUSTOMER_ID,response);
  assert.equal(result.ledger_balanced,true);
  assert.equal(result.no_cash_entry,true);
  assert.equal(result.real_payment_verified,false);
});
test('duplicated, unknown or cash ledger entry cannot be presented as isolated synthetic',()=>{
  for(const fault of [
    (body)=>body.entries.push({...body.entries[0]}),
    (body)=>body.entries[1].account='cash:simulator',
    (body)=>body.entries[1].debit_minor=10,
    (body)=>body.entries[0].journal_id=9999,
    (body)=>body.entries.pop(),
  ]){
    const inv=invoiceFor(0),id=inv.id;
    const body={journals:[{id:1000+id,invoice_id:id,kind:'invoice',currency:'AED',
      amount_minor:inv.total_minor,idempotency_key:'invoice:'+id}],entries:entries(inv,1000+id)};
    fault(body);assert.throws(()=>audit.validateInvoiceJournal(inv,CUSTOMER_ID,body));
  }
});
test('fake or incomplete finance posting is rejected',()=>{
  for(const invalid of [
    inv=>inv.total_minor+=1,inv=>inv.vendor_minor+=1,
    inv=>inv.evidence='LIVE_PSP',inv=>inv.currency='USD',inv=>inv.user_id=999,
    inv=>inv.vendor_id=null,inv=>inv.tax_minor=-1
  ]){
    const inv=invoiceFor(0),id=inv.id;invalid(inv);
    const response={journals:[{id:1000+id,invoice_id:id,kind:'invoice',currency:'AED',
      amount_minor:inv.total_minor,idempotency_key:'invoice:'+id}],entries:entries(inv,1000+id)};
    assert.throws(()=>audit.validateInvoiceJournal(inv,CUSTOMER_ID,response));
  }
});
test('empty invoice double-entry requires at least a positive financial amount',()=>{
  const inv=invoiceFor(0);inv.subtotal_minor=0;inv.total_minor=0;inv.vendor_minor=0;inv.tax_minor=0;inv.commission_minor=0;
  const response={journals:[{id:1000+inv.id,invoice_id:inv.id,kind:'invoice',currency:'AED',
    amount_minor:0,idempotency_key:'invoice:'+inv.id}],entries:[]};
  assert.throws(()=>audit.validateInvoiceJournal(inv,CUSTOMER_ID,response));
});
test('customer denied journal while Admin obtains read-only balanced postings',()=>sandbox(async({file})=>{
  const trace=[];const result=await audit.inspect(file,sender({trace}),()=> '2026-11-07T18:00:00Z');
  assert.equal(result.status,'LOCAL_SIMULATED_LEDGER_VERIFIED');
  assert.equal(result.balanced_invoice_postings,2);
  assert.equal(result.production_accepted,false);
  assert.equal(result.real_money_moved,false);
  assert.deepEqual(result.simulated_protocols,['1.6','2.0.1']);
  assert.equal(trace.filter(x=>x.route.includes('/journal')).length,6);
  assert.equal(trace.filter(x=>x.route.includes('/journal')&&x.method!=='GET').length,0);
  assert.deepEqual(trace.filter(x=>x.method==='POST').map(x=>x.route),
    ['/api/login','/api/logout','/api/login','/api/logout','/api/login','/api/logout']);
}));
test('customer unexpectedly authorized to view financial journal fails closed',()=>sandbox(async({file})=>{
  const result=await audit.inspect(file,sender({denyCustomerJournal:false}));
  assert.equal(result.status,'INCOMPLETE');
  assert.equal(result.balanced_invoice_postings,0);
}));
test('Admin journal unavailable or incorrect role denies a complete demo',()=>sandbox(async({file})=>{
  for(const settings of [{adminJournalStatus:403},{adminBadRole:true},{customerBadRole:true}]){
    const result=await audit.inspect(file,sender(settings));
    assert.equal(result.status,'INCOMPLETE');
  }
}));
test('unbalanced journal and unknown external cash account are refused',()=>sandbox(async({file})=>{
  for(const journalFault of [body=>body.entries[0].debit_minor+=1,
    body=>body.entries[1].account='cash:real_bank',
    body=>body.journals[0].kind='payment']){
    const result=await audit.inspect(file,sender({journalFault}));
    assert.equal(result.status,'INCOMPLETE');
    assert.equal(result.balanced_invoice_postings,0);
  }
}));
test('Admin logout must invalidate the private session',()=>sandbox(async({file})=>{
  const result=await audit.inspect(file,sender({badAdminLogout:true}));
  assert.equal(result.status,'INCOMPLETE');
}));
test('private owner-only proof is never overwritten or promoted to real payment acceptance',()=>sandbox(async({file,output})=>{
  const options=['--manifest',file,'--output',output];
  assert.equal(await audit.main(options,{send:sender()}),0);
  const data=JSON.parse(fs.readFileSync(output,'utf8'));
  assert.equal(data.status,'LOCAL_SIMULATED_LEDGER_VERIFIED');
  for(const field of ['real_money_moved','payment_processor_verified','physical_charging_verified',
    'production_accepted','customer_contract_accepted'])assert.equal(data[field],false);
  if(process.platform!=='win32')assert.equal(fs.statSync(output).mode&0o077,0);
  assert.equal(await audit.main(options,{send:sender()}),2);
}));
test('source invalid or remote fixture cannot produce private proof',()=>sandbox(async({file,output})=>{
  const changed=manifestData();changed.base='https://production.example.org';
  fs.writeFileSync(file,JSON.stringify(changed));
  assert.equal(await audit.main(['--manifest',file,'--output',output],{send:sender()}),2);
  assert.equal(fs.existsSync(output),false);
}));
test('unexpected CLI switches and public server URLs cannot be used',()=>{
  for(const args of [[],['--url','https://real-provider.example.org'],
    ['--manifest','--unsafe','--output','proof.json'],
    ['--manifest','a','--output','b','--issue-payment']]){
    assert.throws(()=>audit.parseArgs(args));
  }
});

test('vendor must see both own simulated journals and no unrelated invoices',()=>sandbox(async({file})=>{
  const trace=[];
  const proof=await audit.inspect(file,sender({trace}));
  assert.equal(proof.status,'LOCAL_SIMULATED_LEDGER_VERIFIED');
  const vendorReads=trace.filter(x=>x.role==='vendor'&&x.route.includes('/journal'));
  assert.equal(vendorReads.length,2);
  assert.ok(vendorReads.every(x=>x.method==='GET'));
}));
test('foreign vendor invoice in vendor list blocks synthetic finance acceptance',()=>sandbox(async({file})=>{
  const proof=await audit.inspect(file,sender({vendorForeignInvoice:true}));
  assert.equal(proof.status,'INCOMPLETE');
}));
test('vendor without journal read permission or wrong role cannot pass',()=>sandbox(async({file})=>{
  for(const opts of [{vendorJournalStatus:403},{vendorBadRole:true}]){
    const proof=await audit.inspect(file,sender(opts));
    assert.equal(proof.status,'INCOMPLETE');
  }
}));
