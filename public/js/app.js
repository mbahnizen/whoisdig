/**
 * WHOISDIG — Application Logic
 * ============================
 * Modules: State, Theme, Mode, Input, Processing, Components, Filtering, Utilities
 */

// ===========================
// STATE & CONFIG
// ===========================
const API_BASE = 'api.php';
let currentMode = 'whois';
let selectedDigRecord = 'A';
let processedResults = [];
let isProcessing = false;

// ===========================
// ELEMENTS
// ===========================
const $ = id => document.getElementById(id);
const elInput = $('input-domains');
const elResults = $('results-grid');
const elEmpty = $('empty-state');
const elBtnProcess = $('btn-process');
const elBtnText = $('btn-text');
const elProgress = $('progress-section');
const elProgressBar = $('progress-bar');
const elProgressCount = $('progress-count');
const elProgressLabel = $('progress-label');
const elFilterBar = $('filter-bar');
const elSearch = $('search-results');

// Year
$('year').textContent = new Date().getFullYear();

// ===========================
// THEME
// ===========================
function applyThemeIcon() {
    const icon = $('theme-icon');
    if (document.documentElement.classList.contains('light')) {
        icon.className = 'ph-bold ph-sun text-base';
    } else {
        icon.className = 'ph-bold ph-moon text-base';
    }
}
applyThemeIcon();

function toggleTheme() {
    document.documentElement.classList.toggle('light');
    const isLight = document.documentElement.classList.contains('light');
    localStorage.setItem('whoisdig-theme', isLight ? 'light' : 'dark');
    applyThemeIcon();
}

// ===========================
// MODE SWITCHING
// ===========================
function switchMode(mode) {
    currentMode = mode;
    const btnW = $('btn-mode-whois');
    const btnD = $('btn-mode-dig');
    const digOpts = $('dig-options');

    btnW.classList.toggle('active', mode === 'whois');
    btnD.classList.toggle('active', mode === 'dig');
    btnW.classList.toggle('text-slate-400', mode !== 'whois');
    btnD.classList.toggle('text-slate-400', mode !== 'dig');

    if (mode === 'dig') {
        digOpts.classList.remove('hidden');
        $('section-title').textContent = 'DNS Lookup';
    } else {
        digOpts.classList.add('hidden');
        $('section-title').textContent = 'WHOIS Lookup';
    }
    updateCount();
}

// Dig type buttons
document.querySelectorAll('.dig-type-btn').forEach(btn => {
    btn.addEventListener('click', e => {
        document.querySelectorAll('.dig-type-btn').forEach(b => {
            b.classList.remove('active', 'bg-accent/20', 'text-accent', 'border-accent/30');
            b.classList.add('text-slate-400', 'border-white/5');
        });
        e.target.classList.add('active', 'bg-accent/20', 'text-accent', 'border-accent/30');
        e.target.classList.remove('text-slate-400', 'border-white/5');
        selectedDigRecord = e.target.dataset.type;
    });
});

// ===========================
// INPUT PARSING
// ===========================
function extractDomains(text) {
    const domainRegex = /\b([a-zA-Z0-9_]([a-zA-Z0-9\-_]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}\b/i;
    const ipRegex = /\b(?:\d{1,3}\.){3}\d{1,3}\b/;
    const domains = new Set(), ips = new Set();

    text.split(/\n/).forEach(line => {
        let l = line.trim();
        if (!l) return;

        const process = token => {
            const t = token.trim();
            if (!t) return;
            const ipM = t.match(ipRegex);
            if (ipM) { ips.add(ipM[0]); return; }
            const dM = t.match(domainRegex);
            if (dM) domains.add(dM[0].toLowerCase());
        };

        if (l.includes(':')) {
            const parts = l.split(':');
            const key = parts[0].trim();
            if (ipRegex.test(key) || domainRegex.test(key)) { process(key); return; }
            parts.slice(1).join(':').split(',').forEach(process);
            return;
        }
        l.split(',').forEach(process);
    });

    return { domains: [...domains], ips: [...ips], all: [...domains, ...ips] };
}

function updateCount() {
    const data = extractDomains(elInput.value);
    $('domain-count').textContent = data.domains.length;
    $('ip-count').textContent = data.ips.length;
    elBtnProcess.disabled = data.all.length === 0;
    return data;
}

elInput.addEventListener('input', updateCount);
elInput.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        if (!elBtnProcess.disabled) processDomains();
    }
});

