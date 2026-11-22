const assert=require('node:assert/strict'),vm=require('vm'),fs=require('fs');
function node(classNames,parent=null){
 const classes=new Set(classNames.split(' '));
 return {parentNode:parent, attrs:{}, classList:{contains:x=>classes.has(x),toggle:(x,val)=>{val?classes.add(x):classes.delete(x)}},
  closest(sel){if(sel.indexOf('.dendrila-simple-edit-mobile-open')!==-1)return parent?.classList.contains('dendrila-simple-edit-mobile-closed')?parent:null;if(sel==='.wp-block-navigation__responsive-container.is-menu-open')return parent?.classList.contains('is-menu-open')?parent:null;return null},
  querySelector(sel){return sel.indexOf('submenu__toggle')!==-1?this.toggle:null},
  setAttribute(k,v){this.attrs[k]=v}};
}
const overlay=node('wp-block-navigation__responsive-container is-menu-open');
const controlled=node('dendrila-simple-edit-mobile-closed',overlay);
controlled.toggle=node('wp-block-navigation-submenu__toggle',controlled);
const ordinary=node('ordinary-parent',overlay);ordinary.toggle=node('wp-block-navigation-submenu__toggle',ordinary);
const handlers={};
const doc={readyState:'complete',querySelectorAll:s=>s.indexOf('dendrila-simple-edit-mobile-open')!==-1?[controlled]:s==='.wp-block-navigation__responsive-container'?[overlay]:[],
 addEventListener:(kind,fn)=>{handlers[kind]=fn}};
overlay.querySelectorAll=()=>[controlled];
class Observer{observe(){}}
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/navigation-mobile.js','utf8'),{
 document:doc,window:{matchMedia:()=>({matches:true})},MutationObserver:Observer
});
assert(controlled.classList.contains('dendrila-simple-edit-submenu-collapsed'),'closed by default');
assert.equal(controlled.toggle.attrs['aria-expanded'],'false');
let prevented=0,stopped=0;
handlers.click({target:{closest:s=>s.indexOf('submenu__toggle')!==-1?controlled.toggle:null},preventDefault(){prevented++},stopImmediatePropagation(){stopped++}});
assert(controlled.classList.contains('dendrila-simple-edit-submenu-expanded'),'user can open submenu');
assert.equal(controlled.toggle.attrs['aria-expanded'],'true');
assert.equal(prevented,1);assert.equal(stopped,1);
handlers.click({target:{closest:s=>s.indexOf('submenu__toggle')!==-1?ordinary.toggle:null},preventDefault(){prevented++},stopImmediatePropagation(){stopped++}});
assert.equal(prevented,1,'other navigation untouched');
console.log('OK: mobile state defaults, toggle, aria-expanded and unrelated submenus ignored');
