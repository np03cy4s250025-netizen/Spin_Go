<?php
require_once '../../backend/config/db.php';
require_once '../../backend/config/session.php';
require_once '../../backend/config/app.php';
require_once '../../backend/models/Vehicle.php';

$user = getCurrentUser();

// Fetch popular vehicles from DB (up to 6, mix of cars & bikes)
$vehicle_obj = new Vehicle($conn);
$all_vehicles = $vehicle_obj->getAll();
$popular_vehicles = array_slice($all_vehicles, 0, 6);

$navActive = 'home';
$showExtraLinks = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SpinGo - Premium car and bike rentals with unbeatable prices. Choose from our diverse fleet and experience the freedom of the road.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/home.css">
    <title>SpinGo | Premium Car & Bike Rentals</title>
    
    
    
    
    
    
    
</head>
<body>

    <?php 
    $navActive = 'home';
    include '../includes/navbar.php'; 
    ?>

    <!-- Hero Section -->
    <header id="home" class="hero-video-wrapper">
        <video id="promo-video" class="hero-video-bg" autoplay muted loop playsinline poster="../images/hero-bg.jpg">
            <source src="../videos/promo.mp4" type="video/mp4">
        </video>
        <div class="hero-video-overlay"></div>
        
        <div class="container hero-video-content">
            <h1 class="hero-title">DRIVE YOUR DREAMS</h1>
            <p class="hero-subtitle">Premium cars and bikes in Nepal — explore our diverse fleet and book in minutes.</p>
            <a href="fleet.php" class="btn btn-primary btn-hero-modern">Explore Our Fleet &nbsp; &rarr;</a>
            
            <div class="hero-trust-badges">
                <span class="trust-badge"><i class="fas fa-shield-alt"></i> FULLY INSURED</span>
                <span class="trust-badge"><i class="fas fa-bolt"></i> INSTANT BOOKING</span>
                <span class="trust-badge"><i class="fas fa-map-marker-alt"></i> ALL MAJOR NEPALI CITIES</span>
            </div>
        </div>
    </header>

    <!-- Browse by Category -->
    <section class="section categories-section" id="categories">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">EXPLORE</span>
                <h2>Browse by Category</h2>
            </div>
            <div class="categories-grid">
                <a href="fleet.php?type=electric" class="category-card">
                    <div class="category-img-wrap">
                        <div class="category-img cat-electric-bg"></div>
                        <div class="category-overlay"></div>
                        <span class="category-label">ELECTRIC</span>
                    </div>
                </a>
                <a href="fleet.php?type=suv" class="category-card">
                    <div class="category-img-wrap">
                        <div class="category-img cat-suv-bg"></div>
                        <div class="category-overlay"></div>
                        <span class="category-label">SUV</span>
                    </div>
                </a>
                <a href="fleet.php?type=sports" class="category-card">
                    <div class="category-img-wrap">
                        <div class="category-img cat-sports-bg"></div>
                        <div class="category-overlay"></div>
                        <span class="category-label">SPORTS</span>
                    </div>
                </a>
                <a href="fleet.php?type=motorbike" class="category-card">
                    <div class="category-img-wrap">
                        <div class="category-img cat-motorbike-bg"></div>
                        <div class="category-overlay"></div>
                        <span class="category-label">MOTORBIKE</span>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <!-- Popular Vehicles Section -->
    <section class="section popular-section" id="popular">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">TOP PICKS</span>
                <h2>Popular Vehicles</h2>
                <p>Curated selection of our most loved rides</p>
            </div>

            <?php if (!empty($popular_vehicles)): ?>
            <div class="popular-grid">
                <?php foreach ($popular_vehicles as $v): ?>
                <?php 
                    $vehicle = $v;
                    include '../includes/vehicle_card.php';
                ?>
                <?php endforeach; ?>
            </div>
            <div style="text-align: center; margin-top: 40px;">
                <a href="fleet.php" class="btn btn-outline">View Entire Fleet &nbsp; &rarr;</a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Top Cities Section (Always Visible) -->
    <section class="section cities-section" id="cities">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">LOCATIONS</span>
                <h2>Available in Top Cities</h2>
                <p>Experience SpinGo in major hubs across Nepal</p>
            </div>
            <div class="cities-grid">
                <a href="fleet.php?city=Kathmandu" class="city-card">
                    <div class="city-img" style="background-image: url('../assets/images/cities/kathmandu.jpg')"></div>
                    <div class="city-overlay"></div>
                    <div class="city-info">
                        <h3>Kathmandu</h3>
                        <p>The Historic Heart</p>
                    </div>
                </a>
                <a href="fleet.php?city=Pokhara" class="city-card">
                    <div class="city-img" style="background-image: url('../assets/images/cities/pokhara.jpg')"></div>
                    <div class="city-overlay"></div>
                    <div class="city-info">
                        <h3>Pokhara</h3>
                        <p>Lakeside Serenity</p>
                    </div>
                </a>
                <a href="fleet.php?city=Lalitpur" class="city-card">
                    <div class="city-img" style="background-image: url('../assets/images/cities/lalitpur.jpg')"></div>
                    <div class="city-overlay"></div>
                    <div class="city-info">
                        <h3>Lalitpur</h3>
                        <p>The City of Artisans</p>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <!-- How It Works Section (Only for Guests) -->
    <?php if (!isLoggedIn()): ?>
    <section class="section how-it-works-section" id="how-it-works">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">STEP BY STEP</span>
                <h2>How It Works</h2>
            </div>
            <div class="steps-grid">
                <div class="step-card" id="step-1">
                    <div class="step-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <h3>Choose Location</h3>
                    <p>Select your pickup and drop-off locations across Nepal.</p>
                </div>
                <div class="step-card" id="step-2">
                    <div class="step-icon"><i class="fas fa-calendar-alt"></i></div>
                    <h3>Select Dates</h3>
                    <p>Pick your rental period and preferred time.</p>
                </div>
                <div class="step-card" id="step-3">
                    <div class="step-icon"><i class="fas fa-car"></i></div>
                    <h3>Choose Your Ride</h3>
                    <p>Browse our premium fleet and select your vehicle.</p>
                </div>
                <div class="step-card" id="step-4">
                    <div class="step-icon"><i class="fas fa-credit-card"></i></div>
                    <h3>Book & Drive</h3>
                    <p>Complete your booking and hit the mountain roads.</p>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Trust / Features Section (Only for Guests) -->
    <section class="trust-section" id="about">
        <div class="container">
            <div class="section-header" style="text-align:center; margin-bottom: 56px;">
                <span class="section-tag">WHY SPINGO</span>
                <h2 style="color:#fff; margin-top: 12px;">Built for Nepal. Built for You.</h2>
                <p style="color:rgba(255,255,255,0.65); max-width: 560px; margin: 16px auto 0; font-size: 15px; line-height: 1.7;">
                    Getting around Nepal shouldn't be a hassle. SpinGo connects everyday people with quality vehicles — so whether you're navigating Kathmandu's streets or chasing the horizon toward Pokhara, the road is always open.
                </p>
            </div>
            <div class="trust-grid">
                <div class="trust-item">
                    <div class="trust-icon"><i class="fas fa-headset"></i></div>
                    <h3>24/7 Support</h3>
                    <p>Breakdowns don't follow office hours. Our team is available around the clock — so no matter where the road takes you, help is always just a call away.</p>
                </div>
                <div class="trust-item">
                    <div class="trust-icon"><i class="fas fa-bolt"></i></div>
                    <h3>Instant Booking</h3>
                    <p>No waiting, no phone calls, no paperwork. Browse real-time availability, pick your vehicle, and confirm your booking in minutes — entirely online.</p>
                </div>
                <div class="trust-item">
                    <div class="trust-icon"><i class="fas fa-shield-alt"></i></div>
                    <h3>Fully Insured</h3>
                    <p>Every vehicle on SpinGo comes with comprehensive insurance coverage. You focus on the journey — we've got you covered from peak to valley.</p>
                </div>
            </div>

            <!-- Community impact row -->
            <div class="trust-grid" style="margin-top: 32px; grid-template-columns: repeat(3, 1fr);">
                <div class="trust-item">
                    <div class="trust-icon"><i class="fas fa-users"></i></div>
                    <h3>Community-Owned Fleet</h3>
                    <p>SpinGo lets local vehicle owners earn by listing their cars and bikes. Every booking supports a real person in your community — not a faceless corporation.</p>
                </div>
                <div class="trust-item">
                    <div class="trust-icon"><i class="fas fa-leaf"></i></div>
                    <h3>Greener Commutes</h3>
                    <p>Shared vehicles mean fewer cars sitting idle. By renting instead of owning, you're helping reduce congestion and emissions in Nepal's growing cities.</p>
                </div>
                <div class="trust-item">
                    <div class="trust-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <h3>Explore Without Limits</h3>
                    <p>From Kathmandu's valley to Pokhara's lakeside roads, SpinGo gives travellers and locals alike the freedom to explore Nepal on their own terms.</p>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Membership / CTA Banner (Only for Guests) -->
    <?php if (!isLoggedIn()): ?>
    <section class="host-cta-section">
        <div class="container host-cta-container">
            <div class="host-cta-content">
                <h2>Join the SpinGo<br>Expedition Elite.</h2>
                <p>Get exclusive access to specialty fleet releases, curated mountain routes, and early member rates.</p>
                <form class="host-cta-form" id="membership-form" action="register.php" method="GET">
                    <input type="email" name="email" id="membership-email" placeholder="Enter your email" class="host-cta-input" required>
                    <button type="submit" class="btn btn-white">Join Now</button>
                </form>
            </div>
            <div class="host-cta-perks">
                <div class="host-perk-card">
                    <div class="host-perk-icon"><i class="fas fa-headset"></i></div>
                    <div class="host-perk-text">
                        <h4>Priority Support</h4>
                        <p>24/7 Mountain Assistance</p>
                    </div>
                </div>
                <div class="host-perk-card">
                    <div class="host-perk-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="host-perk-text">
                        <h4>Premium Insurance</h4>
                        <p>Full peak-to-peak coverage</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Footer -->
    <?php require_once __DIR__ . '/../partials/footer.php'; ?>

    <script src="../js/home.js"></script>
</body>
</html>