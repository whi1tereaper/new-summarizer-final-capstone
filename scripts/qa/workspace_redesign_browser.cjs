const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/Robert Martin/AppData/Roaming/npm/node_modules/omniroute/node_modules/playwright');
const base = 'http://127.0.0.1:58142/';
const report = process.env.QA_WORKSPACE_REPORT;
const phase = process.env.QA_WORKSPACE_PHASE || 'before';
const privateDir = process.env.QA_WORKSPACE_PRIVATE;
const checks = [];
const record = name => { checks.push({name, passed:true}); console.log('PASS ' + name); };
const fail = (name,error) => { checks.push({name,passed:false,detail:error.message}); console.error('FAIL '+name+': '+error.message); };
(async () => {
  let browser;
  try {
    browser = await chromium.launch({headless:true, executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    const context = await browser.newContext({viewport:{width:1440,height:1000}});
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(base + 'summarizer.php', {waitUntil:'domcontentloaded'});
    assert.equal(response.status(), 200);
    await page.evaluate(() => document.fonts.ready);
    if(phase === 'browser') {
      assert.equal(await page.locator('h1').count(),1);
      assert.equal((await page.locator('h1').textContent()).trim(),'Summarize, without the clutter.');
      assert(await page.locator('.summary-submit-button').isDisabled());
      assert(await page.locator('#btn-nutshell-action').isDisabled());
      record('single page heading and both generation actions disabled without a source');
      assert.equal(await page.locator('a[href="admin_dashboard.php"],a[href="analytics.php"],a[href="admin_audit_logs.php"]').count(),0);
      record('guest navigation hides administrative destinations');
      assert(await page.locator('#student-trial-counter').textContent().then(text=>text.includes('3 free summaries')));
      record('real guest access indicator shows shared three-use allowance');
      const deniedCsrf=await context.request.post(base+'summarize.php',{headers:{Accept:'application/json'},form:{csrf_token:'invalid',source_type:'text',original_text:'This request must be rejected before generation.'}});
      assert.equal(deniedCsrf.status(),403);assert.equal((await deniedCsrf.json()).success,false);record('real backend still rejects invalid CSRF tokens before generation');
      for(const theme of ['light','dark']) {
        await page.evaluate(theme=>{localStorage.setItem('light-results-theme',theme);document.documentElement.dataset.theme=theme;document.body.dataset.theme=theme;},theme);
        for(const width of [360,390,768,1024,1280,1440]) {
          await page.setViewportSize({width,height:1000});
          const layout=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,columns:getComputedStyle(document.querySelector('.summary-grid')).gridTemplateColumns.split(' ').length,clipped:[...document.querySelectorAll('#summarize-form input:not([type="hidden"]),#summarize-form select,#summarize-form button')].filter(el=>el.getClientRects().length).some(el=>{const r=el.getBoundingClientRect();return r.left<0||r.right>innerWidth+1;})}));
          assert(!layout.overflow&&!layout.clipped,JSON.stringify({theme,width,...layout}));
          assert.equal(layout.columns,width>768?2:1,JSON.stringify({theme,width,...layout}));
          record(theme+' '+width+'px: expected columns, no viewport overflow or clipped controls');
        }
      }
      await page.setViewportSize({width:1440,height:1000});
      await page.evaluate(()=>{localStorage.setItem('light-results-theme','light');document.documentElement.dataset.theme='light';document.body.dataset.theme='light';});
      const beforeTheme=await page.locator('html').getAttribute('data-theme');
      await page.locator('[data-theme-toggle]').click();assert.notEqual(await page.locator('html').getAttribute('data-theme'),beforeTheme);
      await page.reload({waitUntil:'domcontentloaded'});assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
      await page.locator('[data-theme-toggle]').click();record('real theme button switches and persists across reload');
      await page.locator('[data-preferences-toggle]').focus();await page.keyboard.press('Enter');assert.equal(await page.evaluate(()=>document.activeElement.id),'summary-preferences');
      assert(await page.locator('#summary-preferences').evaluate(el=>getComputedStyle(el).outlineStyle!=='none'));
      record('Preferences focuses the one existing configuration panel with visible keyboard outline');
      const labelProblems=await page.evaluate(()=>[...document.querySelectorAll('#summarize-form input:not([type="hidden"]):not([type="submit"]),#summarize-form textarea,#summarize-form select')].filter(el=>!el.labels?.length&&!el.getAttribute('aria-label')&&!el.getAttribute('aria-labelledby')).map(el=>el.id));
      assert.deepEqual(labelProblems,[]);assert(await page.locator('#submit-status[role="status"][aria-live="polite"]').count());
      assert(await page.locator('#source-error[role="alert"]').count());record('form controls have labels and status/errors have live-region semantics');
      const source=fs.readFileSync(path.join(privateDir,'source.txt'),'utf8').replace(/\r\n/g,'\n');
      await page.locator('[data-source-choice="text"]').click();await page.locator('#text').fill(source);
      assert(await page.locator('.summary-submit-button').isEnabled());assert((await page.locator('#text-count').textContent()).includes('words'));
      const editor=await page.locator('#text').evaluate(el=>({height:el.clientHeight,scroll:el.scrollHeight}));assert(editor.height>=220);record('pasted text enables actions, updates live count and expands editor');
      await page.locator('#text').fill('x'.repeat(200001));assert(await page.locator('.summary-submit-button').isDisabled());assert(await page.locator('#source-error').isVisible());
      assert(await page.locator('#text').evaluate(el=>el.clientHeight<=422));await page.locator('#text').fill(source);record('existing UTF-8 text size limit rejects excessive input while editor growth remains bounded');
      await page.locator('#document_title').fill('QA workspace title');
      await page.locator('[data-source-choice="url"]').click();await page.locator('#source_url').fill('https://www.nasa.gov/example');
      await page.locator('[data-source-choice="file"]').click();
      assert(await page.locator('.summary-submit-button').isDisabled());
      await page.locator('[data-source-choice="text"]').click();assert.equal(await page.locator('#text').inputValue(),source);
      await page.locator('[data-source-choice="url"]').click();assert.equal(await page.locator('#source_url').inputValue(),'https://www.nasa.gov/example');
      assert.equal(await page.locator('#document_title').inputValue(),'QA workspace title');
      assert.deepEqual(await page.locator('#summarize-form').evaluate(el=>{const f=new FormData(el);return['pdf_file','original_text','source_url'].filter(k=>f.has(k));}),['source_url']);
      record('source switching retains text, URL and title while submitting only the active source');
      await page.locator('#source_url').fill('javascript:alert(1)');await page.locator('#document_title').focus();
      assert(await page.locator('.summary-submit-button').isDisabled());assert(await page.locator('#source-error').isVisible());record('invalid URL receives inline feedback and disables generation');
      for(const [id,expected] of [['output_format',['paragraph','bullets','hybrid','structured','key_takeaways']],['analysis_mode',['general','academic','executive','technical','study','news','legal','scientific']]]) {
        assert.deepEqual(await page.locator('#'+id+' option').evaluateAll(nodes=>nodes.map(n=>n.value)),expected);
        for(const value of expected){await page.locator('#'+id).selectOption(value);assert.equal(await page.locator('#summarize-form').evaluate((el,id)=>new FormData(el).get(id),id),value);}
        record('all '+expected.length+' '+id+' options preserve exact backend field values');
      }
      for(const depth of ['very_brief','brief','short','balanced','detailed','comprehensive']) {
        await page.locator('[data-depth="'+depth+'"]').click();assert.equal(await page.locator('#summary_depth').inputValue(),depth);assert.equal(await page.locator('#summary_length').inputValue(),depth);assert.equal(await page.locator('[data-depth][aria-pressed="true"]').count(),1);assert((await page.locator('#depth_description').textContent()).trim());
      }record('all six depth buttons update both backend fields, one selected state and contextual description');
      await page.locator('[data-source-choice="file"]').click();
      await page.locator('#pdf').setInputFiles({name:'unsupported.exe',mimeType:'application/octet-stream',buffer:Buffer.from('bad')});
      assert(await page.locator('.summary-submit-button').isDisabled());assert(await page.locator('#source-error').isVisible());record('unsupported upload rejected near input before submission');
      await page.locator('#pdf').evaluate(input=>{const limit=JSON.parse(input.form.dataset.capabilities).maxUploadBytes;const dt=new DataTransfer();dt.items.add(new File([new Uint8Array(limit+1)],'oversized.pdf',{type:'application/pdf'}));input.files=dt.files;input.dispatchEvent(new Event('change',{bubbles:true}));});
      assert(await page.locator('.summary-submit-button').isDisabled());assert(await page.locator('#source-error').isVisible());record('upload above effective PHP size limit rejected before submission');
      await page.locator('#pdf').setInputFiles(path.join(privateDir,'source.pdf'));assert(await page.locator('.summary-submit-button').isEnabled());
      assert((await page.locator('.nex-drop-zone__file-meta').textContent()).includes('PDF'));assert(await page.locator('.nex-drop-zone__remove').isVisible());
      await page.locator('.nex-drop-zone__remove').click();assert(await page.locator('.summary-submit-button').isDisabled());record('PDF selection shows extension and size; removal clears source and disables actions');
      await page.locator('#pdf').setInputFiles(path.join(privateDir,'source.docx'));assert((await page.locator('.nex-drop-zone__file-meta').textContent()).includes('DOCX'));record('DOCX file-ready state retains actual file binding');
      await page.locator('.nex-drop-zone__remove').click();
      await page.locator('#drop-zone').evaluate(el=>{const dt=new DataTransfer();dt.items.add(new File(['invalid'],'dragged.exe'));el.dispatchEvent(new DragEvent('dragenter',{bubbles:true,dataTransfer:dt}));});
      assert(await page.locator('#drop-zone').evaluate(el=>el.classList.contains('is-drag-over')));
      await page.locator('#drop-zone').evaluate(el=>el.dispatchEvent(new DragEvent('dragleave',{bubbles:true})));assert(!await page.locator('#drop-zone').evaluate(el=>el.classList.contains('is-drag-over')));record('drag enter/leave resets upload feedback');
      await page.setViewportSize({width:390,height:1000});await page.locator('#nex-menu-toggle').click();assert.equal(await page.locator('#nex-menu-toggle').getAttribute('aria-expanded'),'true');
      await page.keyboard.press('Escape');assert.equal(await page.locator('#nex-menu-toggle').getAttribute('aria-expanded'),'false');assert.equal(await page.evaluate(()=>document.activeElement.id),'nex-menu-toggle');record('mobile menu opens, closes by Escape and restores keyboard focus');
      await page.setViewportSize({width:1440,height:1000});
      await page.locator('[data-source-choice="url"]').click();await page.locator('#source_url').fill('http://127.0.0.1/private');
      await page.locator('.summary-submit-button').click();await page.locator('#source-error').waitFor({state:'visible'});
      assert.equal(await page.locator('#source_url').inputValue(),'http://127.0.0.1/private');assert.equal(await page.locator('#document_title').inputValue(),'QA workspace title');
      assert(await page.locator('.summary-submit-button').isEnabled());record('real backend rejects local-network URL safely and retains inputs for recovery');
      const sessions=JSON.parse(fs.readFileSync(path.join(privateDir,'sessions.json'),'utf8'));
      for(const role of ['admin','user']) {
        const roleContext=await browser.newContext({viewport:{width:1440,height:1000}});await roleContext.addCookies([{name:'LIGHT_QA_SESSID',value:sessions[role],url:base}]);
        const rolePage=await roleContext.newPage();await rolePage.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
        const adminLinks=await rolePage.locator('a[href="admin_dashboard.php"],a[href="analytics.php"],a[href="admin_audit_logs.php"]').count();assert.equal(adminLinks,role==='admin'?3:0);
        await rolePage.locator('.js-logout-link').click();assert(await rolePage.getByRole('dialog',{name:'Log out?'}).isVisible());assert.equal(await rolePage.locator('.confirmation-modal').evaluate(el=>getComputedStyle(el).position),'fixed');await rolePage.keyboard.press('Escape');assert(!await rolePage.getByRole('dialog',{name:'Log out?'}).isVisible());await roleContext.close();
      }record('real QA account sessions preserve administrator-only navigation');
      record('existing logout confirmation remains a modal and cancels with Escape');
      await page.locator('#source_url').fill('');await page.locator('[data-source-choice="file"]').click();await page.locator('#document_title').fill('');
      await page.locator('#analysis_mode').selectOption('general');await page.locator('#output_format').selectOption('paragraph');await page.locator('[data-depth="balanced"]').click();
    }
    for (const [label,width,theme] of [['desktop',1440,'light'],['tablet',768,'light'],['mobile',390,'light'],['dark',1440,'dark']]) {
      await page.setViewportSize({width,height:1000});
      await page.evaluate(theme => { document.documentElement.dataset.theme=theme; document.body.dataset.theme=theme; localStorage.setItem('light-results-theme',theme); },theme);
      await page.screenshot({path:path.join(report,(phase==='before'?'before':'after')+'-'+label+'.png'),fullPage:true,animations:'disabled'});
      record(phase+' '+label+' screenshot');
    }
    if(phase==='browser') {
      const source=fs.readFileSync(path.join(privateDir,'source.txt'),'utf8').replace(/\r\n/g,'\n');
      for(const kind of ['text','pdf','docx','url','nutshell']) {
        let runContext;
        try {
          runContext=await browser.newContext({viewport:{width:1280,height:1000}});const run=await runContext.newPage();await run.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
          if(['pdf','docx'].includes(kind))await run.locator('#pdf').setInputFiles(path.join(privateDir,'source.'+kind));
          else if(kind==='url'){await run.locator('[data-source-choice="url"]').click();await run.locator('#source_url').fill('https://www.nasa.gov/news-release/nasa-isro-satellite-lifts-off-to-track-earths-changing-surfaces/');}
          else{await run.locator('[data-source-choice="text"]').click();await run.locator('#text').fill(source);}
          await run.locator('[data-depth="brief"]').click();
          if(kind==='text'){
            await run.locator('#document_title').fill('QA redesigned workspace real text');
            const focus=run.locator('#focus_query');if(await focus.count()){await run.locator('details').filter({has:focus}).evaluate(el=>el.open=true);await focus.fill('staffing costs and six month pilot');}
          }
          let submissions=0;run.on('request',req=>{if(req.method()==='POST'&&new URL(req.url()).pathname.endsWith('/summarize.php')&&!new URL(req.url()).search)submissions++;});
          if(kind==='nutshell'){
            const pending=run.waitForResponse(r=>r.url().endsWith('nutshell_generate.php')&&r.request().method()==='POST',{timeout:150000});
            await run.locator('#btn-nutshell-action').click();assert(await run.locator('.summary-submit-button').isDisabled());assert(await run.locator('#btn-nutshell-action').isDisabled());
            const response=await pending;const payload=await response.json();assert(payload.success&&payload.nutshell,JSON.stringify(payload));await run.locator('#nutshell-text').waitFor({state:'visible'});assert((await run.locator('#nutshell-text').textContent()).trim());assert(await run.locator('#nutshell-copy-btn').isVisible());
            await run.waitForFunction(()=>document.querySelector('#student-trial-counter').textContent.includes('2 free summaries'));await run.screenshot({path:path.join(report,'real-nutshell.png'),fullPage:true});
            record('real Python NLP Nutshell succeeds, stays inline, enables copy and charges one shared use');
          }else{
            const accepted=run.waitForResponse(r=>r.url().endsWith('/summarize.php')&&r.request().method()==='POST',{timeout:45000});
            await run.evaluate(()=>{const form=document.getElementById('summarize-form');form.requestSubmit();form.requestSubmit();});
            const response=await accepted;assert.equal(response.status(),200);
            await run.waitForURL(/result\.php\?id=/,{timeout:180000});assert.equal(submissions,1);
            assert((await run.locator('body').textContent()).length>100);if(kind==='text')assert((await run.locator('body').textContent()).includes('QA redesigned workspace real text'));
            await run.screenshot({path:path.join(report,'real-'+kind+'-result.png'),fullPage:true});record('real '+kind+' source completes existing processing/result flow with one submission');
          }
        }catch(error){fail('real '+kind+' workflow',error);if(runContext){const p=runContext.pages()[0];if(p)await p.screenshot({path:path.join(report,'failed-'+kind+'.png'),fullPage:true}).catch(()=>{});}}
        finally{if(runContext)await runContext.close();}
      }
    }
    if(['browser','supplementary'].includes(phase)) {
      const sessions=JSON.parse(fs.readFileSync(path.join(privateDir,'sessions.json'),'utf8'));
      const admin=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});await admin.addCookies([{name:'LIGHT_QA_SESSID',value:sessions.admin,url:base}]);
      const adminPage=await admin.newPage();await adminPage.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});await adminPage.evaluate(()=>document.fonts.ready);
      await adminPage.screenshot({path:path.join(report,'after-admin-desktop.png'),fullPage:true,animations:'disabled'});record('administrator screenshot includes complete authorized navigation');await admin.close();
      const draft=await browser.newContext({viewport:{width:1024,height:1000}});const draftPage=await draft.newPage();await draftPage.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
      await draftPage.evaluate(()=>window.LightErrors.draft.write({fields:{source_type:'text',original_text:'A retained document draft about staffing costs.',focus_query:'staffing costs',summary_depth:'brief',summary_length:'short',sentence_count:'5'}}));
      await draftPage.goto(base+'summarizer.php?summary_depth=comprehensive',{waitUntil:'domcontentloaded'});
      assert(await draftPage.locator('.workspace-more-preferences').evaluate(el=>el.open));assert.equal(await draftPage.locator('#focus_query').inputValue(),'staffing costs');
      assert.equal(await draftPage.locator('#summary_depth').inputValue(),'comprehensive');assert.equal(await draftPage.locator('#summary_length').inputValue(),'comprehensive');assert.equal(await draftPage.locator('#sentence_count').inputValue(),'15');record('restored Focus expands and dashboard depth override normalizes both legacy depth fields and sentence count');
      await draftPage.locator('[data-source-choice="file"]').click();await draftPage.locator('#pdf').setInputFiles({name:'bad.exe',mimeType:'application/octet-stream',buffer:Buffer.from('bad')});await draftPage.locator('#analysis_mode').selectOption('executive');await draftPage.locator('[data-depth="short"]').click();
      assert(await draftPage.locator('#source-error').isVisible());assert.equal(await draftPage.locator('#pdf').getAttribute('aria-invalid'),'true');await draftPage.locator('.nex-drop-zone__remove').click();assert(!await draftPage.locator('#source-error').isVisible());assert.equal(await draftPage.locator('#pdf').getAttribute('aria-invalid'),null);record('invalid file feedback survives preference changes and removal clears its error state');
      await draft.close();
      const trial=await browser.newContext({viewport:{width:390,height:1000},reducedMotion:'reduce'});const trialPage=await trial.newPage();await trialPage.goto(base+'summarizer.php',{waitUntil:'domcontentloaded'});
      await trialPage.locator('[data-source-choice="text"]').click();await trialPage.locator('#text').fill(fs.readFileSync(path.join(privateDir,'source.txt'),'utf8'));
      for(const remaining of [2,1,0]) {
        const generated=trialPage.waitForResponse(response=>response.url().endsWith('nutshell_generate.php')&&response.request().method()==='POST',{timeout:150000});await trialPage.locator('#btn-nutshell-action').click();const response=await generated;const payload=await response.json();assert(payload.success&&payload.nutshell,JSON.stringify(payload));
        await trialPage.waitForFunction(remaining=>document.getElementById('student-trial-counter').textContent.includes(remaining+' free '),remaining);
        record('real guest Nutshell generation updates shared remaining allowance to '+remaining);
      }
      assert(await trialPage.locator('#student-trial-exhausted').isVisible());assert(await trialPage.locator('#nutshell-text').isVisible());assert(await trialPage.locator('#nutshell-copy-btn').isVisible());assert(!await trialPage.locator('.summary-submit-button').isVisible());assert(!await trialPage.locator('#btn-nutshell-action').isVisible());
      assert(await trialPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await trialPage.screenshot({path:path.join(report,'real-mobile-trial-exhausted.png'),fullPage:true,animations:'disabled'});record('real three-use exhaustion hides generation controls, keeps result copyable and fits mobile');await trial.close();
    }
    assert.deepEqual(errors,[]);
    record('no browser script exceptions');
    if(checks.some(check=>!check.passed))process.exitCode=1;
  } catch(error) {
    checks.push({name:error.message,passed:false}); console.error(error.stack); process.exitCode=1;
  } finally {
    fs.writeFileSync(path.join(report,phase+'.json'),JSON.stringify(checks,null,2));
    if(browser) await browser.close();
  }
})();
