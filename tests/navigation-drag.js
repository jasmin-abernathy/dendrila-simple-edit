/* DOM-like test of the real sortable handler without a browser or WordPress. */
const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
const main = fs.readFileSync(__dirname + '/../assets/admin.js', 'utf8');
const start = main.indexOf('    function initNavigationSorter() {');
const end = main.indexOf('    function initListCreator() {', start);
assert(start >= 0 && end > start, 'sorter function must be present');
class Fake {
  constructor(name, attrs={}, classes=[]) {
    this.name=name;this.attrs=attrs;this.parentNode=null;this.children=[];this.listeners={};this.dataset={};this.hidden=false;this.textContent='';this.focused=false;
    const set=new Set(classes);
    this.classList={contains:x=>set.has(x),add:x=>set.add(x),remove:x=>set.delete(x),toggle:(x,on)=>{
      if(on===undefined)on=!set.has(x);on?set.add(x):set.delete(x);
    }};
  }
  contains(node){while(node){if(node===this)return true;node=node.parentNode}return false}
  getAttribute(key){return this.attrs[key]??null}
  appendChild(c){if(c.name==='fragment'){for(const node of [...c.children])this.appendChild(node);return c}if(c.parentNode){const a=c.parentNode.children;a.splice(a.indexOf(c),1)}this.children.push(c);c.parentNode=this;return c}
  insertBefore(c,next){if(c===next)return c;if(c.name==='fragment'){for(const node of [...c.children])this.insertBefore(node,next);return c}if(c.parentNode){const a=c.parentNode.children;a.splice(a.indexOf(c),1)}const i=next?this.children.indexOf(next):-1;if(i<0)this.children.push(c);else this.children.splice(i,0,c);c.parentNode=this;return c}
  get nextElementSibling(){const a=this.parentNode?.children||[],i=a.indexOf(this);return i<0?null:a[i+1]||null}
  closest(sel){let el=this;while(el){if(sel[0]==='[' && el.attrs[sel.slice(1,-1)]!==undefined)return el;if(sel[0]==='.'&&el.classList.contains(sel.slice(1)))return el;el=el.parentNode}return null}
  querySelector(sel){
    if(sel==='[data-dendrila-simple-edit-nav-parent]')return this.parentSelect;
    if(sel==='[data-dendrila-simple-edit-nav-mobile-wrap]')return this.mobileWrap;
    if(sel==='.dendrila-simple-edit-nav-sort-label')return this.label;
    if(sel==='[data-dendrila-simple-edit-nav-status]')return this.status;
    for(const c of this.children){const f=c.querySelector(sel);if(f)return f}return null;
  }
  addEventListener(n,fn){(this.listeners[n]??=[]).push(fn)}
  dispatch(n,e){for(const fn of this.listeners[n]||[])fn(e)}
  focus(){this.focused=true}
  getBoundingClientRect(){const i=this.parentNode?.children.indexOf(this)||0;return {top:i*100,height:100,left:0,bottom:(i+1)*100}}
  setPointerCapture(id){this.capture=id}
  hasPointerCapture(id){return this.capture===id}
  releasePointerCapture(){this.capture=null}
}
const list=new Fake('ol',{'data-dendrila-simple-edit-nav-sort':''});list.classList.add('dendrila-simple-edit-nav-sort-list');
const form=new Fake('form');const status=new Fake('p',{'data-dendrila-simple-edit-nav-status':''});form.status=status;form.appendChild(list);
const ids=['11','22','33'];const rows={};
for(const id of ids){
 const r=new Fake('li',{'data-dendrila-simple-edit-nav-id':id},['dendrila-simple-edit-nav-sort-item']);
 r.parentSelect=new Fake('select',{'data-dendrila-simple-edit-nav-parent':''});r.parentSelect.value='0';
 r.parentSelect.options=[{value:'0',disabled:false},...ids.filter(x=>x!==id).map(value=>({value,disabled:false}))];r.appendChild(r.parentSelect);
 r.mobileWrap=new Fake('label',{'data-dendrila-simple-edit-nav-mobile-wrap':''});r.appendChild(r.mobileWrap);
 r.label=new Fake('span',{},['dendrila-simple-edit-nav-sort-label']);r.label.textContent=id;r.appendChild(r.label);
 r.handle=new Fake('button',{'data-dendrila-simple-edit-nav-handle':''});r.appendChild(r.handle);
 r.up=new Fake('button',{'data-dendrila-simple-edit-nav-move':'up'});r.appendChild(r.up);
 r.down=new Fake('button',{'data-dendrila-simple-edit-nav-move':'down'});r.appendChild(r.down);
 r.drop=new Fake('span',{'data-dendrila-simple-edit-nav-group-drop':''});r.appendChild(r.drop);
 rows[id]=r;list.appendChild(r);
}
const document={
 querySelectorAll:s=>s==='[data-dendrila-simple-edit-nav-sort]'?[list]:[],
 createDocumentFragment:()=>new Fake('fragment'),
 elementFromPoint:(x,y)=>{const i=Math.max(0,Math.min(list.children.length-1,Math.floor(y/100)));return x<80?list.children[i].drop:list.children[i].label}
};
const context={document,window:{innerHeight:700,scrollBy:()=>{},requestAnimationFrame:()=>1,cancelAnimationFrame:()=>{}},t:x=>x};
vm.runInNewContext(main.slice(start,end)+'\ninitNavigationSorter();',context,{filename:'admin-sorter-extract.js'});
function pointer(type,target,x,y){list.dispatch(type,{target,button:0,pointerType:'touch',pointerId:3,clientX:x,clientY:y,preventDefault(){}})}
function current(){return list.children.map(x=>x.attrs['data-dendrila-simple-edit-nav-id']).join(',')}
assert.equal(current(),'11,22,33');
// Stable pointer capture remains on list even when the dragged row moves.
pointer('pointerdown',rows['11'].handle,180,40);
assert.equal(list.hasPointerCapture(3),true,'pointer capture is on the list');
pointer('pointermove',rows['11'].handle,180,270);
pointer('pointerup',rows['11'].handle,180,270);
assert.equal(current(),'22,33,11','touch drag moves a row after last sibling');
// Explicit hit on the group drop target nests the dragged row under 22.
pointer('pointerdown',rows['33'].handle,180,150);
pointer('pointermove',rows['33'].handle,30,40);
pointer('pointerup',rows['33'].handle,30,40);
assert.equal(rows['33'].parentSelect.value,'22','drop-zone creates an actual submenu');
assert.equal(current(),'22,33,11','child stays contiguous below parent');
// Arrow button can move root groups, preserving their children.
list.dispatch('click',{target:rows['22'].down,preventDefault(){}});
assert.equal(current(),'11,22,33','fallback button moves parent with its child');
// A keyboard arrow moves that group back up.
list.dispatch('keydown',{target:rows['22'].handle,key:'ArrowUp',preventDefault(){}});
assert.equal(current(),'22,33,11','keyboard moves whole group without orphaning child');
console.log('OK: touch pointer captured on stable list, nested drop, fallback buttons, keyboard group movement');
