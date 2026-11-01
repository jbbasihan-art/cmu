
(function () {
    'use strict';

    const viewer = document.getElementById('imageViewer');
    const viewport = document.getElementById('imageViewport');
    const image = document.getElementById('detailImage');

    if (!viewer || !viewport || !image) {
        console.error('Floor layout image viewer elements not found.');
        return;
    }

    const arrows = Array.from(
        viewport.querySelectorAll('[data-photo-nav]')
    );

    // Arrow opacity settings
    arrows.forEach(function (arrow) {
        arrow.style.opacity = '0.28';
        arrow.style.pointerEvents = 'auto';
        arrow.style.transition = 'opacity 0.2s ease, background 0.2s ease';

        arrow.addEventListener('mouseenter', function () {
            arrow.style.opacity = '0.72';
        });

        arrow.addEventListener('mouseleave', function () {
            arrow.style.opacity = '0.28';
        });

        // Keep the arrow visible when clicked.
        arrow.addEventListener('click', function () {
            arrow.style.opacity = '0.72';
        });
    });

    // Make the arrows visible when the cursor moves over the photo.
    viewport.addEventListener('pointermove', function () {
        if (!viewer.classList.contains('fullscreen')) return;

        arrows.forEach(function (arrow) {
            arrow.style.opacity = '0.28';
        });
    });

    // Dolly transition when changing viewpoints.
    let moving = false;
    const originalNavigatePhoto = window.navigatePhoto;

    if (typeof originalNavigatePhoto === 'function') {
        window.navigatePhoto = function (direction) {
            if (
                !viewer.classList.contains('fullscreen') ||
                moving
            ) {
                return;
            }

            moving = true;

            image.classList.remove('dolly-in', 'dolly-out');
            void image.offsetWidth;
            image.classList.add('dolly-out');

            window.setTimeout(function () {
                originalNavigatePhoto(direction);

                image.classList.remove('dolly-out');
                void image.offsetWidth;
                image.classList.add('dolly-in');

                window.setTimeout(function () {
                    image.classList.remove('dolly-in');
                    moving = false;
                }, 420);

            }, 260);
        };
    }
})();
