# Developer guide

Plain PHP 8 + vanilla JavaScript, no framework, no build step. Setup on a new PC: `../README.md` → "Local setup".
File-by-file detail: `../PRODUCT-NEW-MIGRATION.md` §0.2. Open decisions: `../NOTES-OPEN-ITEMS.md`.

## Flow

```
index.php (quiz, assets/funnel.js)
   │ contact screen: submit.php?action=verify (xverify) → ?action=lead (MEMBERSHIP → DB → Vrio prospect → GHL)
   │ last screen:    ?action=intake (validate, disqualify, save)
   ▼
product.php (assets/product.js) ── ?action=product (plan must belong to the visitor's offer)
   ▼
checkout.php (assets/checkout.js) ── ?action=checkout
   │   Vrio pre-auth (prepaid card → retried on the prepaid campaign) → Vouched crosscheck + DOB
   ├── verified     → process.php → patient portal (redirect_url)            [GHL portal link after the redirect]
   └── not verified → idcheck.php (Vouched plugin) ── ?action=idcheck
                          ├── true  → process.php → portal
                          └── false → usernotverified.php                   [GHL tag failed_id]
```

Every step is logged to `storage/logs/lead-flow.log`. Slow CRM calls run **after** the reply
(`funnel_json_response_then()` / `redirect_now_then()` in `bootstrap.php`) so Cloudflare's 120 s limit is never hit.

## Where things live

| Area | Files |
|---|---|
| Settings | `config.php` (defaults) + `.env` per server (`lib/Env.php`: `KEY=` keeps the default, `KEY=''` empties it) |
| Funnel data | `lib/FunnelRepository.php` — API (`FUNNEL_API_URL`, cached 300 s in `storage/cache/`) or `config.php → sample_file` (now `data/r4mf-v1-funnel.json`). Normalizes steps, questions, conditions, products + offers per campaign (main **and** prepaid), membership set, tenant GHL/Vouched credentials |
| Quiz rules (server copy of `funnel.js`) | `lib/FunnelRules.php` (validation, conditions, BMI, target weight, DQ), `lib/Screens.php` (screens from `index.json`) |
| AJAX endpoint | `submit.php` — `track`, `verify`, `lead`, `intake`, `product`, `checkout`, `idcheck` (all need `X-CSRF-Token`) |
| Orchestration + log | `lib/LeadFlow.php` — `submit()`, `checkout()`, `identity()`, `process()`, `portalLink()`, `idFailed()`, `log()` |
| Integrations | `lib/VrioClient.php`, `lib/Payment.php`, `lib/GhlClient.php`, `lib/VouchedClient.php`, `lib/PortalClient.php`, `lib/XverifyClient.php`, `lib/Membership.php`, `lib/EncodeDecodeHelper.php`, `lib/Http.php` |
| Database | `lib/LeadRepository.php` (`dynamic_remedy`), `lib/FlowTracker.php` (`flow_tracking`, `flow_tracking_events`); SQL in `database/001…003` |
| Rendering | `lib/Page.php` + `lib/view.php` + `components/` + `projects/<brand>/*.json` (see `../projects/README.md`) |
| QA error log | `lib/ErrorLogger.php`, `assets/qa-errors.js`, `qa-log.php` (JS reports), `qa-errors.php` (viewer), `tools/qa-errors.php` (CLI), `database/004_create_error_logs.sql` |
| Pages | `index.php`, `product.php`, `checkout.php`, `idcheck.php`, `idcheck-callback.php` (Vouched server callback), `process.php`, `usernotverified.php`, `legal.php`, `thank-you.php` (not in the flow) |
| Reference | `demo/` — the original live-site scripts this was ported from (not deployed; files with keys are git-ignored) |

## Payments (Vrio pre-auth)

- Driver: `FUNNEL_PAYMENT_DRIVER` = `vrio` (real request to Vrio), `test` (approves `FUNNEL_TEST_CARD` only, order id
  `TEST-…`, nothing sent), `none`. Whether a `vrio` request is a test depends on **Vrio's gateway** for that campaign, not on our code.
- Request = `demo/preauth.php`: `POST {vrio.api_url}` with campaign, one offer (the chosen plan), billing, card, `action: authorize`.
- **Prepaid cards:** Vrio answers `campaign_prepaid_not_allowed` → one retry on `campaign_set → prepaid_campaign`
  with the plan's offer on that campaign (`variants[].crm_campaigns[type=prepaid]`). The charged campaign is kept in
  `$record['order']['crm_campaign_id']` and sent to the portal. Vrio also creates an order for the refused attempt.
- Test cards: `4111 2222 3333 4444` (normal), `4111 2222 3333 6666` (prepaid, `vrio` driver only).
- Log: `[VRIO-PREAUTH] SENT … attempt N campaign X payload: {…}` / `REPLY … (HTTP …): {…}` per call (card = last 4, CVV
  `***`), then one result line `OK|FAILED … campaign <charged> (driver …): approved, order … (prepaid card: …)`.

## Identity + portal

- After payment: `LeadFlow::identity()` → Vouched crosscheck + DOB; verified = crosscheck ≥ `vouched.min_confidence` (80) and DOB match.
- `idcheck.php`: Vouched plugin → `?action=idcheck` (`true` → `verified_url`; `false` → `not_verified_url` + GHL tag
  `ghl.failed_id_tag` once per session). `idcheck-callback.php` confirms results by job id.
- `process.php` → `LeadFlow::process()` → `PortalClient` (`{"data": signed payload}`, `X-API-Key`), needs
  `FUNNEL_PORTAL_API_KEY` + `FUNNEL_TENANT_SECRET_KEY`. Payload + reply logged as `[PORTAL] SENT` / `REPLY` and stored in
  `portal_processed_payload` / `portal_processed_response`. Consents not shown are sent as `null`.

