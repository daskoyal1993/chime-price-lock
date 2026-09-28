# Open items — to discuss

Items parked for later (last updated 2026-09-28). Each one says where it lives in the code, so it can be changed in one place.
Overview of the whole build: `PRODUCT-NEW-MIGRATION.md` §0. Editing pages: `projects/README.md`.
(This file is blocked from the web by `.htaccess`, like every `*.md`, and not uploaded by the deploy.)

---

## 1. GHL fields not mapped yet

The GHL sample (`demo/sample-create-contact.php`) sends these, but they are **not sent** yet:

| GHL key | Why |
|---|---|
| `contact.goal_weight` (`xTlZEuAqqmmzWTUYM4Wu`) | The quiz now asks **target weight** (`target_weight`), but the funnel API gives it no GHL field (`ghl_custom_id` / `ghl_field_key` empty). |
| `contact.what_pace_feels_right_for_you` (`0oUlAEHJYYVuruTmFrgk`) | The funnel has no "pace" question (the live site asks it after the target weight). |

- **Goal weight:** add `contact.goal_weight` to `target_weight` in the funnel API, or add to `config.php → ghl.field_map`:
  `'target_weight' => ['key' => 'contact.goal_weight'],` (the id is looked up in GHL, see item 3).
- **Pace:** add the question to the funnel API (with its GHL field), or decide it isn't needed.

## 2. Consent date / time fields — RESOLVED 2026-09-25

The time each consent box was first ticked is sent to both its GHL date and time field (`config.php → ghl.field_map`, `@date` / `@time`):

| Question | GHL fields |
|---|---|
| `elderly_consent` | Date / Time of Elderly Consent |
| `sms_consent` | Date / Time of SMS Consent |
| `glp_consent` | Date of GLP Consent (from the funnel API) + **Time of GLP Consent** |
| `metabolic_consent` | **Date / Time of BMI Consent** |
| `contact_agree` (one box: "I agree to the Truthfulness Consent, Telehealth Consent & Informed Treatment Consent") | **Date / Time of Truthfulness, Telehealth and Informed Consent** (6 fields) |

Rule in `lib/LeadFlow.php → customFields()`: an API mapping whose key contains `.date_of_` / `.time_of_` gets the consent date / time;
a `field_map` entry can be a list (one answer → several fields). Timezone and format as the live site's `server-time.php`:
UTC, `September 24, 2026` / `2:49 PM UTC` (`config.php → ghl.consent_timezone / consent_date_format / consent_time_format`).

## 3. GHL field ids looked up by key

A GHL field that has a key but no id — in the funnel API (`ghl_field_key` without `ghl_custom_id`) or in `ghl.field_map` —
gets its id from GHL: `GET /locations/{location}/customFields` (`lib/GhlClient.php → fieldIds()`, like `demo/check-custom-field.php`),
cached one day in `storage/cache/ghl-fields-*.json`. A key that doesn't exist in GHL is skipped and logged as
`[GHL] FAILED not sent, no such custom field in GHL: contact.…`. After adding a field in GHL, delete the cache file to see it at once.

## 4. GHL token and location

- They come from the funnel JSON (`tenant.ghl.credentials`, see item 15). `config.php → ghl.token / location_id` are only the fallback.
- The fallback token (`pit-c395…`, copied from `demo/sample-create-contact.php`) is still a default in `config.php` and so in git.
  **Rotate it** and remove the default before go-live (`FUNNEL_GHL_TOKEN` in `.env` still overrides it).

## 5. Membership check — placeholder

The real membership API doesn't exist yet. Current behaviour (`lib/Membership.php`):

- Called once, when the contact screen (email) is submitted; the answer is kept in the session.
- `config.php → membership.api_url` empty → built-in sample: **no email is a member** (`false`) for now.
  `sample-api/membership-check.php` is the same sample as an HTTP endpoint (blocked on the live site by `.htaccess`).
- Expected real API: `POST {"email"}` → `{"success": true|false}` (`success` = has membership). Confirm the real
  URL, method, auth and response shape, then set `FUNNEL_MEMBERSHIP_API_URL` (and `FUNNEL_MEMBERSHIP_API_TOKEN`).
- If the API fails, `membership.fallback` is used (`false` = not a member → `campaign_set`). Confirm what should happen then.

Offer per answer (`offer_funnel()` in `bootstrap.php`):

| Membership | Campaign (Vrio `campaign_id`, order record) | Product page / plans |
|---|---|---|
| member (`success: true`) | `membership_campaign_set` → campaign 24, CRM **64** | `membership_campaign_set_products` (Semaglutide / Tirzepatide & Tesamorelin bundles, 1M, 3M) |
| not a member (`success: false`) | `campaign_set` → main campaign 21, CRM **68** | `products` (Semaglutide / Tirzepatide 1M, 3M, 6M, 12M) |

## 6. Membership bundle presentation (product page)

