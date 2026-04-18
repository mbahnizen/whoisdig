<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WHOISDIG — Domain & DNS Intelligence</title>
    <meta name="description" content="Lookup WHOIS, RDAP, and DNS records for any domain instantly. Modern, fast, and beautiful domain intelligence tool.">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">

    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Theme: apply before paint to prevent FOUC -->
    <script>
        const stored = localStorage.getItem('whoisdig-theme');
        if (stored === 'light') document.documentElement.classList.add('light');
        else document.documentElement.classList.remove('light');

        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        display: ['Outfit', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        surface: {
                            50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0',
                            800: '#1a1b2e', 900: '#0d0e1a', 950: '#080812',
                        },
                        accent: {
                            DEFAULT: '#8b5cf6', hover: '#7c3aed',
                            muted: 'rgba(139, 92, 246, 0.15)',
                        },
                        rose: { 400: '#fb7185', 500: '#f43f5e' },
                    },
                    animation: {
                        'shimmer': 'shimmer 2s infinite',
                        'expand': 'expand 0.3s ease-out',
                        'slide-up': 'slideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1)',
                        'pulse-slow': 'pulse 3s infinite',
                    },
                    keyframes: {
                        shimmer: {
                            '0%': { transform: 'translateX(-100%)' },
                            '100%': { transform: 'translateX(100%)' },
                        },
                        expand: {
                            '0%': { opacity: 0, maxHeight: 0 },
                            '100%': { opacity: 1, maxHeight: '800px' },
                        },
                        slideUp: {
                            '0%': { opacity: 0, transform: 'translateY(12px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' },
                        },
                    },
                }
            }
        }
    </script>

    <!-- Application Styles -->
    <link rel="stylesheet" href="css/app.css">
</head>

