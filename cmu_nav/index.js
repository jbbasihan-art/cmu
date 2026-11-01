document.addEventListener("DOMContentLoaded", function () {
    // Run only on the Campus Map page
    if (!window.location.pathname.endsWith("/campus-map.php")) {
        return;
    }

    // Prevent duplicate loading screens
    if (document.getElementById("cmu-loading-screen")) {
        return;
    }

    // Total time the loading screen is on screen (ms), fade-out included
    const TOTAL_TIME = 200;
    const FADE_TIME = 200;

    // Add loading screen styles
    const style = document.createElement("style");

    style.textContent = `
        #cmu-loading-screen {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f7f9fc;
            opacity: 1;
            visibility: visible;
            transition: opacity ${FADE_TIME}ms ease, visibility ${FADE_TIME}ms ease;
        }

        #cmu-loading-screen.cmu-loading-hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .cmu-loader-logo {
            width: 130px;
            height: 130px;
            object-fit: contain;
            display: block;
            margin-bottom: 24px;
            animation: cmuLogoAppear 0.3s ease both;
        }

        .cmu-loader-title {
            margin: 0;
            color: #0b3b82;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-align: center;
        }

        .cmu-loader-subtitle {
            margin: 12px 0 22px;
            color: #64748b;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            text-align: center;
        }

        .cmu-loader-dots {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .cmu-loader-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #d9a900;
            animation: cmuDotBounce 0.5s infinite ease-in-out;
        }

        .cmu-loader-dot:nth-child(2) {
            animation-delay: 0.08s;
        }

        .cmu-loader-dot:nth-child(3) {
            animation-delay: 0.16s;
        }

        @keyframes cmuLogoAppear {
            from {
                opacity: 0;
                transform: scale(0.88);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes cmuDotBounce {
            0%, 60%, 100% {
                transform: translateY(0);
                opacity: 0.5;
            }
            30% {
                transform: translateY(-8px);
                opacity: 1;
            }
        }

        @media (max-width: 480px) {
            .cmu-loader-logo {
                width: 105px;
                height: 105px;
            }

            .cmu-loader-title {
                font-size: 17px;
                letter-spacing: 1px;
            }

            .cmu-loader-subtitle {
                font-size: 13px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            #cmu-loading-screen,
            .cmu-loader-logo,
            .cmu-loader-dot {
                animation: none;
                transition: none;
            }
        }
    `;

    document.head.appendChild(style);

    // Create the loading screen
    const loader = document.createElement("div");
    loader.id = "cmu-loading-screen";

    loader.innerHTML = `
        <img
            class="cmu-loader-logo"
            src="cmu logo.jpg"
            alt="City of Malabon University Logo"
        >

        <h1 class="cmu-loader-title">
            CMU CAMPUS NAVIGATOR
        </h1>

        <p class="cmu-loader-subtitle">
            Loading campus map...
        </p>

        <div class="cmu-loader-dots" aria-label="Loading">
            <span class="cmu-loader-dot"></span>
            <span class="cmu-loader-dot"></span>
            <span class="cmu-loader-dot"></span>
        </div>
    `;

    document.body.appendChild(loader);

    // Hide the logo if the image path is incorrect
    const logo = loader.querySelector(".cmu-loader-logo");

    logo.addEventListener("error", function () {
        logo.style.display = "none";
    });

    // Fixed timing: start fading out, then remove everything at TOTAL_TIME
    window.setTimeout(function () {
        loader.classList.add("cmu-loading-hidden");
    }, TOTAL_TIME - FADE_TIME);

    window.setTimeout(function () {
        loader.remove();
        style.remove();
    }, TOTAL_TIME);
});