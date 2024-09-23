(()=>{"use strict";const e=window.React,t=window.wp.element,n=window.wp.data,o=window.wp.i18n,{registerPaymentMethod:a}=wc.wcBlocksRegistry,{getSetting:r}=wc.wcSettings,{decodeEntities:i}=wp.htmlEntities,{applyFilters:c}=wp.hooks,s=r("wc_montonio_card_data",{}),l=c("wc_montonio_card_block_title",i(s.title||(0,o.__)("Card Payment","montonio-for-woocommerce")),s),m=c("wc_montonio_card_block_description",i(s.description),s),d=({setIsFormCompleted:a,setEmbeddedPayment:r,onReinitialization:i})=>{const[c,l]=(0,t.useState)(!1),[m,d]=(0,t.useState)(!1),[u,w]=(0,t.useState)(null),y=(0,n.useSelect)((e=>{const t=e("wc/store/cart").getCustomerData();return t.billingAddress.country||t.shippingAddress.country})),p=(0,t.useCallback)((async()=>{if(l(!0),w(null),window.cardPaymentsIntentData)return await E(),d(!0),void l(!1);const e=new FormData;e.append("action","get_payment_intent"),e.append("method","cardPayments"),e.append("sandbox_mode",s.sandboxMode);try{const t=await fetch(woocommerce_params.ajax_url,{method:"POST",credentials:"same-origin",body:e}),n=await t.json();if(!n.success)throw new Error(n.data||"Failed to initialize payment");window.cardPaymentsIntentData=n.data,await E(),d(!0)}catch(e){console.error("Error initializing payment:",e),w(e.message||"Failed to initialize payment")}finally{l(!1)}}),[]),E=async()=>{try{if("undefined"==typeof Montonio||void 0===Montonio.Checkout)throw new Error("Montonio SDK is not loaded");const e=await Montonio.Checkout.EmbeddedPayments.initializePayment({stripePublicKey:window.cardPaymentsIntentData.stripePublicKey,stripeClientSecret:window.cardPaymentsIntentData.stripeClientSecret,paymentIntentUuid:window.cardPaymentsIntentData.uuid,locale:s.locale||"en",country:y||"EE",targetId:"montonio-card-form"});e.on("change",(e=>{a(e.isCompleted)})),r(e)}catch(e){console.error("Error in initializePayment:",e)}};return(0,t.useEffect)((()=>{m||p()}),[m,p]),(0,t.useEffect)((()=>{i((()=>{d(!1),p()}))}),[i,p]),(0,e.createElement)("div",{id:"montonio-card-form-wrapper",className:c?"loading":"",style:c?{opacity:.6}:{}},c&&(0,e.createElement)("div",{className:"montonio-loader"},(0,o.__)("Loading...","montonio-for-woocommerce")),u&&(0,e.createElement)("div",{className:"montonio-error"},u),(0,e.createElement)("div",{id:"montonio-card-form"}))},u=({eventRegistration:a})=>{if("yes"!==s.inlineCheckout)return m?(0,e.createElement)("p",{className:"montonio-payment-block-description"},m):null;const{onPaymentSetup:r,onCheckoutSuccess:i}=a,[c,l]=(0,t.useState)(!1),[u,w]=(0,t.useState)(null),{createErrorNotice:y,removeNotice:p}=(0,n.useDispatch)("core/notices"),E=(0,t.useRef)(null),_=document.getElementById("montonio-card-form"),f=(0,t.useCallback)((e=>{E.current=e}),[]);return(0,t.useEffect)((()=>{const e=r((()=>{if(p("wc-montonio-card-error","wc/checkout"),_&&""!==_.innerHTML.trim()&&!c)return{type:"error",message:(0,o.__)("Please fill in the required fields for the payment method.","montonio-for-woocommerce")}})),t=i((async e=>{if(_&&""!==_.innerHTML.trim())try{return{type:"success",redirectUrl:(await u.confirmPayment("yes"===s.sandboxMode)).returnUrl}}catch(e){const t=e.message||(0,o.__)("An error occurred during payment processing. Please try again.","montonio-for-woocommerce");return y(t,{context:"wc/checkout"}),E.current&&E.current(),{type:"error",message:t,retry:!0}}}));return()=>{e(),t()}}),[r,i,c,u,y,p]),(0,e.createElement)(e.Fragment,null,m&&(0,e.createElement)("p",{className:"montonio-payment-block-description"},m),(0,e.createElement)(d,{setIsFormCompleted:l,setEmbeddedPayment:w,onReinitialization:f}))},w=()=>(0,e.createElement)("span",null,(0,e.createElement)("img",{src:s.iconurl,alt:"Montonio Card"}));a({name:"wc_montonio_card",label:(0,e.createElement)((()=>(0,e.createElement)("span",null,l,(0,e.createElement)(w,null))),null),content:(0,e.createElement)(u,null),edit:(0,e.createElement)(u,null),canMakePayment:()=>c("wc_montonio_card_block_enabled",!0,s),ariaLabel:l})})();