function clearInput() {
    elInput.value = '';
    elResults.innerHTML = '';
    elResults.classList.add('hidden');
    elEmpty.classList.remove('hidden');
    elFilterBar.classList.add('hidden');
    elProgress.classList.add('hidden');
    processedResults = [];
    updateCount();
}

// ===========================
// PROCESSING ENGINE (Progressive)
// ===========================
async function processDomains() {
    if (isProcessing) return;
    const data = updateCount();
    let targets;

    if (currentMode === 'dig') {
        targets = selectedDigRecord === 'PTR' ? data.ips : data.domains;
    } else {
        targets = data.all;
    }

    targets = targets.slice(0, 500);
    if (targets.length === 0) return;

    // UI: start
    isProcessing = true;
    elBtnProcess.disabled = true;
    elBtnText.textContent = 'Processing...';
    elEmpty.classList.add('hidden');
    elResults.innerHTML = '';
    elResults.classList.remove('hidden');
    processedResults = [];

    const total = targets.length;
    let done = 0;

    // Show progress for multiple domains
    if (total > 1) {
        elProgress.classList.remove('hidden');
        elProgressBar.style.width = '0%';
        elProgressCount.textContent = `0 / ${total}`;
        elProgressLabel.textContent = 'Processing...';
    }

    // Show skeletons
    targets.forEach((_, i) => {
        elResults.appendChild(createSkeleton(i));
    });

    // Process each domain individually (progressive)
    const refresh = $('check-refresh').checked;

    for (let i = 0; i < targets.length; i++) {
        const domain = targets[i];
        try {
            const action = currentMode === 'whois' ? 'whois-single' : 'dig';
            const params = new URLSearchParams({ action, domain, type: selectedDigRecord });
            if (refresh) params.append('refresh', '1');

            const res = await fetch(`${API_BASE}?${params}`);
            const rawText = await res.text();
            let result;
            try {
                result = JSON.parse(rawText);
            } catch (parseErr) {
                console.error("Failed to parse JSON. Raw response:", rawText);
                throw new Error("Invalid response from server: " + rawText.substring(0, 100));
            }

            // Replace skeleton with real card
            const skeleton = elResults.querySelector(`[data-skeleton="${i}"]`);
            if (skeleton) {
                let card;
                if (currentMode === 'whois') {
                    card = result.is_ip ? createIpCard(result) : createWhoisCard(result);
                } else {
                    card = createDigCard(result);
                }
                skeleton.replaceWith(card);
            }

            processedResults.push(result);
        } catch (err) {
            const skeleton = elResults.querySelector(`[data-skeleton="${i}"]`);
            if (skeleton) {
                skeleton.replaceWith(createErrorCard(domain, err.message));
            }
            processedResults.push({ success: false, domain, error: err.message });
        }

        done++;
        if (total > 1) {
            const pct = Math.round((done / total) * 100);
            elProgressBar.style.width = pct + '%';
            elProgressCount.textContent = `${done} / ${total}`;
        }
    }

    // UI: done
    if (total > 1) {
        elProgressLabel.textContent = 'Complete!';
        elFilterBar.classList.remove('hidden');
    }
    if (total === 1 && processedResults.length > 0) {
        // Auto-expand single result
        const firstCard = elResults.querySelector('.result-card');
        if (firstCard) toggleCard(firstCard);
    }

    isProcessing = false;
    elBtnProcess.disabled = false;
    elBtnText.textContent = 'Start Checking';
}

// ===========================
// SKELETON COMPONENT
// ===========================
function createSkeleton(index) {
    const div = document.createElement('div');
    div.setAttribute('data-skeleton', index);
    div.className = 'glass rounded-2xl p-5 animate-slide-up';
    div.style.animationDelay = `${index * 40}ms`;
    div.innerHTML = `
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="skeleton w-5 h-5 rounded-full"></div>
                <div class="skeleton w-36 h-5"></div>
            </div>
            <div class="skeleton w-20 h-6 rounded-full"></div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
            <div class="skeleton w-full h-14 rounded-xl"></div>
            <div class="skeleton w-full h-14 rounded-xl"></div>
            <div class="skeleton w-full h-14 rounded-xl"></div>
            <div class="skeleton w-full h-14 rounded-xl"></div>
        </div>
    `;
    return div;
}

