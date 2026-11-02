const fs=require('fs'),vm=require('vm'),assert=require('assert');
const path=require('path');const root=path.join(__dirname,'../jangopay-for-woocommerce');
let count=0;
for(const data of [{title:'JangoPay (USDT / USDC)',description:'Hosted payment',icon:'https://store.test/logo.png'},{title:'Merchant label',description:'',icon:''}]){
 let registered;const createElement=(type,props,...children)=>({type,props:props||{},children});
 const context={window:{wc:{wcBlocksRegistry:{registerPaymentMethod:v=>registered=v},wcSettings:{getSetting:()=>data}},wp:{element:{createElement},htmlEntities:{decodeEntities:v=>v}}}};
 vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/blocks.js'),'utf8'),context);
 assert.equal(registered.name,'jangopay_woocommerce');assert.equal(registered.ariaLabel,data.title);assert.equal(registered.label.children[1],data.title);
 assert.equal(registered.content.type().children[0],data.description);
 assert.equal(registered.canMakePayment({cartTotals:{currency_code:'USD'}}),true);assert.equal(registered.canMakePayment({cartTotals:{currency_code:'EUR'}}),false);
 count+=6;
 if(data.icon){const image=registered.label.children[0];assert.equal(image.props.src,data.icon);assert.equal(image.props.alt,'');const event={currentTarget:{style:{}}};image.props.onError(event);assert.equal(event.currentTarget.style.display,'none');assert.equal(registered.label.children[1],data.title);count+=4;}
}
console.log(JSON.stringify({checks:count,passed:count,custom_text_and_image_failure_verified:true}));

