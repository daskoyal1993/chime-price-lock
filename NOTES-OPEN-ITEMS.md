# Open items — to discuss

Items parked for later (last updated 2026-09-24). Each one says where it lives in the code, so it can be changed in one place.
Overview of the whole build: `PRODUCT-NEW-MIGRATION.md` §0. Editing pages: `projects/README.md`.
(This file is blocked from the web by `.htaccess`, like every `*.md`.)

---

## 1. GHL fields not mapped yet (the quiz has no matching question)

The GHL sample (`demo/sample-create-contact.php`) sends these, but this funnel has no question for them, so they are **not sent**:

| GHL field id | GHL key | Sample variable |
|---|---|---|
| `xTlZEuAqqmmzWTUYM4Wu` | `contact.goal_weight` | `$goalWeightLbs` |
| `0oUlAEHJYYVuruTmFrgk` | `contact.what_pace_feels_right_for_you` | `$pace` |
| `Xs6j5Y8vZLOrRDEyQN0g` | `contact.date_of_bmi_consent` | `$bmiConsentDate` |
| `PaFMCXTfnGt1F6op8Nsz` | `contact.time_of_bmi_consent` | `$bmiConsentTime` |

- **Goal weight / pace:** add the questions to the funnel API (with their `ghl` field id), or decide they are not needed.
- **BMI consent:** in the old site it was recorded automatically (obese BMI + over 65). Our quiz has `metabolic_consent`
  (a checkbox with different conditions). Decide whether `metabolic_consent` should fill these two fields.
  If yes, add to `config.php → ghl.field_map`:
  ```php
  'metabolic_consent@date' => ['id' => 'Xs6j5Y8vZLOrRDEyQN0g', 'key' => 'contact.date_of_bmi_consent'],
  'metabolic_consent@time' => ['id' => 'PaFMCXTfnGt1F6op8Nsz', 'key' => 'contact.time_of_bmi_consent'],
  ```

## 2. `glp_consent` is mapped to a GHL *date* field

The funnel API maps question `glp_consent` to `EBwjhHrmWoH3mSN6Af7n` / `contact.date_of_glp_consent`.
Because the field is a date, we send **the date the consent was ticked** (not the checkbox text).
Rule in `lib/LeadFlow.php → customFields()`: any API mapping whose key contains `.date_of_` / `.time_of_` gets the consent date / time.

To confirm: is that field meant to hold the date? Is there also a `time_of_glp_consent` field to fill?

## 3. Consent timestamps timezone — RESOLVED

Now the same as the live site's `server-time.php` (`https://qualify.chimehealth.com/rnad/v2/server-time.php` →
`{"date":"September 24, 2026","time":"2:49 PM UTC"}`): UTC, date `F j, Y`, time `g:i A T`
(`config.php → ghl.consent_timezone / consent_date_format / consent_time_format`).

Consents recorded: `elderly_consent`, `metabolic_consent`, `glp_consent`, `contact_agree`, `sms_consent`
(`config.php → ghl.consent_questions`). Only the elderly and SMS ones are mapped to GHL (plus `glp_consent`, see 2).

## 4. GHL token and location — temporary values

`config.php → ghl.token` defaults to the token copied from `demo/sample-create-contact.php` (`pit-c395…`),
and `ghl.location_id` to `AeheBO22OOorfyaJeD8X` (same as the sample and the funnel JSON `tenant.ghl.location_id`).

- **Plan:** take them from the funnel JSON later (e.g. `tenant.ghl`), then remove the hard-coded default.
  The funnel JSON already has `tenant.ghl.location_id`, `new_contact_tag` (`["lead"]`) and `purchased_tag`.
- `FUNNEL_GHL_TOKEN` (environment) still overrides the default.
- The token is in plain text in `demo/sample-create-contact.php` and `config.php`. Rotate it before go-live
  and keep it out of any shared copy of the project.

## 5. Membership check — placeholder

The real membership API doesn't exist yet. Current behaviour (`lib/Membership.php`):

- Called once, when the contact screen (email) is submitted; the answer is kept in the session.
- `config.php → membership.api_url` empty → built-in sample: **no email is a member** (`false`) for now.
  `sample-api/membership-check.php` is the same sample as an HTTP endpoint:
  `POST {"email": "..."}` → `{"success": false, "email": "..."}`.
- Expected real API: `POST {"email"}` → `{"success": true|false}` (`success` = has membership). Confirm the real
  URL, method, auth and response shape, then set `FUNNEL_MEMBERSHIP_API_URL` (and `FUNNEL_MEMBERSHIP_API_TOKEN`).
- If the API fails, `membership.fallback` is used (`false` = not a member → `campaign_set`). Confirm what should happen then.

Offer per answer (`offer_funnel()` in `bootstrap.php`):

| Membership | Campaign (Vrio `campaign_id`, order record) | Product page / plans |
|---|---|---|
| member (`success: true`) | `membership_campaign_set` → campaign 24, CRM **64** | `membership_campaign_set_products` (Semaglutide / Tirzepatide & Tesamorelin bundles, 1M, 3M) |
| not a member (`success: false`) | `campaign_set` → main campaign 21, CRM **68** | `products` (Semaglutide / Tirzepatide 1M, 3M, 6M) |

Confirmed 2026-09-24: member → `membership_campaign_set`, not a member → `campaign_set`.

## 6. Membership bundle presentation (product page)

