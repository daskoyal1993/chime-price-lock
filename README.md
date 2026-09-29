# dscript — dynamic GLP-1 funnel

Plain PHP + JavaScript funnel (no framework, no Composer, no build step): quiz → product → checkout (Vrio pre-auth) →
Vouched identity check → order hand-off to the patient portal. Questions, products and prices come from the funnel API;
design and text come from `projects/<brand>/*.json`; keys come from `.env`.

**Team docs are in [`docs/`](docs/README.md):** [`docs/DEVELOPER.md`](docs/DEVELOPER.md) (code, integrations, logs, Git workflow) ·
[`docs/DESIGNER.md`](docs/DESIGNER.md) (text, images, colors, layout, the `design` branch).

| Read next | For |
|---|---|
| `docs/` | developer and designer guides (start at `docs/README.md`) |
| `projects/README.md` | page JSON, components, CSS variables, rebranding, design roadmap |
| `PRODUCT-NEW-MIGRATION.md` §0 | how the whole flow works, integrations, what is checked, still to do |
| `NOTES-OPEN-ITEMS.md` | open decisions and temporary values, by item |

## Flow

```
index.php (quiz) → product.php → checkout.php ──(Vrio pre-auth, Vouched crosscheck + DOB)──┐
                                                                                            ├─ verified     → process.php → patient portal
                                                                                            └─ not verified → idcheck.php ─┬─ verified → process.php
                                                                                                                            └─ failed   → usernotverified.php (GHL tag failed_id)
```

A prepaid card refused on the main campaign is retried on the prepaid campaign (`campaign_set → prepaid_campaign`).
Every browser call goes to `submit.php?action=track|lead|verify|intake|product|checkout|idcheck`. `lib/LeadFlow.php` runs the
DB, Vrio, GHL, Vouched and portal steps and logs each one to `storage/logs/lead-flow.log`.

## Requirements

- XAMPP (Apache + PHP 8.x + MySQL 8 / MariaDB). Developed on PHP 8.2.12.
- PHP extensions `curl`, `json`, `mbstring`, `openssl`, `pdo_mysql` (on by default in XAMPP).
- Apache must read `.htaccess` (`AllowOverride All`): it hides `lib/`, `components/`, `projects/*.json`, `storage/`, `.env`, `*.md`.
- A local `.env` from the project lead, plus staging keys for the integrations you work on.

## Local setup

1. Clone into the XAMPP web root and start Apache + MySQL:
   ```bash
   cd C:/xampp/htdocs
   git clone git@github.com:INV2025/dynamicquiz.git dscript   # or https://github.com/INV2025/dynamicquiz.git
   ```
2. Copy `.env.example` to `.env`.
3. **Before opening any page, turn off what you are not testing.** `config.php` falls back to live Vrio, GHL, xverify and
   Google Maps keys, and `FUNNEL_PAYMENT_DRIVER` defaults to `vrio` (real pre-auths). In `.env`, `KEY=` (nothing) keeps the
   `config.php` default; `KEY=''` sets it empty and turns that integration off:
   ```bash
   FUNNEL_PAYMENT_DRIVER=test        # approves FUNNEL_TEST_CARD only, charges nothing
   FUNNEL_VRIO_API_KEY=''            # no Vrio prospects / pre-auths
   FUNNEL_GHL_TOKEN=''               # no GHL contacts
   FUNNEL_XVERIFY_API_KEY=''         # no email / phone checks
   FUNNEL_PORTAL_API_KEY=''          # no portal orders
   FUNNEL_API_URL=                   # empty = data/updated-sample-funnel.json
   ```
4. Database (optional — without it leads are just not saved): create a database in phpMyAdmin, import
   `database/001_create_dynamic_remedy.sql` (an existing old table: run `002_upgrade_dynamic_remedy.sql` step by step) and
   `database/003_create_flow_tracking.sql` (progress tracking) and `database/004_create_error_logs.sql` (QA error log), then:
   ```bash
   FUNNEL_DB_HOST=localhost
   FUNNEL_DB_NAME=dscript_local
   FUNNEL_DB_USER=root
   FUNNEL_DB_PASS=''                 # XAMPP root has no password
   ```
5. `php tools/check-integrations.php` — OK / SKIP / FAIL per integration; writes nothing (the DB test row is rolled back).
6. Open `http://localhost/dscript/`, answer the quiz, pick a plan, pay with **4111 2222 3333 4444** (any future expiry, any CVV).
   Prepaid-card test: **4111 2222 3333 6666** with `FUNNEL_PAYMENT_DRIVER=vrio` — Vrio refuses it on the main campaign
   ("Prepaid cards not allowed…") and checkout retries on the prepaid campaign (log: `prepaid card → retried on campaign …`).
   With the `test` driver this card is simply declined.
7. Watch `storage/logs/lead-flow.log`.

## Configuration

All settings are in `config.php`; each server overrides them with its own `.env` (`FUNNEL_…`, see `.env.example`).

