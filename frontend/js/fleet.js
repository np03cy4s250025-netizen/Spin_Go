/**
 * frontend/js/fleet.js
 * ─ Search / Type / City instant filters
 * ─ Price Range dropdown (auto-applies on drag)
 * ─ Sort: Default / Price asc-desc / Name asc-desc
 * NOTE: Navbar scroll & hamburger are handled by app.js globally.
 */
(function () {
    'use strict';

    /* ══════════════════════════════════════════════════
       Filter state
    ══════════════════════════════════════════════════ */
    const searchInput = document.getElementById('fleet-search');
    const cityInput   = document.getElementById('city-fleet-filter');
    const filterBtns  = document.querySelectorAll('.fleet-filter-btn');
    const grid        = document.getElementById('fleet-vehicle-grid');
    const emptyState  = document.getElementById('city-empty-state');
    const cards       = Array.from(document.querySelectorAll('.fleet-vehicle-card'));

    // Store original DOM order for "Default" sort reset
    const originalOrder = [...cards];

    let activeType  = 'all';
    let searchQuery = '';
    let cityQuery   = cityInput?.value.toLowerCase().trim() || '';

    /* ── Price slider refs ──────────────────────────── */
    const minSlider  = document.getElementById('price-min-range');
    const maxSlider  = document.getElementById('price-max-range');
    const minInput   = document.getElementById('price-min-input');
    const maxInput   = document.getElementById('price-max-input');
    const fillEl     = document.getElementById('fpd-fill');
    const labelEl    = document.getElementById('fpd-label');
    const avgEl      = document.getElementById('fpd-avg-val');
    const histogram  = document.getElementById('fpd-histogram');
    const triggerBtn = document.getElementById('fpb-price-btn');
    const labelSpan  = document.getElementById('fpb-price-label');
    const panel      = document.getElementById('fpd-panel');
    const resetBtn   = document.getElementById('fpd-reset');

    const FLOOR = minSlider ? +minSlider.min : 0;
    const CEIL  = maxSlider ? +maxSlider.max : 999999;
    let activeMin = FLOOR;
    let activeMax = CEIL;

    const bars = histogram ? Array.from(histogram.querySelectorAll('.fpd-bar')) : [];
    const bucketCount = bars.length || 20;

    /* ══════════════════════════════════════════════════
       Core filter function — runs on EVERY change
    ══════════════════════════════════════════════════ */
    function applyFilters() {
        let n = 0;
        let priceSum = 0;

        cards.forEach(card => {
            const type  = (card.dataset.type  || '').toLowerCase();
            const name  = (card.dataset.name  || '').toLowerCase();   // FIX: lowercased
            const city  = (card.dataset.city  || '').toLowerCase();   // FIX: lowercased
            const price = +card.dataset.price || 0;

            const typeOk = activeType === 'all' || type.includes(activeType);

            const ok =
                typeOk &&
                name.startsWith(searchQuery) &&                        // FIX: startsWith not includes
                (cityQuery === '' || city.includes(cityQuery)) &&
                price >= activeMin && price <= activeMax;

            card.classList.toggle('hidden-filter', !ok);
            if (ok) { n++; priceSum += price; }
        });

        /* Update results count */
        const countEl = document.getElementById('results-count');
        if (countEl) countEl.innerHTML = `Showing <strong>${n}</strong> vehicle${n !== 1 ? 's' : ''}`;

        /* Update average to reflect only VISIBLE vehicles */
        if (avgEl) {
            const avg = n > 0 ? Math.round(priceSum / n) : 0;
            avgEl.textContent = 'NPR ' + avg.toLocaleString();
        }

        /* Empty state */
        if (emptyState) emptyState.style.display = (n === 0) ? 'block' : 'none';
    }

    /* ══════════════════════════════════════════════════
       Search / City / Type handlers
    ══════════════════════════════════════════════════ */
    searchInput?.addEventListener('input', e => {
        searchQuery = e.target.value.toLowerCase().trim();
        applyFilters();
    });

    cityInput?.addEventListener('input', e => {
        cityQuery = e.target.value.toLowerCase().trim();
        applyFilters();
    });

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeType = btn.dataset.type;
            applyFilters();
        });
    });

    /* ══════════════════════════════════════════════════
       Price dropdown toggle
    ══════════════════════════════════════════════════ */
    triggerBtn?.addEventListener('click', e => {
        e.stopPropagation();
        const open = panel.classList.toggle('is-open');
        triggerBtn.classList.toggle('is-open', open);
    });

    document.addEventListener('click', e => {
        if (panel?.classList.contains('is-open') && !panel.contains(e.target) && !triggerBtn.contains(e.target)) {
            panel.classList.remove('is-open');
            triggerBtn.classList.remove('is-open');
        }
    });

    /* ══════════════════════════════════════════════════
       Slider / input sync — INSTANT filter on every drag
    ══════════════════════════════════════════════════ */
    if (!minSlider || !maxSlider) { applyFilters(); return; }

    function fmt(n) { return parseInt(n).toLocaleString(); }

    function updateVisuals() {
        const lo   = +minSlider.value;
        const hi   = +maxSlider.value;
        const span = CEIL - FLOOR || 1;

        if (fillEl) {
            fillEl.style.left  = ((lo - FLOOR) / span * 100) + '%';
            fillEl.style.width = ((hi - lo)    / span * 100) + '%';
        }

        bars.forEach((bar, i) => {
            const bLo = FLOOR + (i     / bucketCount) * span;
            const bHi = FLOOR + ((i+1) / bucketCount) * span;
            bar.classList.toggle('sel', bHi > lo && bLo < hi);
        });

        if (labelEl) labelEl.textContent = 'NPR ' + fmt(lo) + '  –  NPR ' + fmt(hi);
    }

    function onSlider() {
        let lo = +minSlider.value;
        let hi = +maxSlider.value;
        if (lo > hi - 500) { lo = hi - 500; minSlider.value = lo; }
        if (minInput) minInput.value = lo;
        if (maxInput) maxInput.value = hi;
        activeMin = lo;
        activeMax = hi;
        updateVisuals();
        applyFilters();
        updateBtnLabel();
    }

    function onNumberInput() {
        let lo = +(minInput?.value ?? FLOOR);
        let hi = +(maxInput?.value ?? CEIL);
        lo = Math.max(FLOOR, Math.min(lo, CEIL));
        hi = Math.max(FLOOR, Math.min(hi, CEIL));
        if (lo > hi - 500) lo = hi - 500;
        minSlider.value = lo;
        maxSlider.value = hi;
        if (minInput) minInput.value = lo;
        if (maxInput) maxInput.value = hi;
        activeMin = lo;
        activeMax = hi;
        updateVisuals();
        applyFilters();
        updateBtnLabel();
    }

    function updateBtnLabel() {
        const isDefault = activeMin <= FLOOR && activeMax >= CEIL;
        if (labelSpan) {
            labelSpan.textContent = isDefault
                ? 'Price Range'
                : 'NPR ' + fmt(activeMin) + ' – ' + fmt(activeMax);
        }
        triggerBtn?.classList.toggle('has-filter', !isDefault);
    }

    resetBtn?.addEventListener('click', () => {
        minSlider.value = FLOOR;
        maxSlider.value = CEIL;
        if (minInput) minInput.value = FLOOR;
        if (maxInput) maxInput.value = CEIL;
        activeMin = FLOOR;
        activeMax = CEIL;
        updateVisuals();
        applyFilters();
        updateBtnLabel();
    });

    minSlider.addEventListener('input', onSlider);
    maxSlider.addEventListener('input', onSlider);
    minInput?.addEventListener('change', onNumberInput);
    maxInput?.addEventListener('change', onNumberInput);

    /* ══════════════════════════════════════════════════
       SORT
    ══════════════════════════════════════════════════ */
    const sortBtn   = document.getElementById('fleet-sort-btn');
    const sortPanel = document.getElementById('fleet-sort-panel');
    const sortLabel = document.getElementById('fleet-sort-label');
    const sortIcon  = document.getElementById('fleet-sort-icon');
    const sortOpts  = document.querySelectorAll('.fleet-sort-option');
    let   activeSort = 'default';

    /* Toggle sort dropdown */
    sortBtn?.addEventListener('click', e => {
        e.stopPropagation();
        const open = sortPanel.classList.toggle('is-open');
        sortBtn.classList.toggle('is-open', open);
    });

    /* Close sort dropdown when clicking outside */
    document.addEventListener('click', e => {
        if (sortPanel?.classList.contains('is-open') &&
            !sortPanel.contains(e.target) && !sortBtn.contains(e.target)) {
            sortPanel.classList.remove('is-open');
            sortBtn.classList.remove('is-open');
        }
    });

    const sortIconMap = {
        'default'   : 'fas fa-arrow-up-wide-short',
        'price-asc' : 'fas fa-arrow-up',
        'price-desc': 'fas fa-arrow-down',
        'name-asc'  : 'fas fa-arrow-down-a-z',
        'name-desc' : 'fas fa-arrow-up-z-a'
    };

    const sortLabelMap = {
        'default'   : 'Sort by',
        'price-asc' : 'Price: Low → High',
        'price-desc': 'Price: High → Low',
        'name-asc'  : 'Name: A → Z',
        'name-desc' : 'Name: Z → A'
    };

    function applySort() {
        if (!grid) return;

        if (activeSort === 'default') {
            originalOrder.forEach(c => grid.appendChild(c));
        } else {
            const cardEls = Array.from(grid.querySelectorAll('.fleet-vehicle-card'));
            cardEls.sort((a, b) => {
                const pa = +a.dataset.price || 0;
                const pb = +b.dataset.price || 0;
                const na = (a.dataset.name || '').toLowerCase();
                const nb = (b.dataset.name || '').toLowerCase();
                if (activeSort === 'price-asc')  return pa - pb;
                if (activeSort === 'price-desc') return pb - pa;
                if (activeSort === 'name-asc')   return na.localeCompare(nb);
                if (activeSort === 'name-desc')  return nb.localeCompare(na);
                return 0;
            });
            cardEls.forEach(c => grid.appendChild(c));
        }

        applyFilters();
    }

    sortOpts.forEach(opt => {
        opt.addEventListener('click', () => {
            activeSort = opt.dataset.sort;

            sortOpts.forEach(o => o.classList.remove('active'));
            opt.classList.add('active');

            if (sortLabel) sortLabel.textContent = sortLabelMap[activeSort];
            if (sortIcon)  sortIcon.className    = sortIconMap[activeSort];
            sortBtn?.classList.toggle('has-filter', activeSort !== 'default');

            sortPanel.classList.remove('is-open');
            sortBtn.classList.remove('is-open');

            applySort();
        });
    });

    /* Initial render */
    updateVisuals();
    applyFilters();

})();