'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {parseJsonStrict}=require('../scripts/agent10-strict-json.cjs');
test('strict scanner accepts nested canonical JSON and literal quote/colon value text',()=>{
 const value={z:[true,false,null,{key:'a: b, { "quoted"'}],n:-12.3e4,other:'\\quoted'};
 assert.deepEqual(parseJsonStrict(JSON.stringify(value)),value);
});
test('rejects duplicate top-level and nested keys',()=>{
 for (const input of ['{"id":1,"id":2}', '{"a":{"x":1,"x":2}}', '[{"a":3,"a":5}]', '{"a":1,"\\u0061":2}'])
   assert.throws(()=>parseJsonStrict(input),/Duplicate JSON object key/);
});
test('value strings cannot be mistaken for object property names',()=>{
 assert.deepEqual(parseJsonStrict('{"x":"key: value, {\\\"y\\\":9}","y":2}'),{x:'key: value, {"y":9}',y:2});
});
test('invalid JSON, trailing text and excessive nesting all fail',()=>{
 for(const text of ['{"x":NaN}','{"x":Infinity}','{"x":1}{}','{"x":1,}','[1,,2]','{"x":"\\q"}','{"x":1e}', '{"x":"unterminated}', '00', 'truefalse'])
   assert.throws(()=>parseJsonStrict(text));
 assert.throws(()=>parseJsonStrict('['.repeat(130)+'0'+']'.repeat(130)),/nesting limit/);
});
