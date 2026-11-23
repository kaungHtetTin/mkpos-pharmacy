import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
const { POS_BASE_URL: base, POS_TEST_EMAIL: email, POS_TEST_PASSWORD: password } = process.env;
const enabled=base&&email&&password;
const product=(index,stock=20)=>({id:`retained-${index}`,name:`QA Retained Medicine ${index}`,barcode:`QA-KEEP-${index}`,stock_quantity:stock,discount_percentage:0,total_tax_rate:0,tax_method:'Exclusive',units:[{id:'qa-pu',unit_id:'qa-unit',name:'Piece',short_name:'pcs',conversion_factor:1,is_base_unit:true,is_default_selling_unit:true,selling_price:100,wholesale_price:80}]});
for(const scenario of [
 {width:1440,view:'table',search:false,unconfirmed:true},
 {width:1440,view:'table',search:true},
 {width:1440,view:'grid',search:false,failRefresh:true},
 {width:390,view:'table',search:false},
 {width:390,view:'table',search:true},
]) {
 test(`checkout preserves catalog ${scenario.width}px ${scenario.view} ${scenario.search?'search':'paged'}${scenario.failRefresh?' with retry':''}`,{skip:!enabled,timeout:60000},async()=>{
  const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--disable-dev-shm-usage']});
  const page=await browser.newPage({viewport:{width:scenario.width,height:900},serviceWorkers:'block'});page.setDefaultTimeout(12000);
  const mobile=scenario.width<981,errors=[];page.on('pageerror',e=>errors.push(e.message));
  let confirmed=false,posts=0,refreshFailed=false,refreshRequests=[];
  let releaseRefresh;const refreshGate=new Promise(resolve=>{releaseRefresh=resolve;});
  try {
   await page.goto(base+'/login');await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL('**/dashboard');
   const response=await page.goto(base+'/pos'),html=await response.text(),pattern=/(<script id="spa-page" type="application\/json">)([\s\S]*?)(<\/script>)/,match=html.match(pattern);assert(match);const data=JSON.parse(match[2]);Object.assign(data.props,{activeSession:{id:'qa-session',opened_at:new Date().toISOString(),opening_amount:0},paymentMethods:['Cash','Banking'],posDefaults:{...data.props.posDefaults,default_view:scenario.view,default_payment_method:'Banking',barcode_focus:false,auto_print_receipt:false}});
   await page.route('**/pos',r=>r.fulfill(r.request().headers()['x-spa']==='true'?{json:data}:{contentType:'text/html',body:html.replace(pattern,(_,a,c,d)=>a+JSON.stringify(data).replaceAll('<','\\u003c')+d)}));
   await page.route('**/pos/categories',r=>r.fulfill({json:[{id:'qa-category',name:'Pain relief'}]}));
   await page.route('**/pos/catalog*',async r=>{const url=new URL(r.request().url()),n=Number(url.searchParams.get('page')||1);if(confirmed){refreshRequests.push({page:n,category:url.searchParams.get('category_id')});await refreshGate;if(scenario.failRefresh&&!refreshFailed){refreshFailed=true;return r.fulfill({status:503,json:{message:'QA temporary stock refresh failure'}});}}await r.fulfill({json:{data:n===1?[product(1,confirmed?19:20),product(2)]:[product(3),product(4)],meta:{current_page:n,last_page:2,total:4}}});});
   await page.route('**/pos/products*',async r=>{const url=new URL(r.request().url());if(confirmed){refreshRequests.push({query:url.searchParams.get('query'),category:url.searchParams.get('category_id')});await refreshGate;}await r.fulfill({json:[product(1,confirmed?19:20)]});});
   await page.route('**/pos/checkout',async r=>{posts++;const result=structuredClone(data);if(scenario.unconfirmed&&posts===1){result.props.flash={error:'QA sale was not confirmed'};return r.fulfill({json:result});}confirmed=true;result.props.flash={sale_receipt:{invoice_number:'QA-RETAINED',grand_total:100,tax:0,discount:0,amount_received:100,change_due:0,payment_status:'Paid',payment_method:'Banking',items:[{name:product(1).name,quantity:1,total_price:100}]}};await r.fulfill({json:result});});
   await page.goto(base+'/pos');await page.getByText(product(1).name,{exact:true}).first().waitFor();
   const search=mobile?page.getByRole('textbox',{name:'Search products',exact:true}):page.getByPlaceholder('Search product or chemical name, or scan barcode',{exact:true});
   const category=mobile?page.getByRole('combobox',{name:/^Category/}):page.getByRole('combobox',{name:'Product category',exact:true});
   await category.click();await page.getByRole('option',{name:'Pain relief',exact:true}).click();
   if(scenario.search){await search.fill('QA Retained');await page.getByText(product(1).name,{exact:true}).first().waitFor();}
   else {await page.getByText(product(4).name,{exact:true}).first().waitFor();}
   if(mobile){await page.getByRole('button',{name:`Add ${product(1).name}`,exact:true}).click();await page.getByRole('button',{name:'Review cart',exact:true}).click();await page.getByRole('button',{name:/Continue to payment/}).click();await page.getByRole('heading',{name:'Payment',exact:true}).waitFor();assert.equal(posts,0,'Entering Payment must never submit the sale');}
   else {if(scenario.view==='table')await page.locator('[data-pos-product-id="retained-1"]').getByRole('button',{name:'Add',exact:true}).click();else await page.getByText(product(1).name,{exact:true}).first().click();await page.getByRole('button',{name:'Checkout',exact:true}).click();await page.getByRole('dialog',{name:'Payment',exact:true}).getByRole('button',{name:'Banking',exact:true}).click();}
   const complete=mobile?page.getByRole('button',{name:/Complete sale/}):page.getByRole('dialog',{name:'Payment',exact:true}).getByRole('button',{name:'Complete Sale',exact:true});await complete.click();
   if(scenario.unconfirmed){await page.getByText('QA sale was not confirmed',{exact:true}).first().waitFor();assert(await page.getByRole('dialog',{name:'Payment',exact:true}).isVisible(),'An unconfirmed sale keeps checkout open');await complete.click();}
   if(mobile){await page.getByRole('heading',{name:'Sale complete',exact:true}).waitFor();await page.getByRole('button',{name:'Start new sale',exact:true}).click();}else await page.getByRole('dialog',{name:'Payment',exact:true}).waitFor({state:'hidden'});
   await page.waitForFunction(()=>!document.querySelector('button[type=submit]')?.textContent.includes('Completing'));
   assert.equal(await search.inputValue(),scenario.search?'QA Retained':'');assert((await category.innerText()).includes('Pain relief'));
   assert(await page.getByText(product(1).name,{exact:true}).first().isVisible(),'Products remain visible while stock refresh is delayed');
   if(!scenario.search)assert(await page.getByText(product(4).name,{exact:true}).first().isVisible(),'All loaded pages remain in the selector');
   if(mobile)assert.equal(await page.locator('[data-mobile-pos-dock]').count(),0,'Cart clears after confirmation');else assert(await page.getByRole('button',{name:'Checkout',exact:true}).isDisabled(),'Cart clears after confirmation');
   releaseRefresh();
   if(scenario.failRefresh){await page.getByRole('button',{name:'Retry loading products',exact:true}).waitFor();assert(await page.getByText(product(1).name,{exact:true}).first().isVisible(),'Refresh failures keep existing products');await page.getByRole('button',{name:'Retry loading products',exact:true}).click();}
   if(mobile)await page.locator('[data-mobile-product="retained-1"]').getByText('19 available',{exact:true}).waitFor();else if(scenario.view==='table')await page.locator('[data-pos-product-id="retained-1"]').getByText('19',{exact:true}).waitFor();else await page.locator('.pos-catalog').getByText(/Stock 19/).waitFor();
   assert(refreshRequests.length>0,'Sale completion refreshes product stock');assert(refreshRequests.every(r=>r.category==='qa-category'));
   if(scenario.search)assert(refreshRequests.every(r=>r.query==='QA Retained'),'Search results refresh using the same query');else {assert(refreshRequests.some(r=>r.page===1));assert(refreshRequests.some(r=>r.page===2));}
   assert.deepEqual(errors,[]);
  }catch(error){console.error(scenario,error.message);console.error((await page.locator('[data-mobile-pos]').innerText().catch(()=>'' )).slice(0,1200));throw error;}finally{releaseRefresh();await browser.close();}
 });
}