// ===========================
// WHOIS CARD COMPONENT
// ===========================
function createWhoisCard(data) {
    const div = document.createElement('div');
    div.className = 'result-card glass rounded-2xl overflow-hidden cursor-pointer animate-slide-up transition-all hover:border-accent/20';
    div.setAttribute('data-domain', (data.domain || '').toLowerCase());

    if (!data.success) return createErrorCard(data.domain, data.error);

    const registrar = data.registrar && data.registrar !== 'N/A' ? data.registrar : null;
    const created = data.created && data.created !== 'N/A' ? data.created : null;
    const nsArr = Array.isArray(data.nameservers) ? data.nameservers : [];
    const rawStatuses = Array.isArray(data.status) ? data.status : [];
    const statusStr = rawStatuses.join(' ').toLowerCase();
    const isRegistered = registrar || created || nsArr.length > 0 || rawStatuses.length > 0;

    // Status analysis
    const badges = [];

    if (!isRegistered) {
        badges.push({ label: 'Available', cls: 'badge-emerald', icon: 'check-circle' });
        div.setAttribute('data-status', 'available');
    } else {
        badges.push({ label: 'Registered', cls: 'badge-blue', icon: 'identification-card' });
        div.setAttribute('data-status', 'registered');
        if (statusStr.includes('redemptionperiod')) badges.push({ label: 'Redemption', cls: 'badge-orange', icon: 'clock-countdown' });
        if (statusStr.includes('pendingdelete')) badges.push({ label: 'Pending Delete', cls: 'badge-red', icon: 'trash' });
        if (statusStr.includes('clienthold') || statusStr.includes('serverhold')) badges.push({ label: 'On Hold', cls: 'badge-yellow', icon: 'hand-palm' });
    }

    // Days left
    let daysHtml = '';
    if (data.lifecycle && data.lifecycle.days_until_expiry !== undefined) {
        const d = data.lifecycle.days_until_expiry;
        if (d > 30) daysHtml = `<span class="badge-emerald text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1"><i class="ph-bold ph-shield-check"></i>${d}d</span>`;
        else if (d > 0) daysHtml = `<span class="badge-yellow text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1"><i class="ph-bold ph-warning"></i>${d}d</span>`;
        else daysHtml = `<span class="badge-red text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1"><i class="ph-bold ph-x-circle"></i>Expired</span>`;
    }

    const icon = isRegistered
        ? '<i class="ph-fill ph-identification-card text-blue-400 text-lg"></i>'
        : '<i class="ph-fill ph-check-circle text-emerald-400 text-lg"></i>';

    const badgesHtml = badges.map(b => `<span class="${b.cls} text-[10px] font-bold px-2.5 py-0.5 rounded-lg flex items-center gap-1"><i class="ph-bold ph-${b.icon}"></i>${b.label}</span>`).join('');

    const nsDisplay = nsArr.length > 2 ? nsArr.slice(0, 2).join(', ') + ` +${nsArr.length - 2}` : nsArr.join(', ');

    div.innerHTML = `
        <!-- Card Header -->
        <div class="card-header p-3.5 md:p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3" onclick="toggleCard(this.parentElement)">
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                ${icon}
                <span class="font-display font-bold text-white text-base md:text-lg truncate">${escapeHtml(data.domain)}</span>
                ${daysHtml}
            </div>
            <div class="flex items-center gap-2">
                <div class="flex flex-wrap gap-1.5">${badgesHtml}</div>
                <i class="ph-bold ph-caret-down chevron text-slate-500 text-sm ml-1"></i>
            </div>
        </div>

        <!-- Summary Row -->
        <div class="summary-row px-4 md:px-5 pb-4 grid grid-cols-2 md:grid-cols-4 gap-2.5 text-xs" onclick="toggleCard(this.parentElement)">
            <div class="collapse-col bg-black/15 p-3 rounded-xl border border-white/5">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">Registrar</div>
                <div class="text-slate-200 font-medium truncate" title="${escapeHtml(data.registrar || '')}">${escapeHtml(truncate(data.registrar || '-', 22))}</div>
            </div>
            <div class="collapse-col bg-black/15 p-3 rounded-xl border border-white/5">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">Expiry</div>
                <div class="text-purple-300 font-mono truncate" title="${data.expires || ''}">${formatDate(data.expires)}</div>
            </div>
            <div class="collapse-col bg-black/15 p-3 rounded-xl border border-white/5 hidden md:block">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">Status</div>
                <div class="text-slate-300 font-mono text-[11px] truncate">${escapeHtml(rawStatuses[0]) || '-'}</div>
            </div>
            <div class="collapse-col bg-black/15 p-3 rounded-xl border border-white/5 hidden md:block">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">Nameservers</div>
                <div class="text-slate-300 font-mono text-[11px] truncate">${escapeHtml(nsDisplay) || '-'}</div>
            </div>
        </div>

        <!-- Expandable Detail Body -->
        <div class="card-body">
            <div>
                <div class="px-4 md:px-5 pb-4 pt-1 border-t border-white/5 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                        ${detailCell('Created', formatDate(data.created), 'calendar-blank', 'text-accent')}
                        ${detailCell('Updated', formatDate(data.updated), 'clock-clockwise', 'text-slate-300')}
                        ${detailCell('Expiry', formatDate(data.expires), 'flag', 'text-pink-400')}
                        ${detailCell('Registrar', escapeHtml(data.registrar) || '-', 'buildings', 'text-slate-200')}
                        ${detailCell('WHOIS Server', data.whois_server || '-', 'hard-drives', 'text-slate-300')}
                        ${detailCell('TLD', '.' + (data.tld || '-'), 'globe-hemisphere-east', 'text-slate-300')}
                    </div>

                    <div class="bg-black/20 p-3 rounded-xl border border-white/5">
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2">Nameservers</div>
                        <div class="flex flex-wrap gap-1.5">
                            ${nsArr.length > 0 ? nsArr.map(ns => `<span class="text-[11px] font-mono bg-white/5 text-slate-300 px-2 py-1 rounded-lg border border-white/5">${escapeHtml(ns)}</span>`).join('') : '<span class="text-slate-600 text-[11px]">—</span>'}
                        </div>
                    </div>

                    <div class="bg-black/20 p-3 rounded-xl border border-white/5">
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2">Domain Status</div>
                        <div class="flex flex-wrap gap-1.5">
                            ${rawStatuses.length > 0 ? rawStatuses.map(s => `<span class="text-[11px] font-mono bg-white/5 text-slate-400 px-2 py-1 rounded-lg border border-white/5">${escapeHtml(s)}</span>`).join('') : '<span class="text-slate-600 text-[11px]">—</span>'}
                        </div>
                    </div>

                    ${data.raw ? `
                    <details class="group">
                        <summary class="text-[10px] uppercase tracking-wider font-bold text-slate-500 cursor-pointer hover:text-slate-300 transition-colors flex items-center gap-1.5">
                            <i class="ph ph-terminal"></i> Raw WHOIS Output
                            <i class="ph ph-caret-down text-[10px] group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <pre class="mt-2 bg-black/40 p-3 rounded-xl text-[11px] font-mono text-emerald-400/80 max-h-48 overflow-auto border border-white/5 whitespace-pre-wrap break-all">${escapeHtml(atob(data.raw))}</pre>
                    </details>` : ''}

                    <div class="flex gap-2 pt-1">
                        <a href="http://${escapeHtml(data.domain)}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" class="text-[11px] font-medium px-3 py-1.5 rounded-lg bg-white/5 text-slate-400 hover:text-white hover:bg-white/10 transition-colors flex items-center gap-1.5 border border-white/5">
                            <i class="ph-bold ph-arrow-square-out"></i> Visit
                        </a>
                    </div>
                </div>
            </div>
        </div>
    `;

    div._data = data;
    return div;
}

