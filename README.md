# BingoCommerce

Hosted multi-tenant e-commerce platform for bingo halls, churches, and similar venues.

Each hall operates its own independent WooCommerce store on a subdomain (`hallname.parent-domain.com`) inside one managed WordPress Multisite network.

**Platform Owner:** Bingo Innovation (Bingo supplies, est. 1978, Pompano Beach, FL)

> **Note:** This is **NOT a marketplace**. One order belongs to exactly one hall. Halls sell physical products and digital bingo packages. A digital order produces **one QR code per order**, which is redeemed to **one Android device** at the hall entrance.

---

## Technical Stack

| Layer | Technology |
| :--- | :--- |
| **CMS / Network** | WordPress 7.0.3 Multisite (subdomain mode), one site per hall |
| **Commerce Engine** | WooCommerce with HPOS (High-Performance Order Storage) |
| **Runtime** | PHP 8.4 |
| **Database** | MySQL 9.5 (InnoDB) |
| **Object Cache** | Redis (per-hall keys, selective flush enabled) |
| **Media Storage** | S3-compatible Object Storage + CDN |
| **Custom Logic** | `bingocommerce-*` custom plugins + custom indexed tables |

---

## Repository Structure

| Path | Purpose |
| :--- | :--- |
| `wp-content/plugins/bingocommerce-*` | Custom feature plugins — **All core business logic** |
| `wp-content/mu-plugins/bingocommerce-*` | Must-Use platform guards (billing engine, capacity locks, isolation) |
| `wp-content/themes/bingocommerce-*` | Phase 1 standard storefront theme |
| `docs/requirements/` | Business requirements & domain specs |
| `docs/architecture/` | Technical architecture & system data flow |
| `docs/decisions/` | Append-only Architecture Decision Log (`DECISIONS.md`) |
| `docs/runbooks/` | Operational procedures (deployment, backups, incident response) |
| `docs/ui-ux/` | Design references & wireframe links |
| `AGENTS.md` | Universal AI agent source of truth (**Cursor, Claude Code, CodeX**) |
| `PROJECT_STATUS.md` | Current build status, completed tasks, and immediate roadmap |

*WordPress Core files are git-ignored and MUST NOT be edited directly. `wp-config.php` is never committed.*

---

## Local Setup Instructions

### Prerequisites
- **PHP 8.4**
- **MySQL 9.5**
- Local Web Server (Apache / Nginx / Local)
- **WordPress 7.0.3** (included in repository root)

### Setup Steps
1. **Database Creation:** Create a local MySQL database (e.g., `bingocommerce`) via CLI or GUI client.
2. **Configuration:** Copy `wp-config-sample.php` to `wp-config.php` in root, fill in local database credentials, and generate unique security salts.
3. **Web Server Host:** Point your local vhost/web server to the repository root directory.
4. **WordPress Installation:** Complete the initial WordPress setup wizard.
5. **Convert to Subdomain Multisite:**
   - Add `define( 'WP_ALLOW_MULTISITE', true );` to `wp-config.php`.
   - Go to **Tools -> Network Setup** in `wp-admin`, select **Subdomains**, and run the setup.
   - Update `wp-config.php` and `.htaccess` with the generated Multisite configuration rules.
6. **Activate WooCommerce:** Network-activate WooCommerce and ensure High-Performance Order Storage (HPOS) is enabled under WooCommerce Settings.
7. **Create Test Hall:** Create your first test site (e.g., `sunrise.localhost`) and verify subdomain routing.

---

## AI Agent & Developer Guardrails

- **`AGENTS.md`** is the primary source of truth. AI coding tools (Cursor, Claude Code, CodeX) must read this file before generating code.
- **Locked Decisions:** Decisions recorded in `docs/decisions/DECISIONS.md` are locked and must not be altered implicitly by code changes.
- **Session Tracking:** `PROJECT_STATUS.md` must be updated at the conclusion of every development session to track active progress.

---

## Git Conventions

- **Never Commit Credentials:** `wp-config.php`, `/uploads/` directory, cache directories, and core WP runtime files are ignored via `.gitignore`.
- **Atomic Commits:** Small, focused, reviewable commits (one feature/fix per commit).
- **Environment Isolation:** No direct edits on production. All updates pass through staging verification.