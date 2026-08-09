# BingoCommerce — Project Status

*Living document. Update at the END of every work session. Keep it short, factual, and chronological.*

---

## Current Phase
**Phase 0 — Local Environment & Project Foundation**

---

## Done
- **[2026-08-07]** Local stack verified: PHP 8.4.14 (Homebrew), MySQL 9.5, WordPress 7.0.3 installed at repo root (unconfigured).
- **[2026-08-07]** Repository structure created: `docs/` (`architecture/`, `decisions/`, `requirements/`, `runbooks/`, `ui-ux/`).
- **[2026-08-07]** Decisions locked in `docs/decisions/DECISIONS.md`:
  - `ADR-001`: Stack (WP Multisite Subdomain mode + WooCommerce HPOS).
  - `BR-001`: Address book shared across halls; payment tokens isolated per hall.
  - `BR-002`: Session capacity = total qualifying orders per session.
  A qualifying order is a completed order containing at least one digital package for that session.
  Multiple digital packages in one order still count as one qualifying order.
- **[2026-08-07]** AI instruction & memory layer created: `AGENTS.md`, `CLAUDE.md`, `README.md`, `.claude/`.

---

## In Progress
- Local environment finalization (Git hygiene, `.gitignore`, `.cursorrules` alignment).

---

## Next Up (Strict Execution Order)
1. Finalize root `.gitignore`, ensure `wp-config.php` and runtime caches are ignored, then execute initial commit.
2. Configure WordPress as **SUBDOMAIN Multisite** (`wp-config.php` + `.htaccess` rewrite rules).
3. Network-activate WooCommerce, enable HPOS (High-Performance Order Storage), and verify Multisite compatibility.
4. Create first test hall site (`sunrise.localhost`) and verify local subdomain routing.
5. Scaffold initial feature plugin shell: `wp-content/plugins/bingocommerce-core/`.

---

## Blockers / Waiting On
- **Local Dev:** Nothing blocking.
- **Open Business/Infra Items (Tracked in `docs/decisions/`):**
  - Online payment gateway selection
  - Production parent domain name
  - Production hosting provider & Object storage selection (AWS S3 vs. Cloudflare R2)

---

## Environment Notes
- **Local System:** macOS (arm64), PHP 8.4.14 (Homebrew), MySQL 9.5, Apache Web Server
- **WordPress Core:** WordPress 7.0.3 at repo root (Pending `wp-config.php` setup)
- **Rule for AI Agents:** Do NOT start writing custom feature logic in `wp-content/plugins/` until step 4 in "Next Up" is complete and verified.