function detailCell(label, value, icon, colorClass) {
    const safeVal = escapeHtml(value);
    return `
        <div class="bg-black/20 p-3 rounded-xl border border-white/5">
            <div class="flex items-center gap-1.5 mb-1">
                <i class="ph ph-${icon} text-slate-500 text-xs"></i>
                <span class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider">${escapeHtml(label)}</span>
            </div>
            <div class="${colorClass} font-mono text-xs truncate" title="${safeVal}">${safeVal}</div>
        </div>
    `;
}

// ===========================
// IP ADDRESS CARD COMPONENT
// ===========================
function createIpCard(data) {
    const div = document.createElement('div');
    div.className = 'result-card ip-card glass rounded-2xl overflow-hidden cursor-pointer animate-slide-up transition-all hover:border-purple-500/20';
    div.setAttribute('data-domain', (data.domain || '').toLowerCase());
    div.setAttribute('data-status', 'registered');

    if (!data.success) return createErrorCard(data.domain, data.error);

    const rawStatuses = Array.isArray(data.status) ? data.status : [];
    const geo = data.geo || {};
    const countryCode = data.country && data.country !== 'N/A' ? data.country : (geo.country_code || '');
    const countryFlag = countryCode ? countryToFlag(countryCode) : '';
    const countryName = geo.country_name || countryCode || '-';
    const ipRange = (data.start_address && data.start_address !== 'N/A')
        ? `${data.start_address} — ${data.end_address}` : '-';

    // Extra badges
    const extraBadges = [];
    if (geo.is_proxy) extraBadges.push({ label: 'Proxy / VPN', cls: 'badge-orange', icon: 'shield-warning' });
    if (geo.is_hosting) extraBadges.push({ label: 'Hosting / DC', cls: 'badge-slate', icon: 'hard-drives' });
    if (geo.is_mobile) extraBadges.push({ label: 'Mobile', cls: 'badge-blue', icon: 'device-mobile' });

    const extraBadgesHtml = extraBadges.map(b => `<span class="${b.cls} text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1"><i class="ph-bold ph-${b.icon}"></i>${b.label}</span>`).join('');

    // Copy button helper — S-1 FIX: use data-attribute instead of inline onclick
    const copyBtn = (val, label) => `<button data-copy-value="${escapeHtml(val)}" class="copy-btn ml-1 p-0.5 rounded hover:bg-white/10 text-slate-500 hover:text-white transition-colors inline-flex" title="Copy ${escapeHtml(label)}"><i class="ph-bold ph-copy text-[10px]"></i></button>`;

    div.innerHTML = `
        <!-- Card Header -->
        <div class="card-header p-3.5 md:p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3" onclick="toggleCard(this.parentElement)">
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <div class="w-8 h-8 rounded-lg bg-purple-500/15 flex items-center justify-center flex-shrink-0">
                    <i class="ph-fill ph-wifi-high text-purple-400 text-base"></i>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-1">
                        <span class="font-display font-bold text-white text-base md:text-lg truncate">${escapeHtml(data.domain)}</span>
                        ${copyBtn(data.domain, 'IP')}
                    </div>
                    <span class="text-slate-500 text-[10px] font-mono">${data.hostname ? escapeHtml(data.hostname) + ' · ' : ''}${escapeHtml(data.network_name) || ''}${data.handle && data.handle !== 'N/A' ? ' · ' + escapeHtml(data.handle) : ''}</span>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="badge-purple text-[10px] font-bold px-2.5 py-0.5 rounded-lg flex items-center gap-1">
                    <i class="ph-bold ph-network"></i>${data.ip_version === 'v6' ? 'IPv6' : 'IP Address'}
                </span>
                ${extraBadgesHtml}
                ${countryFlag ? `<span class="text-sm" title="${countryName}">${countryFlag}</span>` : ''}
                <i class="ph-bold ph-caret-down chevron text-slate-500 text-sm ml-1"></i>
            </div>
        </div>

        <!-- Summary Row -->
        <div class="summary-row px-4 md:px-5 pb-4 grid grid-cols-2 md:grid-cols-4 gap-2.5 text-xs" onclick="toggleCard(this.parentElement)">
            <div class="collapse-col bg-purple-500/5 p-3 rounded-xl border border-purple-500/10">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">ISP / Organization</div>
                <div class="text-slate-200 font-medium truncate" title="${geo.isp || data.organization || ''}">${truncate(geo.isp || data.organization || '-', 22)}</div>
            </div>
            <div class="collapse-col bg-purple-500/5 p-3 rounded-xl border border-purple-500/10">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">Location</div>
                <div class="text-purple-300 font-mono truncate">${countryFlag} ${geo.city || '-'}${geo.region ? ', ' + geo.region : ''}</div>
            </div>
            <div class="collapse-col bg-purple-500/5 p-3 rounded-xl border border-purple-500/10 hidden md:block">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">ASN</div>
                <div class="text-slate-300 font-mono text-[11px] truncate">${data.asn || '-'}</div>
            </div>
            <div class="collapse-col bg-purple-500/5 p-3 rounded-xl border border-purple-500/10 hidden md:block">
                <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-1">CIDR</div>
                <div class="text-slate-300 font-mono text-[11px] truncate">${data.cidr || '-'}</div>
            </div>
        </div>

        <!-- Expandable Detail Body -->
        <div class="card-body">
            <div>
                <div class="px-4 md:px-5 pb-4 pt-1 border-t border-purple-500/10 space-y-3">

                    <!-- SECTION 1: Location & Network -->
                    ${geo.city ? `
                    <div>
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2 flex items-center gap-1.5">
                            <i class="ph ph-map-pin text-purple-400 text-xs"></i> Location & Network
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                            ${detailCell('City', geo.city || '-', 'buildings', 'text-slate-200')}
                            ${detailCell('Region', geo.region || '-', 'map-trifold', 'text-slate-300')}
                            ${detailCell('Country', countryFlag + ' ' + countryName, 'flag', 'text-slate-200')}
                            ${detailCell('Postal Code', geo.postal || '-', 'mailbox', 'text-slate-300')}
                            ${detailCell('Timezone', geo.timezone || '-', 'clock', 'text-slate-300')}
                            ${geo.lat ? detailCell('Coordinates', geo.lat.toFixed(4) + ', ' + geo.lon.toFixed(4), 'crosshair', 'text-slate-300') : ''}
                        </div>
                    </div>
                    ` : `
                    <div class="bg-yellow-500/5 p-2.5 rounded-xl border border-yellow-500/10 text-[11px] text-yellow-400/80 flex items-center gap-2">
                        <i class="ph ph-info text-sm"></i> Geolocation data unavailable (rate limit or provider issue)
                    </div>
                    `}

                    <!-- SECTION 2: Organization & Network -->
                    <div>
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2 flex items-center gap-1.5">
                            <i class="ph ph-buildings text-purple-400 text-xs"></i> Organization & Network
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                            ${detailCell('ISP', geo.isp || data.organization || '-', 'broadcast', 'text-purple-300')}
                            ${detailCell('Organization', data.organization || '-', 'buildings', 'text-slate-200')}
                            ${detailCell('ASN', data.asn ? data.asn + (data.asn_name ? ' (' + data.asn_name + ')' : '') : '-', 'flow-arrow', 'text-accent')}
                            ${detailCell('Network Name', data.network_name || '-', 'tag', 'text-slate-300')}
                            ${detailCell('CIDR', data.cidr || '-', 'chart-pie-slice', 'text-slate-200')}
                            ${detailCell('IP Range', ipRange, 'arrows-out-line-horizontal', 'text-slate-300')}
                            ${detailCell('Allocation Type', data.type || '-', 'folder-open', 'text-slate-300')}
                            ${detailCell('WHOIS Server', data.port43 || '-', 'hard-drives', 'text-slate-300')}
                            ${detailCell('Hostname (PTR)', data.hostname || '-', 'link', 'text-slate-300')}
                        </div>
                    </div>

                    <!-- SECTION 3: Technical Details -->
                    <div>
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2 flex items-center gap-1.5">
                            <i class="ph ph-wrench text-purple-400 text-xs"></i> Technical Details
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                            ${detailCell('IP Version', data.ip_version || '-', 'stack', 'text-slate-200')}
                            ${detailCell('Decimal', data.ip_decimal || '-', 'number-square-one', 'text-slate-300')}
                            ${detailCell('Hex', data.ip_hex || '-', 'hash', 'text-slate-300')}
                            ${detailCell('Registration', formatDate(data.registration), 'calendar-blank', 'text-accent')}
                            ${detailCell('Last Changed', formatDate(data.last_changed), 'clock-clockwise', 'text-slate-300')}
                            ${detailCell('Handle', data.handle || '-', 'identification-badge', 'text-slate-300')}
                        </div>
                    </div>

                    <!-- Abuse Contact -->
                    ${data.abuse_contact ? `
                    <div class="bg-rose-500/5 p-3 rounded-xl border border-rose-500/10">
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2 flex items-center gap-1.5">
                            <i class="ph ph-shield-warning text-rose-400 text-xs"></i> Abuse Contact
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            ${data.abuse_contact.email ? `<span class="text-[11px] font-mono bg-white/5 text-rose-300 px-2 py-1 rounded-lg border border-white/5 flex items-center gap-1"><i class="ph ph-envelope text-[10px]"></i>${data.abuse_contact.email}</span>` : ''}
                            ${data.abuse_contact.phone ? `<span class="text-[11px] font-mono bg-white/5 text-slate-400 px-2 py-1 rounded-lg border border-white/5 flex items-center gap-1"><i class="ph ph-phone text-[10px]"></i>${data.abuse_contact.phone}</span>` : ''}
                            ${data.abuse_contact.handle ? `<span class="text-[11px] font-mono bg-white/5 text-slate-400 px-2 py-1 rounded-lg border border-white/5">${data.abuse_contact.handle}</span>` : ''}
                        </div>
                    </div>
                    ` : ''}

                    <!-- Network Status -->
                    <div class="bg-black/20 p-3 rounded-xl border border-white/5">
                        <div class="text-slate-500 font-semibold text-[10px] uppercase tracking-wider mb-2">Network Status</div>
                        <div class="flex flex-wrap gap-1.5">
                            ${rawStatuses.length > 0 ? rawStatuses.map(s => `<span class="text-[11px] font-mono bg-purple-500/8 text-purple-300 px-2 py-1 rounded-lg border border-purple-500/15">${escapeHtml(s)}</span>`).join('') : '<span class="text-slate-600 text-[11px]">—</span>'}
                        </div>
                    </div>

                    <!-- Raw RDAP Output -->
                    ${data.raw ? `
                    <details class="group">
                        <summary class="text-[10px] uppercase tracking-wider font-bold text-slate-500 cursor-pointer hover:text-slate-300 transition-colors flex items-center gap-1.5">
                            <i class="ph ph-terminal"></i> Raw RDAP Output
                            <i class="ph ph-caret-down text-[10px] group-open:rotate-180 transition-transform"></i>
                        </summary>
                        <pre class="mt-2 bg-black/40 p-3 rounded-xl text-[11px] font-mono text-purple-300/80 max-h-48 overflow-auto border border-white/5 whitespace-pre-wrap break-all">${escapeHtml(atob(data.raw))}</pre>
                    </details>` : ''}
                </div>
            </div>
        </div>
    `;

    div._data = data;

    // S-1 FIX: Attach event listeners for copy buttons instead of inline onclick
    div.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const val = btn.getAttribute('data-copy-value');
            navigator.clipboard.writeText(val).then(() => {
                btn.innerHTML = '<i class="ph-bold ph-check"></i>';
                setTimeout(() => btn.innerHTML = '<i class="ph-bold ph-copy text-[10px]"></i>', 1200);
            });
        });
    });

    return div;
}

