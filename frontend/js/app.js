/**
 * frontend/js/app.js
 * Core application logic for UI interactions.
 */

/* ---- Navigation / Hamburger ---- */

/* ---- User Dropdown Menu ---- */
const userMenuTrigger = document.getElementById("user-menu-trigger");
const userMenu        = document.getElementById("user-menu");

if (userMenuTrigger && userMenu) {
    userMenuTrigger.addEventListener('click', (e) => {
        e.stopPropagation();
        userMenu.classList.toggle('active');
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
        if (!userMenu.contains(e.target)) {
            userMenu.classList.remove('active');
        }
    });

    // Close on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            userMenu.classList.remove('active');
        }
    });
}

/* ---- Navbar Scroll Effect (hide on scroll down, show on scroll up) ---- */
const navbar = document.getElementById('navbar');
if (navbar) {
    let lastScrollY = 0;
    let ticking = false;

    const onScroll = () => {
        const y = window.scrollY;
        // Add background blur when scrolled past 20px
        navbar.classList.toggle('scrolled', y > 20);

        // Hide/show based on direction (only after scrolling past 80px)
        if (y > 80) {
            if (y > lastScrollY + 5) {
                // Scrolling DOWN — hide
                navbar.classList.add('nav-hidden');
            } else if (y < lastScrollY - 5) {
                // Scrolling UP — show
                navbar.classList.remove('nav-hidden');
            }
        } else {
            // Near the top — always show
            navbar.classList.remove('nav-hidden');
        }
        lastScrollY = y;
        ticking = false;
    };

    window.addEventListener('scroll', () => {
        if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
    }, { passive: true });

    // Initial check
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

// ── Unified Real-time Notifications (SSE) ──────────────────────────────────────
const bell = document.getElementById('notif-bell');
const count = document.getElementById('notif-count');
const panel = document.getElementById('notif-panel');
const list = document.getElementById('notif-list');
const markRead = document.getElementById('mark-all-read');

if (window.location.search.includes('logout=1')) {
    const notifKey  = localStorage.getItem('spingo_active_notif_key');
    const lastIdKey = localStorage.getItem('spingo_active_lastid_key');
    if (notifKey)  localStorage.removeItem(notifKey);
    if (lastIdKey) localStorage.removeItem(lastIdKey);
    localStorage.removeItem('spingo_active_notif_key');
    localStorage.removeItem('spingo_active_lastid_key');
}

if (bell && typeof EventSource !== 'undefined') {
    const markUrl = bell.dataset.markread;
    const userRole = bell.dataset.role || 'user';
    const userId   = bell.dataset.uid  || '0';
    const STORAGE_KEY     = `spingo_notifications_${userRole}_${userId}`;
    const STORAGE_LAST_ID = `spingo_notif_last_id_${userRole}_${userId}`;

    // Write meta-keys so logout can find the correct scoped keys
    localStorage.setItem('spingo_active_notif_key',  STORAGE_KEY);
    localStorage.setItem('spingo_active_lastid_key', STORAGE_LAST_ID);

    let storedNotifs = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
    let lastId = parseInt(localStorage.getItem(STORAGE_LAST_ID) || '0');

    // Resolve relative links (e.g. 'admin/bookings.php') to pages root absolute paths
    function resolveNotifLink(link) {
        if (!link) return '#';
        if (link.startsWith('http') || link.startsWith('/')) return link;
        return '/Spin_Go/frontend/pages/' + link;
    }
    
    const updateBadge = () => {
        let unreadCount = storedNotifs.filter(n => n.is_read === 0).length;
        if (count) {
            if (unreadCount > 0) {
                count.textContent = unreadCount > 9 ? '9+' : unreadCount;
                count.style.display = 'flex';
            } else {
                count.style.display = 'none';
            }
        }
    };

    const renderNotif = (notif) => {
        const typeIcons = {
            'booking':              '🚗',
            'booking_confirmed':    '✅',
            'new_booking':          '📋',
            'cancellation':         '❌',
            'host_application':     '🤝',
            'application_approved': '🎉',
            'license_verified':     '🪪',
            'payment':              '💳',
            'new_vehicle':          '🚘',
            'vehicle_listed':       '✅'
        };

        const typeBg = {
            'booking_confirmed':    '#f0fdf4',
            'new_booking':          '#eff6ff',
            'cancellation':         '#fef2f2',
            'application_approved': '#fefce8',
            'license_verified':     '#f0fdf4',
            'new_vehicle':          '#eff6ff',
            'vehicle_listed':       '#f0fdf4',
            'default':              '#fefce8'
        };

        const icon = typeIcons[notif.type] || '🔔';
        const isRead = notif.is_read === 1;
        const bg   = isRead ? '#fff' : (typeBg[notif.type] || typeBg.default);
        const time = new Date(notif.created_at).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
        
        const href = resolveNotifLink(notif.link);
        const item = document.createElement('a');
        item.href = href;
        item.style.cssText = `display:block; padding:14px 18px; border-bottom:1px solid #f3f4f6; border-left: 4px solid ${isRead ? 'transparent' : '#3b82f6'}; text-decoration:none; background:${bg}; transition:all 0.2s;`;
        
        if (!isRead) {
            item.onmouseover = () => item.style.background = '#f9fafb';
            item.onmouseout  = () => item.style.background = bg;
        }

        const opacity = isRead ? '0.6' : '1';
        
        item.innerHTML   = `
            <div style="display:flex; gap:12px; align-items:flex-start;">
                <span style="font-size:20px; margin-top:1px;">${icon}</span>
                <div style="flex:1; min-width:0; opacity: ${opacity};" class="notif-content-wrapper">
                    <div style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:3px; font-family:'Space Grotesk',sans-serif;">${notif.title}</div>
                    <div style="font-size:12px; color:#6b7280; line-height:1.4; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${notif.message}</div>
                    <div style="font-size:11px; color:#9ca3af; margin-top:4px;">${time}</div>
                </div>
            </div>
        `;
        
        item.addEventListener('click', function(e) {
            if (!isRead) {
                e.preventDefault();
                notif.is_read = 1;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs));
                const fd = new FormData();
                fd.append('id', notif.id);
                fetch(markUrl, { method: 'POST', body: fd })
                    .catch(err => console.error('[NotifMarkRead-Error] ', err))
                    .finally(() => {
                        window.location.href = href;
                    });
            }
        });

        list.appendChild(item);
    };

    const renderAllNotifs = () => {
        if (!list) return;
        list.innerHTML = '';
        if (storedNotifs.length === 0) {
            list.innerHTML = '<div class="empty-state" style="padding: 20px; text-align: center; color: #6b7280; font-size: 14px;">No notifications</div>';
        } else {
            storedNotifs.forEach(n => renderNotif(n));
        }
        updateBadge();
    };

    const startSSE = () => {
        const sseUrl  = bell.dataset.stream + '?lastId=' + lastId;
        const evtSource = new EventSource(sseUrl);
        
        evtSource.onerror = function(err) {
            console.warn("SSE Connection lost. Retrying standard reconnection...", err);
        };
        
        evtSource.onmessage = function(e) {
            try {
                const notif = JSON.parse(e.data);
                
                // Deduplication
                if (!storedNotifs.some(n => n.id === notif.id)) {
                    // Ensure is_read exists
                    if (typeof notif.is_read === 'undefined') notif.is_read = 0;
                    
                    storedNotifs.unshift(notif);
                    
                    // Limit the number of stored notifications to avoid localStorage bloat
                    if (storedNotifs.length > 50) {
                        storedNotifs = storedNotifs.slice(0, 50);
                    }
                    
                    lastId = Math.max(lastId, notif.id);
                    
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs));
                    localStorage.setItem(STORAGE_LAST_ID, lastId);
                    
                    renderAllNotifs();
                    
                    // Play subtle sound (short ping)
                    const audio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAD//w==');
                    audio.volume = 0.3;
                    audio.play().catch(() => {}); 
                    
                    // Flash page title
                    let original = document.title;
                    let unreadCount = storedNotifs.filter(n => n.is_read === 0).length;
                    let flashing = setInterval(() => {
                        document.title = document.title === original ? `(${unreadCount}) New Alert!` : original;
                    }, 1000);
                    setTimeout(() => { clearInterval(flashing); document.title = original; }, 8000);
                }
            } catch(err) {
                console.error('SSE Error:', err);
            }
        };
    };

    // Load initial notifications via REST endpoint
    const fetchUrl = bell.dataset.fetch || '/Spin_Go/frontend/pages/get_notifications.php';
    fetch(fetchUrl + '?limit=20')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.notifications) {
                storedNotifs = data.notifications;
                
                // Update lastId to be the maximum ID in the fetched list
                if (storedNotifs.length > 0) {
                    lastId = Math.max(...storedNotifs.map(n => n.id));
                } else {
                    lastId = 0;
                }
                
                localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs));
                localStorage.setItem(STORAGE_LAST_ID, lastId);
                
                renderAllNotifs();
            } else {
                renderAllNotifs();
            }
            startSSE();
        })
        .catch(err => {
            console.error('Failed to fetch initial notifications:', err);
            renderAllNotifs();
            startSSE(); // Fallback to start SSE with whatever is in localStorage
        });
    
    // Toggle panel
    bell.addEventListener('click', function(e) {
        e.stopPropagation();
        if (!panel) return;
        const isOpen = panel.style.display === 'block';
        panel.style.display = isOpen ? 'none' : 'block';
    });
    
    // Prevent clicks inside panel from bubbling up to the bell and toggling it
    if (panel) {
        panel.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    
    // Close panel on outside click
    document.addEventListener('click', function(e) {
        if (panel && !panel.contains(e.target)) {
            panel.style.display = 'none';
        }
    });
    
    // Mark all read
    if (markRead) {
        markRead.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fetch(markUrl, { method: 'POST' })
                .then(() => {
                    storedNotifs.forEach(n => n.is_read = 1);
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(storedNotifs));
                    renderAllNotifs();
                });
        });
    }
}

