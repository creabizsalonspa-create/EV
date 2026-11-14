const {test} = require('node:test'), assert = require('node:assert/strict');
const {procIdentity,verifies} = require('./helpers/owned-simulator-process.cjs');
const root='/owned/root',manifest=root+'/artifacts/client-demo/owned/access.json';
test('known exiting child remains owned until actual zombie state, including empty argv',()=>{
    assert.equal(verifies({command:'',state:'R',start_ticks:'123'},'123',root,manifest),true);
    assert.equal(verifies({command:'',state:'Z',start_ticks:'123'},'123',root,manifest),false);
    assert.equal(verifies({command:`node ${root}/scripts/client-demo-charger.cjs ${manifest}`,state:'S',start_ticks:'123'},'123',root,manifest),true);
});
test('reused PID, different program and unproven empty argv remain refused',()=>{
    assert.throws(()=>verifies({command:'',state:'R',start_ticks:'124'},'123',root,manifest),/PID belongs/);
    assert.throws(()=>verifies({command:'node other-app',state:'S',start_ticks:'123'},'123',root,manifest),/PID belongs/);
    assert.throws(()=>verifies({command:'',state:'R',start_ticks:'123'},null,root,manifest),/PID belongs/);
});
test('kernel birth marker parser handles spaces and parentheses in process names',()=>{
    const fields=['S',...Array(18).fill('0'),'123','0'];
    assert.deepEqual(procIdentity('42 (node (worker)) '+fields.join(' ')),{state:'S',start_ticks:'123'});
});