// Country code to flag emoji
function countryToFlag(code) {
    if (!code || code.length !== 2) return '';
    const c = code.toUpperCase();
    return String.fromCodePoint(...[...c].map(ch => 0x1F1E6 + ch.charCodeAt(0) - 65));
}

// ===========================
// DIG CARD COMPONENT
// ===========================
function createDigCard(data) {
    const div = document.createElement('div');
    div.className = 'result-card glass rounded-2xl overflow-hidden animate-slide-up';
    div.setAttribute('data-domain', (data.domain || '').toLowerCase());
    div.setAttribute('data-status', data.success ? 'registered' : 'error');

    if (!data.success) return createErrorCard(data.domain, data.error);

    const colorMap = {
        A: 'text-blue-400', AAAA: 'text-blue-400', MX: 'text-emerald-400',
        TXT: 'text-pink-400', CNAME: 'text-purple-400', NS: 'text-orange-400',
        PTR: 'text-yellow-400', SRV: 'text-teal-400'
    };
    const color = colorMap[data.record_type] || 'text-slate-300';

    const records = data.results && data.results.length > 0
        ? data.results.map(r => `<div class="text-[11px] font-mono bg-black/30 px-3 py-2 rounded-lg text-slate-300 border border-white/5 flex items-start gap-2"><i class="ph-bold ph-arrow-elbow-down-right ${color} opacity-60 mt-0.5 flex-shrink-0"></i><span class="break-all">${escapeHtml(r)}</span></div>`).join('')
        : '<div class="text-xs text-slate-500 italic">No records found</div>';

    div.innerHTML = `
        <div class="p-4 md:p-5">
            <div class="flex justify-between items-center mb-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-[11px] font-bold px-2.5 py-1 rounded-lg ${color} bg-current/10 border border-current/20" style="background: currentColor; -webkit-background-clip: unset; background-clip: unset; background: ${color.replace('text-', '').includes('blue') ? 'rgba(59,130,246,0.12)' : color.replace('text-', '').includes('emerald') ? 'rgba(16,185,129,0.12)' : color.replace('text-', '').includes('pink') ? 'rgba(236,72,153,0.12)' : color.replace('text-', '').includes('purple') ? 'rgba(168,85,247,0.12)' : color.replace('text-', '').includes('orange') ? 'rgba(249,115,22,0.12)' : 'rgba(148,163,184,0.12)'}; border-color: ${color.replace('text-', '').includes('blue') ? 'rgba(59,130,246,0.2)' : 'rgba(255,255,255,0.1)'}">${data.record_type}</span>
                    <h3 class="font-display font-bold text-white text-base">${escapeHtml(data.domain)}</h3>
                </div>
                <button data-copy-value="${escapeHtml(data.domain)}" class="copy-btn p-1.5 rounded-lg hover:bg-white/10 text-slate-500 hover:text-white transition-colors" title="Copy">
                    <i class="ph-bold ph-copy text-sm"></i>
                </button>
            </div>
            <div class="space-y-1.5">${records}</div>
        </div>
    `;

    // S-1 FIX: Attach copy listener
    div.querySelector('.copy-btn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        copyText(data.domain);
    });

    return div;
}