Product names come from the funnel JSON (e.g. "Semaglutide & Tesamorelin Bundle"). `projects/chime/site.json → products` has
placeholder images (the GLP-1 vial) and taglines for the two bundles. Needed: bundle images, taglines, badges.
Also check the product page copy for non-members: `site.json → bonus` (free NAD+), `product.json` hero/plans text
(“GLP-1 + NAD+ combo”, “every plan comes with NAD+”) are written for the regular offer.

## 7. xverify — temporary key and domain

`config.php → xverify` defaults to the key and domain copied from `demo/xverify.php` (`1024692-6A65809C`, domain `amerilean.com`).

- Confirm which domain this funnel should report to xverify, and move the key to `FUNNEL_XVERIFY_API_KEY`
  (or the funnel JSON) before go-live; the key is in plain text in `config.php` (and so in git).
- Only status `invalid` blocks the visitor (`xverify.block_statuses`). Decide whether `risky` / `unknown` should block too.
- If xverify can't be reached, the visitor is let through and it's logged as `[XVERIFY] FAILED … not blocking`.

## 8. SMS consent on the contact screen — wording

`sms_consent` is on the name / email / phone screen, **ticked by default** and required (`projects/chime/index.json → questions.sms_consent`).
Its text comes from the funnel JSON and names **AmeriLean**, not Chime Health — confirm with compliance and update it in the funnel API.

## 9. Vrio pre-authorization at checkout

Live since 2026-09-25 (`HTTP 200 order_id=4450`). `lib/VrioClient.php → authorize()`, same request as `demo/preauth.php`.

