/**
 * frontend/js/app.js
 * Core application logic for UI interactions.
 */

// ── Clear notification cache on logout ───────────────────────────────────────
if (window.location.search.includes('logout=1')) {
    try {
        const notifKey = localStorage.getItem('spingo_active_notif_key');
        const lastIdKey = localStorage.getItem('spingo_active_lastid_key');
        if (notifKey) localStorage.removeItem(notifKey);
        if (lastIdKey) localStorage.removeItem(lastIdKey);
        localStorage.removeItem('spingo_active_notif_key');
        localStorage.removeItem('spingo_active_lastid_key');
    } catch (e) { }
}

/* ---- User Dropdown Menu ---- */
const userMenuTrigger = document.getElementById("user-menu-trigger");
const userMenu = document.getElementById("user-menu");

if (userMenuTrigger && userMenu) {
    userMenuTrigger.addEventListener('click', (e) => {
        e.stopPropagation();
        userMenu.classList.toggle('active');
    });
    document.addEventListener('click', (e) => {
        if (!userMenu.contains(e.target)) userMenu.classList.remove('active');
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') userMenu.classList.remove('active');
    });
}

/* ---- Navbar Scroll Effect ---- */
const navbar = document.getElementById('navbar');
if (navbar) {
    let lastScrollY = 0;
    let ticking = false;

    const onScroll = () => {
        const y = window.scrollY;
        navbar.classList.toggle('scrolled', y > 20);
        if (y > 80) {
            if (y > lastScrollY + 5) navbar.classList.add('nav-hidden');
            else if (y < lastScrollY - 5) navbar.classList.remove('nav-hidden');
        } else {
            navbar.classList.remove('nav-hidden');
        }
        lastScrollY = y;
        ticking = false;
    };

    window.addEventListener('scroll', () => {
        if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });

    onScroll();
}