| Integration | `.env` | `''` (empty) means |
|---|---|---|
| Brand / design | `FUNNEL_PROJECT` | `chime` |
| Funnel API | `FUNNEL_API_URL`, `FUNNEL_API_TOKEN` | local sample JSON |
| Database | `FUNNEL_DB_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASS`, `_TABLE` | leads not saved |
| Vrio | `FUNNEL_VRIO_API_KEY`, `_CONNECTION_ID`, `_API_URL`, `_SHIPPING_PROFILE_ID` | Vrio skipped |
| Payment | `FUNNEL_PAYMENT_DRIVER` (`vrio` / `test` / `none`), `FUNNEL_TEST_CARD` | — |
| GoHighLevel | `FUNNEL_GHL_TOKEN`, `_LOCATION_ID`, `_API_URL` | GHL skipped |
| xverify | `FUNNEL_XVERIFY_API_KEY`, `_DOMAIN`, `_API_URL` | no check |
| Membership | `FUNNEL_MEMBERSHIP_API_URL`, `_API_TOKEN` | built-in sample (not a member) |
| Vouched | `FUNNEL_VOUCHED_API_KEY`, `_APP_ID`, `_API_URL` | keys from the funnel JSON |
| Tenant secret | `FUNNEL_TENANT_SECRET_KEY` | `process.php` can't send orders |
| Patient portal | `FUNNEL_PORTAL_API_KEY`, `FUNNEL_PORTAL_API_URL` | orders not sent |
| Google Places | `FUNNEL_GOOGLE_MAPS_KEY` | no address autocomplete |

## Where to change what

| Change | Where |
|---|---|
| Logo, brand name, colors, fonts, footer, product images | `projects/<brand>/site.json` |
| Page text, images, section order / visibility | `projects/<brand>/<page>.json` |
| A new design (extra classes, design CSS, component variants — HTML and logic unchanged) | `designs/<design>/config.json` + `design.css`; active: `site.json → "design"` or `FUNNEL_DESIGN`; preview `?design=<name>`. Guide: `docs/NEW-DESIGN.md` |
| Privacy, terms, telehealth consent, HIPAA, refund, shipping pages | `projects/<brand>/legal/<key>.html` (list: `legal.json`; URL `legal.php?page=<key>`) |
| Section markup | `components/<group>/*.php` — keep the ids, classes and `data-*` the JS uses (listed in each header comment) |
| Styling | `assets/css/*.css`, or `projects/<brand>/css/*.css` for one brand |
| Flow, validation, integrations | page controllers (`*.php` in the root), `submit.php`, `lib/`, `assets/*.js` |
| DB schema | a new numbered file in `database/` |

## Progress tracking (drop-offs)

`lib/FlowTracker.php` records the last step each visitor reached in `flow_tracking` (one row per visitor) and every step
change in `flow_tracking_events`. Tables: `database/003_create_flow_tracking.sql` (report queries at the end of that file).

- **Visitor id:** `tracking_id` from the `fid` cookie (HttpOnly, 1 year), set on the first page load — before email/phone.
  `lead_id` (the `dynamic_remedy` row) and `prospect_id` (Vrio) are added once the contact screen is saved.
- **Steps:** quiz screens `step1` … `stepN` in `projects/<brand>/index.json → screens` order, with `last_step_key` = the
  screen's first question (`funnel.js → trackScreen()` → `submit.php?action=track`); then `product` (900) and `checkout` (910).
  `max_step_index` = furthest reached (Back doesn't lower it).
- **Status:** `in_progress` → `disqualified` (DQ answer) or `completed` (checkout payment approved; `last_step` stays `checkout`,
  `completed_at` set). A new quiz start after completion gets a new tracking id.
- The drop-off point is simply the last recorded step — no unload/close events. No personal data is stored.
- Turn off with `config.php → tracking.enabled = false`. DB problems are logged as `[TRACK] FAILED` and never stop the funnel.

## Logs and troubleshooting

- **QA error log** — every error of a test in one place: start with `?qa_test=start`, test, `?qa_test=end`, then
  `php tools/qa-errors.php` or `qa-errors.php?key=<FUNNEL_QA_KEY>`. Details: `docs/DEVELOPER.md` → "QA error log".

- `storage/logs/lead-flow.log`: one line per step (`[DB]`, `[VRIO]`, `[GHL]`, `[VOUCHED]`, `[PORTAL]` …, `OK` / `FAILED` / `SKIP`).
  The portal payload and reply are logged in full (`[PORTAL] SENT …` / `[PORTAL] REPLY …`), and so is every Vrio pre-auth call
  (`[VRIO-PREAUTH] SENT … attempt N campaign X payload` / `REPLY …`, card masked to the last 4 digits, no CVV) followed by one
  result line on the campaign actually charged. Failures and portal lines also go to the PHP error log.
- The log holds patients' personal and health data: never paste it into tickets or chats.
- Funnel API changes not showing → delete `storage/cache/`.
- "Page config not found" → `FUNNEL_PROJECT` names a missing `projects/` folder. "Unknown component" → a page JSON names a missing `components/` file.

## Deployment

A push to `main` goes live: `.github/workflows/deploy.yml` uploads the changed files over FTPS (secrets `FTP_SERVER`,
`FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`). `main` is protected: work on a branch (the designer uses `design`)
and merge through a pull request — see `docs/DEVELOPER.md` → "Git workflow".
Not uploaded: `.env`, `storage/logs/`, `storage/cache/`, `demo/`, `database/`, `*.md`. Each server keeps its own `.env`;
SQL files are run by hand. Details: `PRODUCT-NEW-MIGRATION.md` §0.8.
