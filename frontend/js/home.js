/**
 * frontend/js/home.js
 * Home page interactive behaviour:
 * - Video play/pause toggle
 * - Video fallback handling (shows placeholder when no video file exists)
 * - Scroll-reveal animations
 * NOTE: Navbar scroll & hamburger are handled by app.js globally.
 */

(function () {
    'use strict';

    /* ---- Background Video handling ---- */
    const video = document.getElementById('promo-video');
    if (video) {
        video.play().catch(() => {
            // Background video autoplay blocked or failed.
        });
    }

    /* ---- Scroll-reveal (light Intersection Observer) ---- */
    const revealTargets = document.querySelectorAll(
        '.category-card-modern, .vehicle-card-modern, .m-perk-card, .trust-item'
    );

    if ('IntersectionObserver' in window && revealTargets.length) {
        // Set initial state
        revealTargets.forEach(function (el, i) {
            el.style.opacity   = '0';
            el.style.transform = 'translateY(28px)';
            el.style.transition = 'opacity 0.55s ease ' + (i % 4) * 80 + 'ms, transform 0.55s ease ' + (i % 4) * 80 + 'ms';
        });

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.style.opacity   = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        revealTargets.forEach(function (el) { observer.observe(el); });
    }

    /* ---- Smooth scroll for anchor links ---- */
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            const hash = this.getAttribute('href');
            if (hash === '#') return;
            const target = document.querySelector(hash);
            if (target) {
                e.preventDefault();
                const offset = navbar ? navbar.offsetHeight : 0;
                const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        });
    });

})();