// ── Global Terms & Privacy Modal Logic ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Intercept click on policy links page-wide
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (!link) return;

        const href = link.getAttribute('href');
        if (!href) return;

        // Match terms.php or privacy.php generally
        if (/(^|\/)(terms|privacy)\.php($|\?|#)/.test(href)) {
            e.preventDefault();
            openLegalModal(link.href);
        }
    });

    function openLegalModal(url) {
        let modal = document.getElementById('legal-modal');
        if (!modal) {
            // Create modal elements dynamically if they don't exist
            modal = document.createElement('div');
            modal.id = 'legal-modal';
            modal.className = 'legal-modal-overlay';
            modal.style.display = 'none';
            modal.innerHTML = `
                <div class="legal-modal-container">
                    <button class="legal-modal-close-btn" id="legal-modal-close" aria-label="Close modal">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="legal-modal-body" id="legal-modal-content"></div>
                </div>
            `;
            document.body.appendChild(modal);

            // Add event listeners for closing this new modal
            modal.addEventListener('click', function(e) {
                if (e.target === modal || e.target.closest('#legal-modal-close')) {
                    closeLegalModal();
                }
            });
        }

        const modalContent = document.getElementById('legal-modal-content');
        if (!modalContent) return;

        modalContent.innerHTML = `
            <div class="legal-spinner-container">
                <div class="legal-spinner"></div>
                <div>Loading document...</div>
            </div>
        `;
        modal.style.display = 'flex';
        // force layout reflow
        modal.offsetHeight;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        fetch(url)
            .then(res => {
                if (!res.ok) throw new Error('Network error');
                return res.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const legalWrap = doc.querySelector('.legal-wrap');
                if (legalWrap) {
                    const backBtn = legalWrap.querySelector('.back-btn');
                    if (backBtn) backBtn.remove();
                    modalContent.innerHTML = legalWrap.innerHTML;
                } else {
                    modalContent.innerHTML = `<p style="color:#dc2626;text-align:center;padding:20px;font-weight:600;">Failed to parse document content.</p>`;
                }
            })
            .catch(err => {
                modalContent.innerHTML = `<p style="color:#dc2626;text-align:center;padding:20px;font-weight:600;">Failed to load document. Please check your internet connection.</p>`;
            });
    }

    function closeLegalModal() {
        const modal = document.getElementById('legal-modal');
        if (!modal) return;

        modal.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => {
            modal.style.display = 'none';
            const modalContent = document.getElementById('legal-modal-content');
            if (modalContent) modalContent.innerHTML = '';
        }, 300);
    }

    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLegalModal();
        }
    });
});