/* ---- Confirmation Messages Auto-hide ---- */
const alerts = document.querySelectorAll('.booking-alert, .av-alert, .dash-alert');
if (alerts.length > 0) {
    setTimeout(() => {
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease, margin 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
}

// ── Unified Notification System ───────────────────────────────────────────────
const bell = document.getElementById('notif-bell');
const count = document.getElementById('notif-count');
const panel = document.getElementById('notif-panel');
const list = document.getElementById('notif-list');
const markRead = document.getElementById('mark-all-read');

const typeIcons = {
    'booking': '🚗',
    'booking_confirmed': '✅',
    'new_booking': '📋',
    'cancellation': '❌',
    'host_application': '🤝',
    'application_approved': '🎉',
    'license_verified': '🪪',
    'payment': '💳',
    'new_vehicle': '🚘',
    'vehicle_listed': '✅'
};
const typeBg = {
    'booking_confirmed': '#f0fdf4',
    'new_booking': '#eff6ff',
    'cancellation': '#fef2f2',
    'application_approved': '#fefce8',
    'license_verified': '#f0fdf4',
    'new_vehicle': '#eff6ff',
    'vehicle_listed': '#f0fdf4',
    'default': '#fefce8'
};

// Resolve a relative notification link (e.g. 'admin/bookings.php') to an absolute path
function resolveNotifLink(link) {
    if (!link) return '#';
    if (link.startsWith('http') || link.startsWith('/')) return link;
    return '/Spin_Go/frontend/pages/' + link;
}

if (bell && typeof EventSource !== 'undefined') {
    const markUrl = bell.dataset.markread;
    const fetchUrl = bell.dataset.fetch || '/Spin_Go/frontend/pages/get_notifications.php';
    const userRole = bell.dataset.role || 'user';
    const userId = bell.dataset.uid || '0';

    // Scoped storage keys — separates admin vs user vs host, and per-user-id
    const STORAGE_KEY = `spingo_notifications_${userRole}_${userId}`;
    const STORAGE_LAST_ID = `spingo_notif_last_id_${userRole}_${userId}`;

    // Save meta-keys so the logout cleanup block above can find them
    try {
        localStorage.setItem('spingo_active_notif_key', STORAGE_KEY);
        localStorage.setItem('spingo_active_lastid_key', STORAGE_LAST_ID);
    } catch (e) { }

    // In-memory state — initialised from localStorage, then overwritten by REST fetch
    let storedNotifs = [];
    try { storedNotifs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { }
    let lastId = parseInt(localStorage.getItem(STORAGE_LAST_ID) || '0', 10);

    // ── Badge ─────────────────────────────────────────────────────────────────
    function syncBadge() {
        if (!count) return;
        const unread = storedNotifs.filter(n => n.is_read === 0).length;
        if (unread > 0) {
            count.textContent = unread > 9 ? '9+' : unread;
            count.style.display = 'flex';
        } else {
            count.style.display = 'none';
        }
    }

    // ── Build one notification DOM element ────────────────────────────────────
    function buildItem(notif) {
        const isRead = notif.is_read === 1;
        const icon = typeIcons[notif.type] || '🔔';
        const bg = isRead ? '#fff' : (typeBg[notif.type] || typeBg.default);
        const borderColor = isRead ? 'transparent' : '#3b82f6';
        const opacity = isRead ? '0.6' : '1';
        const href = resolveNotifLink(notif.link);
        const time = new Date(notif.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        const item = document.createElement('a');
        item.href = href;
        item.dataset.notifId = notif.id;
        item.style.cssText = `display:block; padding:14px 18px; border-bottom:1px solid #f3f4f6; border-left:4px solid ${borderColor}; text-decoration:none; background:${bg}; transition:all 0.2s;`;
        item.onmouseover = () => { item.style.background = '#f9fafb'; };
        item.onmouseout = () => { item.style.background = isRead ? '#fff' : bg; };

        item.innerHTML = `
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <span style="font-size:20px; margin-top:1px;">${icon}</span>
                <div style="flex:1; min-width:0; opacity:${opacity};" class="notif-content-wrapper">
                    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:3px; font-family:'Space Grotesk',sans-serif;">${notif.title}</div>
                    <div style="font-size:12px; color:#6b7280; line-height:1.4; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${notif.message}</div>
                    <div style="font-size:11px; color:#9ca3af; margin-top:4px;">${time}</div>
                </div>
            </div>`;

        item.addEventListener('click', function (e) {
            e.preventDefault();
            // Mark as read in memory + storage
            const idx = storedNotifs.findIndex(n => n.id === notif.id);
            if (idx !== -1 && storedNotifs[idx].is_read === 0) {
                storedNotifs[idx].is_read = 1;
                try { localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs)); } catch (e2) { }
                syncBadge();
            }
            // Mark as read on server, then navigate
            const fd = new FormData();
            fd.append('id', notif.id);
            fetch(markUrl, { method: 'POST', body: fd }).finally(() => {
                if (href !== '#') window.location.href = href;
            });
        });

        return item;
    }

    // ── Render the full list ──────────────────────────────────────────────────
    function renderAll() {
        if (!list) return;
        list.innerHTML = '';

        if (storedNotifs.length === 0) {
            list.innerHTML = `
                <div class="empty-state" style="padding:28px; text-align:center; color:var(--text-muted); font-size:13px;">
                    <i class="fas fa-bell-slash" style="font-size:24px; display:block; margin-bottom:10px; opacity:0.4;"></i>
                    No notifications
                </div>`;
        } else {
            // storedNotifs is always newest-first (DESC from REST, unshift from SSE)
            storedNotifs.forEach(n => list.appendChild(buildItem(n)));
        }

        syncBadge();
    }

    // ── SSE — only called after initial REST fetch completes ─────────────────
    function startSSE() {
        const sseUrl = bell.dataset.stream + '?lastId=' + lastId;
        const evtSource = new EventSource(sseUrl);

        evtSource.onerror = () => {
            console.warn('SSE connection lost — closing to prevent retry loop');
            evtSource.close();
        };

        evtSource.onmessage = function (e) {
            try {
                const notif = JSON.parse(e.data);
                notif.id = parseInt(notif.id, 10);
                notif.is_read = parseInt(notif.is_read ?? 0, 10);

                // Deduplicate
                if (storedNotifs.some(n => n.id === notif.id)) return;

                storedNotifs.unshift(notif);               // newest first
                if (storedNotifs.length > 50) storedNotifs = storedNotifs.slice(0, 50);

                lastId = Math.max(lastId, notif.id);
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs));
                    localStorage.setItem(STORAGE_LAST_ID, String(lastId));
                } catch (e2) { }

                renderAll();

                // Only alert for unread ones arriving live
                if (notif.is_read === 0) {
                    const audio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAD//w==');
                    audio.volume = 0.3;
                    audio.play().catch(() => { });

                    const unread = storedNotifs.filter(n => n.is_read === 0).length;
                    const original = document.title;
                    let flashing = setInterval(() => {
                        document.title = document.title === original ? `(${unread}) New Alert!` : original;
                    }, 1000);
                    setTimeout(() => { clearInterval(flashing); document.title = original; }, 8000);
                }
            } catch (err) {
                console.error('SSE parse error:', err);
            }
        };
    }

    // ── Boot sequence: REST fetch first, then SSE ─────────────────────────────
    // Show localStorage data immediately while waiting for the REST response
    renderAll();

    fetch(fetchUrl + '?limit=20')
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(data => {
            if (data.success && Array.isArray(data.notifications)) {
                storedNotifs = data.notifications;
                // data.notifications is ORDER BY id DESC — already newest-first ✓
                lastId = storedNotifs.length > 0
                    ? Math.max(...storedNotifs.map(n => n.id))
                    : 0;
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs));
                    localStorage.setItem(STORAGE_LAST_ID, String(lastId));
                } catch (e2) { }
                renderAll();
            }
            startSSE();
        })
        .catch(err => {
            console.error('Initial notification fetch failed:', err);
            // Fall back to whatever is in localStorage and start SSE from that lastId
            startSSE();
        });

    // ── Panel toggle ──────────────────────────────────────────────────────────
    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    });

    panel.addEventListener('click', e => e.stopPropagation());

    document.addEventListener('click', function (e) {
        if (panel && !panel.contains(e.target)) panel.style.display = 'none';
    });

    // ── Mark all read ─────────────────────────────────────────────────────────
    if (markRead) {
        markRead.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            storedNotifs.forEach(n => n.is_read = 1);
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs)); } catch (e2) { }
            renderAll();

            fetch(markUrl, { method: 'POST' }).catch(() => { });
        });
    }
}