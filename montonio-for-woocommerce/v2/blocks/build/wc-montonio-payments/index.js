(()=>{"use strict";const e=window.React,t=window.wp.i18n,n=window.wp.element,o=window.wp.data,{registerPaymentMethod:i}=wc.wcBlocksRegistry,{getSetting:a}=wc.wcSettings,{decodeEntities:c}=wp.htmlEntities,{applyFilters:r}=wp.hooks,l=a("wc_montonio_payments_data",{}),s=r("wc_montonio_payments_block_title",c(l.title||(0,t.__)("Pay with your bank","montonio-for-woocommerce")),l),m=r("wc_montonio_payments_block_description",c(l.description),l),u=({defaultRegion:t})=>((0,n.useEffect)((()=>{"undefined"!=typeof Montonio&&Montonio.Checkout&&Montonio.Checkout.PaymentInitiation&&(window.onMontonioLoaded=function(){Montonio.Checkout.PaymentInitiation.create({accessKey:l.accessKey,storeSetupData:l.storeSetupData,currency:l.currency,targetId:"montonio-pis-widget-container",defaultRegion:t,regions:l.regions,regionNames:l.regionNames,inputName:"montonio_payments_preselected_bank",regionInputName:"montonio_payments_preferred_country",displayAsList:"list"===l.handleStyle}).init()},window.onMontonioLoaded())}),[]),(0,n.useEffect)((()=>{if("billing"===l.preselectCountry&&l.availableCountries.includes(t)){const e=document.querySelector(".montonio-bank-select-class");e&&(e.value=t,e.dispatchEvent(new Event("change")))}}),[t]),(0,e.createElement)("div",{id:"montonio-pis-widget-container"})),d=({eventRegistration:t})=>{const{onPaymentSetup:i}=t,a=(0,o.useSelect)((e=>"billing"===l.preselectCountry&&e("wc/store/cart").getCartData().billingAddress.country||l.defaultRegion));return(0,n.useEffect)((()=>{const e=i((()=>({type:"success",meta:{paymentMethodData:{montonio_payments_preselected_bank:document.querySelector('input[name="montonio_payments_preselected_bank"]')?.value||"",montonio_payments_preferred_country:document.querySelector('input[name="montonio_payments_preferred_country"]')?.value||a}}})));return()=>e()}),[i,a]),(0,e.createElement)(e.Fragment,null,m&&(0,e.createElement)("p",{className:"montonio-payment-block-description"},m),"hidden"!==l.handleStyle&&(0,e.createElement)(u,{defaultRegion:a}))},p=()=>(0,e.createElement)("span",null,(0,e.createElement)("img",{src:l.iconurl}));i({name:"wc_montonio_payments",label:(0,e.createElement)((()=>(0,e.createElement)("span",null,s,(0,e.createElement)(p,null))),null),content:(0,e.createElement)(d,null),edit:(0,e.createElement)(d,null),canMakePayment:()=>r("wc_montonio_payments_block_enabled",!0,l),ariaLabel:s})})();