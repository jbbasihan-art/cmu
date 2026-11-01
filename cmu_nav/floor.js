/*
 * floor.js — animations used ONLY by admin/floor-layout.php
 * (same style as campus.js on the Campus Map page)
 *
 * What it does when the Floor Layout page opens:
 *   1. Shows a "Loading Floor Layout…" screen (spinner + progress bar)
 *   2. Fades the loading screen out once the page has loaded
 *   3. Animates the page in: back link, heading, floor buttons, panel,
 *      and the rooms (one by one)
 *
 * Add it in the <head> of floor-layout.php (replaces ../fade.js):
 *   <script src="../floor.js"></script>
 *
 * Tweak the timings here:
 */
(function () {
    "use strict";

    var MIN_LOADING_MS = 1100;    // shortest time the loading screen stays visible
    var MAX_LOADING_MS = 6000;    // safety: never keep the loading screen longer than this
    var ROOM_STAGGER_MS = 80;     // delay between each room popping in
    var RISE_STAGGER_MS = 110;    // delay between back link / heading / floors / panel

    var prefersReducedMotion =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* Respect "reduce motion": no loading screen, no entrance animation */
    if (prefersReducedMotion) {
        return;
    }

    var root = document.documentElement;
    var startedAt = performance.now();
    var finished = false;
    var loader = null;

    /* ---------- styles (kept inside this file so floor-layout.php stays clean) ---------- */

    var css = [
        "html.cm-loading { overflow: hidden; }",

        "#cmLoader {",
        "  position: fixed; inset: 0; z-index: 100000;",
        "  display: flex; flex-direction: column; align-items: center; justify-content: center;",
        "  gap: 18px; background: #f3f6fb; color: #0b3b82;",
        "  font-family: Arial, Helvetica, sans-serif;",
        "  transition: opacity .5s ease, visibility .5s ease;",
        "}",
        "#cmLoader.cm-hide { opacity: 0; visibility: hidden; pointer-events: none; }",

        ".cm-spinner {",
        "  position: relative; width: 74px; height: 74px;",
        "}",
        ".cm-spinner::before, .cm-spinner::after {",
        "  content: ''; position: absolute; inset: 0; border-radius: 50%;",
        "  border: 5px solid transparent;",
        "}",
        ".cm-spinner::before {",
        "  border-top-color: #0b3b82; border-right-color: #0b3b82;",
        "  animation: cm-spin 1s linear infinite;",
        "}",
        ".cm-spinner::after {",
        "  inset: 12px; border-bottom-color: #d9a900; border-left-color: #d9a900;",
        "  animation: cm-spin 1.4s linear infinite reverse;",
        "}",

        ".cm-title { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: .3px; }",
        ".cm-sub { margin: -8px 0 0; color: #52627a; font-size: 13px; }",
        ".cm-dots span { animation: cm-blink 1.2s infinite both; }",
        ".cm-dots span:nth-child(2) { animation-delay: .2s; }",
        ".cm-dots span:nth-child(3) { animation-delay: .4s; }",

        ".cm-bar {",
        "  width: 220px; height: 6px; overflow: hidden;",
        "  border-radius: 6px; background: #d8e1ee;",
        "}",
        ".cm-bar i {",
        "  display: block; height: 100%; width: 0; border-radius: 6px;",
        "  background: linear-gradient(90deg, #0b3b82, #d9a900);",
        "  animation: cm-fill 1.1s cubic-bezier(.4, 0, .2, 1) forwards;",
        "}",

        /* entrance: rooms pop in. Uses the separate `scale` property so it
           does not fight the inline rotate() transform of each room. */
        ".floor-plan .room.cm-intro { animation: cm-pop .6s cubic-bezier(.2, .8, .3, 1) backwards; }",

        /* entrance: back link, heading, floor buttons, panel slide up */
        ".cm-rise { animation: cm-rise .6s cubic-bezier(.2, .8, .3, 1) backwards; }",

        "@keyframes cm-spin { to { transform: rotate(360deg); } }",
        "@keyframes cm-blink { 0%, 80%, 100% { opacity: .2; } 40% { opacity: 1; } }",
        "@keyframes cm-fill { from { width: 0; } to { width: 100%; } }",
        "@keyframes cm-pop {",
        "  0%   { opacity: 0; scale: .5; }",
        "  60%  { opacity: 1; scale: 1.08; }",
        "  100% { opacity: 1; scale: 1; }",
        "}",
        "@keyframes cm-rise {",
        "  from { opacity: 0; translate: 0 22px; }",
        "  to   { opacity: 1; translate: 0 0; }",
        "}"
    ].join("\n");

    var style = document.createElement("style");
    style.id = "cmAnimationStyles";
    style.textContent = css;
    (document.head || root).appendChild(style);

    /* ---------- loading screen ---------- */

    function createLoader() {
        loader = document.createElement("div");
        loader.id = "cmLoader";
        loader.setAttribute("role", "status");
        loader.setAttribute("aria-live", "polite");

        loader.innerHTML =
            '<div class="cm-spinner" aria-hidden="true"></div>' +
            '<p class="cm-title">Loading Floor Layout</p>' +
            '<p class="cm-sub">City of Malabon University' +
            '<span class="cm-dots" aria-hidden="true"> <span>.</span><span>.</span><span>.</span></span></p>' +
            '<div class="cm-bar" aria-hidden="true"><i></i></div>';

        /* <body> may not exist yet when this script runs in <head> */
        root.appendChild(loader);
        root.classList.add("cm-loading");
    }

    createLoader();

    /* ---------- entrance animation ---------- */

    function removeWhenDone(element, className) {
        element.addEventListener("animationend", function handler(event) {
            if (event.target !== element) {
                return;
            }

            element.classList.remove(className);
            element.style.animationDelay = "";
            element.removeEventListener("animationend", handler);
        });
    }

    function playEntrance() {
        /* Back link, heading, floor buttons and the main panel slide up in order */
        var rise = document.querySelectorAll(
            ".back-link, .page-heading, .floor-selector, main .panel"
        );

        Array.prototype.forEach.call(rise, function (element, index) {
            element.style.animationDelay = (index * RISE_STAGGER_MS) + "ms";
            element.classList.add("cm-rise");
            removeWhenDone(element, "cm-rise");
        });

        /* Rooms pop in one by one, starting once the panel is in */
        var baseDelay = 360;
        var rooms = document.querySelectorAll(".floor-plan .room");

        Array.prototype.forEach.call(rooms, function (element, index) {
            element.style.animationDelay = (baseDelay + index * ROOM_STAGGER_MS) + "ms";
            element.classList.add("cm-intro");
            removeWhenDone(element, "cm-intro");
        });
    }

    function hideLoader() {
        if (!loader) {
            return;
        }

        loader.classList.add("cm-hide");
        root.classList.remove("cm-loading");

        playEntrance();

        setTimeout(function () {
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
            loader = null;
        }, 600);
    }

    function finishLoading() {
        if (finished) {
            return;
        }

        finished = true;

        var elapsed = performance.now() - startedAt;
        var wait = Math.max(0, MIN_LOADING_MS - elapsed);

        setTimeout(hideLoader, wait);
    }

    /* Finish when everything (images, scripts) has loaded... */
    if (document.readyState === "complete") {
        finishLoading();
    } else {
        window.addEventListener("load", finishLoading);
    }

    /* ...or after a maximum wait, so the page never gets stuck on the loader */
    setTimeout(finishLoading, MAX_LOADING_MS);
})();