// ===========================
// ERROR CARD COMPONENT (with retry)
// ===========================
function createErrorCard(domain, msg) {
    const div = document.createElement('div');
    div.className = 'result-card glass rounded-2xl p-4 md:p-5 border-l-[3px] border-l-rose-500/60 animate-slide-up';
    div.setAttribute('data-domain', (domain || '').toLowerCase());
    div.setAttribute('data-status', 'error');

    // S-1 FIX: Escape all dynamic values to prevent XSS
    const safeDomain = escapeHtml(domain) || 'Unknown';
    const safeMsg = escapeHtml(msg) || 'Unknown error';

    div.innerHTML = `
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-xl bg-rose-500/10 flex items-center justify-center flex-shrink-0">
                    <i class="ph-bold ph-warning text-rose-400 text-base"></i>
                </div>
                <div class="min-w-0">
                    <h3 class="font-bold text-white text-sm truncate">${safeDomain}</h3>
                    <p class="text-rose-400/80 text-xs truncate">${safeMsg}</p>
                </div>
            </div>
            <button data-retry-domain="${safeDomain}" class="retry-btn flex-shrink-0 text-[11px] font-semibold px-3 py-1.5 rounded-lg bg-rose-500/10 text-rose-400 hover:bg-rose-500/20 transition-colors border border-rose-500/20 flex items-center gap-1.5">
                <i class="ph-bold ph-arrow-clockwise"></i> Retry
            </button>
        </div>
    `;

    // S-1 FIX: Use addEventListener instead of inline onclick
    const retryBtn = div.querySelector('.retry-btn');
    retryBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        retryDomain(domain, retryBtn);
    });

    return div;
}