Product names now come from the funnel JSON (e.g. "Semaglutide & Tesamorelin Bundle"). `projects/chime/site.json → products` has
placeholder images (the GLP-1 vial) and taglines for the two bundles (`… & tesamorelin`). Needed: bundle images, taglines, badges.
The NAD+ vial image is hidden for products whose name doesn't contain "NAD" (all current ones) — the NAD+ bonus *text* still shows.

Also check the product page copy for non-members: `site.json → bonus` (free NAD+), `product.json` hero/plans text
(“GLP-1 + NAD+ combo”, “every plan comes with NAD+”) are written for the regular offer.

## 7. xverify — temporary key and domain

`config.php → xverify` defaults to the key and domain copied from `demo/xverify.php`
(`1024692-6A65809C`, domain `amerilean.com`; the demo also had `inv-health.com` commented out).

- Confirm which domain this funnel should report to xverify, and move the key to `FUNNEL_XVERIFY_API_KEY`
  (or the funnel JSON) before go-live; the key is in plain text in `demo/xverify.php` and `config.php`.
- Only status `invalid` blocks the visitor (`xverify.block_statuses`). Decide whether `risky` / `unknown` should block too.
- If xverify can't be reached, the visitor is let through and it's logged as `[XVERIFY] FAILED … not blocking`.

## 8. SMS consent on the contact screen — wording

Like the live step 16, `sms_consent` is on the name / email / phone screen, **ticked by default** and required
(`projects/chime/index.json → questions.sms_consent`). Its title and text come from the funnel JSON
(`sms_consent`: "SMS Consent" / "I consent to receive marketing text messages from AmeriLean …"), so they change with the API.

To confirm with compliance: the funnel text names **AmeriLean**, not Chime Health — update it in the funnel API.
(The live site shows a shorter line instead: "Clinician + delivery texts & refill updates. Unsubscribe anytime." —
to show that again, add `"text": "…"` and `"compact": true` back to `questions.sms_consent`.)

## 9. Vrio pre-authorization at checkout — open points

"Complete Purchase" now pre-authorizes the card in Vrio (`config.php → payment_driver` = `vrio`, `lib/VrioClient.php → authorize()`,
same request as `demo/preauth.php`). To go live / confirm:

- **`FUNNEL_VRIO_SHIPPING_PROFILE_ID`** — `VRIO_SHIPPING_PROFILE_ID` from the demo's `vrio-config.php` (not in this project).
  Until it is set, checkout answers "Payments are not set up yet" and logs `[VRIO-PREAUTH] FAILED … not configured`.
- **Different billing address:** like the demo, the request only has `bill_*` fields and `same_address: true`. When the visitor
  enters a separate billing address, the billing address is sent as `bill_*`; the shipping address isn't sent to Vrio. Confirm the
  Vrio field names for a separate shipping address (e.g. `ship_*`) if that's needed.
- **Prepaid retry:** implemented like the demo, but both campaign sets have `prepaid_campaign: null` in the funnel JSON, so a
  prepaid card is declined ("Prepaid cards not allowed on campaign 68"). Add a prepaid campaign to the funnel to enable the retry.
- **Scrap flow** (`VRIO_SRP_CAMPAIGN_ID` / `steps.meta.isScrapFlow` in the demo) is not part of this funnel — not implemented.
- **Order vs. prospect:** the prospect created on the contact screen (`POST /orders` in `demo/create-prospect.php`) and the pre-auth
  (`POST /orders`, `action: authorize`) are separate calls, as in the demo; the pre-auth doesn't send the prospect's `customer_id`.
  Confirm whether Vrio should link them.
- On localhost this makes **real authorizations** with the live key. Use `FUNNEL_PAYMENT_DRIVER=test` in `.env` to test the
  checkout without Vrio (approves the test card 4111 2222 3333 4444 — config.php → test_card — and charges nothing).

## 10. Checkout "As low as $x/day" uses `display_price`

The order summary shows the variant's `display_price` (funnel JSON) ÷ (30 × months): 1M ÷ 30, 3M ÷ 90, 6M ÷ 180
(`checkout.php`). On every 1-month plan `display_price` differs from the charged price, so the line
"Total Due Today $299 · Just $5.97/day" doesn't add up ($5.97 × 30 = $179):

| Plan | Charged | display_price |
|---|---|---|
| Semaglutide 1M | 299 | 179 |
| Tirzepatide 1M | 359 | 399 |
| Semaglutide & Tesamorelin 1M | 299 | 449 |
| Tirzepatide & Tesamorelin 1M | 399 | 349 |

The bundle 3-month plans have no `display_price` (the charged price is used). Fix `display_price` in the funnel data,
or switch the per-day figure to the charged price.

## 11. NAD+ bonus text on products without NAD+

The NAD+ *image* is hidden for products whose name doesn't contain "NAD" (`site.json → bonus.match`), which is every
current product. The bonus *text* still shows: "BONUS · FREE NAD+" pill, green bonus bar and bonus box on each card,
the first "included" row, "FREE NAD+" in the checkout summary, and the "GLP-1 + NAD+ combo" headlines in `product.json`.
Decide: hide it the same way per product, or set `bonus` to `null`.

## 12. Google Places autocomplete (checkout)

- The key (`config.php → google_maps.api_key`, `FUNNEL_GOOGLE_MAPS_KEY`) is a browser key — restrict it in Google Cloud
  to the live domain + localhost and to the Maps JavaScript / Places APIs.
- It uses the classic `google.maps.places.Autocomplete` (as in the live snippet). Google logs that it's closed to *new*
  customers since March 2025 (not scheduled to be switched off; 12 months' notice promised). Successor: `PlaceAutocompleteElement`.