- **`FUNNEL_VRIO_CONNECTION_ID` / `FUNNEL_VRIO_SHIPPING_PROFILE_ID`** are `1` in the server `.env` — confirm these are the real values
  (`VRIO_CONNECTION_ID` / `VRIO_SHIPPING_PROFILE_ID` in the demo's `vrio-config.php`).
- **Logging:** every attempt that reaches the server gets a `[VRIO-PREAUTH]` line, also when it stops before Vrio (no plan in the
  session, form errors — field names only). A Vrio refusal logs Vrio's full reply (`response=`, card-like numbers masked) and the ids sent (`sent=`).
- **Test card** `4111 2222 3333 4444`: with `payment_driver = test` it is approved locally (order id `TEST-…`); with `vrio` it skips the
  card-number checksum and is **sent to Vrio**, which decides (a test gateway approves it). Set `FUNNEL_TEST_CARD=''` on the live
  server before real customers.
- **Different billing address:** like the demo, only `bill_*` fields and `same_address: true` are sent; with a separate billing address
  the shipping address isn't sent to Vrio. Confirm the Vrio field names for a separate shipping address if needed.
- **Prepaid retry:** implemented like the demo, but both campaign sets have `prepaid_campaign: null` in the funnel JSON.
- **Scrap flow** (`VRIO_SRP_CAMPAIGN_ID` in the demo) is not part of this funnel — not implemented.
- **Offers:** one offer per order (the chosen plan, quantity 1); the demo could send several. `offer_funnel_slug` is the funnel's `slug`
  (`chime-main`), the demo sent `"chime-main"` too.

## 10. Checkout "As low as $x/day" uses `display_price`

The order summary shows the variant's `display_price` (funnel JSON) ÷ (30 × months) (`checkout.php`). On most plans `display_price`
differs from the charged price, so e.g. "Total Due Today $129 · Just $5.97/day" doesn't add up ($5.97 × 30 = $179):

| Plan | Charged | display_price |
|---|---|---|
| Semaglutide 1M / 3M / 6M / 12M | 129 / 357 / 654 / 1,188 | 179 / 627 / 1,194 / 2,148 |
| Tirzepatide 1M / 3M / 6M / 12M | 149 / 417 / 774 / 1,428 | 399 / 948 / 1,794 / 2,988 |
| Semaglutide & Tesamorelin 1M | 299 | 449 |
| Tirzepatide & Tesamorelin 1M | 399 | 349 |

If `display_price` is meant as the "was" price, switch the per-day figure to the charged price; otherwise fix the funnel data.

## 11. NAD+ bonus text on products without NAD+

The NAD+ *image* is hidden for products whose name doesn't contain "NAD" (`site.json → bonus.match`), which is every
current product. The bonus *text* still shows (pill, bonus bar and box, first "included" row, checkout summary, `product.json` headlines).
Decide: hide it the same way per product, or set `bonus` to `null`.

## 12. Google Places autocomplete (checkout)

- The key (`config.php → google_maps.api_key`, `FUNNEL_GOOGLE_MAPS_KEY`) is a browser key — restrict it in Google Cloud
  to the live domain + localhost and to the Maps JavaScript / Places APIs.
- It uses the classic `google.maps.places.Autocomplete`, closed to *new* Google customers since March 2025 (not switched off).
  Successor: `PlaceAutocompleteElement`.

## 13. Vouched identity check

After an authorized pre-auth (`LeadFlow::identity()`, like `demo/identity_verify.php`): `identity/crosscheck` + `dob/verify`.
Verified = crosscheck **≥ 80** (`config.php → vouched.min_confidence`) **and** DOB match → `process.php`; otherwise → `idcheck.php`.

- **Keys:** from the funnel JSON (`tenant.vouched`: `app_secret` = private key on the server, `app_id` = public key for the plugin),
  else `FUNNEL_VOUCHED_API_KEY` / `FUNNEL_VOUCHED_APP_ID`. No key → logged `[VOUCHED] SKIP` and treated as verified (never blocks an order) — confirm.
- **Vouched error** (timeout, HTTP error) → the visitor goes to the ID check page.
- **Already verified:** a row with the same email that passed before (ID check `true`, crosscheck ≥ 80, DOB match — the demo's rule) → no new check.
- **ID check result:** like the demo, the browser reports `job.result.success` (`submit.php?action=idcheck`) and the page moves on;
  Vouched's own callback (`idcheck-callback.php`) confirms it on the row by job id. Don't rely on the browser's report alone for shipping.
  Allow `https://<domain>/idcheck-callback.php` as the callback URL in Vouched. Not verified → message with "Try again" (replaces the demo's `usernotverified.php`).
- `process.php` also accepts a row that is verified in the DB (crosscheck ≥ 80 + DOB match, or `idcheck_vouched_status = 'true'`).

## 14. Portal order hand-off (`process.php`) — to confirm

`LeadFlow::process()` + `lib/PortalClient.php`, like `demo/process/process.php`, fields from the "Parameter Reference" sheet:
`POST {tenant}/api/offer/process` — `config.php` currently defaults to the **staging** portal
`https://chime-health.stagingtenant.axismd.io/api/offer/process` (live: `https://chime-health.medaxis.io/api/offer/process`;
switch back in `config.php` or with `FUNNEL_PORTAL_API_URL` before go-live) — header `X-API-Key` (`FUNNEL_PORTAL_API_KEY`),
body `{"data": EncodeDecodeHelper::encodeWithToken(payload, FUNNEL_TENANT_SECRET_KEY)}` → `redirect_url` (the visitor is sent there).

- `answers` are sent by question key (the "Answers Reference" sheet uses the funnel's own keys), without the top-level fields
  (name, email, phone, DOB, gender, height, weight, BMI, address). Checkbox answers are arrays of the option texts.
- `products[].product_type` is always `regular` (also when Vrio retried a prepaid card on the prepaid campaign).
- `crm_transaction_id` isn't sent; `billing_same_as_shipping` is always `yes`.
- `vouched_dob_job_id` gets `idcheck_vouched_job_id` and `vouched_dob_id` gets the DOB job id — **as the demo does**; the sheet describes
  `vouched_dob_job_id` as the DOB job id. Confirm which the portal expects.
- `product_category` = `["weightloss", "antiaging"]` (`config.php → portal.product_category`, from the demo).
- `crm_customer_id`: the pre-auth reply, else Vrio `GET /orders/{id}` (the demo's guessed endpoint, not verified against Vrio's docs).
- A failed hand-off shows an error page with the order number and "Try again"; the portal rejects a repeated `crm_order_id`.
- **Logging (2026-09-28):** the full payload (before signing) and the portal's reply are written to `storage/logs/lead-flow.log`
  as `[PORTAL] SENT row #… order … offer/process payload: {…}` / `[PORTAL] REPLY … (HTTP …): …`, and to the PHP error log.
  The payload is also saved in `portal_processed_payload`. It holds personal and health data — decide whether to keep full
  payload logging after go-live or trim it to ids only.

## 15. Funnel credentials and the tenant secret key

`tenant.ghl.credentials` and `tenant.vouched` in the funnel JSON are `base64(JSON).hmac_sha256(…, tenant secret)` (`lib/EncodeDecodeHelper.php`).

- **With `FUNNEL_TENANT_SECRET_KEY`** in `.env`: the signature is checked; a wrong one → the credentials are ignored (PHP error log:
  "Funnel credentials ignored: signature does not match FUNNEL_TENANT_SECRET_KEY").
- **Without it:** they are read without the check (base64 is only encoding). Anyone who can change the funnel data could then change the keys.
- The same secret signs the portal payload (item 14), so it is **required** for `process.php`. It is `MEDAXIS_TOKEN_SIGNING_SECRET`
  in `demo/process/EncodeDecodeHelper.php` (verified: it matches the signatures in the funnel JSON). Keep it in `.env` only.

## 16. Demo files with keys in plain text

`demo/check-custom-field.php`, `demo/identity_verify.php`, `demo/idcheck*.php` and `demo/process/` contain the GHL token, the Vouched
private key, the portal API key and the signing secret. They are in `.gitignore` (not committed, not deployed). Don't share them;
rotate those keys if they were ever shared.
`demo/sample-create-contact.php` and `demo/db_update.php` (GHL token) and `demo/xverify.php` (xverify key) were committed before — they are in the git history
on GitHub, so rotate those two keys (items 4, 7).