// ===========================
// RETRY SINGLE DOMAIN
// ===========================
async function retryDomain(domain, btn) {
    const card = btn.closest('.result-card');
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Retrying...';

    try {
        const action = currentMode === 'whois' ? 'whois-single' : 'dig';
        const params = new URLSearchParams({ action, domain, type: selectedDigRecord, refresh: '1' });
        const res = await fetch(`${API_BASE}?${params}`);
        const result = await res.json();

        let newCard;
        if (currentMode === 'whois') {
            newCard = result.is_ip ? createIpCard(result) : createWhoisCard(result);
        } else {
            newCard = createDigCard(result);
        }
        card.replaceWith(newCard);
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-arrow-clockwise"></i> Retry';
    }
}

// ===========================
// CARD TOGGLE (expand/collapse)
// ===========================
function toggleCard(cardEl) {
    const body = cardEl.querySelector('.card-body');
    const chevron = cardEl.querySelector('.chevron');
    if (!body) return;

    const isOpening = !body.classList.contains('open');
    body.classList.toggle('open');
    if (chevron) chevron.classList.toggle('open');

    // Smoothly collapse/expand the entire summary row
    const summaryRow = cardEl.querySelector('.summary-row');
    if (summaryRow) {
        if (isOpening) {
            summaryRow.classList.add('collapsed');
        } else {
            summaryRow.classList.remove('collapsed');
        }
    }
}

