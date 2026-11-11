'use strict';
(() => {
 const form=document.getElementById('user-action-form');
 if(!form)return;
 const actions=JSON.parse(document.getElementById('user-actions-data').textContent);
 const select=form.querySelector('[name="action"]');
 const container=document.getElementById('user-action-value');
 const confirm=document.getElementById('user-action-confirm');
 function update() {
  const action=actions.find(a=>a.id===select.value);
  if(!action)return;
  container.replaceChildren();container.hidden=!action.input;
  const checkbox=confirm.querySelector('input');
  confirm.hidden=!action.danger;checkbox.disabled=!action.danger;checkbox.required=!!action.danger;checkbox.checked=false;
  if(!action.input)return;
  const label=document.createElement('label');label.htmlFor='action-value';
  label.textContent=action.kind==='staff_scope'?'Permission':action.kind.startsWith('inventory_')?'Asset ID':action.group==='Economy'?'Dius':'New value';
  const rule=action.input;let input;
  if(rule.startsWith('in:')){
   input=document.createElement('select');
   for(const value of rule.slice(3).split(',')){const option=document.createElement('option');option.value=value;option.textContent=value;input.append(option);}
  }else{
   input=document.createElement((rule.includes('max:2000')||rule.includes('max:4000'))?'textarea':'input');
   if(input.tagName==='INPUT')input.type=rule.includes('integer')?'number':rule.startsWith('email')?'email':'text';
   const min=rule.match(/(?:^|\|)min:(\d+)/),max=rule.match(/(?:^|\|)max:(\d+)/);
   if(rule.includes('integer')){input.step='1';if(min)input.min=min[1];if(max)input.max=max[1];}else if(max)input.maxLength=Number(max[1]);
  }
  input.id='action-value';input.name='value';input.className='form-control mb-3';input.required=true;
  container.append(label,input);
 }
 select.addEventListener('change',update);
 form.addEventListener('submit',()=>{form.querySelector('[type="submit"]').disabled=true;});
})();
