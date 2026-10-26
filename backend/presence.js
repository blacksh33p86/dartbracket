const activeUsersPanel = document.querySelector('[data-active-users]');
const activeUsersList = activeUsersPanel?.querySelector('[data-active-users-list]');
const activeUsersCount = activeUsersPanel?.querySelector('[data-active-users-count]');
const activeUsersStatus = activeUsersPanel?.querySelector('[data-active-users-status]');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
    ?? document.querySelector('input[name="csrf_token"]')?.value;

async function refreshActiveUsers() {
    if (!csrfToken) {
        return;
    }

    try {
        const response = await fetch('presence.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({ csrf_token: csrfToken }),
        });

        if (!response.ok) {
            throw new Error(`Presence request failed with HTTP ${response.status}`);
        }

        const data = await response.json();
        if (activeUsersList && activeUsersCount) {
            activeUsersList.replaceChildren();
            for (const user of data.users) {
                const item = document.createElement('li');
                const dot = document.createElement('span');
                dot.className = 'active-user-dot';
                dot.setAttribute('aria-hidden', 'true');
                const name = document.createElement('span');
                name.className = 'active-user-name';
                name.textContent = user.username;
                item.append(dot, name);
                if (String(user.id) === activeUsersPanel.dataset.currentUserId) {
                    const self = document.createElement('span');
                    self.className = 'active-user-self';
                    self.textContent = 'Du';
                    item.append(self);
                }
                activeUsersList.append(item);
            }
            activeUsersCount.textContent = String(data.users.length);
        }
        if (activeUsersStatus) {
            activeUsersStatus.textContent = 'Aktiv in den letzten 2 Minuten';
        }
    } catch (error) {
        console.error('Aktive Nutzer konnten nicht aktualisiert werden.', error);
        if (activeUsersStatus) {
            activeUsersStatus.textContent = 'Status momentan nicht verfügbar';
        }
    }
}

refreshActiveUsers();
window.setInterval(refreshActiveUsers, 30_000);
