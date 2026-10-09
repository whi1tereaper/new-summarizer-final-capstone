/**
 * nex-landing.js — LIGHT editorial landing page interactions
 *
 * Responsibilities:
 *   1. Sticky header nav-theme switching (IntersectionObserver)
 *   2. Smooth-scroll for anchor nav links
 *   3. Scroll-reveal for .nex-reveal and .nex-reveal-scale
 *   4. Subtle hero sculpture parallax (rAF, max 40px travel)
 *   5. Drag-over drop-zone feedback
 *   6. File input filename display
 *   7. Back to top button
 *
 * No scroll-hijacking. No wheel preventDefault(). Native scrolling always.
 */
(function () {
  'use strict';

  /* ----------------------------------------------------------------
     Helper: run after DOM is ready
  ---------------------------------------------------------------- */
  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  /* ----------------------------------------------------------------
     1. Header nav-theme switching
     Watches each section with data-nav-theme="light|dark|accent" and
     sets data-active-theme on .nex-header.
  ---------------------------------------------------------------- */
  function initHeaderTheme() {
    const header = document.querySelector('.nex-header');
    if (!header) return;

    const sections = document.querySelectorAll('[data-nav-theme]');
    if (!sections.length) return;

    // We use a map keyed by section element → its theme
    const themeMap = new WeakMap();
    const visible = new Set();

    sections.forEach(function (section) {
      themeMap.set(section, section.dataset.navTheme || 'light');
    });

    function applyTheme() {
      // Pick the theme of the topmost visible section
      let theme = 'light';
      let smallestTop = Infinity;
      visible.forEach(function (el) {
        const rect = el.getBoundingClientRect();
        if (rect.top < smallestTop) {
          smallestTop = rect.top;
          theme = themeMap.get(el) || 'light';
        }
      });
      if (header.dataset.activeTheme !== theme) {
        header.dataset.activeTheme = theme;
      }
    }

    const observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            visible.add(entry.target);
          } else {
            visible.delete(entry.target);
          }
        });
        applyTheme();
      },
      { threshold: 0, rootMargin: '-1px 0px -1px 0px' }
    );

    sections.forEach(function (section) {
      observer.observe(section);
    });

    // Initial state
    applyTheme();
  }

  /* ----------------------------------------------------------------
     2. Smooth-scroll for anchor links (no scroll-jacking)
  ---------------------------------------------------------------- */
  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (!href || href === '#') return;
        const target = document.querySelector(href);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    // Back to top buttons
    document.querySelectorAll('.js-back-top').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  }

  /* ----------------------------------------------------------------
     3. Scroll reveal (IntersectionObserver, no rAF loop)
  ---------------------------------------------------------------- */
  function initScrollReveal() {
    // Respect reduced-motion preference
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    const revealItems = document.querySelectorAll('.nex-reveal, .nex-reveal-scale');
    if (!revealItems.length) return;

    const observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }
    );

    revealItems.forEach(function (el) {
      observer.observe(el);
    });
  }

  /* ----------------------------------------------------------------
     4. Hero sculpture parallax (subtle, max 40px, rAF)
  ---------------------------------------------------------------- */
  function initParallax() {
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    const sculpture = document.querySelector('.nex-hero__sculpture');
    if (!sculpture) return;

    let ticking = false;
    let lastScrollY = 0;

    function update() {
      const heroEl = document.querySelector('.nex-hero');
      if (!heroEl) return;
      const heroHeight = heroEl.offsetHeight;
      const progress = Math.min(lastScrollY / heroHeight, 1);
      // Max 40px upward movement
      const translateY = -(progress * 40);
      sculpture.style.transform = 'translate(-50%, calc(-50% + ' + translateY + 'px))';
      ticking = false;
    }

    window.addEventListener('scroll', function () {
      lastScrollY = window.scrollY;
      if (!ticking) {
        window.requestAnimationFrame(update);
        ticking = true;
      }
    }, { passive: true });
  }

  /* ----------------------------------------------------------------
     5. Drag-over feedback on drop zone
  ---------------------------------------------------------------- */
  function initDropZone() {
    const dropZones = document.querySelectorAll('.nex-drop-zone');
    if (!dropZones.length) return;

    dropZones.forEach(function (dropZone) {
      const fileInput = dropZone.querySelector('input#pdf') || document.querySelector('input#pdf');
      const filenameEl = dropZone.querySelector('.nex-drop-zone__filename');
      const fileRow = dropZone.querySelector('.nex-drop-zone__file-row');
      const replaceBtn = dropZone.querySelector('.nex-drop-zone__replace');
      const removeBtn = dropZone.querySelector('.nex-drop-zone__remove');
      const hintEl = dropZone.querySelector('.nex-drop-zone__hint');
      const defaultHintHtml = hintEl ? hintEl.innerHTML : 'PDF / DOCX — <span class="nex-drop-zone__action">click or drag to upload</span>';

      ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
          e.preventDefault();
          dropZone.classList.add('is-drag-over');
          if (hintEl && !dropZone.classList.contains('has-file')) {
            hintEl.textContent = 'DROP FILE TO UPLOAD';
          }
        });
      });

      ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function () {
          dropZone.classList.remove('is-drag-over');
          if (hintEl && !dropZone.classList.contains('has-file')) {
            hintEl.innerHTML = defaultHintHtml;
          }
        });
      });

      // Show filename after selection
      if (fileInput) {
        fileInput.addEventListener('change', function () {
          const file = this.files && this.files[0];
          if (file) {
            if (filenameEl) filenameEl.textContent = file.name;
            if (fileRow) fileRow.hidden = false;
            else if (filenameEl) filenameEl.hidden = false;
            dropZone.classList.add('has-file');
            if (hintEl) hintEl.innerHTML = 'PDF / DOCX selected — <span class="nex-drop-zone__action">click to replace</span>';
          } else {
            if (filenameEl) filenameEl.textContent = '';
            if (fileRow) fileRow.hidden = true;
            else if (filenameEl) filenameEl.hidden = true;
            dropZone.classList.remove('has-file');
            if (hintEl) hintEl.innerHTML = defaultHintHtml;
          }
        });
      }

      if (removeBtn && fileInput) {
        removeBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          fileInput.value = '';
          if (filenameEl) filenameEl.textContent = '';
          if (fileRow) fileRow.hidden = true;
          else if (filenameEl) filenameEl.hidden = true;
          dropZone.classList.remove('has-file');
          if (hintEl) hintEl.innerHTML = defaultHintHtml;
          fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }

      if (replaceBtn && fileInput) {
        replaceBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          fileInput.click();
        });
      }
    });
  }

  /* ----------------------------------------------------------------
     6. Footer "LIGHT" Mesh Text Hover Effect
     Physical spring deformation, cursor tracking, localized proximity,
     dynamic transform-origin, and subtle chromatic separation.
     Pure Vanilla JS + CSS Variables. No WebGL, no Canvas, no dependencies.
  ---------------------------------------------------------------- */
  function initFooterMeshEffect() {
    const brand = document.getElementById('footer-light-brand') ||
                  document.querySelector('.nex-footer__giant-brand, .site-footer__brand');
    if (!brand || brand.__meshInitialized) return;
    brand.__meshInitialized = true;

    // Respect user's motion preferences
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    const rawText = (brand.textContent || '').trim();
    if (!rawText) return;

    // Preserve semantic accessibility while splitting letters for localized deformation
    brand.setAttribute('role', 'img');
    brand.setAttribute('aria-label', rawText);
    brand.textContent = '';

    const letters = [];
    for (let i = 0; i < rawText.length; i++) {
      const span = document.createElement('span');
      span.className = 'mesh-letter';
      span.setAttribute('aria-hidden', 'true');
      span.textContent = rawText[i];
      brand.appendChild(span);

      letters.push({
        el: span,
        curX: 0,
        velX: 0,
        curY: 0,
        velY: 0,
        curScale: 1,
        velScale: 0,
        curSkew: 0,
        velSkew: 0,
      });
    }

    // Geometry cache (never queried inside rAF tick)
    let brandRect = null;
    let letterOffsets = [];
    let brandPageX = 0;
    let brandPageY = 0;

    function updateBounds() {
      if (!brand) return;
      const rect = brand.getBoundingClientRect();
      brandRect = rect;
      brandPageX = rect.left + window.scrollX;
      brandPageY = rect.top + window.scrollY;

      letterOffsets = letters.map(function (item) {
        const lRect = item.el.getBoundingClientRect();
        return {
          cx: (lRect.left - rect.left) + lRect.width / 2,
          cy: (lRect.top - rect.top) + lRect.height / 2,
        };
      });
    }

    // Initial bounds measurement
    updateBounds();

    // Physics parameters
    const SPRING = 0.08;         // Spring stiffness (k)
    const DAMPING = 0.88;        // Damping factor (underdamped for natural bounce)
    const LETTER_SPRING = 0.09;
    const LETTER_DAMPING = 0.86;

    // Interaction state
    let isInside = false;
    let lastTime = 0;
    let lastPx = 0;
    let lastPy = 0;
    let smoothVx = 0;
    let smoothVy = 0;
    let currentSpeed = 0;
    let pointerRelX = 0;
    let pointerRelY = 0;

    // Word spring state
    let curDx = 0;
    let velDx = 0;
    let curDy = 0;
    let velDy = 0;
    let curScaleX = 1;
    let velScaleX = 0;
    let curScaleY = 1;
    let velScaleY = 0;
    let curSkewX = 0;
    let velSkewX = 0;
    let curSkewY = 0;
    let velSkewY = 0;

    // Dynamic transform origin (smoothly follows cursor)
    let curOriginX = 50;
    let curOriginY = 50;
    let targetOriginX = 50;
    let targetOriginY = 50;

    // Chromatic separation spring state
    let curChromaX = 0;
    let velChromaX = 0;
    let curChromaY = 0;
    let velChromaY = 0;
    let curChromaA = 0;
    let velChromaA = 0;

    let isAnimating = false;

    function startAnimation() {
      if (!isAnimating) {
        isAnimating = true;
        window.requestAnimationFrame(tick);
      }
    }

    function onPointerEnter(e) {
      updateBounds();
      isInside = true;
      lastTime = performance.now();
      const px = e.pageX - brandPageX;
      const py = e.pageY - brandPageY;
      lastPx = px;
      lastPy = py;
      pointerRelX = px;
      pointerRelY = py;
      startAnimation();
    }

    function onPointerMove(e) {
      const now = performance.now();
      const px = e.pageX - brandPageX;
      const py = e.pageY - brandPageY;
      const dt = Math.max(now - lastTime, 8);

      const rawVx = ((px - lastPx) / dt) * 16.67;
      const rawVy = ((py - lastPy) / dt) * 16.67;

      // Low-pass filter for smooth motion without jitter
      smoothVx = smoothVx * 0.55 + rawVx * 0.45;
      smoothVy = smoothVy * 0.55 + rawVy * 0.45;

      // Restrained clamp
      smoothVx = Math.max(-32, Math.min(32, smoothVx));
      smoothVy = Math.max(-22, Math.min(22, smoothVy));
      currentSpeed = Math.hypot(smoothVx, smoothVy);

      pointerRelX = px;
      pointerRelY = py;
      lastPx = px;
      lastPy = py;
      lastTime = now;

      if (brandRect && brandRect.width > 0 && brandRect.height > 0) {
        targetOriginX = Math.max(10, Math.min(90, (px / brandRect.width) * 100));
        targetOriginY = Math.max(15, Math.min(85, (py / brandRect.height) * 100));
      }

      startAnimation();
    }

    function onPointerLeave() {
      isInside = false;
      targetOriginX = 50;
      targetOriginY = 50;
      startAnimation();
    }

    function onPointerDown(e) {
      if (e.pointerType === 'touch') {
        updateBounds();
        const px = e.pageX - brandPageX;
        const py = e.pageY - brandPageY;
        pointerRelX = px;
        pointerRelY = py;
        if (brandRect && brandRect.width > 0 && brandRect.height > 0) {
          targetOriginX = Math.max(10, Math.min(90, (px / brandRect.width) * 100));
          targetOriginY = Math.max(15, Math.min(85, (py / brandRect.height) * 100));
        }
        smoothVy = 10;
        currentSpeed = 10;
        isInside = true;
        startAnimation();
        setTimeout(function () {
          isInside = false;
        }, 90);
      }
    }

    function tick(timestamp) {
      // Natural decay when cursor is stationary within element
      const timeSinceMove = timestamp - lastTime;
      if (timeSinceMove > 32) {
        smoothVx *= 0.84;
        smoothVy *= 0.84;
        if (Math.abs(smoothVx) < 0.05) smoothVx = 0;
        if (Math.abs(smoothVy) < 0.05) smoothVy = 0;
        currentSpeed = Math.hypot(smoothVx, smoothVy);
      }

      // Smooth transform-origin interpolation
      curOriginX += (targetOriginX - curOriginX) * 0.16;
      curOriginY += (targetOriginY - curOriginY) * 0.16;

      const w = (brandRect && brandRect.width) || 400;
      const h = (brandRect && brandRect.height) || 120;
      const normX = Math.max(-1, Math.min(1, (pointerRelX / w) * 2 - 1));

      // Calculate target physical deformation
      let targetDx = 0;
      let targetDy = 0;
      let targetScaleX = 1;
      let targetScaleY = 1;
      let targetSkewX = 0;
      let targetSkewY = 0;
      let targetChromaX = 0;
      let targetChromaY = 0;
      let targetChromaA = 0;

      if (isInside) {
        targetDx = Math.max(-14, Math.min(14, smoothVx * 0.42));
        targetDy = Math.max(-10, Math.min(10, smoothVy * 0.32));

        targetScaleX = 1 + Math.min(0.045, Math.abs(smoothVx) * 0.0022);
        targetScaleY = 1 - Math.min(0.025, Math.abs(smoothVx) * 0.0012);
        if (Math.abs(smoothVy) > Math.abs(smoothVx)) {
          targetScaleY = 1 + Math.min(0.038, Math.abs(smoothVy) * 0.002);
          targetScaleX = 1 - Math.min(0.018, Math.abs(smoothVy) * 0.001);
        }

        targetSkewX = Math.max(-4, Math.min(4, smoothVx * -0.12 + normX * 1.2));
        targetSkewY = Math.max(-2, Math.min(2, smoothVy * 0.06));

        // Subtle chromatic separation proportional to cursor velocity
        targetChromaX = Math.max(-4.5, Math.min(4.5, smoothVx * 0.22));
        targetChromaY = Math.max(-2.5, Math.min(2.5, smoothVy * 0.12));
        targetChromaA = Math.min(0.55, currentSpeed / 16);
      }

      // Euler integration for word spring
      velDx += (targetDx - curDx) * SPRING;
      velDx *= DAMPING;
      curDx += velDx;

      velDy += (targetDy - curDy) * SPRING;
      velDy *= DAMPING;
      curDy += velDy;

      velScaleX += (targetScaleX - curScaleX) * SPRING;
      velScaleX *= DAMPING;
      curScaleX += velScaleX;

      velScaleY += (targetScaleY - curScaleY) * SPRING;
      velScaleY *= DAMPING;
      curScaleY += velScaleY;

      velSkewX += (targetSkewX - curSkewX) * SPRING;
      velSkewX *= DAMPING;
      curSkewX += velSkewX;

      velSkewY += (targetSkewY - curSkewY) * SPRING;
      velSkewY *= DAMPING;
      curSkewY += velSkewY;

      velChromaX += (targetChromaX - curChromaX) * SPRING;
      velChromaX *= DAMPING;
      curChromaX += velChromaX;

      velChromaY += (targetChromaY - curChromaY) * SPRING;
      velChromaY *= DAMPING;
      curChromaY += velChromaY;

      velChromaA += (targetChromaA - curChromaA) * 0.14;
      velChromaA *= 0.82;
      curChromaA = Math.max(0, curChromaA + velChromaA);

      // Write CSS variables on brand container
      brand.style.setProperty('--mesh-origin-x', curOriginX.toFixed(2) + '%');
      brand.style.setProperty('--mesh-origin-y', curOriginY.toFixed(2) + '%');
      brand.style.setProperty('--mesh-tx', curDx.toFixed(2) + 'px');
      brand.style.setProperty('--mesh-ty', curDy.toFixed(2) + 'px');
      brand.style.setProperty('--mesh-scale-x', curScaleX.toFixed(4));
      brand.style.setProperty('--mesh-scale-y', curScaleY.toFixed(4));
      brand.style.setProperty('--mesh-skew-x', curSkewX.toFixed(2) + 'deg');
      brand.style.setProperty('--mesh-skew-y', curSkewY.toFixed(2) + 'deg');
      brand.style.setProperty('--mesh-cx', curChromaX.toFixed(2) + 'px');
      brand.style.setProperty('--mesh-cy', curChromaY.toFixed(2) + 'px');
      brand.style.setProperty('--mesh-ca', curChromaA.toFixed(3));

      // Localized letter proximity force
      const radius = Math.max(90, Math.min(220, w * 0.26));
      let allLettersSettled = true;

      letters.forEach(function (item, idx) {
        const offset = letterOffsets[idx] || { cx: 0, cy: 0 };
        const dist = Math.hypot(pointerRelX - offset.cx, pointerRelY - offset.cy);
        const prox = isInside ? (1 / (1 + Math.pow(dist / radius, 2))) : 0;

        const lTargetX = isInside ? Math.max(-10, Math.min(10, smoothVx * 0.35 * prox)) : 0;
        const lTargetY = isInside ? Math.max(-7, Math.min(7, smoothVy * 0.25 * prox)) : 0;
        const lTargetScale = isInside ? (1 + Math.min(0.04, (currentSpeed / 28) * prox * 0.03)) : 1;
        const lTargetSkew = isInside ? Math.max(-2.5, Math.min(2.5, smoothVx * -0.08 * prox)) : 0;

        item.velX += (lTargetX - item.curX) * LETTER_SPRING;
        item.velX *= LETTER_DAMPING;
        item.curX += item.velX;

        item.velY += (lTargetY - item.curY) * LETTER_SPRING;
        item.velY *= LETTER_DAMPING;
        item.curY += item.velY;

        item.velScale += (lTargetScale - item.curScale) * LETTER_SPRING;
        item.velScale *= LETTER_DAMPING;
        item.curScale += item.velScale;

        item.velSkew += (lTargetSkew - item.curSkew) * LETTER_SPRING;
        item.velSkew *= LETTER_DAMPING;
        item.curSkew += item.velSkew;

        item.el.style.setProperty('--letter-dx', item.curX.toFixed(2) + 'px');
        item.el.style.setProperty('--letter-dy', item.curY.toFixed(2) + 'px');
        item.el.style.setProperty('--letter-scale', item.curScale.toFixed(4));
        item.el.style.setProperty('--letter-skew', item.curSkew.toFixed(2) + 'deg');

        if (Math.abs(item.curX) > 0.02 || Math.abs(item.velX) > 0.02 ||
            Math.abs(item.curY) > 0.02 || Math.abs(item.velY) > 0.02 ||
            Math.abs(item.curScale - 1) > 0.001 || Math.abs(item.velScale) > 0.001 ||
            Math.abs(item.curSkew) > 0.02 || Math.abs(item.velSkew) > 0.02) {
          allLettersSettled = false;
        }
      });

      // Settle check: stop rAF completely when at rest
      const isWordSettled =
        Math.abs(curDx) < 0.02 && Math.abs(velDx) < 0.02 &&
        Math.abs(curDy) < 0.02 && Math.abs(velDy) < 0.02 &&
        Math.abs(curScaleX - 1) < 0.001 && Math.abs(velScaleX) < 0.001 &&
        Math.abs(curScaleY - 1) < 0.001 && Math.abs(velScaleY) < 0.001 &&
        Math.abs(curSkewX) < 0.02 && Math.abs(velSkewX) < 0.02 &&
        curChromaA < 0.008 && Math.abs(curChromaX) < 0.02 &&
        Math.abs(curOriginX - 50) < 0.1 && Math.abs(curOriginY - 50) < 0.1;

      if (!isInside && isWordSettled && allLettersSettled) {
        // Reset exact resting state
        brand.style.setProperty('--mesh-origin-x', '50%');
        brand.style.setProperty('--mesh-origin-y', '50%');
        brand.style.setProperty('--mesh-tx', '0px');
        brand.style.setProperty('--mesh-ty', '0px');
        brand.style.setProperty('--mesh-scale-x', '1');
        brand.style.setProperty('--mesh-scale-y', '1');
        brand.style.setProperty('--mesh-skew-x', '0deg');
        brand.style.setProperty('--mesh-skew-y', '0deg');
        brand.style.setProperty('--mesh-cx', '0px');
        brand.style.setProperty('--mesh-cy', '0px');
        brand.style.setProperty('--mesh-ca', '0');

        letters.forEach(function (item) {
          item.curX = 0;
          item.velX = 0;
          item.curY = 0;
          item.velY = 0;
          item.curScale = 1;
          item.velScale = 0;
          item.curSkew = 0;
          item.velSkew = 0;
          item.el.style.setProperty('--letter-dx', '0px');
          item.el.style.setProperty('--letter-dy', '0px');
          item.el.style.setProperty('--letter-scale', '1');
          item.el.style.setProperty('--letter-skew', '0deg');
        });

        curDx = 0; velDx = 0;
        curDy = 0; velDy = 0;
        curScaleX = 1; velScaleX = 0;
        curScaleY = 1; velScaleY = 0;
        curSkewX = 0; velSkewX = 0;
        curSkewY = 0; velSkewY = 0;
        curChromaX = 0; velChromaX = 0;
        curChromaY = 0; velChromaY = 0;
        curChromaA = 0; velChromaA = 0;
        curOriginX = 50; curOriginY = 50;

        isAnimating = false;
        return;
      }

      window.requestAnimationFrame(tick);
    }

    brand.addEventListener('pointerenter', onPointerEnter, { passive: true });
    brand.addEventListener('pointermove', onPointerMove, { passive: true });
    brand.addEventListener('pointerleave', onPointerLeave, { passive: true });
    brand.addEventListener('pointerdown', onPointerDown, { passive: true });
    window.addEventListener('resize', updateBounds, { passive: true });
  }

  /* ----------------------------------------------------------------
     Init
  ---------------------------------------------------------------- */
  ready(function () {
    initHeaderTheme();
    initSmoothScroll();
    initScrollReveal();
    initParallax();
    initDropZone();
  });

})();
