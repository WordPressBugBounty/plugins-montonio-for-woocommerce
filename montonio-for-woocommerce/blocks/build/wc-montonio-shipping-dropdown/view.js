(()=>{"use strict";const e=window.React,o=JSON.parse('{"$schema":"https://schemas.wp.org/trunk/block.json","apiVersion":3,"name":"montonio-for-woocommerce/wc-montonio-shipping-dropdown","version":"0.1.0","title":"Montonio Shipping Pickup Point Dropdown Block","category":"woocommerce","description":"Adds a Montonio Shipping Pickup Point Dropdown to the checkout page.","supports":{"html":false,"align":false,"multiple":false,"reusable":false},"parent":["woocommerce/checkout-shipping-methods-block"],"attributes":{"lock":{"type":"object","default":{"remove":true,"move":true}}},"textdomain":"montonio-for-woocommerce","editorScript":"file:./index.js","viewScript":"file:./view.js"}'),t=window.wp.element,n=window.wp.i18n,i=window.wc.blocksCheckout,c=window.wp.data,a=window.lodash,{getSetting:r}=wc.wcSettings,s=r("wc-montonio-shipping-dropdown_data",{}),p={metadata:o,component:({checkoutExtensionData:o})=>{const[i,r]=(0,t.useState)(""),[p,l]=(0,t.useState)([]),[d,u]=(0,t.useState)(!1),[m,w]=(0,t.useState)(!1),[h,g]=(0,t.useState)(!1),{setExtensionData:k}=o,f=(0,t.useRef)(null),b=(0,t.useRef)(null),S="montonio-pickup-point",{setValidationErrors:E,clearValidationError:v}=(0,c.useDispatch)("wc/store/validation"),y=(0,c.useSelect)((e=>e("wc/store/validation").getValidationError(S))),{selectedShippingRate:_,shippingAddress:C}=(0,c.useSelect)((e=>{const o=e("wc/store/cart").getCartData();return{selectedShippingRate:o.shippingRates?.[0]?.shipping_rates?.find((e=>e.selected))?.rate_id||null,shippingAddress:o.shippingAddress||{}}})),D=(0,t.useCallback)((0,a.debounce)(((e,o,t)=>{k(e,o,t)}),1e3),[k]),j=(0,t.useCallback)((e=>{const o=e.target.value;r(o),D("montonio-for-woocommerce","selected_pickup_point",o),g(!0)}),[D]),M=(0,t.useCallback)((async(e,o)=>{f.current&&f.current.abort();const t=new AbortController;f.current=t,u(!0);try{const n=await fetch(`${s.getShippingMethodItemsUrl}?shipping_method=${e}&country=${o}`,{headers:{"Content-Type":"application/json","X-WP-Nonce":s.nonce},signal:t.signal});if(!n.ok)throw new Error("Network response was not ok");return await n.json()}catch(e){return"AbortError"===e.name?console.log("Fetch aborted"):console.error("Error fetching pickup points:",e),{}}finally{u(!1),f.current=null}}),[]),N=(0,t.useCallback)((e=>Object.entries(e).map((([e,o])=>({label:e,options:o.map((o=>({value:o.id,label:`${o.name}${"yes"===s.includeAddress&&o.address?.trim()?` - ${o.address}, ${e}`:""}`})))})))),[]),$=(0,t.useCallback)((0,a.debounce)((async(e,o,t)=>{const n=await M(o,t),i=N(n);l(i),r(""),"undefined"!=typeof Montonio&&Montonio.Checkout&&Montonio.Checkout.ShippingDropdown&&(window.montonioShippingDropdown&&window.montonioShippingDropdown.destroy(),window.montonioShippingDropdown=new Montonio.Checkout.ShippingDropdown({shippingMethod:e,accessKey:s.accessKey,targetId:"montonio-shipping-pickup-point-dropdown",shouldInjectCSS:!0,onLoaded:function(){const e=document.getElementById("montonio-shipping-pickup-point-dropdown").value;e&&j({target:{value:e}})}}),window.montonioShippingDropdown.init()),g(!1),u(!1)}),400),[M,N,j]),A=(0,t.useCallback)(((e,o)=>{const[t]=(e||":").split(":"),n=t.startsWith("montonio_")&&(t.endsWith("parcel_machines")||t.endsWith("post_offices"));w(n),n?(u(!0),$(e,t,o)):(l([]),r(""))}),[$]);(0,t.useEffect)((()=>{_!==b.current&&(A(_,C.country),b.current=_)}),[_,C.country,A]),(0,t.useEffect)((()=>{m&&""===i?E({[S]:{message:(0,n.__)("Please select a pickup point","montonio-for-woocommerce"),hidden:!h}}):y&&v(S)}),[m,i,E,v,S,h,y]);const P=(0,t.useCallback)((o=>[(0,e.createElement)("option",{key:"default",value:""},(0,n.__)("Select a pickup point","montonio-for-woocommerce")),...o.map(((o,t)=>(0,e.createElement)("optgroup",{key:t,label:o.label},o.options.map((o=>(0,e.createElement)("option",{key:o.value,value:o.value},o.label))))))]),[]);return(0,e.createElement)("div",{id:"montonio-shipping-pickup-point-dropdown-wrapper"},m&&(0,e.createElement)(e.Fragment,null,(0,e.createElement)("h2",{className:"wc-block-components-title wc-block-components-checkout-step__title"},(0,n.__)("Pickup point","montonio-for-woocommerce")),d?(0,e.createElement)("p",null,(0,n.__)("Loading pickup points...","montonio-for-woocommerce")):(0,e.createElement)("div",{className:!1===y?.hidden?"has-error":""},(0,e.createElement)("input",{type:"text",className:"montonio-pickup-point-id",name:"montonio-pickup-point-id",value:i}),(0,e.createElement)("select",{id:"montonio-shipping-pickup-point-dropdown",onChange:j,value:i,className:!1===y?.hidden?"has-error":""},P(p)),!1===y?.hidden&&(0,e.createElement)("div",{className:"wc-block-components-validation-error"},(0,e.createElement)("p",null,y.message)))))}};(0,i.registerCheckoutBlock)(p)})();