<body class="min-h-screen antialiased selection:bg-accent/30 selection:text-white">

    <div class="container mx-auto px-4 py-8 md:py-12 max-w-5xl relative z-10">

        <!-- ===== HEADER ===== -->
        <header id="app-header" class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 md:mb-10 gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-accent to-pink-500 flex items-center justify-center shadow-lg shadow-accent/20">
                    <i class="ph-bold ph-globe-hemisphere-west text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="font-display text-2xl md:text-3xl font-bold text-white tracking-tight">
                        WHOISDIG
                    </h1>
                    <p class="text-slate-500 text-xs mt-0.5">Domain & DNS Intelligence</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <!-- Theme Toggle -->
                <button id="btn-theme" onclick="toggleTheme()" class="w-9 h-9 rounded-xl glass flex items-center justify-center text-slate-400 hover:text-white transition-colors" title="Toggle Theme">
                    <i class="ph-bold ph-moon text-base" id="theme-icon"></i>
                </button>

                <!-- Mode Tabs -->
                <div class="flex gap-1 p-1 rounded-xl glass">
                    <button onclick="switchMode('whois')" id="btn-mode-whois" class="tab-pill active flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold border border-transparent">
                        <i class="ph-bold ph-identification-card text-sm"></i> WHOIS
                    </button>
                    <button onclick="switchMode('dig')" id="btn-mode-dig" class="tab-pill flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold text-slate-400 border border-transparent hover:text-slate-200">
                        <i class="ph-bold ph-globe text-sm"></i> DNS Dig
                    </button>
                </div>
            </div>
        </header>

        <!-- ===== INPUT SECTION ===== -->
        <main class="space-y-6">
            <section class="glass rounded-2xl md:rounded-3xl p-5 md:p-7 relative overflow-hidden">
                <!-- Decorative corner glow -->
                <div class="absolute -top-12 -right-12 w-40 h-40 bg-accent/10 rounded-full blur-3xl pointer-events-none"></div>

                <!-- Title Row -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-5 gap-3 relative z-10">
                    <div>
                        <h2 class="text-lg font-display font-bold text-white flex items-center gap-2">
                            <span id="section-title">WHOIS Lookup</span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-accent/10 text-accent border border-accent/20 font-semibold tracking-wider">SMART INPUT</span>
                        </h2>
                        <p class="text-slate-500 text-xs mt-1">Paste domains or IPs (one per line). Results appear progressively.</p>
                    </div>

                    <!-- Dig Record Type (hidden by default) -->
                    <div id="dig-options" class="hidden w-full sm:w-auto">
                        <div class="flex flex-wrap gap-1.5">
                            <button class="dig-type-btn active text-[11px] font-bold px-3 py-1.5 rounded-lg bg-accent/20 text-accent border border-accent/30" data-type="A">A</button>
                            <button class="dig-type-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15 hover:text-slate-200 transition-colors" data-type="AAAA">AAAA</button>
                            <button class="dig-type-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15 hover:text-slate-200 transition-colors" data-type="MX">MX</button>
                            <button class="dig-type-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15 hover:text-slate-200 transition-colors" data-type="NS">NS</button>
                            <button class="dig-type-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15 hover:text-slate-200 transition-colors" data-type="CNAME">CNAME</button>
                            <button class="dig-type-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15 hover:text-slate-200 transition-colors" data-type="TXT">TXT</button>
                            <button class="dig-type-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15 hover:text-slate-200 transition-colors" data-type="PTR">PTR</button>
                        </div>
                    </div>
                </div>

                <!-- Textarea -->
                <div class="relative z-10">
                    <textarea id="input-domains" rows="4"
                        placeholder="example.com&#10;google.com&#10;8.8.8.8"
                        class="input-field w-full p-4 rounded-xl font-mono text-sm leading-relaxed resize-y min-h-[120px] text-slate-300 placeholder-slate-600"></textarea>
                    <button onclick="clearInput()" class="absolute bottom-3 right-3 p-1.5 rounded-lg hover:bg-white/10 text-slate-600 hover:text-slate-300 transition-colors" title="Clear">
                        <i class="ph ph-trash text-base"></i>
                    </button>
                </div>

                <!-- Action Bar -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mt-4 gap-3 relative z-10">
                    <div class="flex items-center gap-4 text-xs text-slate-500 font-medium">
                        <span class="flex items-center gap-1.5">
                            <i class="ph-bold ph-globe text-accent"></i>
                            <span id="domain-count" class="font-mono text-accent font-bold">0</span> domains
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="ph-bold ph-chart-scatter text-pink-400"></i>
                            <span id="ip-count" class="font-mono text-pink-400 font-bold">0</span> IPs
                        </span>
                        <label class="flex items-center gap-1.5 cursor-pointer select-none" title="Bypass cache">
                            <input type="checkbox" id="check-refresh" class="w-3.5 h-3.5 rounded border-slate-600 text-accent focus:ring-accent/30 bg-transparent">
                            <span class="text-slate-500 hover:text-slate-300 transition-colors">Force Refresh</span>
                        </label>
                    </div>
                    <button onclick="processDomains()" id="btn-process"
                        class="btn-primary px-6 py-2.5 rounded-xl font-bold text-white text-sm flex items-center gap-2 w-full sm:w-auto justify-center" disabled>
                        <span id="btn-text">Start Checking</span>
                        <i class="ph-bold ph-arrow-right text-sm"></i>
                    </button>
                </div>

                <!-- Progress Bar (hidden) -->
                <div id="progress-section" class="hidden mt-5 relative z-10">
                    <div class="flex justify-between items-center mb-2">
                        <span id="progress-label" class="text-xs text-slate-400 font-medium">Processing...</span>
                        <span id="progress-count" class="text-xs font-mono text-accent font-bold">0 / 0</span>
                    </div>
                    <div class="h-1.5 w-full bg-white/5 rounded-full overflow-hidden">
                        <div id="progress-bar" class="progress-fill h-full bg-gradient-to-r from-accent to-pink-500 rounded-full" style="width: 0%"></div>
                    </div>
                </div>
            </section>

            <!-- ===== FILTER BAR ===== -->
            <div id="filter-bar" class="hidden flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
                <div class="relative flex-1">
                    <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-sm"></i>
                    <input id="search-results" type="text" placeholder="Search results..."
                        class="input-field w-full pl-9 pr-4 py-2 rounded-xl text-xs text-slate-300" oninput="filterResults()">
                </div>
                <div class="flex gap-1.5">
                    <button onclick="filterByStatus('all')" class="filter-btn active text-[11px] font-semibold px-3 py-1.5 rounded-lg bg-accent/15 text-accent border border-accent/25" data-filter="all">All</button>
                    <button onclick="filterByStatus('registered')" class="filter-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15" data-filter="registered">Registered</button>
                    <button onclick="filterByStatus('available')" class="filter-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15" data-filter="available">Available</button>
                    <button onclick="filterByStatus('error')" class="filter-btn text-[11px] font-medium px-3 py-1.5 rounded-lg text-slate-400 border border-white/5 hover:border-white/15" data-filter="error">Errors</button>
                </div>
            </div>

            <!-- ===== RESULTS ===== -->
            <section id="results-area">
                <!-- Empty State -->
                <div id="empty-state" class="flex flex-col items-center justify-center text-center py-16 opacity-60">
                    <div class="w-20 h-20 mb-5 rounded-2xl bg-white/5 flex items-center justify-center">
                        <i class="ph-duotone ph-magnifying-glass text-4xl text-slate-600"></i>
                    </div>
                    <h3 class="text-base font-display font-bold text-slate-500 mb-1">Ready to Decode</h3>
                    <p class="text-slate-600 text-xs max-w-xs">Paste your domains above and hit "Start Checking" to reveal their secrets.</p>
                </div>

                <!-- Results Grid -->
                <div id="results-grid" class="hidden space-y-3"></div>
            </section>
        </main>

        <!-- ===== FOOTER ===== -->
        <footer class="text-center mt-16 pt-6 border-t border-white/5">
            <div class="text-slate-600 text-xs space-y-1">
                <p>&copy; <span id="year"></span> Developed by <span class="text-slate-400 font-semibold">@mbahnizen</span></p>
                <p class="flex items-center justify-center gap-1 opacity-70">
                    Co-piloted by
                    <span class="bg-gradient-to-r from-blue-400 via-indigo-400 to-purple-400 text-transparent bg-clip-text font-bold flex items-center gap-0.5">
                        <i class="ph-fill ph-sparkle text-indigo-400 text-[10px]"></i> Gemini
                    </span>
                </p>
            </div>
        </footer>
    </div>

    <!-- Application Logic -->
    <script src="js/app.js"></script>
</body>
</html>