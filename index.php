<?php
require_once __DIR__ . '/bootstrap.php';
af_security_headers();
$pdo = af_db();
$brand_settings = af_settings($pdo);
$company_name = $brand_settings['company_name'] ?? 'AeroFind Cabin Recovery';
$company_short_name = $brand_settings['company_short_name'] ?? 'AeroFind';
$favicon_url = af_valid_url_or_path($brand_settings['favicon_url'] ?? '');
$developer_contact_email = filter_var($brand_settings['developer_contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $brand_settings['developer_contact_email'] : '';
$passenger_has_station = af_has_selected_station();
$active_station = af_get_current_station();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= af_h($company_name) ?> | Passenger Terminal</title>
    <?php if ($favicon_url !== ''): ?>
        <link rel="icon" href="<?= af_h($favicon_url) ?>"><?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --input: #f1f5f9;
            --text: #0f172a;
            --secondary: #64748b;
            --border: rgba(0, 0, 0, 0.08);
            --accent: #f43f5e;
        }

        .dark {
            --bg: #0f172a;
            --card: rgba(30, 41, 59, 0.7);
            --input: rgba(15, 23, 42, 0.6);
            --text: #f8fafc;
            --secondary: #94a3b8;
            --border: rgba(255, 255, 255, 0.08);
            --accent: #f43f5e;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Outfit', sans-serif;
            transition: background 0.3s ease, color 0.3s ease;
            overflow: hidden;
        }

        /* Fix page transition and autofill input boxes visual flashes */
        input,
        select,
        textarea {
            transition: border-color 0.15s ease-in-out, opacity 0.15s ease-in-out !important;
            outline: none !important;
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px var(--input) inset !important;
            -webkit-text-fill-color: var(--text) !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .glass {
            background: var(--card);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--border);
        }

        .airline-card {
            border: 1px solid var(--border);
            background: var(--card);
            border-radius: 1rem;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .airline-card:hover {
            transform: translateY(-6px) scale(1.03);
            border-color: var(--brand-color, var(--accent));
            box-shadow: 0 20px 35px -5px rgba(var(--brand-color-rgb, 244, 63, 94), 0.15);
        }

        .airline-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(var(--brand-color-rgb, 244, 63, 94), 0.08) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.4s ease;
            z-index: 0;
        }

        .airline-card:hover::before {
            opacity: 1;
        }

        .airline-card .logo-container {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .airline-card:hover .logo-container {
            transform: scale(1.1);
        }

        .airline-card:hover h3 {
            color: var(--brand-color, var(--accent)) !important;
        }

        @keyframes cardIn {
            0% {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }

            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .animate-card {
            animation: cardIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        img[data-smooth-image] {
            opacity: 0;
            transition: opacity 0.35s ease;
        }

        img[data-smooth-image].is-loaded {
            opacity: 1;
        }

        .item-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            transition: all 0.2s;
        }

        .item-card:hover {
            border-color: var(--accent);
            transform: scale(1.02);
        }

        /* Bulletproof Dynamic Card Styles */
        .item-card-el {
            height: 270px;
            display: flex;
            flex-direction: column;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 2rem;
            overflow: hidden;
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .item-card-el:hover {
            border-color: rgba(244, 63, 94, 0.3);
            box-shadow: 0 25px 50px -12px rgba(244, 63, 94, 0.08);
            transform: translateY(-4px) scale(1.01);
        }

        .item-card-image-wrap {
            height: 160px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--input);
        }

        .sensitive-doc-photo {
            filter: blur(12px);
            transform: scale(1.06);
            pointer-events: none;
        }

        .sensitive-doc-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.2);
            z-index: 6;
        }

        .item-card-body {
            padding: 0.75rem;
            height: 110px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
        }

        details.filter-panel>summary {
            list-style: none;
        }

        details.filter-panel>summary::-webkit-details-marker {
            display: none;
        }

        details.filter-panel .filter-chevron {
            transition: transform 0.2s ease;
        }

        details.filter-panel[open] .filter-chevron {
            transform: rotate(180deg);
        }

        @media (max-width: 640px) {
            #item-view {
                padding: 0.625rem;
            }

            #item-view>.flex:first-child {
                gap: 0.5rem;
                margin-bottom: 0.625rem;
            }

            #item-grid {
                gap: 0.625rem;
                padding-right: 0;
                padding-bottom: 1rem;
            }

            html[data-card-layout="dual"] #item-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            html[data-card-layout="single"] #item-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            #active-airline-header {
                padding: 0.5rem 0.65rem !important;
            }

            .item-card-el {
                height: 218px;
                border-radius: 1rem;
            }

            .item-card-image-wrap {
                height: 104px !important;
            }

            .item-card-body {
                height: 114px;
                padding: 0.55rem;
            }

            .item-card-body h4 {
                font-size: 0.66rem;
                line-height: 1.08;
            }

            .item-card-body p {
                font-size: 0.48rem;
            }

            .item-card-body span {
                font-size: 0.4rem;
                line-height: 1.05;
            }

            .item-card-body .item-card-meta span:last-child {
                font-size: 0.42rem;
            }

            .item-card-body .item-card-meta {
                gap: 0.45rem;
            }

            .item-card-body .item-card-footer {
                align-items: flex-end;
                gap: 0.35rem;
            }

            .item-card-body .claim-btn {
                padding: 0.4rem 0.45rem;
                max-width: 4.6rem;
                font-size: 0.45rem;
                letter-spacing: 0.08em;
                line-height: 1;
                white-space: normal;
            }

            .item-status-badge {
                top: 0.5rem;
                left: 0.5rem;
                max-width: calc(100% - 1rem);
                padding: 0.28rem 0.45rem;
                font-size: 0.42rem;
                letter-spacing: 0.08em;
                line-height: 1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .item-card-image-wrap .missing-icon {
                width: 1.75rem;
                height: 1.75rem;
                margin-bottom: 0.25rem;
            }

            .item-card-image-wrap .missing-icon svg {
                width: 0.9rem;
                height: 0.9rem;
            }

            .item-card-image-wrap .missing-text {
                font-size: 0.36rem;
                letter-spacing: 0.22em;
            }

            html[data-card-layout="single"] .item-card-el {
                height: 270px;
            }

            html[data-card-layout="single"] .item-card-image-wrap {
                height: 150px !important;
            }

            html[data-card-layout="single"] .item-card-body {
                height: 120px;
                padding: 0.75rem;
            }

            html[data-card-layout="single"] .item-card-body h4 {
                font-size: 0.82rem;
                line-height: 1.15;
            }

            html[data-card-layout="single"] .item-card-body p {
                font-size: 0.62rem;
            }

            html[data-card-layout="single"] .item-card-body span {
                font-size: 0.52rem;
                line-height: 1.1;
            }

            html[data-card-layout="single"] .item-card-body .item-card-meta span:last-child {
                font-size: 0.58rem;
            }

            html[data-card-layout="single"] .item-card-body .claim-btn {
                max-width: none;
                padding: 0.5rem 0.8rem;
                font-size: 0.55rem;
                white-space: nowrap;
            }

            html[data-card-layout="single"] .item-status-badge {
                font-size: 0.5rem;
                padding: 0.35rem 0.65rem;
            }
        }

        @media (max-width: 420px) {
            .mobile-title {
                max-width: clamp(7.25rem, 34vw, 10rem);
                font-size: 0.95rem !important;
                letter-spacing: 0;
            }
        }

        .custom-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.2);
            border-radius: 10px;
        }

        .view-transition {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .hidden-view {
            opacity: 0;
            pointer-events: none;
            transform: translateY(10px);
            display: none;
        }

        .footer-bg {
            background: var(--header);
        }

        .theme-toggle {
            cursor: pointer;
            padding: 10px;
            border-radius: 12px;
            background: var(--input);
            border: 1px solid var(--border);
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--border);
        }

        /* Custom premium Toast container and notification styles */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
            width: calc(100% - 48px);
            pointer-events: none;
        }

        .toast-card {
            pointer-events: auto;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 20px;
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transform: translateY(20px) scale(0.95);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .dark .toast-card {
            background: rgba(15, 23, 42, 0.85);
            border-color: rgba(255, 255, 255, 0.08);
        }

        /* Light mode support */
        html:not(.dark) .toast-card {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(0, 0, 0, 0.06);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.1);
        }

        html:not(.dark) .toast-card span.text-slate-100 {
            color: #0f172a !important;
        }

        .toast-card.show {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        .toast-card.hide {
            transform: translateY(20px) scale(0.95);
            opacity: 0;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            width: 100%;
            transform-origin: left;
            animation: toast-progress-shrink 4s linear forwards;
        }

        @keyframes toast-progress-shrink {
            from {
                transform: scaleX(1);
            }

            to {
                transform: scaleX(0);
            }
        }
    </style>
    <script>
        const theme = localStorage.getItem('theme') || 'dark';
        if (theme === 'dark') document.documentElement.classList.add('dark');
        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        }
    </script>
