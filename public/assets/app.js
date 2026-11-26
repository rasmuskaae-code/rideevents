for (const select of document.querySelectorAll('[data-auto-submit]')) {
    select.addEventListener('change', () => select.form.requestSubmit());
}
for (const form of document.querySelectorAll('[data-confirm]')) {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
}
