import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const source=await fs.readFile(new URL('../../resources/js/Utils/priceTypes.js',import.meta.url),'utf8');
const {unitPrice,priceTypeName,defaultPriceTypes}=await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
test('custom unit prices remain distinct from defaults and missing prices never fall back',()=>{
 const unit={selling_price:'100.00',wholesale_price:'80.00',custom_prices:{type_member:{amount:'95.25',mode:'manual'}}};
 assert.equal(unitPrice(unit),100);assert.equal(unitPrice(unit,'wholesale'),80);assert.equal(unitPrice(unit,'type_member'),95.25);
 for(const price of [undefined,{amount:null},{amount:''},{amount:0},{amount:'120.00',status:'cost_required'}])assert.equal(unitPrice({...unit,custom_prices:{type_member:price}},'type_member'),null);
 assert.equal(unitPrice(unit,'type_foreign'),null);assert.equal(unitPrice(null,'retail'),null);
 assert.equal(unitPrice({...unit,selling_price:0,selling_price_mode:'automatic'},'retail'),null);
 assert.equal(priceTypeName('type_member',[...defaultPriceTypes,{code:'type_member',name:'Member'}]),'Member');
});
