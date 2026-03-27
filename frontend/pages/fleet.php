<?php
// frontend/pages/fleet.php

require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/models/Vehicle.php';

$vehicle_obj = new Vehicle($conn);
$presetType  = $_GET['type'] ?? 'all';
$presetCity  = $_GET['city'] ?? '';

$filterType  = ($presetType !== 'all') ? $presetType : null;
$filterCity  = !empty($presetCity) ? $presetCity : null;

$vehicles = $vehicle_obj->getAll($filterType, $filterCity);

$isLoggedIn = isLoggedIn();
$user       = getCurrentUser();
$userName   = $user['full_name'] ?? '';
$userRole   = $user['role'] ?? '';

$totalVehicles = count($vehicles);

// Price bounds for the range slider
$allPrices = array_column($vehicles, 'price');
$priceMin  = !empty($allPrices) ? (int)min($allPrices) : 0;
$priceMax  = !empty($allPrices) ? (int)max($allPrices) : 50000;
$sliderMax = (int)(ceil($priceMax  / 1000) * 1000);
$sliderMin = (int)(floor($priceMin / 1000) * 1000);
$avgPrice  = !empty($allPrices) ? (int)round(array_sum($allPrices) / count($allPrices)) : 0;

// Histogram: 20 buckets across the price range
$bucketCount = 20;
$bucketRange = $sliderMax - $sliderMin ?: 1;
$bucketSize  = $bucketRange / $bucketCount;
$buckets     = array_fill(0, $bucketCount, 0);
foreach ($allPrices as $p) {
    $idx = (int)floor(($p - $sliderMin) / $bucketSize);
    $idx = max(0, min($bucketCount - 1, $idx));
    $buckets[$idx]++;
}
$maxBucket = max($buckets) ?: 1;