## Progress tracking

`lib/FlowTracker.php`: `fid` cookie (1 year) = tracking id from the first page; `hit()` per quiz screen
(`funnel.js → trackScreen()` → `?action=track`), product and checkout; `link()` adds `lead_id` / `prospect_id`;
`status()` = `disqualified` / `completed` (payment approved). Never throws; `config.php → tracking.enabled`.
Reports: end of `database/003_create_flow_tracking.sql`.

## QA error log (all errors in one place)

`lib/ErrorLogger.php` collects **every error** — no page tracking — in `storage/logs/qa-errors.log` (one JSON line each) and in
the `error_logs` table when it exists (`database/004_create_error_logs.sql`).

| Captured | How |
|---|---|
| PHP warnings / notices / deprecations, uncaught exceptions, fatal errors | `set_error_handler`, `set_exception_handler`, `register_shutdown_function` (registered in `bootstrap.php`); `@`-silenced calls are skipped |
| API / cURL failures (GHL, Vrio, Vouched, Portal, xverify, membership, funnel API) | `Http::json()`: cURL error, HTTP >= 400, reply that isn't JSON. GHL "duplicate contact" and Vrio "prepaid not allowed" = `WARNING` (handled by the code) |
| Every FAILED step (DB, pre-auth, Vouched, portal, GHL tag, tracking…) | `LeadFlow::log(…, false, …)` → typed `DATABASE_ERROR`, `PAYMENT_ERROR`, `PORTAL_ERROR`, `IDENTITY_ERROR`, `INTEGRATION_ERROR`; declines = `PAYMENT_DECLINED` (WARNING), "not verified" = `IDENTITY_RESULT` (INFO) |
| Error replies of `submit.php` (400, 405, 409 = `MISSING_DATA`, 419, 402, 503) | `funnel_json_response()`; 422 form validation is not logged |
| JSON that can't be parsed | funnel data, stored quiz JSON, unknown condition operators → `JSON_ERROR` |
| JavaScript errors, failed / non-JSON `fetch` calls | `assets/qa-errors.js` (loaded first on every page) → `qa-log.php` (CSRF-checked, max 20 per page) → `JS_ERROR`, `AJAX_ERROR` |

**A QA run:** open any page with `?qa_test=start` → id `TEST-YYYYMMDD-NNN` (cookie, 12 h; a small badge shows it on every page),
test the flow, then `?qa_test=end`. On `FUNNEL_ENV=live` add `&key=<FUNNEL_QA_KEY>`. Errors outside a run get `no-test`.

**Read it:**
- `php tools/qa-errors.php` (runs + counts) · `php tools/qa-errors.php TEST-20260929-001` · `… today` · `--errors-only` · `--details`
- Browser: `qa-errors.php?key=<FUNNEL_QA_KEY>` (no key needed on `FUNNEL_ENV=local`; off on live unless `FUNNEL_QA_VIEWER_ON_LIVE=1`)
- DB: `SELECT … FROM error_logs WHERE test_id = 'TEST-…'` (more queries at the end of `004_create_error_logs.sql`)

**Safe by design:** passwords, keys, tokens, `Authorization`, CVV removed; card numbers → last 4; emails `t***@domain`; phones
`******2452`; responses cut at 1,000 characters; request bodies and quiz answers never stored. The logger never throws; if the
file or DB write fails the app carries on. Settings: `config.php → qa_errors` (`FUNNEL_QA_ERRORS=0` turns it off).

## Logs

`storage/logs/lead-flow.log` (one line per step: `[MEMBERSHIP] [XVERIFY] [DB] [VRIO] [GHL] [VRIO-PREAUTH] [VOUCHED]
[IDCHECK] [IDCHECK-CALLBACK] [PORTAL] [TRACK]`, each `OK` / `FAILED` / `SKIP` or `SENT` / `REPLY`). Failures and all
`[PORTAL]` lines also go to the PHP error log. The log holds personal and health data: keep it private.

## Conventions

- Nothing may break the funnel: integration code logs and carries on (`try/catch` → `LeadFlow::log(..., false, ...)`).
- Server re-validates everything the browser checks (`FunnelRules`, `submit.php`); keep `funnel.js` and `FunnelRules.php` in step.
- Secrets only in `.env`; never print keys; mask card data in logs.
- Text and layout in `projects/<brand>/*.json` + `components/`, not in PHP/JS. New section → component + JSON entry.
- Comment density and naming: match the surrounding file. `php -l` every changed PHP file.
- A behaviour change updates the docs in the same commit (see `README.md` in this folder).

## Checks before a PR

1. `php -l` on changed files; `php tools/check-integrations.php`; after a test run `php tools/qa-errors.php today --errors-only` is empty.
2. A full local run with `FUNNEL_PAYMENT_DRIVER=test` (and `vrio` + the prepaid card if payments changed); read `lead-flow.log`.
3. Private files still 404: `/config.php`, `/.env`, `/lib/`, `/storage/logs/lead-flow.log`, `/projects/chime/site.json`.
4. New table/column → a new `database/00N_….sql` (run by hand on the server; the deploy skips `database/`).

## Git workflow

- `main` = live: every push/merge deploys over FTPS (`.github/workflows/deploy.yml`, skips `.env`, `storage/`, `demo/`, `database/`, `*.md`). Protected: changes arrive by pull request.
- Developers: a feature branch from `main` (`git checkout -b feature/<name>`), push, open a PR to `main`.
- Designer: works on `design`; the project lead reviews the PR `design → main` (see `DESIGNER.md`).
- Keep branches current: `git merge origin/main` before opening a PR.
