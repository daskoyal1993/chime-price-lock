# Chime Health GLP-1 funnel (rnad-v2): project overview + product page migration

**Last updated:** 2026-09-28 · **Snapshot:** `rnad-v2/23-09-2026` + `Dynamic script` (API-driven rebuild)

This document explains how the whole funnel works (quiz → product → checkout → verification → fulfilment) and what was done to replace the product page with the new design. It is written for developers who haven't seen the code yet.

- **§0** describes the **API-driven rebuild in this folder (`Dynamic script`)** and how far it has got. File references in §0 are relative to this folder.
- **§1–§11** describe the **live rnad-v2 site** (Blade templates on the CodeClouds framework). They are kept as the reference for what the rebuild must eventually do. File references there are relative to the rnad-v2 project root and those files are **not** in this folder.

> **Status of the rebuild (§0):** the whole flow is built and deployed to cPanel from GitHub: quiz, product page, checkout with a **real Vrio pre-authorization** (order 4450 on 2026-09-25), Vouched identity check (automatic crosscheck + DOB, then the ID check page), and the order hand-off to the patient portal (`process.php`). Contact details and answers are saved to the database and GHL at every step. **Not confirmed live yet:** a real Vouched check and a real portal hand-off (they need `FUNNEL_TENANT_SECRET_KEY` and `FUNNEL_PORTAL_API_KEY` on the server); the real funnel and membership APIs aren't connected. See [§0.9 Still to do](#09-still-to-do) and `NOTES-OPEN-ITEMS.md`.
>
> **Status of the rnad-v2 product page migration:** built, not live. **Blocked on the CRM campaign IDs (CIDs)** for the four new offers — see [§9 Open items](#9-open-items).

## Contents
0. [Dynamic funnel rebuild (this folder): status](#0-dynamic-funnel-rebuild-this-folder-status) — flow, files, integrations, local testing, deploy, still to do
1. [Big picture](#1-big-picture)
2. [Framework and routing](#2-framework-and-routing)
3. [The quiz (steps 1–18)](#3-the-quiz-steps-118)
4. [Browser storage: the data contract between pages](#4-browser-storage-the-data-contract-between-pages)
5. [Product page: old vs new](#5-product-page-old-vs-new)
6. [Checkout → payment → verification → fulfilment](#6-checkout--payment--verification--fulfilment)
7. [Backend, database and external services](#7-backend-database-and-external-services)
8. [Other templates, assets and tracking](#8-other-templates-assets-and-tracking)
9. [Open items](#9-open-items)
10. [Known issues found during the review](#10-known-issues-found-during-the-review)
11. [How to test end to end](#11-how-to-test-end-to-end)

---

## 0. Dynamic funnel rebuild (this folder): status

### 0.1 What it is

A standalone PHP + plain JavaScript version of the funnel (no framework, no Blade). The questions, conditions, disqualifying answers and products all come from a **funnel API** instead of being hardcoded per step page. All page content, images, section order and colors live in JSON: `projects/<project>/site.json` plus one file per page (`index.json`, `product.json`, `checkout.json`, `idcheck.json`, `process.json`, `thank-you.json`), rendered by reusable components in `components/`. See `projects/README.md`.

```
 index.php (quiz) ─────────────▶ product.php ──────────▶ checkout.php ──────────────────────────┐
   │ contact screen (name/email/phone + SMS consent):   │                       │ POST ?action=checkout
   │   POST ?action=verify → xverify email + phone      │ POST ?action=product  │  validate → Vrio pre-auth
   │   POST ?action=lead   → MEMBERSHIP → DB → VRIO → GHL│ plan must belong to   │  → Vouched crosscheck + DOB
   │   (every later screen: lead again = DB + GHL)     │ the visitor's offer   │  → reply to the browser
   │ last screen: POST ?action=intake → validate, DQ, final save                │  → then DB + GHL (address, purchased)
   │                                                                            ▼
   │                          verified (crosscheck ≥ 80 and DOB match) ── process.php ──▶ patient portal (redirect_url)
   │                          not verified ── idcheck.php (Vouched plugin) ──┘   │ POST {tenant}/api/offer/process
   │                                  └─ POST ?action=idcheck, idcheck-callback.php │ then GHL portal link
   └──────── visitor record kept in the PHP session ($_SESSION['funnel_<slug>']) ───────────────────┘
```

- Every page keeps the visitor's query string (e.g. `prospect_id`, `affid`, `c1`) on redirects.
- **Offer per visitor:** the membership check (on the contact screen) decides which campaign + products the visitor gets —
  member (`true`) → `membership_campaign_set` + `membership_campaign_set_products`; not a member (`false`) → `campaign_set` + `products`
  (`offer_funnel()` in `bootstrap.php`). The sample answer is `false` for now.
- **Quiz order** follows the live site (`https://qualify.chimehealth.com/rnad/v1/`): product → height/weight → **target weight** (own screen) → …
  The shipping address is **not** asked in the quiz (`index.json → skip_questions`); it is asked on checkout.
- Every integration step is logged, one line each, in `storage/logs/lead-flow.log` (`[XVERIFY]`, `[MEMBERSHIP]`, `[DB]`, `[VRIO]`, `[GHL]`,
  `[VRIO-PREAUTH]`, `[VOUCHED]`, `[IDCHECK]`, `[IDCHECK-CALLBACK]`, `[PORTAL]`). A failure in one step never stops the others or the funnel.
  Card data is never logged.
- `thank-you.php` is no longer part of the flow (verified orders go to the portal); it is kept for a later confirmation page.

### 0.2 Files

| File | Purpose |
|---|---|
| `config.php` | All settings, each overridable from the environment / `.env` (`FUNNEL_…`). Funnel API + sample file, questionnaire slug, validation limits, page URLs (`verified_url` = `process.php`, `idcheck_url`), `db`, `membership`, `xverify`, `lead_log`, `vrio`, `ghl` (incl. `field_map`), `google_maps`, `tenant_secret_key`, `vouched`, `portal`, `payment_driver`, `test_card`. |
| `.env` / `.env.example` | Server-specific settings (loaded by `lib/Env.php`, blocked from the web, **never uploaded by the deploy**). `KEY=` keeps the default, `KEY=''` sets an empty value. `.env.example` lists every name. |
| `bootstrap.php` | Loads classes, config and the project; secure session; CSRF token; `load_funnel_or_fail()` (503 page); `quiz_funnel()` (drops `skip_questions`); `offer_funnel()`; `funnel_session()`; `funnel_json_response_then()` / `redirect_now_then()` (reply first, slow CRM work after). |
| `lib/Env.php` | `.env` loader + `Env::get()`. |
| `lib/EncodeDecodeHelper.php` | Signed tokens `base64(JSON).hmac_sha256` (portal code). Decodes `tenant.ghl.credentials` / `tenant.vouched` from the funnel JSON and signs the portal order payload. With `FUNNEL_TENANT_SECRET_KEY` set the signature is checked; without it the token is read unchecked. |
| `lib/FunnelRepository.php` | Loads the definition from the API (file cache in `storage/cache/`, stale copy if the API is down) or from `data/updated-sample-funnel.json`. Normalizes steps, questions (incl. each question's GHL field — kept server side; a field key without an id is allowed), options, conditions, products **and** membership products with their CRM offers, the prepaid campaign of each set, the tenant's **GHL and Vouched credentials** and the GHL portal-link field. |
| `lib/FunnelRules.php` | Server copy of the rules: age, BMI + rating, show/hide conditions, per-type validation (incl. **target weight** below the current weight and BMI ≥ 18.5), disqualifying options. |
| `lib/Screens.php` | Splits the API steps into screens (plan in `index.json → screens`, the rest grouped automatically). |
| `lib/view.php` | HTML helpers, `product_look()`, plan months, sellable/featured variants. |
| `lib/Page.php` + `components/` | JSON-driven pages: head (styles, theme colors), header, sections, footer. |
| `lib/LeadRepository.php` | The `dynamic_remedy` row: insert/update, quiz JSON merge, Vrio ids, checkout (address, `purchased`, `vrio_order_id`), Vouched results, ID check, `customer_id`, portal payload / response / `processed`. |
| `lib/LeadFlow.php` | The steps and their log: lead save (MEMBERSHIP → DB → VRIO prospect → GHL, consent timestamps, GHL custom fields incl. id lookup), `checkout()` (DB + GHL), `identity()` (Vouched), `process()` (portal payload + hand-off), `portalLink()` (GHL). |
| `lib/Membership.php` + `sample-api/membership-check.php` | Membership check (placeholder: sample returns `false`). `sample-api/` is blocked on the live site. |
| `lib/XverifyClient.php` | Email + phone verification (like `demo/xverify.php`). |
| `lib/VrioClient.php` | Vrio: prospect (like `demo/create-prospect.php`), card pre-authorization (like `demo/preauth.php`, prepaid retry, response 101; on a refusal the log gets Vrio's reply + the ids sent), customer id of an order (like `demo/process/get_vrio_customer_id_using_order_id.php`). |
| `lib/GhlClient.php` | GoHighLevel contact create / update (duplicate → update existing); `fieldIds()` = the location's custom fields by key (like `demo/check-custom-field.php`, cached one day). |
| `lib/VouchedClient.php` | Vouched `identity/crosscheck` + `dob/verify` (like `demo/identity_verify.php`). |
| `lib/PortalClient.php` | Portal order hand-off: `POST {tenant}/api/offer/process` with `{"data": signed token}` (like `demo/process/process.php`). |
| `lib/Http.php` | JSON-over-HTTPS helper (SSL verification on). |
| `lib/Payment.php` | Payment drivers: `vrio` (default, real pre-auth), `test` (approves `test_card` only, charges nothing), `none`; Luhn check (skipped for the test card in `test` **and** `vrio` mode — in `vrio` it is sent to Vrio, which decides). |
| `index.php` + `assets/funnel.js` | Quiz: every API question type, conditions, exclusive "None" options, masks, progress, back button, DQ screen, localStorage progress. Auto-continue, xverify on the contact screen, per-question settings (`index.json → questions`: pre-ticked SMS consent, target weight placeholder + hint), target weight card ("That is 30 lbs, about 15% of your weight"). |
| `product.php` + `assets/product.js` | Hero from the answers, product cards + plan rows from the visitor's offer. |
| `checkout.php` + `assets/checkout.js` | Shipping/contact, optional billing, card form, order summary, Google Places autocomplete. |
| `submit.php` | JSON endpoint: `verify`, `lead`, `intake`, `product`, `checkout`, `idcheck` (all need `X-CSRF-Token`). Re-validates everything on the server. |
| `idcheck.php` + `assets/idcheck.js` | ID check with the Vouched plugin (like `demo/idcheck.php`), prefilled from the quiz; result → `submit.php?action=idcheck`. |
| `idcheck-callback.php` | Vouched's job callback (like `demo/idcheck-callback.php`): `idcheck_vouched_status` by job id. |
| `process.php` | Order hand-off to the portal (like `demo/process/process.php`); verified in the session **or on the DB row**; success → portal `redirect_url`, failure → error page. |
| `thank-you.php` | Order confirmation (not in the flow for now). |
| `database/` | `001_create_dynamic_remedy.sql` (fresh install), `002_upgrade_dynamic_remedy.sql` (existing table). The Vouched / portal columns are already in 001. |
| `tools/check-integrations.php` | CLI check: DB, GHL (read-only), Vrio settings, membership. Run it in cPanel → Terminal: `php tools/check-integrations.php`. |
| `demo/` | Reference code from the live site. Not used at runtime; blocked from the web; not deployed. Files with hardcoded keys (`check-custom-field.php`, `identity_verify.php`, `idcheck*.php`, `process/`) are in `.gitignore`. |
| `.github/workflows/deploy.yml` | Deploy: every push to `main` uploads the changed files to cPanel over FTPS (skips `.env`, `storage/logs`, `storage/cache`, `demo/`, `database/`, `*.md`). |
| `.htaccess` | Blocks `config.php`, `bootstrap.php`, `.env*`, `lib/`, `data/`, `storage/`, `components/`, `database/`, `demo/`, `tools/`, `sample-api/`, `projects/**/*.json|php` and `*.md` (Apache only). |
| `assets/css/`, `assets/img/` | `common.css` + `quiz.css`, `product.css`, `checkout.css`; images. |
| `NOTES-OPEN-ITEMS.md` | Decisions still to make. |

### 0.3 Funnel data (`data/updated-sample-funnel.json`)

Funnel **"Chime Main"** (`chime-main`), questionnaire `offer-intake` (6 steps, 50 questions; 26 of them carry a GHL custom field).
`tenant.ghl.credentials` (GHL token + location) and `tenant.vouched` (Vouched `app_id` + `app_secret`) are **signed tokens**
(`lib/EncodeDecodeHelper.php`) — base64, so readable by anyone who has the file: they never reach the browser, don't share the file.

| Offer | Campaign (CRM id) | Products (sellable variants: portal `variant_id`, charged price) |
|---|---|---|
| `campaign_set` (not a member) | main 21 (CRM **68**), prepaid: none | Semaglutide (Inj): 1M (1) $129 · 3M (2) $357 · 6M (4) $654 · 12M (35) $1,188 — Tirzepatide (inj): 1M (5) $149 · 3M (6) $417 · 6M (8) $774 · 12M (36) $1,428 |
| `membership_campaign_set` (member) | 24 (CRM **64**), prepaid: none | Semaglutide & Tesamorelin Bundle: 1M (37) $299 · 3M (57) $897 — Tirzepatide & Tesamorelin Bundle: 1M (39) $399 · 3M (58) $1,197 |

`display_price` differs from the charged price on most plans (e.g. Semaglutide 1M: display 179, charged 129) — see NOTES item 10.

### 0.4 Integrations (server side only; keys never reach the browser, except the Google Maps key and the Vouched public `app_id`)

| Step | When | What | Stored |
|---|---|---|---|
| xverify | contact screen Continue (again at lead/intake) | email `/v2/ev/`, phone `/v2/pv/`; only `invalid` blocks | session cache per value |
| Membership | first save with an email | `lib/Membership.php` (sample: `false`) | session `has_membership` |
| DB | contact screen, every later screen, final submit | `dynamic_remedy` row: name, email, phone, `quiz` JSON (all answers + `consent_timestamps`), tracking; one row per visitor | `lead_id` in session |
| Vrio prospect | first contact save | `POST /orders` (connection, campaign of the offer, name, email, phone) | `prospectid`, `vrio_order_id` |
| GHL | every save | create / update; standard fields + custom fields (funnel JSON + `ghl.field_map`; a key without an id is looked up in GHL); consent date + time for SMS, elderly, GLP, BMI (`metabolic_consent`) and Truthfulness / Telehealth / Informed (`contact_agree`) | `ghl_contact_id` in session |
| Vrio pre-auth | "Complete Purchase" | `POST /orders` `action: authorize` (offer, billing, tracking, card) | `vrio_order_id` |
| DB + GHL at checkout | after the pre-auth, **after the reply is sent** (like `demo/db_update.php`) | row: address, `purchased` (1 when authorized), order id, address in `quiz`; GHL contact: address, city, state, ZIP | row |
| Vouched | after an authorized pre-auth | `identity/crosscheck` + `dob/verify`; verified = crosscheck ≥ 80 and DOB match; already verified with this email before → no new check | `info_vouched_job_id`, `info_vouched_status`, `info_vouched_dob_id`, `vouched_crosscheck_persent`, `vouched_dob_status` |
| ID check | `idcheck.php` (not verified) | Vouched plugin; `onDone` → `?action=idcheck`; Vouched → `idcheck-callback.php` | `idcheck_vouched_job_id`, `idcheck_vouched_status` |
| Portal | `process.php` | `POST {tenant}/api/offer/process` `{"data": token}`, `X-API-Key` (fields: "Parameter Reference" sheet; answers by question key: "Answers Reference" sheet) | `portal_processed_payload`, `portal_process_response`, `processed`, `portal_processed`, `processedtiming`, `customer_id` |
| GHL portal link | after the redirect to the portal | `contact.portal_redirection_link` = portal `redirect_url` | — |
| Google Places | typing the address on checkout | browser: suggestions, fills address/city/state/ZIP | — |

Consent timestamps use the live site's `server-time.php` format: `September 24, 2026` / `2:49 PM UTC`.
The checkout answers the browser before the slow CRM calls (Cloudflare cuts a request off after 120 s); Vouched calls time out after 20 s each.

### 0.5 What the session stores

`$_SESSION['funnel_<slug>']`:
- `answers`, `params` (by `default_params`), `disqualified`, `completed_at` — after `intake`
- `has_membership`, `membership_email`, `xverify`, `consents` (first-ticked time per consent)
- `lead_id` (DB row), `vrio_prospect_id`, `vrio_order_id`, `ghl_contact_id`
- `product` — product/variant ids (`variant_id` = portal variant), name, months, days, price, campaign ids, CRM offer/item ids — after `product`
- `order` — `order_id`, `vrio_customer_id`, `total`, `email`, `placed_at` — after an authorized `checkout`
- `identity_verified` — after the Vouched check / ID check; `portal_redirect` — after `process.php` (a refresh goes straight to the portal)

Card data is only held in memory for the request and is never stored or logged.

### 0.6 Checked

- **2026-09-24:** all PHP files pass `php -l`; pages render without PHP errors; local DB insert/update/merge; GHL token + location + custom field ids; Vrio / xverify / membership against mock servers; headless Chrome for auto-continue, xverify errors, SMS consent, Google Places.
- **2026-09-25 (live, cPanel):** quiz → product → checkout with a **real Vrio pre-auth** (`HTTP 200 order_id=4450`), DB row updated at checkout (address, `purchased = 1`, `vrio_order_id`), GHL contact updated at checkout; no Cloudflare timeout.
- **2026-09-25 (local):** target weight rules (below current weight, BMI ≥ 18.5); all 10 consent GHL fields resolve to the right ids via the GHL lookup; funnel credentials decoded (and rejected with a wrong secret); Vouched DB saves, "already verified" check and callback; `process.php` payload against a local mock portal (signature, `X-API-Key`, DB columns); `process.php` accepts a row verified in the DB.
- **Not yet done:** a real Vouched check and a real portal hand-off on live.

### 0.7 Running it locally

Full setup for a new developer (XAMPP, `.env`, database, safe local settings): `README.md`.

1. `.env`: local DB (`FUNNEL_DB_*`), and `FUNNEL_PAYMENT_DRIVER=test` to check out with the test card **4111 2222 3333 4444** (any future expiry, any CVV) without Vrio.
2. `php tools/check-integrations.php` — every line should be OK (or SKIP for what isn't configured).
3. Run the quiz at `http://localhost/dscript/`, then check `storage/logs/lead-flow.log` and phpMyAdmin → `amerilean_dynamicdb` → `dynamic_remedy`.
4. xverify, GHL, Vouched and (with `vrio`) Vrio are the **live** services, also from localhost: use test emails.
   To turn one off locally, set its key to an empty quoted value in `.env` (`FUNNEL_GHL_TOKEN=''`); a bare `KEY=` keeps the `config.php` default.
5. Test data usually fails the Vouched crosscheck (→ ID check page). To test `process.php` anyway: on that row set
   `vouched_crosscheck_persent = '85'`, `vouched_dob_status = '1'`, `info_vouched_status = 'success'`, then open `process.php`
   in the same browser tab.

### 0.8 Deploying (GitHub → cPanel)

1. `git add .` · `git commit -m "…"` · `git push origin main` → GitHub Actions uploads the changed files (Actions tab; ~1–3 min).
   To upload everything again: delete `.ftp-deploy-sync-state.json` in the site folder (cPanel shows it with "Show Hidden Files"), then Actions → **Deploy to live** → **Run workflow**. Never delete the whole folder: `.env` and `storage/` live only on the server.
2. The server's `.env` is edited by hand in cPanel File Manager. Needed on live:
   `FUNNEL_PAYMENT_DRIVER=vrio`, `FUNNEL_VRIO_CONNECTION_ID`, `FUNNEL_VRIO_SHIPPING_PROFILE_ID`,
   `FUNNEL_TENANT_SECRET_KEY` (Portal → Site Settings → Secret Key; signs the portal payload),
   `FUNNEL_PORTAL_API_KEY` (portal `X-API-Key`). Before real customers: `FUNNEL_TEST_CARD=''`.
   Don't copy the local `.env` to the server (its `FUNNEL_DB_USER=root` would override the live DB login).
3. `storage/logs/` must be writable (755/775). The DB tables: import `database/001…` (and `002…`) in the server's phpMyAdmin.

### 0.9 Still to do

1. **Live Vouched + portal:** set `FUNNEL_TENANT_SECRET_KEY` and `FUNNEL_PORTAL_API_KEY` on the server; run one order through to the portal; allow `https://<domain>/idcheck-callback.php` as the Vouched callback. Confirm the portal payload points in NOTES item 14.
2. **Vrio:** confirm the real `FUNNEL_VRIO_CONNECTION_ID` / `FUNNEL_VRIO_SHIPPING_PROFILE_ID` (currently `1`) and the open points in NOTES item 9.
3. **Real membership API** (NOTES item 5) and the membership bundle copy/images (item 6).
4. **Connect the real funnel API** (`FUNNEL_API_URL` / `FUNNEL_API_TOKEN`; `storage/` writable).
5. **Keys in plain text:** the GHL token, xverify key/domain and Google Maps key are still defaults in `config.php` (committed to git): move them to `.env` / the funnel JSON and rotate them (NOTES items 4, 7).
6. **Missing live-site steps:** "What pace feels right" and the plan screen after the target weight, `purchased` tag in GHL, tracking pixels (ClickGo/IntentAds). See §6–§8.
7. **Open decisions:** everything in `NOTES-OPEN-ITEMS.md`.

---

## 1. Big picture

```
 index.php / step1.php ─▶ step2 … step15 ─▶ step16 (contact) ─▶ step17 ─▶ step18 ─▶ product.php ─▶ checkout.php
        │                                    │                                         │              │
        │  answers → localStorage            │  xverify (phone)                        │ plan choice  │ preauth (Vrio card auth)
        │  "amerilean_funnel"                │  create_prospect (Vrio)                 │ → localStorage│ db_update (+GHL)
        │                                    │  db_insert (MySQL + GoHighLevel)        │              │ identity_verify (Vouched)
        ▼                                    ▼                                         ▼              ▼
   disqualified.php (DQ rules)          ?prospect_id= carried in the URL                    process.php ─or─ idcheck.php
                                                                                                │           │ (ID + selfie)
                                                                                                │           └─▶ process.php / usernotverified.php
                                                                                                ▼
                                                                                   MedAxis intake → GHL portal link → redirect
```

- **Front end:** static-looking Blade templates in `app/desktop/*.blade.php`, driven by plain JavaScript in `app/desktop/js/` and `app/desktop/js-product/`.
- **Back end:** single-purpose PHP files in the project root (`create_prospect.php`, `preauth.php`, `process.php`, …) on top of the CodeClouds `App::run` framework.
- **Services:** Vrio (CRM/payments), GoHighLevel (contacts/portal link), Vouched (identity), xverify (phone validation), MedAxis/AxisMD (clinical intake), Google Places (address autocomplete), ClickGo/IntentAds (tracking).

## 2. Framework and routing

Every page wrapper in the root (`step1.php`, `product.php`, `checkout.php`, …) calls `App::run([...])` with `tpl`, `go_to`, `pageType`, `version => 'desktop'`, `config_id`.

- `tpl` is cut at the first `.` and rendered with Blade from `app/{version}/{name}.blade.php` (e.g. `product.tpl` → `app/desktop/product.blade.php`).
- **`$path[...]`** is built by the framework from the sub-folders of `app/{version}/`: `$path['css']`, `$path['images']`, `$path['images-new']`, `$path['js']`, `$path['js-product']`, `$path['member']`, `$path['upsellmanage']`, plus `assets_*` for folders under `assets/`.
- Helper functions used in templates (`get_no_thank_you_link()`, `perform_body_tag_open_actions()`, …) live in the framework's `library/functions.php`.

> ⚠️ **The `library/` framework folder (and `admin/`, `vendor/`, `storage/`, `extensions/`, `assets/`) is not in this snapshot.** It exists only inside `v2-backup.zip`. This copy cannot run on its own; deploy on top of the full site.

| Wrapper | Template | `go_to` | Notes |
|---|---|---|---|
| `index.php` | step1 | checkout | `resetSession=true` |
| `step1.php` … `step38.php` | stepN | step(N+1) | `step17.php` → `report.php` (doesn't exist); `step23.php` skips 24. The JS decides the real next page (see §3). |
| `step39.php` | step39 | product.php | legacy, unreachable |
| `product.php` | product | product.php | |
| `checkout.php` | checkout | upsell1.php | `pageType: checkoutPage` |
| `upsell1.php` / `upsell2.php` | upsell1/2 | thank-you | framework one-click upsell stubs |
| `thank-you.php` | thank-you | – | framework stub |
| `disqualified.php`, `usernotverified.php`, `discount.php` | same name | – | |

`app/mobile/` is an incomplete default framework set that no wrapper uses; `app/general/` holds shared framework partials (header, analytics, member header/footer, language files).

`.htaccess`: no directory listing, `index.php` as index, `X-Frame-Options SAMEORIGIN`, `*.tpl` not directly reachable, rewrites for `cms/…` and `upsellmanage[1-9].php`.

## 3. The quiz (steps 1–18)

All step logic is in **`app/desktop/js/quiz-flow.js`**. It picks an init function from the page's file name (`PAGE_INIT`), so pages must be requested as `stepN.php`. Links keep the current query string.

Helpers: `js/funnel-state.js` (`FunnelState`, `FunnelCalc` — BMI, plan maths), `js/quiz-store.js` (`QuizStore`), `js/quiz-validate.js`, `js/quiz-dq.js` (disqualification), `js/screening-bands.js` (age/BMI bands), `js/input-format.js` (DOB/phone masks).

| Step | Screen | Saves to `amerilean_funnel` | Next |
|---|---|---|---|
| 1 | Height + weight, live BMI | `heightFeet`, `heightInches`, `weightLbs` (50–700), `bmi` | 2. **Clears all storage on load.** |
| 2 | Goal weight | `goalWeightLbs` (below current, BMI ≥ 18.5) | 3 |
| 3 | Pace (3 / 6 / 12 months / help me choose) | `pace` | 4 |
| 4 | Personal plan (from `FunnelCalc.plan`) | – | 5 |
| 5 | How the program works | – | 6 |
| 6 | GLP-1 in the past month + follow-ups | `medicationPastMonth`, `medicationType`, `medicationDose`, `medicationLastTaken`, `medicationContinuePlan` | 7 |
| 7 | Sex, DOB, state, age/BMI consents | `sex`, `dob`, `state`, `elderly_consent`, `metabolic_consent`, `bmi_consent`, `screening_band`, `screening_reason`, `consent_timestamps` | DQ check → 8 |
| 8 | Delivery map for the state | – | 9 |
| 9 | Results counters | – | female → 10, otherwise → 11 |
| 10 | Female health (women only) | `femaleHealthStatus`, `femaleHealthAnswered`, `femaleHealthDq` | DQ check → 11 |
| 11 | Health conditions | `healthConditions` | DQ check → 12 |
| 12 | Diagnoses | `healthConditionsAdditional` | DQ check → 13 |
| 13 | More conditions | `moreHealthConditions` | 14 (no DQ rule) |
| 14 | Opiates (3 mo) / gastric bypass (6 mo) | `takenPainMedicationsOrStreetDrugs`, `gastricBypass6Months` | DQ check → 15 |
| 15 | Medical review summary | – | 16 |
| 16 | Contact details | `first_name`, `last_name`, `email`, `phone`, `sms_consent` | server calls (below) → `step17.php?prospect_id=…` |
| 17 | Vitals + medical history | `bloodPressure`, `restingHeartRate`, `lastMedicalEvaluation`, `lastLabTests`, `prescriptionMedications`, `medicationAllergies`, `additionalDocInformation` (+ `_info` text) | 18 |
| 18 | GLP-1 allergies, glucose meds, consents, doctor note | `glp1_allergies`, `current_glucose_medications`, `doctor_note`, `consents`, `consent_timestamps` | DQ check → **product.php** |

**Server calls from the quiz**

| Step | Call | What |
|---|---|---|
| 7, 18 | `GET server-time.php` | server time for consent timestamps (falls back to device clock) |
| 16 | `GET xverify.php?phone=` | phone must be `valid` |
| 16 | `POST create_prospect.php` | name/email/phone → Vrio `POST /orders` → `prospect_id` |
| 16 | `POST db_insert.php` (JSON) | full `amerilean_funnel` + contact + prospect_id → MySQL insert + GoHighLevel contact |

**Disqualification** (`js/quiz-dq.js`, `ENABLED = true`): a failed rule sends the visitor to `disqualified.php?back=<step>&next=index.php`.

| Step | Disqualifies when |
|---|---|
| 7 | `screening_band = "disqualify"`: age ≥ 65 with BMI < 22, or age 18–64 with BMI < 20 |
| 10 | any female-health option picked |
| 11 | anything other than "None of these" |
| 12 | anything other than "None of the below" / "Type 2 diabetes (not on insulin)" |
| 14 | "Yes" to either question |
| 18 | *(intended: allergies / glucose meds other than "none" — currently never fires, see §10)* |

**Steps 19–39** are older standalone pages (English/Spanish) that repeat questions from steps 6 and 11–18. No JavaScript drives them and nothing links to them. Treat them as legacy.

## 4. Browser storage: the data contract between pages

| Key | Where | Written by | Read by |
|---|---|---|---|
| `amerilean_funnel` | localStorage | every quiz step (`FunnelState.save`), `QuizDQ` (`dq_page`, `dq_enforced`) | quiz steps (restore), **product page hero**, checkout (prefill name/email/phone, `db_update`), `idcheck.php` (prefill), `identity_verify` (dob, sex) |
| `Quiz` | localStorage | `QuizStore` (answers keyed by question text) | nobody (the send in step 16 is commented out) |
| `amerilean_selected_product` | localStorage | **product page** on plan select | checkout via `ProductCatalog.get()` → order summary + `campaign_id` |
| `chime.chooseTreatment` | sessionStorage | **product page** on plan select | nobody yet (page's own record) |
| `prospect_id` | localStorage | checkout, copied from `?prospect_id=` | checkout hidden field |
| `selectedTreatment`, `medication` | sessionStorage | `usernotverified.blade.php` | – |

`prospect_id` itself travels **in the URL** from step 16 onward. Every page keeps the query string, so it reaches checkout.

## 5. Product page: old vs new

### Goal
Replace `app/desktop/product.blade.php` with the redesigned `app/desktop/product-new.blade.php` **without losing any behaviour** checkout depends on. The new design came from the static prototype `SEP/shamik/Site-main/chime-checkout-bonus-v2/product.html` (GLP-1 + FREE NAD+ combo). It was dropped in as-is, so it pointed at assets that didn't exist here and had no connection to checkout or the quiz data.

### What the product page must do

**Read** (quiz data → hero *Today / With Chime* report), via the existing `js/product.js` + `js/funnel-state.js`:

| Element ID | Shows | From `amerilean_funnel` |
|---|---|---|
| `productTodayWeight` | current weight | `weightLbs` |
| `productGoalWeight` | goal weight | `goalWeightLbs` |
| `productTodayBmi` | current BMI | `bmi`, or computed from height + weight |
| `productGoalBmi` | goal BMI | computed from height + goal weight |
| `productBeforeImage` / `productAfterImage` | silhouettes | `sex` = `male` → `male-start.png` / `male-end.png` |

Missing values leave the placeholders (234 lbs / 220 lbs / BMI 20.8 / 19.5).

**Write** (same as the old page, so checkout keeps working):

`localStorage["amerilean_selected_product"]`
```json
{
  "slug": "tirzepatide",          // must be "semaglutide" or "tirzepatide", or ProductCatalog ignores the record
  "display_name": "Tirzepatide",
  "image": "tirzepatide.webp",
  "price": 897,                   // billed today
  "term": 3,                      // months billed (checkout's per-day price = price / (30 × term))
  "termLabel": "3 Month Plan",
  "campaign_id": null,            // ← CRM CID, see §9
  "full_name": "Tirzepatide - 3M (Chime)",
  "rate": 897,
  "charge": 897,
  "covers": 4,                    // months supplied (3 + 1 free)
  "retail": 359                   // monthly price, "compare at" line
}
```

`sessionStorage["chime.chooseTreatment"]` → `{ "med": "tirz", "term": 3, "plan": "threeMonth", "cid": null, "at": <ms> }`

**Redirect:** `checkout.php` + the page's current query string, unchanged.

### Plans

| | Old page (`product.blade.php`) | New page (`product-new.blade.php`) |
|---|---|---|
| Plan lengths | 6-Month / 3-Month / Monthly | Monthly / 3-Month (every 4th month free) |
| Semaglutide | 1M $299 · 3M $627 · 6M $1,194 | 1M $299 · 3M **$747** today ($249/mo, 4 months) |
| Tirzepatide | 1M $359 · 3M $948 · 6M $1,794 | 1M $359 · 3M **$897** today ($299/mo, 4 months) |
| CIDs | Sema 1 / 2 / 3 · Tirz 4 / 7 / 8 | **not yet assigned** |
| Data file | `js-product/plans-data-v2.js` | `CHIME_PLANS` at the top of `js-product/plan-select.js` |

The old CIDs are **not** reused because the 3-month prices differ.

### What changed

**`app/desktop/product-new.blade.php`**
- All asset paths now use `<?=$path['css'|'images'|'js'|'js-product']?>`.
- Loads `css/2_6-q88y6yuc5-product.css` instead of `css/2_6-q88y6yuc5.css` (reason below).
- Added the six `product…` IDs to the hero report.
- Loads at the end of `<body>`: GSAP (CDN), `js-product/plan-select.js`, `js-product/treatment-select.js`, `js/funnel-state.js`, `js/product.js`.

**New JS in `app/desktop/js-product/`**

| File | Responsibility |
|---|---|
| `plan-select.js` | Plan rows in each product card: select medication + length, enable the button, 3-month pulse (GSAP), sticky "Buy Now" bar (`window.ChimeFlow`), `?med=&term=` pre-select. **Button click: writes both storage records and redirects to checkout** (same logic as the old `js-product/checkout.js` → `onPlanSelect()`). |
| `treatment-select.js` | Hero "Select your treatment" cards: mark the card, name it in the sticky bar, glide-scroll to that product's plans. Writes nothing. |

Both come from the prototype. The only change to `treatment-select.js`: the sticky-bar image swaps just the filename, so the PHP image path is kept.

**New CSS in `app/desktop/css/`** (from the prototype): `chime-theme.css`, `bonus.css`, `plan-stage.css`, `redesign.css`, `2_6-q88y6yuc5-product.css`.

> **Why `2_6-q88y6yuc5-product.css`?** The project's `2_6-q88y6yuc5.css` defines theme colours under `data-theme=remedymeds`. The new page uses `data-theme="chime"`, which only the prototype's copy defines (it also has a different `.text-base` line-height). Checkout uses the project's file, so that file is untouched and the new page has its own copy.

**New images in `app/desktop/images/`:** 78 files from the prototype (vials incl. `vial-nad-plus.webp`, press logos, before-after photos, favicon, web fonts). Only missing files were added; nothing was overwritten.

**Not changed:** `product.blade.php`, `js-product/checkout.js`, `js-product/plans-data-v2.js` (the old page still works), checkout, root `product.php` (still renders the old template).

**Deliberately not carried over:** the urgency countdown strip, the "chose this today" counters, the old mobile treatment selector. The visitor's first name (`productFirstName`) isn't shown because the new design has no place for it.

## 6. Checkout → payment → verification → fulfilment

**`app/desktop/checkout.blade.php`** (static Next.js export). Form `#order_form` collects shipping, contact, optional billing and card fields. Hidden fields: `#dynamicCampaign` → `campaigns[1][id]`, `#prospectId` → `prospect_id`.

> The whole form sits inside `<div hidden id="S:0">` and only appears when the streaming shim `$RC("B:0","S:0")` near the end of the page runs. Don't remove it.

Scripts: `funnel-state.js`, `product-catalog.js`, `checkout.js` (order summary), `input-format.js`, `checkout-payment.js` (payment), `address-autocomplete.js` (Google Places, US only), `checkout2.js` (carousels).

**`js/product-catalog.js`** reads `amerilean_selected_product`. If a visitor has no stored choice it falls back to a built-in default (Tirzepatide, campaign 4, $399 — note: no `term` and an image path that doesn't exist).

**Payment sequence** (`js/checkout-payment.js`):
1. `#dynamicCampaign` ← `ProductCatalog.getOrDefault().campaign_id`; name/email/phone prefilled from `amerilean_funnel`.
2. Submit → `POST preauth.php` (card authorise in Vrio). Only `success === true` continues.
3. ClickGo `trackConversion({orderTotal})`.
4. `POST db_update.php?purchased=1` (funnel data + address → MySQL + GHL).
5. `POST identity_verify.php` (Vouched crosscheck + DOB).
6. Verified → `process.php?…&campaign_id=…&order_total=…`; otherwise → `idcheck.php?…` (Vouched ID + selfie widget) → `process.php` or `usernotverified.php`.

**`process.php`** finds the DB row, backfills Vrio order/customer IDs, maps `campaign_id` → a MedAxis product with **`$campaignProductMap`**, builds and signs the intake payload, posts it to MedAxis, saves the response, updates the GHL portal link and redirects the visitor to it.

## 7. Backend, database and external services

| File | Purpose |
|---|---|
| `create_prospect.php` | Vrio prospect from step 16 |
| `xverify.php` | phone (and email) validation |
| `db_insert.php` / `db_update.php` | MySQL row + GoHighLevel contact (step 16 / checkout) |
| `preauth.php` | Vrio card authorisation. Offers come from the framework's campaign config (`Config::campaigns()[id]['product_array']`); Vrio campaign constants are in `vrio-config.php` |
| `identity_verify.php`, `idcheck.php`, `idcheck-update.php`, `idcheck-callback.php` | Vouched identity checks, widget, updates and webhook |
| `process.php` (+ `save_processed_payload.php`, `mark_portal_processed.php`, `update_portal_redirect.php`, `get_vrio_customer_id_using_order_id.php`, `EncodeDecodeHelper.php`) | fulfilment hand-off to MedAxis + GHL |
| `offer_process.php`, `encode.php` | MedAxis helpers (`offer_process.php` points at **staging**) |
| `ajax.php`, `api.php` | framework entry points |
| `cms_helper.php` | token-protected deploy/file tool |
| `server-time.php`, `server-check.php` | server time / environment check |

**Database:** MySQL (`dbConfig.php`). Main table **`chime-health-mainglp`**, one row per lead. Columns include contact fields, `quiz` (JSON), address, `prospectid`, `purchased`, `vrio_order_id`, `customer_id`, `processed`, `portal_processed_payload`, `portal_process_response`, and the Vouched status columns.

**Campaign ID maps** — a `campaign_id` must be known in all of these to work end to end:

| Where | Used for | Current IDs |
|---|---|---|
| Framework campaign config (admin, `Config::campaigns()`) | `preauth.php` builds the Vrio offers from it | managed in the admin panel (not in this snapshot) |
| `process.php` → `$campaignProductMap` | MedAxis product (`variant_id`) | 1, 2, 3, 4, 7, 8 — **unknown IDs silently default to `1` (semaglutide)**; the comment says variant IDs are placeholders |
| `vrio-config.php` → `VRIO_OFFER_MAP` | – | defined but **not used anywhere** |

**Credentials:** API credentials for Vrio, GoHighLevel, Vouched, xverify, MedAxis, MySQL, Google Maps and Customer.io are hardcoded in the PHP/template files. Don't commit or share this project outside the team.

## 8. Other templates, assets and tracking

- **Post-purchase:** `thank-you.blade.php`, `upsell1/2.blade.php`, `upsellmanage/upsellManage.blade.php` are framework stubs (Order ID table, one-click upsell, Upsell Manager extension).
- **Member area** (`app/desktop/member/*`): framework stubs (login, orders, order details/tracking/cancel/refund, card details, subscription, password pages). They depend on `assets/member/js/member.js` and root `member/*.php`, neither of which is in this snapshot.
- **CSS usage:**

| File | Used by |
|---|---|
| `questionaire-style.css` | steps 1–18, disqualified, usernotverified |
| `2_6-q88y6yuc5.css`, `36xz9kx5hwvks.css`, `1p1s7yzodipl6.css` | checkout, legal pages (`1p1s…` also product-new) |
| `2_6-q88y6yuc5-product.css`, `chime-theme.css`, `bonus.css`, `plan-stage.css`, `redesign.css`, `extracted-inline.css` | product-new |
| `checkout.css` | old `product.blade.php` |
| `footerlink.css` | legal pages |
| `landing.css`, `slick.css` | usernotverified |
| *unused* | `questionaire-style-bkp.css`, `questionaire-style-BKP-21-09-2026.css`, `slick-theme.css` |

- **Unused JS:** `js/script.js`, `chart-config.js`, `calculator.js`, `clinical-chart.js`, `slick.js`, and `js/treatment-select.js` (an unmodified prototype copy; the page loads `js-product/treatment-select.js`).
- **Tracking:** ClickGo / IntentAds (`https://intentads.io/sdk.js`) only.

| Page | Event |
|---|---|
| step 1 | `track()` + eventId 19 (SDK in `debug: true`) |
| step 11 | eventId 20 (medical history start) |
| step 17 | eventId 21 |
| product (old, via `js/product.js` buttons) | eventId 22 |
| checkout load | eventId 23 |
| after preauth success | `trackConversion({orderTotal})` |

No GTM or Facebook pixel. Legacy GA only via `app/general/__analytics__.tpl` if `ga_site_id` is set.

## 9. Open items

1. **CIDs for the new offers (blocker).** Until these exist, the product page stores `campaign_id: null`. As a result checkout posts an empty campaign, and `process.php` defaults to semaglutide. For each offer, the ID must be:
   - set in `CHIME_PLANS` in `js-product/plan-select.js` (marked `TODO(CID)`),
   - configured in the framework campaign config with the right Vrio offer/price, and
   - added to `$campaignProductMap` in `process.php` with the right MedAxis `variant_id`.

   | Offer | Charged today | CID | MedAxis variant |
   |---|---|---|---|
   | Semaglutide + NAD+ Monthly | $299 | ? | ? |
   | Semaglutide + NAD+ 3-Month (+1 free) | $747 | ? | ? |
   | Tirzepatide + NAD+ Monthly | $359 | ? | ? |
   | Tirzepatide + NAD+ 3-Month (+1 free) | $897 | ? | ? |

2. **ClickGo eventId 22 on the new product page.** The old page fired it from `[data-product-select]` buttons, which the new design doesn't have. The IntentAds SDK isn't loaded on product-new either. Decide whether to add both.
3. **End-to-end test on the server** (§11). The product page's storage/redirect logic has only been tested in Node with a stubbed DOM.
4. **Go-live switch:** point the product route at the new template (or rename `product-new.blade.php` → `product.blade.php`, keeping a backup of the old one).

## 10. Known issues found during the review

Not caused by the product page work. Listed so they can be planned.

**Quiz**
- **Step 18 DQ never fires:** the rule checks `medication_allergies` / `current_medications`, but step 18 saves `glp1_allergies` / `current_glucose_medications` (`js/quiz-dq.js` vs `js/quiz-flow.js`).
- **Disqualified page links are rejected:** `okUrl()` in `disqualified.blade.php` only accepts `*.html`, but it receives `stepN.php` / `index.php`. "Keep my answer" falls back to `index.html`, which doesn't exist.
- **Step 6:** validation compares against ids, but the radios submit labels, so follow-up questions aren't enforced and the saved label is empty.
- **Step 1 clears all storage** every time it loads, including when a visitor goes back.
- **Under-18 visitors** get an `under18` band but aren't blocked.
- **Step 13** calls the DQ check but has no rule.
- **Wrapper `go_to` values disagree with the JS:** `step17.php` → `report.php` (missing), `step18.php` → step19. The JS navigation wins.
- **Legacy pages load missing files:** `step39.blade.php` (`js/step39.js`, consent-modal files); `usernotverified.blade.php` loads `js/landing/*`, `css/landing/*`.
- `step2.tpl` is an old leftover template next to `step2.blade.php`.

**Checkout**
- The default ProductCatalog entry has no `term`, so the daily price shows `NaN` for visitors who arrive without a product choice. Its image path (`images/product/…`) doesn't exist.
- Each submit click adds another handler to `cb.beforeFormSubmitEvents`, so they pile up on retries.
- `#checkoutFormError`, `#creditCardName` and `#billing-same` are referenced but not in the template, so errors aren't shown and the billing-same sync never runs.
- The IntentAds SDK is loaded twice. `$path['images-new']/logo-white.svg` and `image.webp` don't exist.

**Backend**
- `db_insert.php` backfills `prospectid` into a different table (`chime-health-nad`).
- `db_common.php` isn't included anywhere. `VRIO_OFFER_MAP` isn't used.
- `display_errors` is on in `preauth.php`, `process.php` and `identity_verify.php`. `identity_verify.php` returns raw Vouched responses (`debug_raw_*`).
- Vouched logs (`vouched_log.txt`, `vouched_update_log.txt`) are written to the web root.

**Server hygiene — remove from production or protect:** `phpinfo.php`, `phpliteadmin.php`, `test.php` (prints the session), `server-check.php`, `cms_helper.php`, `encode.php`, `installation.zip`, **`v2-backup.zip` (58 MB, full site)**, and this `.md` file (it sits in a web-served folder).

## 11. How to test end to end

1. Start at `index.php` (or `step1.php`). Enter a known height/weight/goal weight. Run it once as "male" to check the image swap.
2. At step 16 use a real-format phone. Check that the URL gets `?prospect_id=` and that a row appears in `chime-health-mainglp`.
3. On the product page:
   - the hero shows your weight, goal weight and matching BMI;
   - picking a plan row enables the button;
   - `?med=tirz&term=3` pre-selects that plan.
4. Press the button. In DevTools → Application:
   - `localStorage.amerilean_selected_product` has the right slug, price, term and **CID**;
   - `sessionStorage["chime.chooseTreatment"]` is set;
   - the URL is `checkout.php` with the same query string (incl. `prospect_id`).
5. On checkout:
   - the order summary shows the chosen medication and price;
   - `#dynamicCampaign` holds the CID;
   - after payment, the `process.php` URL has the right `campaign_id`, and MedAxis receives the matching product (not the semaglutide default).