$navActive = 'fleet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo Fleet - Browse our premium cars and bikes available for rental in Nepal.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/fleet.css">
    <title>SpinGo | Explore Our Fleet</title>

    <!-- Price-range dropdown — inline to bypass any cache issues -->
    <style>
    .fpb-price-wrap { position: relative; flex-shrink: 0; }

    .fpb-price-btn {
        display: flex; align-items: center; gap: 6px;
        background: #e8eaea; border: 1.5px solid #c8cccc;
        border-radius: 50px; padding: 8px 16px;
        font-size: 13px; font-weight: 600; color: #52525c;
        cursor: pointer; font-family: inherit;
        transition: all 0.2s; white-space: nowrap;
    }
    .fpb-price-btn:hover, .fpb-price-btn.is-open {
        background: #1c1e26; color: #fff; border-color: #1c1e26;
    }
    .fpb-price-btn.has-filter { background: #1c1e26; color: #fff; border-color: #1c1e26; }
    .fpb-price-btn i.fa-chevron-down { font-size: 10px; transition: transform 0.2s; }
    .fpb-price-btn.is-open i.fa-chevron-down { transform: rotate(180deg); }

    /* Dropdown card */
    .fpd-panel {
        display: none; position: absolute;
        top: calc(100% + 10px); right: 0;
        width: 310px; background: #fff;
        border: 1px solid #d4d7d7; border-radius: 16px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.14);
        padding: 20px; z-index: 500;
        animation: fpdIn 0.2s cubic-bezier(0.16,1,0.3,1);
    }
    .fpd-panel.is-open { display: block; }
    @keyframes fpdIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }

    /* Header row */
    .fpd-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
    .fpd-title { font-size: 14px; font-weight: 700; color: #1a1a1a; }
    .fpd-avg { font-size: 12px; color: #52525c; margin: 0; }
    .fpd-avg strong { color: #c9776a; font-weight: 700; }

    /* Chart: histogram + slider overlay */
    .fpd-chart { position: relative; height: 72px; margin-bottom: 6px; user-select: none; }
    .fpd-bars {
        position: absolute; bottom: 20px; left: 0; right: 0;
        height: 50px; display: flex; align-items: flex-end; gap: 2px;
        pointer-events: none;
    }
    .fpd-bar {
        flex: 1; border-radius: 2px 2px 0 0;
        background: #d4d7d7; min-height: 6px;
        transition: background 0.15s;
    }
    .fpd-bar.sel { background: #3F3E46; }

    /* Slider row */
    .fpd-slider { position: absolute; bottom: 0; left: 0; right: 0; height: 20px; }
    .fpd-track {
        position: absolute; top: 50%; left: 0; right: 0;
        height: 3px; background: #d4d7d7; border-radius: 2px;
        transform: translateY(-50%); z-index: 1;
    }
    .fpd-fill { position: absolute; height: 100%; background: #1c1e26; border-radius: 2px; }
    .fpd-range {
        position: absolute; top: 50%; left: 0;
        width: 100%; height: 3px; margin: 0; padding: 0;
        transform: translateY(-50%);
        -webkit-appearance: none; appearance: none;
        background: transparent; pointer-events: none;
        z-index: 3; outline: none;
    }
    .fpd-range::-webkit-slider-thumb {
        -webkit-appearance: none; width: 18px; height: 18px;
        border-radius: 50%; background: #1c1e26;
        border: 2.5px solid #fff;
        box-shadow: 0 1px 6px rgba(0,0,0,0.30);
        cursor: grab; pointer-events: all;
    }
    .fpd-range::-webkit-slider-thumb:active { cursor: grabbing; }
    .fpd-range::-moz-range-thumb {
        width: 18px; height: 18px; border-radius: 50%;
        background: #1c1e26; border: 2.5px solid #fff;
        box-shadow: 0 1px 6px rgba(0,0,0,0.30);
        cursor: grab; pointer-events: all;
    }

    /* Live label */
    .fpd-label { font-size: 12px; font-weight: 600; color: #1a1a1a; text-align: center; margin-bottom: 14px; height: 18px; line-height: 18px; }

    /* Inputs */
    .fpd-inputs { display: flex; align-items: flex-end; gap: 8px; margin-bottom: 12px; }
    .fpd-grp { flex: 1; display: flex; flex-direction: column; gap: 3px; }
    .fpd-grp label { font-size: 10px; font-weight: 700; color: #9ca3a3; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
    .fpd-num {
        display: flex; align-items: center; gap: 4px;
        border: 1.5px solid #d4d7d7; border-radius: 8px;
        padding: 7px 10px; background: #fff; transition: border-color 0.2s;
    }
    .fpd-num:focus-within { border-color: #3F3E46; }
    .fpd-curr { font-size: 11px; font-weight: 600; color: #9ca3a3; flex-shrink: 0; }
    .fpd-num input[type="number"] {
        flex: 1; min-width: 0; border: none; outline: none;
        background: transparent; font-size: 13px; font-weight: 700;
        color: #1a1a1a; font-family: inherit; -moz-appearance: textfield;
    }
    .fpd-num input::-webkit-outer-spin-button,
    .fpd-num input::-webkit-inner-spin-button { -webkit-appearance: none; }
    .fpd-sep { font-size: 14px; color: #d4d7d7; padding-bottom: 8px; flex-shrink: 0; }

    /* Footer */
    .fpd-foot { display: flex; justify-content: flex-end; }
    .fpd-reset {
        background: none; border: none; font-size: 12px; font-weight: 600;
        color: #52525c; cursor: pointer; font-family: inherit;
        text-decoration: underline; padding: 0;
    }
    .fpd-reset:hover { color: #1a1a1a; }
    </style>
</head>
<body>

    <?php 
    $navActive = 'fleet';
    include '../includes/navbar.php'; 
    ?>

    <div class="fleet-page-wrapper">

        <!-- ══ Hero ══════════════════════════════════════════ -->
        <div class="fleet-page-header">
            <div class="fleet-hero-inner">
                <h1>
                    Explore
                    <span class="accent-word">Our Fleet</span>
                </h1>
                <p>Engineered for the rugged terrains and high-altitude precision of the Himalayas. Choose your vessel for the ultimate expedition.</p>
            </div>
        </div>

        <!-- ══ Unified Filter Bar ═════════════════════════════ -->
        <?php if (($_GET['error'] ?? '') === 'dates_taken'): ?>
        <div id="dates-taken-banner" style="
            background: #fef3c7; border: 1.5px solid #fbbf24; color: #92400e;
            border-radius: 12px; padding: 14px 20px; margin: 0 0 16px;
            display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 500;">
            <i class="fas fa-exclamation-triangle" style="color:#f59e0b; flex-shrink:0;"></i>
            <span>Someone else completed a booking for those dates just before you. Please choose a different vehicle or different dates.</span>
            <button onclick="document.getElementById('dates-taken-banner').remove();"
                    style="margin-left:auto; background:none; border:none; cursor:pointer; color:#92400e; font-size:18px; line-height:1;" aria-label="Dismiss">&times;</button>
        </div>
        <?php endif; ?>
        <div class="fleet-controls">
            <div class="fleet-filter-pill-bar">

                <!-- Search -->
                <div class="fleet-search-inline">
                    <i class="fas fa-search"></i>
                    <input type="text" id="fleet-search" placeholder="Search by vehicle model...">
                </div>

                <div class="fpb-divider"></div>

                <!-- Type filters -->
                <div class="fleet-type-filters">
                    <button class="fleet-filter-btn <?= $presetType === 'all' ? 'active' : '' ?>" data-type="all" id="filter-all">All</button>
                    <?php
                        $distinctTypes = array_unique(array_map('strtolower', array_column($vehicles, 'type')));
                        sort($distinctTypes);
                        foreach ($distinctTypes as $dtype) {
                            if ($dtype) {
                                $activeClass = ($presetType === $dtype) ? 'active' : '';
                                echo '<button class="fleet-filter-btn ' . $activeClass . '" data-type="' . htmlspecialchars($dtype) . '">' . htmlspecialchars(ucfirst($dtype)) . '</button>';
                            }
                        }
                    ?>
                </div>

                <div class="fpb-divider"></div>

                <!-- City filter -->
                <div class="fleet-city-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <input class="fleet-city-input" type="text" id="city-fleet-filter"
                           placeholder="Select City"
                           value="<?= htmlspecialchars($_GET['city'] ?? '') ?>">
                </div>

                <div class="fpb-divider"></div>

                <!-- ═══ Price Range Dropdown ═══ -->
                <div class="fpb-price-wrap" id="fpb-price-wrap">
                    <button class="fpb-price-btn" id="fpb-price-btn" type="button">
                        <i class="fas fa-tag"></i>
                        <span id="fpb-price-label">Price Range</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>

                    <div class="fpd-panel" id="fpd-panel">

                        <div class="fpd-head">
                            <span class="fpd-title">Price Range</span>
                            <span class="fpd-avg">Avg: <strong id="fpd-avg-val">NPR <?= number_format($avgPrice) ?></strong></span>
                        </div>

                        <!-- Histogram + slider overlay -->
                        <div class="fpd-chart">
                            <div class="fpd-bars" id="fpd-histogram">
                                <?php foreach ($buckets as $count): ?>
                                    <div class="fpd-bar" style="height:<?= max(10, round(($count/$maxBucket)*100)) ?>%"></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="fpd-slider">
                                <div class="fpd-track">
                                    <div class="fpd-fill" id="fpd-fill"></div>
                                </div>
                                <input type="range" id="price-min-range" class="fpd-range"
                                    min="<?= $sliderMin ?>" max="<?= $sliderMax ?>"
                                    step="500" value="<?= $sliderMin ?>">
                                <input type="range" id="price-max-range" class="fpd-range"
                                    min="<?= $sliderMin ?>" max="<?= $sliderMax ?>"
                                    step="500" value="<?= $sliderMax ?>">
                            </div>
                        </div>

                        <!-- Live range label -->
                        <div class="fpd-label" id="fpd-label">
                            NPR <?= number_format($sliderMin) ?> &ndash; NPR <?= number_format($sliderMax) ?>
                        </div>

                        <!-- Min / Max inputs -->
                        <div class="fpd-inputs">
                            <div class="fpd-grp">
                                <label>Min</label>
                                <div class="fpd-num">
                                    <span class="fpd-curr">NPR</span>
                                    <input type="number" id="price-min-input"
                                        min="<?= $sliderMin ?>" max="<?= $sliderMax ?>"
                                        step="500" value="<?= $sliderMin ?>">
                                </div>
                            </div>
                            <div class="fpd-sep">&mdash;</div>
                            <div class="fpd-grp">
                                <label>Max</label>
                                <div class="fpd-num">
                                    <span class="fpd-curr">NPR</span>
                                    <input type="number" id="price-max-input"
                                        min="<?= $sliderMin ?>" max="<?= $sliderMax ?>"
                                        step="500" value="<?= $sliderMax ?>">
                                </div>
                            </div>
                        </div>

                        <div class="fpd-foot">
                            <button type="button" class="fpd-reset" id="fpd-reset">Reset</button>
                        </div>

                    </div><!-- /fpd-panel -->
                </div><!-- /fpb-price-wrap -->

                <div class="fpb-divider"></div>

                <!-- ═══ Sort Dropdown ═══ -->
                <div class="fleet-sort-wrap" id="fleet-sort-wrap">
                    <button class="fpb-price-btn" id="fleet-sort-btn" type="button">
                        <i class="fas fa-grip-lines" id="fleet-sort-icon"></i>
                        <span id="fleet-sort-label">Sort</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="fpd-panel fleet-sort-panel" id="fleet-sort-panel">
                        <div class="fpd-title" style="margin-bottom: 12px; font-size:14px; font-weight:700; color:#1a1a1a;">Sort Vehicles</div>
                        <div class="fleet-sort-options">
                            <button class="fleet-sort-option active" data-sort="default">
                                <i class="fas fa-grip-lines"></i> Default
                            </button>
                            <button class="fleet-sort-option" data-sort="price-asc">
                                <i class="fas fa-arrow-up"></i> Price: Low to High
                            </button>
                            <button class="fleet-sort-option" data-sort="price-desc">
                                <i class="fas fa-arrow-down"></i> Price: High to Low
                            </button>
                            <button class="fleet-sort-option" data-sort="name-asc">
                                <i class="fas fa-arrow-down-a-z"></i> Name: A → Z
                            </button>
                            <button class="fleet-sort-option" data-sort="name-desc">
                                <i class="fas fa-arrow-up-z-a"></i> Name: Z → A
                            </button>
                        </div>
                    </div>
                </div><!-- /fleet-sort-wrap -->

            </div>
        </div><!-- /fleet-controls -->

        <!-- ══ Results count ══════════════════════════════════ -->
        <div class="fleet-results-bar">
            <span class="fleet-results-count" id="results-count">
                Showing <strong><?= $totalVehicles ?></strong> vehicle<?= $totalVehicles !== 1 ? 's' : '' ?>
            </span>
        </div>

        <!-- ══ Vehicle Grid ═══════════════════════════════════ -->
        <div class="fleet-grid-wrapper">
            <div class="fleet-vehicle-grid" id="fleet-vehicle-grid">

                <?php if (!empty($vehicles)): ?>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <?php
                            include '../includes/vehicle_card.php';
                        ?>
                    <?php endforeach; ?>

                <?php else: ?>
                    <div class="fleet-empty">
                        <i class="fas fa-car-crash"></i>
                        <h3>No vehicles available</h3>
                        <p>Check back soon for our fleet updates.</p>
                    </div>
                <?php endif; ?>

            </div><!-- /fleet-vehicle-grid -->

            <!-- City Empty State -->
            <div id="city-empty-state" class="fleet-empty" style="display:none;">
                <i class="fas fa-map-marker-slash"></i>
                <h3>No vehicles available in this city right now</h3>
                <p>Try searching another Nepali city like Kathmandu or Pokhara.</p>
                <button onclick="document.getElementById('city-fleet-filter').value=''; document.getElementById('city-fleet-filter').dispatchEvent(new Event('input'));"
                        class="btn btn-outline" style="margin-top:12px;">Clear City Filter</button>
            </div>

            <!-- Pagination -->
            <div class="fleet-pagination" id="fleet-pagination" style="display:none;">
                <button class="fleet-page-btn arrow" id="prev-page" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>
                <button class="fleet-page-btn active" data-page="1">1</button>
                <button class="fleet-page-btn" data-page="2">2</button>
                <button class="fleet-page-btn" data-page="3">3</button>
                <button class="fleet-page-btn arrow" id="next-page" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
            </div>

        </div><!-- /fleet-grid-wrapper -->

    </div><!-- /fleet-page-wrapper -->

    <!-- Footer -->
    <?php require_once __DIR__ . '/../partials/footer.php'; ?>

    <script src="../js/fleet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const preset = <?= json_encode($presetType) ?>;
            if (preset !== 'all') {
                const btn = document.querySelector('[data-type="' + preset + '"]');
                if (btn) btn.click();
            }
        });
    </script>

</body>
</html>