// ===========================
// FILTERING & SEARCH
// ===========================
function filterResults() {
    const query = elSearch.value.toLowerCase();
    elResults.querySelectorAll('.result-card').forEach(card => {
        const domain = (card.getAttribute('data-domain') || '');
        card.style.display = domain.includes(query) ? '' : 'none';
    });
}

function filterByStatus(status) {
    document.querySelectorAll('.filter-btn').forEach(b => {
        b.classList.remove('active', 'bg-accent/15', 'text-accent', 'border-accent/25');
        b.classList.add('text-slate-400', 'border-white/5');
    });
    const activeBtn = document.querySelector(`.filter-btn[data-filter="${status}"]`);
    if (activeBtn) {
        activeBtn.classList.add('active', 'bg-accent/15', 'text-accent', 'border-accent/25');
        activeBtn.classList.remove('text-slate-400', 'border-white/5');
    }

    elResults.querySelectorAll('.result-card').forEach(card => {
        if (status === 'all') { card.style.display = ''; return; }
        card.style.display = card.getAttribute('data-status') === status ? '' : 'none';
    });
}

// ===========================
// UTILITY FUNCTIONS
// ===========================
function truncate(str, n) {
    if (!str) return '-';
    return str.length > n ? str.substring(0, n - 1) + '…' : str;
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === 'N/A') return '-';
    return dateStr.split('T')[0];
}

function escapeHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 bg-accent text-white text-xs font-bold px-4 py-2 rounded-xl shadow-lg z-50 animate-slide-up';
        toast.textContent = 'Copied!';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 1500);
    });
}
