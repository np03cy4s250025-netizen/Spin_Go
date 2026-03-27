<?php
// frontend/includes/vehicle_card.php
// Requires $vehicle array to be set before inclusion

$typeLabel    = strtoupper(htmlspecialchars($vehicle['type'] ?? ''));
$fuel         = htmlspecialchars($vehicle['fuel']         ?? '—');
$seats        = htmlspecialchars($vehicle['seats']        ?? '—');
$transmission = htmlspecialchars($vehicle['transmission'] ?? 'Manual');
$city         = htmlspecialchars($vehicle['city']         ?? '');

// ── Image source resolution ───────────────────────────────────────────────────
// External URLs (http/https) are routed through the server-side proxy so the
// browser always requests the image from the same origin, bypassing hotlink
// protection on Pinterest, Google gstatic, Hearst, Unsplash, etc.
// Local uploads (stored as "uploads/vehicles/filename.jpg") get a direct path
// relative to frontend/pages/ where fleet.php lives → "../uploads/vehicles/..."
$imgSrc = '';
if (!empty($vehicle['image'])) {
    $raw = $vehicle['image'];
    if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
        // Route through proxy — served from same origin, no hotlink block
        $imgSrc = '../img_proxy.php?url=' . urlencode($raw);
    } else {
        // Local upload path stored as "uploads/vehicles/file.jpg"
        // fleet.php is at frontend/pages/, so we need "../uploads/vehicles/..."
        $imgSrc = '../' . ltrim($raw, '/');
    }
}
?>
<div class="fleet-vehicle-card"
     data-type="<?= htmlspecialchars($vehicle['type'] ?? '') ?>"
     data-price="<?= $vehicle['price'] ?? 0 ?>"
     data-name="<?= htmlspecialchars(strtolower($vehicle['name'] ?? '')) ?>"
     data-city="<?= htmlspecialchars(strtolower($vehicle['city'] ?? '')) ?>">

    <div class="fleet-vehicle-img">
        <?php if ($imgSrc): ?>
            <img src="<?= htmlspecialchars($imgSrc) ?>"
                 alt="<?= htmlspecialchars($vehicle['name'] ?? '') ?>"
                 class="fleet-vehicle-photo" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <i class="fas <?= ($vehicle['type'] ?? '') === 'bike' ? 'fa-motorcycle' : 'fa-car-side' ?> fleet-type-icon" style="display:none;"></i>
        <?php elseif (($vehicle['type'] ?? '') === 'bike'): ?>
            <i class="fas fa-motorcycle fleet-type-icon"></i>
        <?php else: ?>
            <i class="fas fa-car-side fleet-type-icon"></i>
        <?php endif; ?>
        <span class="fleet-type-badge"><?= $typeLabel ?></span>
        <?php if (($vehicle['source'] ?? '') === 'host'): ?>
            <span class="fleet-host-badge">
                <i class="fas fa-user-circle"></i> Listed by Host
            </span>
        <?php endif; ?>
    </div>

    <div class="fleet-vehicle-info">
        <div class="fleet-vehicle-name-headline">
            <span class="fleet-vehicle-name"><?= htmlspecialchars($vehicle['name'] ?? '') ?></span>
            <?php if (!empty($vehicle['model'])): ?>
                <span class="fleet-vehicle-model"><?= htmlspecialchars($vehicle['model']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($city): ?>
            <div class="fleet-vehicle-city">
                <i class="fas fa-map-marker-alt"></i>
                <?= $city ?>
            </div>
        <?php endif; ?>
        <div class="fleet-vehicle-specs">
            <span><i class="fas fa-cog"></i> <?= $transmission ?></span>
            <span class="fleet-spec-dot"></span>
            <span><i class="fas fa-gas-pump"></i> <?= $fuel ?></span>
            <span class="fleet-spec-dot"></span>
            <span><i class="fas fa-users"></i> <?= $seats ?> seats</span>
        </div>
    </div>

    <div class="fleet-vehicle-footer">
        <div class="fleet-footer-rate">
            <span class="fleet-rate-label">Daily Rate</span>
            <span class="fleet-rate-value">NPR <?= number_format($vehicle['price'] ?? 0, 0) ?><small>/day</small></span>
        </div>
        <a href="vehicle-details.php?id=<?= urlencode($vehicle['id'] ?? '') ?>&source=<?= htmlspecialchars($vehicle['source'] ?? '') ?>"
           class="btn-book" id="view-<?= htmlspecialchars($vehicle['id'] ?? '') ?>">
            View Details
        </a>
    </div>

</div>
