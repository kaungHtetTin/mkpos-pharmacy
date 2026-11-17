import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const source=await fs.readFile(new URL('../../resources/js/mobile/storage.js',import.meta.url),'utf8');
const storage=await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
test('cashier queues and caches are partitioned by account and business',()=>{
    const data=new Map();
    globalThis.localStorage={getItem:key=>data.get(key)??null,setItem:(key,value)=>data.set(key,value)};
    globalThis.window=new EventTarget();
    storage.configureBusinessStorage('alice','alpha');
    storage.setPendingSales([{client_reference:'alpha-sale',summary:{grand_total:10}}]);
    storage.saveJson(storage.keys.customers,[{id:'alpha-customer'}]);
    storage.configureBusinessStorage('alice','beta');
    assert.deepEqual(storage.getPendingSales(),[]);
    assert.equal(storage.loadJson(storage.keys.customers),null);
    storage.setPendingSales([{client_reference:'beta-sale'}]);
    storage.configureBusinessStorage('bob','alpha');
    assert.deepEqual(storage.getPendingSales(),[]);
    storage.configureBusinessStorage('alice','alpha');
    assert.equal(storage.getPendingSales()[0].client_reference,'alpha-sale');
    assert.equal(storage.loadJson(storage.keys.customers)[0].id,'alpha-customer');
    storage.configureBusinessStorage(null,null);
    assert.throws(()=>storage.getPendingSales(),/Select a business/);
});