</head>

<body class="h-full flex flex-col">

    <!-- Terminal Header -->
    <header
        class="px-4 py-3 md:px-10 md:py-4 flex items-center justify-between gap-4 shrink-0 border-b bg-[var(--card)] transition-colors border-[var(--border)] sticky top-0 z-50">
        <div class="flex items-center gap-3 md:gap-5 min-w-0">
            <div
                class="w-10 h-10 md:w-12 md:h-12 bg-rose-500 rounded-xl flex items-center justify-center shadow-lg shadow-rose-500/20 shrink-0 overflow-hidden">
                <?= af_brand_logo_html($brand_settings, 'w-full h-full rounded-xl', 'text-lg md:text-xl font-black text-white italic') ?>
            </div>
            <div class="min-w-0">
                <h1
                    class="mobile-title text-lg md:text-xl font-black uppercase tracking-tighter leading-none text-[var(--text)] truncate">
                    <?= af_h($company_short_name) ?>
                </h1>
                <p id="view-title"
                    class="text-[9px] font-black uppercase tracking-widest text-[var(--secondary)] mt-1 hidden xs:block">
                    <?= $passenger_has_station ? 'Step 1: Select Airline' : 'Step 1: Select Station' ?></p>
            </div>
        </div>

        <div class="flex items-center gap-3 md:gap-6">
            <button onclick="showReportForm()"
                class="px-4 py-2 md:px-6 md:py-3 bg-rose-500 text-white rounded-xl text-[9px] md:text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-all shadow-lg shadow-rose-500/20 whitespace-nowrap">
                Report Lost
            </button>
            <div class="h-6 w-px bg-[var(--border)] hidden sm:block"></div>
            <div class="flex items-center gap-2 md:gap-4">
                <!-- Station Switcher Dropdown -->
                <div class="relative inline-block text-left" id="station-switcher-container">
                    <button onclick="toggleStationMenu()"
                        class="w-9 h-9 md:w-auto md:h-10 px-0 md:px-4 flex items-center justify-center gap-1.5 rounded-xl bg-[var(--input)] border border-[var(--border)] text-[10px] font-black uppercase tracking-widest text-[var(--text)] hover:scale-105 transition-all">
                        <svg class="w-4 h-4 text-[var(--secondary)]" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                        </svg>
                        <span id="current-station-label"
                            class="hidden md:inline"><?= $passenger_has_station ? af_h($active_station) : 'Station' ?></span> <span
                            class="text-[8px] opacity-60 hidden md:inline">▼</span>
                    </button>
                    <div id="station-dropdown"
                        class="absolute right-0 mt-2 w-56 rounded-xl glass border border-[var(--border)] shadow-2xl hidden z-[100] overflow-hidden">
                        <div class="p-2 border-b border-[var(--border)] bg-slate-900/10">
                            <span
                                class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] block px-2.5 py-1">Terminal
                                Station</span>
                        </div>
                        <div class="py-1 max-h-60 overflow-y-auto custom-scroll">
                            <?php foreach (af_stations() as $code => $name): ?>
                                <a href="?station=<?= rawurlencode($code) ?>"
                                    class="flex items-center justify-between px-3.5 py-2.5 text-[9px] uppercase font-black tracking-widest text-[var(--text)] hover:bg-rose-500/10 hover:text-rose-500 transition-colors <?= $passenger_has_station && $active_station === $code ? 'text-rose-500 font-extrabold' : '' ?>">
                                    <span class="truncate"><?= af_h($code) ?> - <?= af_h($name) ?></span>
                                    <?php if ($passenger_has_station && $active_station === $code): ?><span>✓</span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <button onclick="toggleTheme()"
                    class="w-9 h-9 md:w-10 md:h-10 flex items-center justify-center rounded-xl bg-[var(--input)] border border-[var(--border)] text-sm">
                    <span id="theme-icon" class="flex items-center justify-center"></span>
                </button>
                <div id="clock" class="text-xs md:text-sm font-bold text-[var(--secondary)] font-mono hidden xs:block">
                    00:00:00</div>
            </div>
            <script>
                function toggleStationMenu() {
                    const menu = document.getElementById('station-dropdown');
                    if (menu) menu.classList.toggle('hidden');
                }
                window.addEventListener('click', function (e) {
                    const container = document.getElementById('station-switcher-container');
                    const menu = document.getElementById('station-dropdown');
                    if (container && !container.contains(e.target) && menu) {
                        menu.classList.add('hidden');
                    }
                });

                function updateIcon() {
                    const isDark = document.documentElement.classList.contains('dark');
                    document.getElementById('theme-icon').innerHTML = isDark
                        ? `<svg class="w-4 h-4 text-[var(--secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707.707M12 8a4 4 0 100 8 4 4 0 000-8z" /></svg>`
                        : `<svg class="w-4 h-4 text-[var(--secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>`;
                }
                const originalToggle = toggleTheme;
                toggleTheme = function () {
                    originalToggle();
                    updateIcon();
                }
                updateIcon();
            </script>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-grow overflow-hidden relative">
        <?php if (!$passenger_has_station): ?>
            <div id="station-select-view"
                class="view-transition absolute inset-0 p-4 md:p-8 overflow-y-auto custom-scroll flex items-center justify-center">
                <div class="w-full max-w-3xl">
                    <div class="mb-6 text-center">
                        <p class="text-[10px] font-black uppercase tracking-[0.25em] text-rose-500 mb-2">Passenger Terminal</p>
                        <h2 class="text-2xl md:text-4xl font-black uppercase tracking-tighter text-[var(--text)]">Select Your Station</h2>
                        <p class="mt-2 text-xs md:text-sm font-bold text-[var(--secondary)]">Choose the airport station before viewing airline found items.</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php foreach (af_stations() as $code => $name): ?>
                            <a href="?station=<?= rawurlencode($code) ?>"
                                class="group rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 transition-all hover:-translate-y-1 hover:border-rose-500/40 hover:shadow-2xl hover:shadow-rose-500/10">
                                <span class="block text-2xl font-black uppercase tracking-tight text-[var(--text)] group-hover:text-rose-500"><?= af_h($code) ?></span>
                                <span class="mt-1 block text-xs font-black uppercase tracking-widest text-[var(--secondary)]"><?= af_h($name) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- View 1: Airline Selection -->
        <div id="airline-view"
            class="view-transition absolute inset-0 p-3 md:p-5 overflow-y-auto custom-scroll <?= $passenger_has_station ? '' : 'hidden-view' ?>">
            <div class="mb-4 relative max-w-md mx-auto md:mx-0">
                <input type="text" id="airline-search" placeholder="Search airline or flight..."
                    class="w-full bg-[var(--input)] border border-[var(--border)] rounded-lg px-10 py-1.5 text-xs text-[var(--text)] focus:border-rose-500/50 transition-all outline-none"
                    oninput="filterAirlines(this.value)">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 opacity-30 text-xs">🔍</span>
            </div>
            <div id="airline-grid"
                class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 md:gap-4">
                <!-- Loaded via JS -->
            </div>
        </div>

        <div id="item-view"
            class="view-transition hidden-view absolute inset-0 flex flex-col p-3 md:p-4 bg-[var(--bg)] overflow-hidden">
            <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 mb-4 shrink-0">
                <!-- Back Button + Active Airline Header Group -->
                <div class="w-full lg:w-auto grid grid-cols-[auto_1fr] gap-2 items-center shrink-0">

                    <!-- Back Button -->
                    <button onclick="showAirlines()"
                        class="flex items-center justify-center gap-1.5 px-3 py-1.5 bg-[var(--card)] border border-[var(--border)] text-[var(--text)] rounded-xl hover:bg-rose-500/5 hover:border-rose-500/30 transition-all font-black uppercase text-[9px] tracking-widest shrink-0">
                        <span class="text-xs leading-none">←</span> Back
                    </button>

                    <!-- Active Airline Header -->
                    <div id="active-airline-header"
                        class="flex items-center justify-center lg:justify-start gap-3 bg-[var(--card)] px-3 py-1.5 rounded-xl border border-[var(--border)] shadow-xl min-w-0">
                        <!-- Loaded via JS -->
                    </div>

                </div>

                <!-- Filters Tab Nested Between -->
                <details id="passenger-filter-panel"
                    class="filter-panel flex-grow bg-[var(--card)] border border-[var(--border)] rounded-xl p-2 shadow-xl mx-0 lg:mx-3">
                    <summary class="flex cursor-pointer items-center justify-between gap-3 px-2 py-1">
                        <div class="min-w-0">
                            <p class="text-[9px] font-black uppercase tracking-widest text-[var(--secondary)]">Filters
                            </p>
                            <p id="passenger-filter-summary" class="truncate text-[11px] font-bold text-[var(--text)]">
                                Search, month, week, or date</p>
                        </div>
                        <span class="filter-chevron text-[var(--secondary)] text-lg leading-none">⌄</span>
                    </summary>
                    <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2">
                        <!-- Text Search -->
                        <div class="relative">
                            <input type="text" id="item-search" placeholder="Search items..."
                                class="w-full bg-[var(--input)] border border-[var(--border)] rounded-lg pl-8 pr-3 py-1.5 text-xs text-[var(--text)] focus:border-rose-500/50 transition-all outline-none"
                                oninput="filterPassengerItems()">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 opacity-30 text-xs">🔍</span>
                        </div>

                        <!-- Month Filter -->
                        <div>
                            <select id="pax-filter-month"
                                class="w-full bg-[var(--input)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-xs text-[var(--text)] focus:border-rose-500/50 transition-all outline-none cursor-pointer"
                                onchange="filterPassengerItems()">
                                <option value="">All Months</option>
                            </select>
                        </div>

                        <!-- Week Filter -->
                        <div>
                            <select id="pax-filter-week"
                                class="w-full bg-[var(--input)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-xs text-[var(--text)] focus:border-rose-500/50 transition-all outline-none cursor-pointer"
                                onchange="filterPassengerItems()">
                                <option value="">All Weeks</option>
                                <option value="this_week">This Week</option>
                                <option value="last_week">Last Week</option>
                                <option value="2_weeks_ago">2 Weeks Ago</option>
                                <option value="3_weeks_ago">3 Weeks Ago</option>
                                <option value="older">Older</option>
                            </select>
                        </div>

                        <!-- Specific Date Filter -->
                        <div>
                            <input type="text" id="pax-filter-date" placeholder="Date" data-placeholder="Date"
                                class="w-full bg-[var(--input)] border border-[var(--border)] rounded-lg px-2.5 py-1.5 text-xs text-[var(--text)] focus:border-rose-500/50 transition-all outline-none cursor-pointer"
                                onchange="filterPassengerItems()">
                        </div>
                    </div>
                </details>
            </div>

            <div id="item-grid"
                class="flex-grow min-h-0 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 items-start gap-3 md:gap-4 overflow-y-auto custom-scroll pr-2 pb-6">
                <!-- Loaded via JS -->
            </div>
        </div>

        <!-- View 3: Report Form -->
        <div id="report-view"
            class="view-transition hidden-view absolute inset-0 flex flex-col p-6 md:p-10 bg-[var(--bg)] overflow-y-auto">
            <div class="max-w-2xl mx-auto w-full">
                <div class="flex items-center justify-between mb-8 md:mb-12">
                    <button onclick="showAirlines()"
                        class="flex items-center gap-3 px-4 py-2 md:px-6 md:py-3 bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl border text-[10px] uppercase font-black">
                        <span>←</span> Cancel
                    </button>
                    <h2 class="text-lg md:text-2xl font-black uppercase tracking-tighter text-[var(--text)]">Report Lost
                    </h2>
                </div>

                <form id="lost-report-form" class="space-y-6" onsubmit="submitLostReport(event)"
                    enctype="multipart/form-data">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label
                                class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Airline</label>
                            <select name="airline" id="report-airlines"
                                class="w-full bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl px-4 py-3 border"
                                required>
                                <option value="">Select Airline...</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label
                                class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Flight
                                Number</label>
                            <input type="text" name="flight_number" placeholder="e.g. LH123"
                                class="w-full bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl px-4 py-3 border"
                                required>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Item
                            Description</label>
                        <input type="text" name="item_description" placeholder="e.g. Black leather wallet with ID"
                            class="w-full bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl px-4 py-3 border"
                            required>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Full
                                Name</label>
                            <input type="text" name="pax_name" placeholder="John Doe"
                                class="w-full bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl px-4 py-3 border"
                                required>
                        </div>
                        <div class="space-y-2">
                            <label
                                class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Contact
                                Email</label>
                            <input type="email" name="contact_email" placeholder="john@example.com"
                                class="w-full bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl px-4 py-3 border"
                                required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Seat
                                / Row</label>
                            <input type="text" name="seat_info" placeholder="e.g. 12A"
                                class="w-full bg-[var(--input)] border-[var(--border)] text-[var(--text)] rounded-xl px-4 py-3 border">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Attach
                            Photo (Optional)</label>
                        <div
                            class="p-6 bg-[var(--card)] border-2 border-dashed border-[var(--border)] rounded-2xl text-center cursor-pointer hover:border-rose-500 transition-all group">
                            <input type="file" name="photo" id="photo-input" class="hidden"
                                accept="image/jpeg,image/png,image/webp" onchange="updateFileLabel(this)">
                            <label for="photo-input" class="cursor-pointer">
                                <div class="text-3xl mb-2 grayscale group-hover:grayscale-0 transition-all">📸</div>
                                <p id="file-label"
                                    class="text-[10px] font-black uppercase tracking-widest text-[var(--secondary)]">Tap
                                    to capture or upload photo</p>
                                <p class="text-[8px] text-[var(--secondary)] mt-1 opacity-50 uppercase font-black">
                                    Visual reference helps us identify your item faster</p>
                            </label>
                        </div>
                    </div>
                    <label
                        class="flex items-start gap-3 p-4 bg-[var(--card)] border border-[var(--border)] rounded-2xl cursor-pointer">
                        <input type="checkbox" name="privacy_consent" value="1" required
                            class="mt-1 w-4 h-4 accent-rose-500 shrink-0">
                        <span class="text-[10px] md:text-xs font-bold leading-relaxed text-[var(--secondary)]">
                            I consent to <?= af_h($company_short_name) ?> processing my contact details, flight
                            information, item description, and any uploaded photo for the purpose of reviewing this lost
                            item report and contacting me about it. I have read the
                            <a href="privacy.php" target="_blank"
                                class="text-rose-500 hover:text-rose-400 font-black underline decoration-rose-500/30">privacy
                                notice</a>.
                        </span>
                    </label>
                    <button type="submit"
                        class="w-full bg-rose-500 hover:bg-rose-600 text-white py-5 rounded-2xl font-black uppercase tracking-[0.2em] shadow-xl shadow-rose-500/20 transition-all">
                        Submit Lost Report
                    </button>
                </form>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer
        class="px-4 py-2 border-t border-[var(--border)] bg-[var(--header)] flex justify-between items-center text-[9px] text-[var(--secondary)] font-black uppercase tracking-widest transition-colors shrink-0">
        <div class="flex items-center gap-4">
            <span>&copy; 2026 <?= af_h($company_short_name) ?> Terminal</span>
            <span class="opacity-20 hidden xs:block">|</span>
            <span id="item-count" class="hidden xs:block">0 Items Found</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="privacy.php" class="hidden sm:inline hover:text-rose-500 transition-colors">Privacy Notice</a>
            <span class="opacity-20 hidden sm:inline">|</span>
            <?php if ($developer_contact_email !== ''): ?>
                <a href="mailto:<?= af_h($developer_contact_email) ?>"
                    class="hidden sm:inline hover:text-rose-500 transition-colors">Developer Contact</a>
                <span class="opacity-20 hidden sm:inline">|</span>
            <?php endif; ?>
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>System Ready</span>
        </div>
    </footer>

    <!-- Claim Item Modal -->
    <div id="claim-modal"
        class="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[200] hidden items-center justify-center p-6">
        <div class="glass max-w-md w-full p-10 rounded-[3rem] relative animate-fade-in">
            <button onclick="closeClaimModal()"
                class="absolute top-8 right-8 text-slate-500 hover:text-white text-2xl font-bold">×</button>
            <div class="flex items-center gap-4 mb-6">
                <div>
                    <h2 class="font-black text-lg uppercase tracking-tight text-[var(--text)]">Claim Found Item</h2>
                    <p id="claim-item-subtitle"
                        class="text-[8px] font-black uppercase tracking-widest text-slate-500 mt-1">Item Ref: </p>
                </div>
            </div>

            <form id="claim-item-form" class="space-y-4" onsubmit="submitClaim(event)">
                <input type="hidden" name="tag_no" id="claim-tag-input">

                <div class="space-y-1">
                    <label class="block text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">Your
                        Full Name</label>
                    <input type="text" name="pax_name" required
                        class="w-full bg-[var(--input)] border border-[var(--border)] rounded-xl px-4 py-3 text-xs text-[var(--text)] outline-none focus:border-rose-500/50"
                        placeholder="John Doe">
                </div>

                <div class="space-y-1">
                    <label class="block text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">Contact
                        Email</label>
                    <input type="email" name="pax_email" required
                        class="w-full bg-[var(--input)] border border-[var(--border)] rounded-xl px-4 py-3 text-xs text-[var(--text)] outline-none focus:border-rose-500/50"
                        placeholder="john@example.com">
                </div>

                <div class="space-y-1">
                    <label class="block text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">Contact
                        Phone Number</label>
                    <input type="tel" name="pax_contact" required
                        class="w-full bg-[var(--input)] border border-[var(--border)] rounded-xl px-4 py-3 text-xs text-[var(--text)] outline-none focus:border-rose-500/50"
                        placeholder="+1 234 567 890">
                </div>

                <div class="space-y-1">
                    <label class="block text-[8px] font-black uppercase tracking-widest text-[var(--secondary)]">Flight
                        Seat / Evidence of Ownership</label>
                    <textarea name="seat_info" required rows="3"
                        class="w-full bg-[var(--input)] border border-[var(--border)] rounded-xl px-4 py-3 text-xs text-[var(--text)] outline-none focus:border-rose-500/50 resize-none"
                        placeholder="Provide seat number (e.g. 14F), boarding pass details, or distinct marks..."></textarea>
                </div>

                <button type="submit" id="claim-submit-btn"
                    class="w-full bg-rose-500 hover:bg-rose-600 text-white py-4 rounded-xl font-black text-[10px] uppercase tracking-[0.15em] shadow-lg shadow-rose-500/20 transition-all flex items-center justify-center gap-2">
                    Submit Property Claim
                </button>
            </form>
        </div>
    </div>

    <!-- Image Zoom Modal Overlay -->
    <div id="image-zoom-overlay"
        class="fixed inset-0 bg-slate-950/90 backdrop-blur-xl z-[900] hidden items-center justify-center p-4 cursor-pointer"
        onclick="closeZoomedImage()">
        <button class="absolute top-8 right-8 text-slate-400 hover:text-white text-3xl font-bold">×</button>
        <img id="image-zoom-img"
            class="max-w-full max-h-[90vh] object-contain rounded-3xl shadow-2xl transition-all duration-300 border border-white/10"
            src="">
    </div>

    <script>
        const stationSelected = <?= $passenger_has_station ? 'true' : 'false' ?>;
        const activeStation = '<?= af_h($active_station) ?>';
        const publicApiCsrfToken = <?= json_encode(af_csrf_token()) ?>;
        let allData = {};
        let passengerViewDays = 30;
        let currentAirlineItems = [];
        applyCardLayout('dual');

        function applyCardLayout(layout) {
            const normalized = layout === 'single' ? 'single' : 'dual';
            document.documentElement.dataset.cardLayout = normalized;
        }

        function getTagNumber(itemOrTag) {
            const tag = typeof itemOrTag === 'string'
                ? itemOrTag
                : (itemOrTag.reference_code || itemOrTag.id || '');
            const match = String(tag).match(/ID-(\d+)/i);
            return match ? parseInt(match[1], 10) : -1;
        }

        function sortItemsByIdDesc(items) {
            return [...items].sort((a, b) => {
                const idDiff = getTagNumber(b) - getTagNumber(a);
                if (idDiff !== 0) return idDiff;
                return String(b.created_at || '').localeCompare(String(a.created_at || ''));
            });
        }

        function updatePassengerFilterSummary() {
            const summary = document.getElementById('passenger-filter-summary');
            if (!summary) return;
            const active = [];
            const query = document.getElementById('item-search')?.value.trim();
            const monthSelect = document.getElementById('pax-filter-month');
            const weekSelect = document.getElementById('pax-filter-week');
            const date = document.getElementById('pax-filter-date')?.value;

            if (query) active.push(`Search: ${query}`);
            if (monthSelect?.value) active.push(monthSelect.options[monthSelect.selectedIndex].text);
            if (weekSelect?.value) active.push(weekSelect.options[weekSelect.selectedIndex].text);
            if (date) active.push(date);

            summary.textContent = active.length ? active.join(' • ') : 'Search, month, week, or date';
        }

        function buildPassengerMonthOptions(days) {
            const monthSelect = document.getElementById('pax-filter-month');
            if (!monthSelect) return;

            const visibleDays = Math.max(1, Math.min(365, parseInt(days, 10) || 30));
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const startDate = new Date(today);
            startDate.setDate(startDate.getDate() - visibleDays);
            startDate.setDate(1);

            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];

            const options = ['<option value="">All Months</option>'];
            const cursor = new Date(today.getFullYear(), today.getMonth(), 1);

            while (cursor >= startDate) {
                const year = cursor.getFullYear();
                const month = String(cursor.getMonth() + 1).padStart(2, '0');
                options.push(`<option value="${year}-${month}">${monthNames[cursor.getMonth()]} ${year}</option>`);
                cursor.setMonth(cursor.getMonth() - 1);
            }

            monthSelect.innerHTML = options.join('');
        }

        function formatFlightNumber(value, airlineName) {
            let flight = String(value || '').trim();
            if (!flight) return 'N/A';

            const escapedAirline = airlineName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            flight = flight.replace(new RegExp(`^${escapedAirline}\\s*`, 'i'), '').trim();
            flight = flight.replace(/\s*\((?:N\/?A|NA)\)\s*/ig, ' ').replace(/\s+/g, ' ').trim();
            return flight || value || 'N/A';
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));
        }

        function safeUrl(value) {
            const raw = String(value || '').trim();
            if (/^https?:\/\//i.test(raw) || /^uploads\/[A-Za-z0-9/_@.-]+$/i.test(raw)) {
                return raw.replace(/"/g, '%22');
            }
            return '';
        }

        function setupDatePlaceholders() {
            document.querySelectorAll('input[data-placeholder]').forEach(input => {
                const syncType = () => {
                    if (input.value) {
                        input.type = 'date';
                    } else if (document.activeElement !== input) {
                        input.type = 'text';
                        input.placeholder = input.dataset.placeholder || '';
                    }
                };

                input.addEventListener('focus', () => {
                    input.type = 'date';
                    if (typeof input.showPicker === 'function') {
                        setTimeout(() => input.showPicker(), 0);
                    }
                });
                input.addEventListener('blur', syncType);
                input.addEventListener('change', syncType);
                syncType();
            });
        }

        function enhanceSmoothImages(root = document) {
            root.querySelectorAll('img[loading="lazy"]:not([data-smooth-bound])').forEach(img => {
                img.dataset.smoothImage = '1';
                img.dataset.smoothBound = '1';
                const reveal = () => img.classList.add('is-loaded');
                if (img.complete && img.naturalWidth > 0) {
                    requestAnimationFrame(reveal);
                } else {
                    img.addEventListener('load', reveal, { once: true });
                    img.addEventListener('error', reveal, { once: true });
                }
            });
        }

        function animateCards(selector) {
            requestAnimationFrame(() => {
                document.querySelectorAll(selector).forEach(card => {
                    card.classList.remove('opacity-0');
                    card.classList.add('animate-card');
                });
            });
        }

        function updateClock() {
            const now = new Date();
            document.getElementById('clock').textContent = now.toTimeString().split(' ')[0];
        }
        setInterval(updateClock, 1000);
        updateClock();
        setupDatePlaceholders();

        function filterAirlines(query) {
            const cards = document.querySelectorAll('.airline-card');
            const q = query.toLowerCase();
            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase();
                const code = card.getAttribute('data-code').toLowerCase();
                if (name.includes(q) || code.includes(q)) {
                    card.style.display = 'block';
                    setTimeout(() => card.style.opacity = '1', 10);
                } else {
                    card.style.opacity = '0';
                    setTimeout(() => card.style.display = 'none', 300);
                }
            });
            setTimeout(() => {
                animateCards('.airline-card');
            }, 50);
        }

        function filterItems(query) {
            const cards = document.querySelectorAll('.item-card-el');
            const q = query.toLowerCase();
            cards.forEach(card => {
                const desc = card.getAttribute('data-desc').toLowerCase();
                const tag = card.getAttribute('data-tag').toLowerCase();
                if (desc.includes(q) || tag.includes(q)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        async function initTerminal() {
            try {
                const res = await fetch(`public_api.php?action=getPublicFoundItems&station=${encodeURIComponent(activeStation)}&_=${Date.now()}`);
                const data = await res.json();

                if (data.success) {
                    applyCardLayout(data.settings?.passenger_card_layout);
                    passengerViewDays = data.settings?.passenger_view_days || 30;
                    buildPassengerMonthOptions(passengerViewDays);
                    allData = data.grouped;
                    renderAirlineGrid();
                    const total = Object.values(data.grouped).reduce((acc, g) => acc + g.items.length, 0);
                    document.getElementById('item-count').textContent = `${total} Items Found`;
                }
            } catch (e) {
                console.error('Init Error:', e);
            }
        }

        function renderAirlineGrid() {
            const grid = document.getElementById('airline-grid');
            grid.innerHTML = '';

            const brandColors = {
                'lufthansa': { hex: '#002F6C', rgb: '0, 47, 108' },
                'emirates': { hex: '#D71920', rgb: '215, 25, 32' },
                'singapore airlines': { hex: '#D4AF37', rgb: '212, 175, 55' },
                'qatar airways': { hex: '#5C0632', rgb: '92, 6, 50' },
                'british airways': { hex: '#072F5F', rgb: '7, 47, 95' },
                'air france': { hex: '#002395', rgb: '0, 35, 149' },
                'delta': { hex: '#E01933', rgb: '224, 25, 51' },
                'ryanair': { hex: '#FFCC00', rgb: '255, 204, 0' },
                'easyjet': { hex: '#FF6600', rgb: '255, 102, 0' },
                'united airlines': { hex: '#005DAA', rgb: '0, 93, 170' },
                'turkish airlines': { hex: '#E01933', rgb: '224, 25, 51' },
                'qantas': { hex: '#E00000', rgb: '224, 0, 0' },
                'ana': { hex: '#004494', rgb: '0, 68, 148' },
                'cathay pacific': { hex: '#006560', rgb: '0, 101, 96' },
                'klm': { hex: '#00A1DE', rgb: '0, 161, 222' }
            };

            Object.keys(allData).sort().forEach(airlineName => {
                const group = allData[airlineName];
                const airlineDisplayName = escapeHtml(group.airline.name || airlineName);
                const airlineCode = escapeHtml(group.airline.code || '');
                const logoUrl = safeUrl(group.airline.logo);
                const fallbackLogo = `https://ui-avatars.com/api/?name=${encodeURIComponent(airlineName)}&background=f1f5f9&color=64748b`;
                const card = document.createElement('div');
                card.className = 'airline-card group view-transition opacity-0 p-3.5 md:p-5 flex flex-col items-center justify-center gap-3.5 h-full relative overflow-hidden';
                card.setAttribute('data-name', group.airline.name || airlineName);
                card.setAttribute('data-code', group.airline.code || '');
                card.onclick = () => showItems(airlineName);

                const nameLower = airlineName.toLowerCase();
                const brand = brandColors[nameLower] || { hex: '#f43f5e', rgb: '244, 63, 94' };
                card.style.setProperty('--brand-color', brand.hex);
                card.style.setProperty('--brand-color-rgb', brand.rgb);

                card.innerHTML = `
                    <div class="logo-container w-11 h-11 md:w-14 md:h-14 bg-[var(--bg)] rounded-2xl flex items-center justify-center p-2.5 border border-[var(--border)] shadow-sm relative z-10">
                        <img src="${logoUrl || fallbackLogo}" class="w-full h-full object-contain filter" loading="lazy" decoding="async" data-smooth-image onerror="this.src='${fallbackLogo}'">
                    </div>
                    <div class="text-center z-10">
                        <h3 class="font-black uppercase text-[8px] md:text-[9px] tracking-widest text-[var(--text)] transition-colors line-clamp-1">${airlineDisplayName}</h3>
                        <div class="mt-1.5 inline-block px-2 py-0.5 rounded-full" style="background-color: rgba(var(--brand-color-rgb), 0.12);">
                            <p class="text-[7px] font-black uppercase tracking-tighter" style="color: var(--brand-color);">${group.items.length} Found${airlineCode ? ` · ${airlineCode}` : ''}</p>
                        </div>
                    </div>
                `;
                grid.appendChild(card);
                enhanceSmoothImages(card);
            });

            animateCards('.airline-card');
        }

        function showItems(airlineName) {
            const airlineView = document.getElementById('airline-view');
            const itemView = document.getElementById('item-view');
            const header = document.getElementById('active-airline-header');
            const grid = document.getElementById('item-grid');
            const searchInput = document.getElementById('item-search');

            searchInput.value = '';
            document.getElementById('pax-filter-month').value = '';
            document.getElementById('pax-filter-week').value = '';
            document.getElementById('pax-filter-date').value = '';
            updatePassengerFilterSummary();
            document.getElementById('passenger-filter-panel')?.removeAttribute('open');

            const group = allData[airlineName];
            const sortedItems = sortItemsByIdDesc(group.items);
            const airlineDisplayName = escapeHtml(airlineName);
            const logoUrl = safeUrl(group.airline.logo);
            const fallbackLogo = `https://ui-avatars.com/api/?name=${encodeURIComponent(airlineName)}`;

            header.innerHTML = `
                <div class="w-8 h-8 bg-[var(--card)] rounded-lg flex items-center justify-center p-1 border border-[var(--border)] shadow-sm shrink-0">
                    <img src="${logoUrl || fallbackLogo}" alt="${airlineDisplayName}" class="w-full h-full object-contain" loading="lazy" decoding="async" data-smooth-image onerror="this.src='${fallbackLogo}'">
                </div>
                <div class="text-left">
                    <h2 class="text-xs font-black uppercase tracking-tighter text-[var(--text)] leading-tight">${airlineDisplayName}</h2>
                    <p class="text-[8px] font-black uppercase tracking-widest text-[var(--secondary)] leading-none">${sortedItems.length} Records</p>
                </div>
            `;
            enhanceSmoothImages(header);

            grid.innerHTML = '';
            sortedItems.forEach(item => {
                const card = document.createElement('div');
                card.className = 'glass rounded-2xl overflow-hidden flex flex-col border border-[var(--border)] hover:border-rose-500/30 hover:shadow-2xl hover:shadow-rose-500/5 transition-all duration-500 group/item item-card-el';
                card.setAttribute('data-desc', item.item_description);
                card.setAttribute('data-tag', item.reference_code || item.id || '');
                card.setAttribute('data-created-at', item.created_at || '');

                const canClaim = item.status === 'Found';
                const statusLabel = item.status === 'Found' ? 'READY FOR CLAIM' : (item.status === 'Claimed' ? 'CLAIMED' : 'REPORTED LOST');
                const statusColor = item.status === 'Found' ? 'bg-emerald-500' : (item.status === 'Claimed' ? 'bg-indigo-500' : 'bg-rose-500');
                const photoPath = String(item.photo || '');
                const photoUrl = photoPath ? safeUrl(/^https?:\/\//i.test(photoPath) ? photoPath : `uploads/cabin_items/${photoPath}`) : null;
                const flightNumber = escapeHtml(formatFlightNumber(item.flight_number, airlineName));
                const isIdentityDocument = Boolean(item.is_identity_document) || /^PP-/i.test(item.reference_code || item.id || '');
                const referenceCode = escapeHtml(item.reference_code || item.id || 'N/A');
                const rawReferenceCode = String(item.reference_code || item.id || '');
                const itemDescription = escapeHtml(item.item_description || '');
                const createdDate = item.created_at ? escapeHtml(String(item.created_at).split(' ')[0]) : 'N/A';

                card.innerHTML = `
                    <div class="item-card-image-wrap bg-[var(--input)] flex items-center justify-center overflow-hidden relative w-full h-[160px] shrink-0">
                        ${photoUrl ? `
                            <img src="${photoUrl}" ${isIdentityDocument ? '' : 'onclick="zoomImage(this.src)"'} class="w-full h-full object-cover transition-transform duration-700 ${isIdentityDocument ? 'sensitive-doc-photo' : 'group-hover/item:scale-110 cursor-pointer'}" loading="lazy" decoding="async" data-smooth-image>
                            ${isIdentityDocument ? `
                                <div class="sensitive-doc-overlay">
                                    <span class="px-3 py-1.5 rounded-full bg-slate-950/70 text-white text-[7px] font-black uppercase tracking-widest shadow-lg">Image Protected</span>
                                </div>
                            ` : ''}
                        ` : `
                            <div class="absolute inset-0 flex flex-col items-center justify-center p-4 text-center">
                                <div class="missing-icon w-10 h-10 rounded-full bg-rose-500/5 flex items-center justify-center mb-2 group-hover/item:scale-110 transition-transform duration-500">
                                    <svg class="text-rose-500 opacity-20 group-hover/item:opacity-40 transition-opacity w-5 h-5" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                </div>
                                <span class="missing-text text-[6px] font-black uppercase tracking-[0.4em] opacity-30 group-hover/item:opacity-50 transition-opacity">Digital Evidence Missing</span>
                                <div class="absolute inset-0 bg-gradient-to-t from-[var(--input)] to-transparent opacity-60"></div>
                            </div>
                        `}
                        <div class="item-status-badge absolute top-3 left-3 ${statusColor} text-white text-[7px] font-black px-2.5 py-1 rounded-full shadow-lg uppercase tracking-widest z-10">
                            ${statusLabel}
                        </div>
                    </div>
                    <div class="item-card-body">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-[9px] font-black text-rose-500 uppercase tracking-widest leading-none mb-1">${referenceCode}</p>
                                <h4 class="font-bold text-[var(--text)] text-xs leading-tight line-clamp-2">${itemDescription}</h4>
                            </div>
                        </div>
                        <div class="item-card-footer pt-2 flex items-center justify-between gap-3 border-t border-[var(--border)]">
                            <div class="item-card-meta flex min-w-0 items-center gap-4">
                                <div class="flex flex-col shrink-0">
                                    <span class="text-[7px] font-black uppercase text-[var(--secondary)] tracking-widest mb-0.5">Last Update</span>
                                    <span class="text-[9px] font-bold text-[var(--text)]">${createdDate}</span>
                                </div>
                                <div class="flex min-w-0 flex-col">
                                    <span class="text-[7px] font-black uppercase text-[var(--secondary)] tracking-widest mb-0.5">Flight</span>
                                    <span class="truncate text-[9px] font-bold uppercase text-[var(--text)]">${flightNumber}</span>
                                </div>
                            </div>
                            ${canClaim ? `
                                <button onclick="openClaimModal('${escapeHtml(rawReferenceCode)}', '${encodeURIComponent(item.item_description || '')}')" class="claim-btn shrink-0 px-3.5 py-1.5 bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white rounded-lg text-[8px] font-black uppercase tracking-widest transition-all">Claim Item</button>
                            ` : `
                                <button disabled class="claim-btn shrink-0 px-3.5 py-1.5 bg-slate-800 text-slate-500 rounded-lg text-[8px] font-black uppercase tracking-widest cursor-not-allowed">${item.status}</button>
                            `}
                        </div>
                    </div>
                `;
                grid.appendChild(card);
                enhanceSmoothImages(card);
            });

            // Transition
            const title = document.getElementById('view-title');
            if (title) title.textContent = `Viewing: ${airlineName}`;
            airlineView.classList.add('hidden-view');
            itemView.classList.remove('hidden-view');
            itemView.style.display = 'flex';
        }

        function showAirlines() {
            const airlineView = document.getElementById('airline-view');
            const itemView = document.getElementById('item-view');
            const reportView = document.getElementById('report-view');
            const title = document.getElementById('view-title');

            if (title) title.textContent = 'Step 1: Select Airline';
            itemView.classList.add('hidden-view');
            reportView.classList.add('hidden-view');
            setTimeout(() => {
                itemView.style.display = 'none';
                reportView.style.display = 'none';
            }, 300);
            airlineView.classList.remove('hidden-view');
        }

        async function showReportForm() {
            if (!stationSelected) {
                toast.warning('Please select a station first.');
                return;
            }

            const airlineView = document.getElementById('airline-view');
            const itemView = document.getElementById('item-view');
            const reportView = document.getElementById('report-view');
            const title = document.getElementById('view-title');

            // Load airlines for dropdown
            try {
                const res = await fetch(`public_api.php?action=getAirlines&station=${encodeURIComponent(activeStation)}`);
                const data = await res.json();
                const select = document.getElementById('report-airlines');
                select.innerHTML = '<option value="">Select Airline...</option>';
                data.airlines.forEach(al => {
                    select.innerHTML += `<option value="${escapeHtml(al.code)}">${escapeHtml(al.name)} (${escapeHtml(al.code)})</option>`;
                });
            } catch (e) { }

            if (title) title.textContent = 'Report Lost Property';
            airlineView.classList.add('hidden-view');
            itemView.classList.add('hidden-view');
            reportView.classList.remove('hidden-view');
            reportView.style.display = 'flex';
        }

        const PHOTO_COMPRESS_MAX_DIMENSION = 1280;
        const PHOTO_COMPRESS_QUALITY = 0.68;

        function canBrowserCompressImage(file) {
            return file
                && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
                && typeof createImageBitmap === 'function'
                && typeof DataTransfer !== 'undefined';
        }

        async function compressImageFile(file) {
            if (!canBrowserCompressImage(file)) return file;
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, PHOTO_COMPRESS_MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));
            const width = Math.max(1, Math.round(bitmap.width * scale));
            const height = Math.max(1, Math.round(bitmap.height * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d', { alpha: false });
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(bitmap, 0, 0, width, height);
            bitmap.close?.();

            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', PHOTO_COMPRESS_QUALITY));
            if (!blob || blob.size >= file.size) return file;
            const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
            return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
        }

        async function compressedFormData(form) {
            const formData = new FormData(form);
            const input = form.querySelector('input[type="file"][name="photo"]');
            const file = input?.files?.[0];
            if (file) {
                formData.set('photo', await compressImageFile(file));
            }
            return formData;
        }

        function updateFileLabel(input) {
            const label = document.getElementById('file-label');
            if (input.files && input.files[0]) {
                label.textContent = `Selected: ${input.files[0].name}`;
                label.classList.add('text-rose-500');
            } else {
                label.textContent = 'Tap to capture or upload photo';
                label.classList.remove('text-rose-500');
            }
        }

        async function submitLostReport(e) {
            e.preventDefault();
            const form = e.target;
            const formData = await compressedFormData(form);
            formData.append('action', 'reportLost');
            formData.append('csrf_token', publicApiCsrfToken);

            try {
                const res = await fetch(`public_api.php?station=${encodeURIComponent(activeStation)}`, {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();
                if (result.success) {
                    const reference = result.tag || result.tag_no || result.reference_code || result.id || result.report_ref;
                    if (!reference) {
                        toast.error('Report saved, but the reference number was not returned. Please contact staff.');
                        return;
                    }
                    const emailNote = result.email_warning ? ` ${result.email_warning}` : '';
                    toast.success(`Report Submitted! Your reference is: ${reference}. Staff will review it before adding it to records.${emailNote}`);
                    form.reset();
                    document.getElementById('file-label').textContent = 'Tap to capture or upload photo';
                    document.getElementById('file-label').classList.remove('text-rose-500');
                    showAirlines();
                } else {
                    toast.error(`Submission failed: ${result.error || 'Please try again.'}`);
                }
            } catch (e) {
                toast.error('Submission failed. Please try again.');
            }
        }

        function filterPassengerItems() {
            const query = document.getElementById('item-search').value.toLowerCase().trim();
            const month = document.getElementById('pax-filter-month').value; // e.g. "2026-05"
            const week = document.getElementById('pax-filter-week').value; // e.g. "this_week", "last_week", etc.
            const date = document.getElementById('pax-filter-date').value; // e.g. "2026-05-18"
            updatePassengerFilterSummary();

            const cards = document.querySelectorAll('.item-card-el');
            cards.forEach(card => {
                const desc = (card.getAttribute('data-desc') || '').toLowerCase();
                const tag = (card.getAttribute('data-tag') || '').toLowerCase();
                const createdAt = card.getAttribute('data-created-at') || ''; // e.g. "2026-05-18 10:15:30"

                // 1. Text Search Filter
                let matchesQuery = true;
                if (query !== '') {
                    matchesQuery = desc.includes(query) || tag.includes(query);
                }

                // 2. Month Filter
                let matchesMonth = true;
                if (month !== '' && createdAt !== '') {
                    matchesMonth = createdAt.startsWith(month);
                }

                // 3. Week Filter
                let matchesWeek = true;
                if (week !== '' && createdAt !== '') {
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);

                    const createdDate = new Date(createdAt.replace(/-/g, '/'));
                    createdDate.setHours(0, 0, 0, 0);

                    const diffTime = today - createdDate;
                    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));

                    if (week === 'this_week') {
                        matchesWeek = diffDays >= 0 && diffDays <= 7;
                    } else if (week === 'last_week') {
                        matchesWeek = diffDays > 7 && diffDays <= 14;
                    } else if (week === '2_weeks_ago') {
                        matchesWeek = diffDays > 14 && diffDays <= 21;
                    } else if (week === '3_weeks_ago') {
                        matchesWeek = diffDays > 21 && diffDays <= 28;
                    } else if (week === 'older') {
                        matchesWeek = diffDays > 28;
                    }
                }

                // 4. Specific Date Filter
                let matchesDate = true;
                if (date !== '' && createdAt !== '') {
                    const createdDateStr = createdAt.split(' ')[0];
                    matchesDate = createdDateStr === date;
                }

                if (matchesQuery && matchesMonth && matchesWeek && matchesDate) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function openClaimModal(tagNo, description) {
            document.getElementById('claim-tag-input').value = tagNo;
            document.getElementById('claim-item-subtitle').textContent = `Item Ref: ${tagNo} (${decodeURIComponent(description)})`;
            const modal = document.getElementById('claim-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeClaimModal() {
            const modal = document.getElementById('claim-modal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
            document.getElementById('claim-item-form').reset();
        }

        async function submitClaim(e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = document.getElementById('claim-submit-btn');
            const originalBtnHtml = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Validating Claim...
            `;

            const formData = new FormData(form);
            formData.append('action', 'claimItem');
            formData.append('csrf_token', publicApiCsrfToken);

            try {
                const res = await fetch(`public_api.php?station=${encodeURIComponent(activeStation)}`, {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.success) {
                    if (result.email_warning) {
                        toast.warning(result.email_warning);
                    } else {
                        toast.success(`Claim request submitted. Staff will verify ownership before releasing the item.`);
                    }
                    closeClaimModal();

                    await initTerminal();
                    const activeHeader = document.querySelector('#active-airline-header h2');
                    if (activeHeader) {
                        showItems(activeHeader.textContent);
                    } else {
                        showAirlines();
                    }
                } else {
                    toast.error(`Claim submission failed: ${result.error || 'Unknown error'}`);
                }
            } catch (err) {
                console.error(err);
                toast.error('An error occurred during submission. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
        }

        function zoomImage(src) {
            const overlay = document.getElementById('image-zoom-overlay');
            const img = document.getElementById('image-zoom-img');
            img.src = src;
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
        }

        function closeZoomedImage() {
            const overlay = document.getElementById('image-zoom-overlay');
            overlay.classList.remove('flex');
            overlay.classList.add('hidden');
        }

        // Custom premium stacked Toast Manager
        class ToastManager {
            constructor() {
                let container = document.getElementById('global-toast-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'global-toast-container';
                    container.className = 'toast-container';
                    document.body.appendChild(container);
                }
                this.container = container;
            }

            show(message, type = 'success', duration = 4000) {
                const card = document.createElement('div');

                let iconHtml = '';
                let titleText = 'Notification';
                let typeClass = '';
                let progressBg = '';
                let glowShadow = '';

                if (type === 'success') {
                    typeClass = 'border-emerald-500/30 text-emerald-400';
                    progressBg = 'bg-emerald-500';
                    glowShadow = 'shadow-2xl shadow-emerald-500/10';
                    titleText = 'Success';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-emerald-500/10 flex items-center justify-center text-emerald-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                    `;
                } else if (type === 'error') {
                    typeClass = 'border-rose-500/30 text-rose-400';
                    progressBg = 'bg-rose-500';
                    glowShadow = 'shadow-2xl shadow-rose-500/10';
                    titleText = 'Error';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-rose-500/10 flex items-center justify-center text-rose-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </div>
                    `;
                } else if (type === 'warning') {
                    typeClass = 'border-amber-500/30 text-amber-400';
                    progressBg = 'bg-amber-500';
                    glowShadow = 'shadow-2xl shadow-amber-500/10';
                    titleText = 'Warning';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-amber-500/10 flex items-center justify-center text-amber-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        </div>
                    `;
                } else {
                    typeClass = 'border-blue-500/30 text-blue-400';
                    progressBg = 'bg-blue-500';
                    glowShadow = 'shadow-2xl shadow-blue-500/10';
                    titleText = 'Information';
                    iconHtml = `
                        <div class="w-8 h-8 rounded-full bg-blue-500/10 flex items-center justify-center text-blue-400 shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    `;
                }

                card.className = `toast-card ${typeClass} ${glowShadow}`;

                card.innerHTML = `
                    ${iconHtml}
                    <div class="flex flex-col flex-grow min-w-0 pr-4">
                        <span class="text-[10px] font-black uppercase tracking-widest opacity-80 leading-tight">${titleText}</span>
                        <span class="text-xs font-semibold text-slate-100 mt-1 leading-relaxed break-words">${escapeHtml(message)}</span>
                    </div>
                    <button class="text-slate-400 hover:text-slate-200 transition-colors text-base font-bold leading-none shrink-0 self-center">&times;</button>
                    <div class="toast-progress ${progressBg}" style="animation-duration: ${duration}ms;"></div>
                `;

                const closeBtn = card.querySelector('button');
                closeBtn.onclick = () => this.remove(card);

                this.container.appendChild(card);

                setTimeout(() => card.classList.add('show'), 50);

                const timeoutId = setTimeout(() => this.remove(card), duration);
                card.dataset.timeoutId = timeoutId;
            }

            confirm(message, onConfirm, onCancel = null) {
                const card = document.createElement('div');

                let typeClass = 'border-rose-500/30 text-rose-400';
                let glowShadow = 'shadow-2xl shadow-rose-500/20';
                let titleText = 'Confirm Deletion';
                let iconHtml = `
                    <div class="w-8 h-8 rounded-full bg-rose-500/10 flex items-center justify-center text-rose-400 shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                `;

                card.className = `toast-card ${typeClass} ${glowShadow} !max-w-[450px]`;

                card.innerHTML = `
                    ${iconHtml}
                    <div class="flex flex-col flex-grow min-w-0 pr-4">
                        <span class="text-[10px] font-black uppercase tracking-widest opacity-80 leading-tight">${titleText}</span>
                        <span class="text-xs font-semibold text-slate-100 mt-1 leading-relaxed break-words">${escapeHtml(message)}</span>
                        <div class="flex items-center gap-2 mt-3">
                            <button class="confirm-btn px-3 py-1.5 bg-rose-500 hover:bg-rose-600 text-white rounded-lg text-[9px] font-black uppercase tracking-widest transition-all shadow-md shadow-rose-500/20 hover:scale-[1.03] active:scale-[0.98]">Yes, Delete</button>
                            <button class="cancel-btn px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 rounded-lg text-[9px] font-black uppercase tracking-widest transition-all hover:scale-[1.03] active:scale-[0.98]">Cancel</button>
                        </div>
                    </div>
                    <button class="close-btn text-slate-400 hover:text-slate-200 transition-colors text-base font-bold leading-none shrink-0 self-start">&times;</button>
                `;

                const confirmBtn = card.querySelector('.confirm-btn');
                const cancelBtn = card.querySelector('.cancel-btn');
                const closeBtn = card.querySelector('.close-btn');

                confirmBtn.onclick = () => {
                    this.remove(card);
                    if (onConfirm) onConfirm();
                };

                cancelBtn.onclick = () => {
                    this.remove(card);
                    if (onCancel) onCancel();
                };

                closeBtn.onclick = () => {
                    this.remove(card);
                    if (onCancel) onCancel();
                };

                this.container.appendChild(card);
                setTimeout(() => card.classList.add('show'), 50);
            }

            remove(card) {
                if (!card.parentNode) return;
                clearTimeout(card.dataset.timeoutId);
                card.classList.remove('show');
                card.classList.add('hide');
                setTimeout(() => {
                    if (card.parentNode) {
                        card.remove();
                    }
                }, 400);
            }
        }

        window.toast = {
            show: (message, type = 'success', duration = 4000) => {
                if (!window.toastManager) {
                    window.toastManager = new ToastManager();
                }
                window.toastManager.show(message, type, duration);
            },
            success: (message, duration = 4000) => window.toast.show(message, 'success', duration),
            error: (message, duration = 4000) => window.toast.show(message, 'error', duration),
            warning: (message, duration = 4000) => window.toast.show(message, 'warning', duration),
            info: (message, duration = 4000) => window.toast.show(message, 'info', duration),
            confirm: (message, onConfirm, onCancel = null) => {
                if (!window.toastManager) {
                    window.toastManager = new ToastManager();
                }
                window.toastManager.confirm(message, onConfirm, onCancel);
            }
        };

        if (stationSelected) {
            initTerminal();
        }
        enhanceSmoothImages();
    </script>
</body>

</html>
