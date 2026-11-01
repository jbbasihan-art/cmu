/* fade.js - shared CMU page transition for all pages
 *
 * Replaces the plain fade with a branded transition that matches the
 * CMU Campus Navigator loading screen: a navy panel with the CMU logo
 * and a gold loading bar that wipes over the page when you leave it,
 * and wipes away when the next page is ready.
 *
 * Add inside <head> (no defer):
 *   Root pages:  <script src="fade.js"></script>
 *   Admin pages: <script src="../fade.js"></script>
 */
(() => {
  const CONFIG = {
    wipe: 450,            // wipe speed (ms)
    minShow: 350,         // shortest time the panel stays on a new page (ms)
    maxWait: 2000,        // the panel always leaves after this long (ms)

    // Brand colors
    colorTop: '#0b3b82',
    colorBottom: '#082d63',
    accent: '#ffdf45',

    // Panel content (paths are relative to fade.js, the project root)
    logo: 'cmu logo.jpg',
    title: 'CMU Campus Navigator',
    subtitle: 'City of Malabon University',

    // Pages that already have their own loading screen. The wipe is not
    // played when going to or arriving on these pages, so only one
    // animation runs at a time.
    ownLoaderPages: ['index.php', 'campus-map.php', 'University-info.php'],

    // Background slideshow (skipped on pages with <body data-no-bg>)
    bgFade: 1500,
    bgInterval: 5000,
    overlay: 'rgba(0,0,0,0.4)',
    images: [
      'cmu-new-building-inspection.webp',
      'cmu-logo.jpg'
    ]
  };

  const root = document.documentElement;
  const base = document.currentScript ? document.currentScript.src : location.href;
  const reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let curtain = null;
  let revealed = false;
  let leaving = false;

  // File name of a path ("/hackaton/" counts as index.php)
  const pageName = path => (path.split('/').pop() || 'index.php').toLowerCase();

  const hasOwnLoader = path =>
    CONFIG.ownLoaderPages.some(name => name.toLowerCase() === pageName(path));

  /* ---------- Transition panel ---------- */

  const setState = state => {
    curtain.classList.remove('tr-cover', 'tr-below', 'tr-above');
    curtain.classList.add('tr-' + state);
  };

  const build = () => {
    const style = document.createElement('style');
    style.textContent = `
      #cmu-transition {
        position: fixed;
        inset: 0;
        z-index: 2147483000;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: linear-gradient(180deg, ${CONFIG.colorTop}, ${CONFIG.colorBottom});
        font-family: Arial, Helvetica, sans-serif;
        will-change: transform;
      }
      #cmu-transition.tr-run {
        transition: transform ${CONFIG.wipe}ms cubic-bezier(0.77, 0, 0.18, 1);
      }
      #cmu-transition.tr-cover { transform: translateY(0); }
      #cmu-transition.tr-below { transform: translateY(100%); }
      #cmu-transition.tr-above { transform: translateY(-100%); }
      #cmu-transition.tr-off   { visibility: hidden; pointer-events: none; }

      #cmu-transition::before,
      #cmu-transition::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        height: 5px;
        background: ${CONFIG.accent};
      }
      #cmu-transition::before { top: 0; }
      #cmu-transition::after  { bottom: 0; }

      #cmu-transition .tr-logo {
        width: 96px;
        height: 96px;
        padding: 6px;
        background: #ffffff;
        border: 3px solid ${CONFIG.accent};
        border-radius: 50%;
        overflow: hidden;
      }
      #cmu-transition .tr-logo img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
      #cmu-transition .tr-title {
        margin-top: 20px;
        color: #ffffff;
        font-size: 20px;
        font-weight: 800;
        letter-spacing: 1px;
        text-align: center;
      }
      #cmu-transition .tr-sub {
        margin-top: 6px;
        color: #ffffff;
        font-size: 13px;
        text-align: center;
      }
      #cmu-transition .tr-bar {
        width: 180px;
        height: 4px;
        margin-top: 22px;
        overflow: hidden;
        background: rgba(255, 255, 255, 0.25);
        border-radius: 4px;
      }
      #cmu-transition .tr-bar span {
        display: block;
        width: 40%;
        height: 100%;
        background: ${CONFIG.accent};
        border-radius: 4px;
        animation: cmu-tr-slide 0.9s ease-in-out infinite;
      }
      @keyframes cmu-tr-slide {
        from { transform: translateX(-100%); }
        to   { transform: translateX(250%); }
      }
    `;
    (document.head || root).appendChild(style);

    curtain = document.createElement('div');
    curtain.id = 'cmu-transition';
    curtain.setAttribute('aria-hidden', 'true');
    curtain.className = 'tr-cover';          // covers the page right away

    const logoSrc = new URL(CONFIG.logo, base).href;
    curtain.innerHTML = `
      <div class="tr-logo"><img src="${logoSrc}" alt=""></div>
      <div class="tr-title">${CONFIG.title}</div>
      <div class="tr-sub">${CONFIG.subtitle}</div>
      <div class="tr-bar"><span></span></div>
    `;

    // Hide the logo circle if the image path is wrong
    const img = curtain.querySelector('img');
    img.addEventListener('error', () => {
      curtain.querySelector('.tr-logo').style.display = 'none';
    });

    // This page has its own loading screen: keep the wipe panel out of the way
    if (hasOwnLoader(location.pathname)) {
      revealed = true;
      curtain.className = 'tr-above tr-off';
    }

    root.appendChild(curtain);
  };

  // Wipe the panel away to reveal the page
  const reveal = () => {
    if (!curtain || revealed) return;
    revealed = true;
    curtain.classList.add('tr-run');
    setState('above');
    setTimeout(() => curtain.classList.add('tr-off'), CONFIG.wipe + 60);
  };

  // Hide the panel immediately (no animation)
  const hideNow = () => {
    if (!curtain) return;
    revealed = true;
    leaving = false;
    curtain.classList.remove('tr-run');
    setState('above');
    curtain.classList.add('tr-off');
  };

  if (!reduceMotion) {
    build();

    const startedAt = Date.now();
    const revealSoon = () => {
      const wait = Math.max(0, CONFIG.minShow - (Date.now() - startedAt));
      setTimeout(reveal, wait);
    };

    document.addEventListener('DOMContentLoaded', () => {
      // Pages that already have their own #cmu-loader screen
      // (Home, University Info) skip the enter wipe to avoid two loaders.
      if (document.getElementById('cmu-loader')) hideNow();
    });

    if (document.readyState === 'complete') {
      revealSoon();
    } else {
      window.addEventListener('load', revealSoon, { once: true });
    }

    // Safety: the panel can never stay on screen
    setTimeout(reveal, CONFIG.maxWait);

    // Back/forward button restores the page from cache
    window.addEventListener('pageshow', e => {
      if (e.persisted) hideNow();
    });

    // Wipe over the page when clicking an internal link
    document.addEventListener('click', e => {
      const a = e.target.closest && e.target.closest('a[href]');
      if (!a || e.defaultPrevented) return;          // respects confirm() cancels
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
      if (a.target === '_blank' || a.hasAttribute('download')) return;

      const url = new URL(a.href, location.href);
      if (url.origin !== location.origin) return;     // external link
      if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
      if (url.pathname === location.pathname &&
          url.search === location.search && url.hash) return; // #anchor

      // The destination has its own loading screen: let it play alone
      if (hasOwnLoader(url.pathname)) return;

      e.preventDefault();
      if (leaving) return;
      leaving = true;

      curtain.classList.remove('tr-run', 'tr-off');
      setState('below');                 // start below the screen
      void curtain.offsetHeight;         // apply it before animating
      curtain.classList.add('tr-run');
      setState('cover');                 // slide up over the page

      setTimeout(() => { location.href = a.href; }, CONFIG.wipe + 30);
    });
  }

  /* ---------- Background slideshow (skip on <body data-no-bg>) ---------- */

  document.addEventListener('DOMContentLoaded', () => {
    if (document.body.hasAttribute('data-no-bg') || !CONFIG.images.length) return;

    const layers = CONFIG.images.map((src, i) => {
      const d = document.createElement('div');
      Object.assign(d.style, {
        position: 'fixed', inset: '0', zIndex: '-2', pointerEvents: 'none',
        backgroundImage: `url("${new URL(src, base).href}")`,
        backgroundSize: 'cover', backgroundPosition: 'center',
        opacity: i === 0 ? '1' : '0',
        transition: `opacity ${CONFIG.bgFade}ms ease-in-out`
      });
      return d;
    });

    const overlay = document.createElement('div');
    Object.assign(overlay.style, {
      position: 'fixed', inset: '0', zIndex: '-1',
      pointerEvents: 'none', background: CONFIG.overlay
    });

    document.body.prepend(overlay, ...layers);

    if (layers.length < 2) return;
    let i = 0;
    setInterval(() => {
      layers[i].style.opacity = '0';
      i = (i + 1) % layers.length;
      layers[i].style.opacity = '1';
    }, CONFIG.bgInterval);
  });
})();