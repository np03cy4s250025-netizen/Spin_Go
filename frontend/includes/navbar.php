<?php
/**
 * frontend/includes/navbar.php
 * Shared premium navbar — include at top of any page.
 *
 * Usage:
 *   $navActive = 'home'; // or 'fleet', 'cities', 'about', 'dashboard', 'admin'
 *   include __DIR__ . '/../includes/navbar.php';
 *
 * Required before include:
 *   require_once '../../backend/config/session.php';
 *   $user = getCurrentUser();
 */
$user = $user ?? getCurrentUser();
// $navActive should be set by the parent page before including this file
$navActive = $navActive ?? '';
?>
<nav class="navbar" id="navbar">
    <div class="nav-content">
        <a href="/Spin_Go/frontend/pages/index.php" class="logo" id="logo-link">
            <div class="logo-icon">
                <i class="fas fa-car"></i>
            </div>
            SpinGo
        </a>

        <ul class="nav-links" id="nav-links">
            <li><a href="/Spin_Go/frontend/pages/index.php" <?= $navActive === 'home' ? 'class="nav-active"' : '' ?>>Home</a></li>
            <?php if (isset($showExtraLinks) && $showExtraLinks): ?>
                <li><a href="/Spin_Go/frontend/pages/fleet.php" <?= $navActive === 'fleet' ? 'class="nav-active"' : '' ?>>Fleet</a></li>
                <?php if ($user && ($user['role'] ?? '') !== 'host'): ?>
                    <li><a href="/Spin_Go/frontend/pages/dashboard.php" <?= $navActive === 'dashboard' ? 'class="nav-active"' : '' ?>>My Bookings</a></li>
                <?php endif; ?>
            <?php endif; ?>
            <li><a href="/Spin_Go/frontend/pages/index.php#cities" <?= $navActive === 'cities' ? 'class="nav-active"' : '' ?>>Cities</a></li>
        </ul>

        <div class="nav-actions">
            <?php if ($user): ?>
            <?php 
                $streamPath = match($user['role'] ?? 'user') {
                    'admin' => '/Spin_Go/frontend/pages/admin/notifications_stream.php',
                    'host'  => '/Spin_Go/frontend/pages/notifications_stream.php',
                    default => '/Spin_Go/frontend/pages/notifications_stream.php'
                };
                $markReadPath = match($user['role'] ?? 'user') {
                    'admin' => '/Spin_Go/frontend/pages/admin/mark_notifications_read.php',
                    default => '/Spin_Go/frontend/pages/mark_notifications_read.php'
                };
                
                $unreadCount = 0;
                if ($user) {
                    $role = $user['role'];
                    $uid  = $user['id'];
                    try {
                        if (!isset($conn)) {
                            require_once __DIR__ . '/../../backend/config/db.php';
                        }
                        if ($role === 'admin') {
                            $stmt = $conn->prepare(
                                "SELECT COUNT(*) FROM notifications 
                                 WHERE recipient_role = 'admin' AND (recipient_id IS NULL OR recipient_id = :uid) AND is_read = 0"
                            );
                            $stmt->execute([':uid' => $uid]);
                        } else {
                            $stmt = $conn->prepare(
                                "SELECT COUNT(*) FROM notifications 
                                 WHERE recipient_id = :uid AND recipient_role = :role AND is_read = 0"
                            );
                            $stmt->execute([':uid' => $uid, ':role' => $role]);
                        }
                        $unreadCount = (int)$stmt->fetchColumn();
                    } catch (PDOException $e) {
                        $unreadCount = 0;
                    }
                }
            ?>
            <div class="notif-bell" id="notif-bell" 
                 data-stream="<?= $streamPath ?>"
                 data-markread="<?= $markReadPath ?>"
                 data-fetch="/Spin_Go/frontend/pages/get_notifications.php"
                 data-role="<?= htmlspecialchars($user['role']) ?>"
                 data-uid="<?= (int)$user['id'] ?>"
                 style="position:relative; cursor:pointer;">
                <i class="fas fa-bell" style="font-size:18px; color:rgba(255, 255, 255, 0.8); transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255, 255, 255, 0.8)'"></i>
                <span id="notif-count" style="
                    display:<?= $unreadCount > 0 ? 'flex' : 'none' ?>; position:absolute; top:-6px; right:-6px;
                    background:#dc2626; color:#fff; font-size:10px; font-weight:700;
                    min-width:18px; height:18px; border-radius:50%;
                    align-items:center; justify-content:center;
                    font-family:'Space Grotesk',sans-serif;
                "><?= $unreadCount > 0 ? ($unreadCount > 9 ? '9+' : $unreadCount) : '' ?></span>
                
                <!-- Dropdown panel -->
                <div id="notif-panel" style="
                    display:none; position:absolute; top:calc(100% + 12px); right:-16px;
                    width:340px; background:#fff; border-radius:16px;
                    box-shadow:var(--shadow-float); border:1px solid var(--border-light);
                    z-index:1200; overflow:hidden;
                ">
                    <div style="padding:14px 18px; border-bottom:1px solid var(--border-light); display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-family:'Space Grotesk',sans-serif; font-weight:700; font-size:14px;">Notifications</span>
                        <button id="mark-all-read" style="font-size:12px; color:var(--accent); background:none; border:none; cursor:pointer; font-weight:600;">Mark all read</button>
                    </div>
                    <div id="notif-list" style="max-height:320px; overflow-y:auto;">
                        <div style="padding:28px; text-align:center; color:var(--text-muted); font-size:13px;" class="empty-state">
                            <i class="fas fa-bell-slash" style="font-size:24px; display:block; margin-bottom:10px; opacity:0.4;"></i>
                            No new notifications
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-menu" id="user-menu">
                    <button class="user-menu-trigger" id="user-menu-trigger" aria-label="User menu">
                        <span class="user-name-label"><?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></span>
                        <div class="user-avatar">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <i class="fas fa-chevron-down chevron"></i>
                    </button>
                    
                    <div class="user-dropdown" id="user-dropdown">
                        <div class="dropdown-header">
                            <p class="dropdown-user-name"><?= htmlspecialchars($user['full_name']) ?></p>
                            <p class="dropdown-user-email"><?= htmlspecialchars($user['email']) ?></p>
                            <div class="user-role-badge <?= strtolower($user['role']) ?>">
                                <?= ucfirst($user['role']) ?>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        
                        <!-- Common Links -->
                        <?php if (($user['role'] ?? '') !== 'host'): ?>
                        <a href="/Spin_Go/frontend/pages/dashboard.php" class="dropdown-item <?= $navActive === 'dashboard' ? 'active' : '' ?>">
                            <i class="fas fa-history"></i> My Bookings
                        </a>
                        <?php endif; ?>

                        <!-- Host Contextual Links -->
                        <?php if ($user['role'] !== 'admin'): ?>
                            <?php if ($user['role'] === 'host'): ?>
                                <a href="/Spin_Go/frontend/pages/host_dashboard.php" class="dropdown-item <?= $navActive === 'host_dashboard' ? 'active' : '' ?>">
                                    <i class="fas fa-gauge-high"></i> Host Dashboard
                                </a>
                            <?php elseif (($_SESSION['host_status'] ?? null) === 'pending'): ?>
                                <span class="dropdown-item dropdown-item-muted" title="Your application is being reviewed">
                                    <i class="fas fa-clock"></i> Host App Pending…
                                </span>
                            <?php else: ?>
                                <a href="/Spin_Go/frontend/pages/become_host.php" class="dropdown-item <?= $navActive === 'become_host' ? 'active' : '' ?>">
                                    <i class="fas fa-key"></i> Become a Host
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <!-- Admin Contextual Links -->
                        <?php if ($user['role'] === 'admin'): ?>
                            <div class="dropdown-divider"></div>
                            <div class="dropdown-label">Administration</div>
                            <a href="/Spin_Go/frontend/pages/admin.php" class="dropdown-item <?= $navActive === 'admin' ? 'active' : '' ?>">
                                <i class="fas fa-shield-alt"></i> Admin Panel
                            </a>
                            <a href="/Spin_Go/frontend/pages/approve_host.php" class="dropdown-item <?= $navActive === 'approve_host' ? 'active' : '' ?>">
                                <i class="fas fa-user-check"></i> Host Apps
                            </a>
                        <?php endif; ?>
                        
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="/Spin_Go/frontend/pages/logout.php" class="logout-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item logout-link">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="auth-buttons">
                    <a href="/Spin_Go/frontend/pages/login.php" class="btn btn-outline">Login</a>
                    <a href="/Spin_Go/frontend/pages/register.php" class="btn btn-primary">Sign Up</a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</nav>

<!-- Essential Global Logic -->
<script src="/Spin_Go/frontend/js/app.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const burger = document.getElementById('hamburger-btn');
    const navLinks = document.getElementById('nav-links');
    burger?.addEventListener('click', () => navLinks?.classList.toggle('mobile-open'));
    
    const handleScroll = () => {
        document.getElementById('navbar')?.classList.toggle('scrolled', window.scrollY > 20);
    };
    window.addEventListener('scroll', handleScroll);
    handleScroll(); // Trigger immediately to sync state
});
</script>


