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

const authLink = document.querySelector('[data-auth-link]');
if (authLink) {
    const token = window.location.hash.slice(1);
    if (/^[a-f0-9]{64}$/.test(token)) {
        authLink.querySelector('[data-link-token]').value = token;
    } else {
        authLink.querySelector('[data-link-error]').hidden = false;
        authLink.querySelector('button[type=submit]').disabled = true;
    }
}

const browserPush = document.querySelector('[data-browser-push]');
if (browserPush) {
    setupBrowserPush(browserPush);
}

async function setupBrowserPush(panel) {
    const status = panel.querySelector('[data-push-status]');
    const toggle = panel.querySelector('[data-push-toggle]');
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        status.textContent = 'Denne browser understøtter ikke browsernotifikationer her.';
        return;
    }
    if (Notification.permission === 'denied') {
        status.textContent = 'Notifikationer er blokeret. Tillad dem i browserens indstillinger.';
        return;
    }

    async function update(action, endpoint) {
        const response = await fetch('?page=profile', {
            method: 'POST',
            body: new URLSearchParams({csrf:panel.dataset.csrf, action, endpoint}),
        });
        if (response.redirected) {
            throw new Error('Log ind igen for at ændre browsernotifikationer.');
        }
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Indstillingen kunne ikke gemmes.');
        }
        return data.active === true;
    }

    try {
        await navigator.serviceWorker.register('push-worker.js');
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        let enabled = subscription ? await update('push-status', subscription.endpoint) : false;

        function render() {
            toggle.hidden = false;
            toggle.textContent = enabled ? 'Slå fra på denne enhed' : 'Aktivér på denne enhed';
            status.textContent = enabled ? 'Slået til i denne browser.' : 'Slået fra i denne browser.';
        }
        render();

        toggle.addEventListener('click', async () => {
            toggle.disabled = true;
            try {
                if (enabled && subscription) {
                    await update('push-disable', subscription.endpoint);
                    enabled = false;
                    await subscription.unsubscribe();
                    subscription = null;
                } else {
                    const permission = await Notification.requestPermission();
                    if (permission !== 'granted') {
                        status.textContent = 'Du skal tillade notifikationer i browseren for at slå dem til.';
                        return;
                    }
                    if (subscription) {
                        await subscription.unsubscribe();
                    }
                    const key = panel.dataset.publicKey.replace(/-/g, '+').replace(/_/g, '/');
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: Uint8Array.from(atob(key), char => char.charCodeAt(0)),
                    });
                    enabled = await update('push-enable', subscription.endpoint);
                }
                render();
            } catch (error) {
                status.textContent = error.message;
            } finally {
                toggle.disabled = false;
            }
        });
    } catch {
        status.textContent = 'Browsernotifikationer kunne ikke indlæses. Prøv at genindlæse siden.';
    }
}
