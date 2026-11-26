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
const layout = document.querySelector('.app-layout');
const navigation = document.querySelector('.app-sidebar');
const menuButton = document.querySelector('.nav-toggle');

if (layout && navigation && menuButton) {
    const mobile = window.matchMedia('(max-width: 760px)');
    layout.dataset.navigationReady = '';
    menuButton.hidden = false;

    function setNavigation(open, restoreFocus = false) {
        layout.toggleAttribute('data-navigation-open', open);
        menuButton.setAttribute('aria-expanded', String(open));
        if (open) {
            (navigation.querySelector('nav a[aria-current]') ?? navigation.querySelector('nav a'))?.focus();
        } else if (restoreFocus) {
            menuButton.focus();
        }
    }

    menuButton.addEventListener('click', () => setNavigation(menuButton.getAttribute('aria-expanded') !== 'true'));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && layout.hasAttribute('data-navigation-open')) {
            setNavigation(false, true);
        }
    });
    document.addEventListener('click', event => {
        if (!navigation.contains(event.target) && !menuButton.contains(event.target)) {
            setNavigation(false);
        }
    });
    mobile.addEventListener('change', () => setNavigation(false));
}

const profileMenu = document.querySelector('.profile');
if (profileMenu) {
    document.addEventListener('click', event => {
        if (!profileMenu.contains(event.target)) {
            profileMenu.open = false;
        }
    });
    profileMenu.addEventListener('keydown', event => {
        if (event.key === 'Escape' && profileMenu.open) {
            profileMenu.open = false;
            profileMenu.querySelector('summary').focus();
        }
    });
}
