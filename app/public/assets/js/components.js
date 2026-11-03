/* Shared action behavior. Native disabled controls retain browser semantics. */
(() => {
  if (window.LightComponents) return;
  window.LightComponents = Object.freeze({ version: '1.1.0' });
  document.addEventListener('click', (event) => {
    const action = event.target instanceof Element ? event.target.closest('.btn, .text-action') : null;
    if (!action || !(action.matches('[aria-disabled="true"], [aria-busy="true"], .is-loading, .is-submitting'))) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
})();
