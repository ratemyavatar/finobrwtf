'use strict';
(() => {
 document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(!confirm(form.dataset.confirm))e.preventDefault();}));
 const title=document.getElementById('post-title'),slug=document.getElementById('post-slug');
 let slugEdited=!!slug?.value;slug?.addEventListener('input',()=>slugEdited=true);
 title?.addEventListener('input',()=>{if(!slugEdited)slug.value=title.value.normalize('NFKD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,180);});
 const form=document.getElementById('blog-image-upload');
 form?.addEventListener('submit',async e=>{
  e.preventDefault();const status=document.getElementById('upload-status'),button=form.querySelector('button');button.disabled=true;status.textContent='Uploading…';
  try{const response=await fetch('/admin/blog/images',{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}});const result=await response.json();if(!response.ok)throw new Error(result.errors?Object.values(result.errors).flat().join(' '):result.message||'Upload failed.');
   const url=document.getElementById('uploaded-image-url');url.value=result.url;url.hidden=false;document.getElementById('image-insert-actions').hidden=false;status.textContent='Image uploaded.';
  }catch(error){status.textContent=error.message;}finally{button.disabled=false;}
 });
 document.getElementById('use-feature-image')?.addEventListener('click',()=>{document.getElementById('feature-image').value=document.getElementById('uploaded-image-url').value;});
 document.getElementById('insert-body-image')?.addEventListener('click',()=>{const body=document.getElementById('post-markdown'),url=document.getElementById('uploaded-image-url').value;const text=`\n![Image description](${url})\n`;body.setRangeText(text,body.selectionStart,body.selectionEnd,'end');body.focus();});
})();
