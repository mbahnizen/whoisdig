# Changelog

All notable changes to WHOISDIG are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] — 2026-04-18

### Added
- Complete Wasmer Edge deployment configuration (`app.yaml`, `wasmer.toml`)
- Persistent storage volume integration for Wasmer Edge
- TLD Preference Memory — learns and caches RDAP preference per-TLD when WHOIS fails

### Changed
- Reverted to `command: run` for Wasmer Edge to bypass `staticfile` auto-detection
- Removed `LOCK_EX` and file locking (`flock`) across all utilities (Cache, Metrics, CircuitBreaker) to fix `errno 29` (ESPIPE) errors on WASI S3 volumes
- Replaced `fopen` and `fwrite` with atomic `file_put_contents` for S3 object compatibility

### Fixed
- Wasmer Edge auto-detection aggressively switching to `static-web-server` by renaming `index.html` to `index.php`
- UI ghost gaps when information cards expand by collapsing padding and borders
- Suppressed filesystem warnings for restrictive WASI environments

---

## [2.0.0] — 2026-04-18

### Added
- Security hardening: XSS prevention, CORS configuration, error isolation
- Storage protection via `.htaccess`
- `.gitignore` for clean repository hygiene
- Configurable CORS origin via `WHOISDIG_CORS_ORIGIN` environment variable
- `LOCK_EX` on all file writes for concurrency safety

### Changed
- Unified cache system — removed redundant `SimpleCache`, using single SWR `Cache` only
- `sanitizeInput()` no longer applies `htmlspecialchars()` (moved to output layer)
- Refresh flag now properly passes through all layers (API → Checker → Service)
- Bulk endpoints capped at `set_time_limit(300)` instead of unlimited
- Exception messages are no longer exposed to API clients
- RDAP URL construction uses regex-based path normalization
- `ip2long()` uses unsigned conversion for 32-bit PHP compatibility

### Fixed
- DNS constant crash for unsupported record types (`AXFR` removed)
- IPv6 address handling in IP decimal/hex conversion
- RDAP URL double-path issue (`/domain/domain/...`)
- Integration test updated for IP lookup support (no longer stale)

### Removed
- `SimpleCache` class from `config/app.php`
- `AXFR` from valid DNS record types
- Inline `onclick` handlers from frontend (replaced with `addEventListener`)

---

## [1.3.0] — 2026-04-18

### Added
- Dark/light theme toggle with persistent preference
- Glassmorphism card UI with smooth micro-animations
- Summary row collapse on card expand
- Progressive loading with skeleton cards
- Smart input parser (auto-detects domains vs IPs)
- Domain/IP counter in input area
- Force Refresh checkbox in UI

### Changed
- Frontend refactored into separate HTML, CSS, and JS files
- Card components redesigned for information density
- Filter bar with status-based filtering (Registered / Available / Error)

---

## [1.2.0] — 2026-04-17

### Added
- Hybrid WHOIS + RDAP resolution engine
- RDAP-first strategy for modern TLDs (`.dev`, `.app`, `.page`, `.google`)
- WHOIS referral chaining (up to 2 levels deep)
- IANA-based WHOIS server discovery
- RDAP bootstrap JSON fallback for server resolution
- Circuit breaker pattern for external service resilience
- Stale-While-Revalidate (SWR) cache with file locking
- Public Suffix List integration (auto-download + fallback)
- Domain lifecycle analysis (age, days until expiry)
- Metrics logging (JSONL format)

### Changed
- Architecture refactored into modular service layers (Clients, Parsers, Resolvers, Utils)
- Cache moved from `SimpleCache` to purpose-built `Cache` class with SWR semantics

---

## [1.1.0] — 2026-04-17

### Added
- IP address intelligence via RDAP `/ip` endpoint
- GeoIP enrichment with multi-provider fallback (ip-api.com → ipwho.is)
- ASN, ISP, and proxy/VPN/hosting detection
- Reverse DNS (PTR) lookup for IP addresses
- IP technical details (decimal, hex conversion)
- Dedicated IP intelligence card in frontend
- Negative caching for failed GeoIP lookups

### Changed
- `WhoisService` now auto-detects IP vs domain input
- Frontend dynamically renders IP cards vs domain cards

---

## [1.0.0] — 2026-04-17

### Added
- Core WHOIS domain lookup via Port 43
- DNS record lookup (A, AAAA, MX, NS, CNAME, TXT, SOA, SRV, PTR)
- Bulk domain processing (up to 500 domains)
- File-based rate limiting (120 requests/hour per IP)
- Input validation and sanitization
- Activity logging
- Responsive frontend interface
- API router with 4 endpoints (`whois-single`, `whois-bulk`, `dig`, `dig-bulk`)
