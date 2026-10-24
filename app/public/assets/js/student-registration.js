(() => {
    'use strict';
    const form=document.getElementById('register-form');
    if(!form)return;
    const student=document.getElementById('student_number');
    const email=document.getElementById('email');
    const password=document.getElementById('password');
    const confirmation=document.getElementById('confirm_password');
    const button=document.getElementById('btn-submit-register');
    const progress=document.getElementById('registration-progress');
    const byteLength=value=>new TextEncoder().encode(value).length;
    const suffix=()=>student.value.trim().replace(/^PEN/i,'').toUpperCase();
    student.addEventListener('input',()=>{student.value=suffix();});
    // Serialize the complete ID without changing the visible suffix/pattern.
    // PHP independently rebuilds/validates PEN when JavaScript is unavailable.
    form.addEventListener('formdata',event=>{event.formData.set('student_number','PEN'+suffix());});
    [['toggle-password',password],['toggle-confirm',confirmation]].forEach(([id,input])=>{
        const toggle=document.getElementById(id);
        toggle.addEventListener('click',()=>{
            const visible=input.type==='password';input.type=visible?'text':'password';
            toggle.classList.toggle('pw-visible',visible);toggle.setAttribute('aria-pressed',String(visible));
            toggle.setAttribute('aria-label',visible?'Hide password':'Show password');
        });
    });
    const strength=document.getElementById('pw-strength-container');
    const fill=document.getElementById('pw-strength-fill');
    const label=document.getElementById('pw-strength-label');
    password.addEventListener('input',()=>{
        const value=password.value;strength.classList.toggle('is-active',value.length>0);
        const score=Number(/[0-9]/.test(value))+Number(/[A-Z]/.test(value))+Number(/[^a-zA-Z0-9]/.test(value));
        const state=byteLength(value)<8||score<2?'weak':score===2?'medium':'strong';
        fill.className='pw-strength__fill pw-strength__fill--'+state;
        label.textContent=state[0].toUpperCase()+state.slice(1);
    });
    const setError=(field,message)=>{
        const input=document.getElementById(field);const error=document.getElementById(field+'-error');
        input.setAttribute('aria-invalid',message?'true':'false');error.textContent=message;error.hidden=!message;
    };
    const fields=['student_number','username','email','password','confirm_password','terms_accept'];
    fields.forEach(field=>document.getElementById(field).addEventListener('input',()=>setError(field,'')));
    form.addEventListener('submit',event=>{
        if(form.dataset.submitting==='1'||form.dataset.registrationUnavailable==='1'){event.preventDefault();return;}
        fields.forEach(field=>setError(field,''));const errors={};
        student.value=suffix();
        if(!/^[0-9]{4}-[0-9]+$/.test(student.value)||student.value.length>37)errors.student_number='Enter the year and number after PEN, preserving leading zeros.';
        const username=document.getElementById('username').value.trim();
        if(username&&!/^[a-zA-Z0-9_]{3,30}$/.test(username))errors.username='Use 3–30 letters, numbers or underscores, or leave this optional field blank.';
        email.value=email.value.trim();
        if(!email.value)errors.email='Email address is required.';
        else if(email.validity.typeMismatch||byteLength(email.value)>100)errors.email='Enter a valid email address of no more than 100 bytes.';
        const size=byteLength(password.value);
        if(size<8||size>72||!/[0-9]/.test(password.value)||(!/[A-Z]/.test(password.value)&&!/[^a-zA-Z0-9]/.test(password.value)))errors.password='Use 8–72 bytes, including a number and an uppercase letter or special character.';
        if(confirmation.value!==password.value||!confirmation.value)errors.confirm_password='Your passwords do not match. Please try again.';
        if(!document.getElementById('terms_accept').checked)errors.terms_accept='Accept the Terms and Conditions before registering.';
        if(Object.keys(errors).length){event.preventDefault();Object.entries(errors).forEach(([field,message])=>setError(field,message));document.getElementById(Object.keys(errors)[0]).focus();return;}
        form.dataset.submitting='1';form.setAttribute('aria-busy','true');button.disabled=true;button.classList.add('is-submitting');
        button.querySelector('.btn-auth__text').textContent='Creating account…';progress.textContent='Creating your account securely. Please wait.';
    });
    window.addEventListener('pageshow',()=>{
        delete form.dataset.submitting;form.removeAttribute('aria-busy');button.disabled=form.dataset.registrationUnavailable==='1';
        button.classList.remove('is-submitting');button.querySelector('.btn-auth__text').textContent='Create Account';progress.textContent='';
    });
    const invalid=form.querySelector('[aria-invalid="true"]');if(invalid)invalid.focus();
})();
