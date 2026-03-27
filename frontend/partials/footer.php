<?php
if (!defined('APP_URL')) {
    // derive base path from $_SERVER, same logic as app.php
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $projectName = 'Spin_Go';
    $pos = strpos($scriptName, '/' . $projectName);
    if ($pos !== false) {
        $projectRoot = substr($scriptName, 0, $pos + strlen($projectName) + 1);
        $projectRoot = rtrim($projectRoot, '/');
    } else {
        $projectRoot = '';
    }
    define('APP_URL', $protocol . '://' . $host . $projectRoot);
}
?>
<footer class="footer" id="footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <a href="<?= APP_URL ?>/frontend/pages/index.php" class="footer-logo" style="text-decoration: none; color: inherit;">
                <div class="logo-icon">
                    <i class="fas fa-car"></i>
                </div>
                SpinGo
            </a>
            <p>Your trusted partner for premium car rentals. Experience the freedom of the road with our diverse fleet.</p>
            <div class="footer-socials">
                <a href="https://www.facebook.com" id="footer-fb" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="https://www.twitter.com" id="footer-tw" target="_blank" rel="noopener noreferrer" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="https://www.instagram.com" id="footer-ig" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="<?= APP_URL ?>/frontend/pages/index.php">Home</a></li>
                <li><a href="<?= APP_URL ?>/frontend/pages/fleet.php">Our Fleet</a></li>
                <li><a href="<?= APP_URL ?>/frontend/pages/index.php#cities">Locations</a></li>
                <li><a href="<?= APP_URL ?>/frontend/pages/index.php#about">About Us</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Services</h4>
            <ul>
                <li style="color: var(--ink-2); font-size: 14px;">Daily Rentals</li>
                <li style="color: var(--ink-2); font-size: 14px;">Weekly Rentals</li>
                <li style="color: var(--ink-2); font-size: 14px;">Monthly Rentals</li>
                <li style="color: var(--ink-2); font-size: 14px;">Airport Pickup</li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Contact Us</h4>
            <ul class="footer-contact">
                <li><i class="fas fa-map-marker-alt"></i>Kathmandu, Nepal</li>
                <li><i class="fas fa-phone"></i> +977-9800000000</li>
                <li><i class="fas fa-envelope"></i>spingo@gmail.com</li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <span>&copy; <?= date('Y') ?> SpinGo. All rights reserved.</span>
        <div class="footer-bottom-links">
            <a href="<?= APP_URL ?>/frontend/pages/privacy.php">Privacy Policy</a>
            <a href="<?= APP_URL ?>/frontend/pages/terms.php">Terms of Service</a>
        </div>
    </div>
</footer>
