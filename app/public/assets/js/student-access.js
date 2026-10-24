(() => {
 'use strict';
 const panel=document.getElementById('student-trial');
 if(!panel)return;
 const form=document.getElementById('summarize-form');
 let blocked=panel.dataset.blocked==='1';
 const reflect=()=>{if(form){form.hidden=blocked;form.setAttribute('aria-disabled',String(blocked));}};
 reflect();
 const refresh=async()=>{
  try{
   const response=await fetch('access_status.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
   if(!response.ok)return;
   const status=await response.json();
   blocked=!status.continued&&status.remaining===0;
   document.getElementById('student-trial-counter').textContent=status.continued?'Continued account access · fair-use controls apply':`${status.remaining} free ${status.remaining===1?'summary':'summaries'} remaining`;
   document.getElementById('student-trial-exhausted').hidden=!blocked;reflect();
  }catch(_){/* Backend checks remain authoritative if the network is unavailable. */}
 };
 if(form)form.addEventListener('submit',event=>{if(blocked){event.preventDefault();event.stopImmediatePropagation();}},true);
 window.addEventListener('focus',refresh);window.addEventListener('pageshow',refresh);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
