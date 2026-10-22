document.addEventListener('DOMContentLoaded', () => {
    const logoutLinks = document.querySelectorAll('.js-logout-link, a[href*="auth.php?action=logout"]');
    if (logoutLinks.length === 0) return;

    const overlay = document.createElement('div');
    overlay.className = 'confirmation-modal';
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <div class="confirmation-modal__backdrop"></div>
        <div class="confirmation-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title" aria-describedby="logout-modal-message">
            <p class="confirmation-modal__eyebrow">Session Confirmation</p>
            <h2 id="logout-modal-title" class="confirmation-modal__title">Log out?</h2>
            <p id="logout-modal-message" class="confirmation-modal__message">
                Are you sure you want to log out of your current session?
            </p>
            <div class="confirmation-modal__actions">
                <button type="button" class="confirmation-modal__button confirmation-modal__button-secondary">Cancel</button>
                <button type="button" class="confirmation-modal__button confirmation-modal__button-primary">Log out</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const cancelButton = overlay.querySelector('.confirmation-modal__button-secondary');
    const confirmButton = overlay.querySelector('.confirmation-modal__button-primary');
    let pendingHref = '';
    let lastActiveElement = null;

    function closeDialog() {
        pendingHref = '';
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (lastActiveElement instanceof HTMLElement) lastActiveElement.focus();
    }

    logoutLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            pendingHref = link.href;
            lastActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            window.setTimeout(() => confirmButton.focus(), 0);
        });
    });

    cancelButton.addEventListener('click', closeDialog);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay || event.target.classList.contains('confirmation-modal__backdrop')) {
            closeDialog();
        }
    });
    confirmButton.addEventListener('click', () => {
        if (pendingHref === '') return;
        window.LightErrors?.draft?.clear();
        window.location.href = pendingHref;
    });

    document.addEventListener('keydown', (event) => {
        if (overlay.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
            return;
        }
        if (event.key !== 'Tab') return;

        const focusableElements = [cancelButton, confirmButton];
        const currentIndex = focusableElements.indexOf(document.activeElement);
        const direction = event.shiftKey ? -1 : 1;
        const fallbackIndex = event.shiftKey ? focusableElements.length - 1 : 0;
        const nextIndex = currentIndex === -1
            ? fallbackIndex
            : (currentIndex + direction + focusableElements.length) % focusableElements.length;
        event.preventDefault();
        focusableElements[nextIndex].focus();
    });
});
