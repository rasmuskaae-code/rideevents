self.addEventListener('push', event => {
    event.waitUntil(self.registration.showNotification('Stævnevagt', {
        body: 'Du har nye notifikationer i Stævnevagt.',
        tag: 'staevnevagt-notices',
        icon: new URL('assets/push-icon-192.png', self.registration.scope).href,
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = new URL('?page=notices', self.registration.scope).href;
    event.waitUntil((async () => {
        const clients = await self.clients.matchAll({type:'window', includeUncontrolled:true});
        const existing = clients.find(client => client.url.startsWith(self.registration.scope));
        if (existing) {
            const updated = await existing.navigate(target);
            if (updated) {
                return updated.focus();
            }
        }
        return self.clients.openWindow(target);
    })());
});
