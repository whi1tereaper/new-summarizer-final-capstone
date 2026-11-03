(() => {
    'use strict';
    const panel=document.getElementById('storage-maintenance');
    if(!panel)return;
    const button=panel.querySelector('#storage-refresh');
    const message=panel.querySelector('#storage-status-message');
    const number=value=>Number.isSafeInteger(value)&&value>=0?value.toLocaleString():'Unavailable';
    const bytes=value=>{
        if(!Number.isSafeInteger(value)||value<0)return 'Unavailable';
        const units=['B','KiB','MiB','GiB','TiB'];let index=0;
        while(value>=1024&&index<units.length-1){value/=1024;index++;}
        return value.toLocaleString(undefined,{minimumFractionDigits:index?2:0,maximumFractionDigits:index?2:0})+' '+units[index];
    };
    const statusLabels={ready:'Readable / writable',read_only:'Read only',missing:'Not created',unreadable:'Unavailable',limited:'Partial scan',unsafe_root:'Check configuration',linked_root:'Linked folder skipped',overlap:'Shared folder — counted once'};
    const metric=(key,value)=>{panel.querySelector('[data-storage-metric="'+key+'"]').textContent=value;};
    const stamp=panel.querySelector('#storage-checked-at');
    const displayTime=()=>{const date=new Date(stamp.dateTime);if(!Number.isNaN(date.getTime()))stamp.textContent=date.toLocaleString();};
    displayTime();
    button.addEventListener('click',async()=>{
        if(button.disabled)return;
        button.disabled=true;button.textContent='Checking…';panel.setAttribute('aria-busy','true');
        message.dataset.state='ok';message.textContent='Checking the system…';
        const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),15000);
        try{
            const response=await fetch(panel.dataset.storageStatusUrl,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
            if(response.status===403)throw new Error('Your administrator session is unavailable. Sign in again to check status.');
            if(!response.ok)throw new Error('Unable to refresh status. Previously displayed measurements have not been changed.');
            const body=await response.json();const snapshot=body.snapshot;
            if(!body.success||!snapshot||!snapshot.database||!snapshot.runtime||!snapshot.runtime.folders||!Array.isArray(snapshot.warnings)||!snapshot.checked_at)throw new Error('The system returned an incomplete status response. Previously displayed measurements have not been changed.');
            const db=snapshot.database;const runtime=snapshot.runtime;
            metric('runtime_bytes',(runtime.complete?'':'At least ')+bytes(runtime.bytes));
            metric('runtime_files',(runtime.complete?'':'At least ')+number(runtime.files)+' files counted');
            metric('allocated_bytes',bytes(db.allocated_bytes));metric('saved_summaries',number(db.saved_summaries));metric('nutshells',number(db.nutshells));
            metric('connection',db.connected?'MySQL connected':'Connection unavailable');metric('queued_jobs',number(db.queued_jobs));metric('processing_jobs',number(db.processing_jobs));
            panel.querySelectorAll('[data-storage-folder]').forEach(row=>{
                const folder=runtime.folders[row.dataset.storageFolder];const prefix=folder&&!folder.complete?'At least ':'';
                row.querySelector('[data-folder-files]').textContent=folder&&folder.readable?prefix+number(folder.files):'Unavailable';
                row.querySelector('[data-folder-bytes]').textContent=folder&&folder.readable?prefix+bytes(folder.bytes):'Unavailable';
                row.querySelector('[data-folder-status]').textContent=folder?(statusLabels[folder.status]||'Unavailable'):'Unavailable';
            });
            stamp.dateTime=snapshot.checked_at;displayTime();
            message.dataset.state=snapshot.warnings.length?'warning':'ok';message.textContent=snapshot.warnings.length?snapshot.warnings.join(' '):'Status refreshed from the system.';
        }catch(error){
            message.dataset.state='error';
            message.textContent=error.name==='AbortError'?'The status check timed out. Previously displayed measurements have not been changed.':error instanceof TypeError||error instanceof SyntaxError?'Unable to refresh status. Previously displayed measurements have not been changed.':error.message;
        }finally{clearTimeout(timeout);button.disabled=false;button.textContent='Refresh status';panel.removeAttribute('aria-busy');}
    });
})();
