# Projects: page content, images and design from JSON

(Short version for designers: `docs/DESIGNER.md`.)

Each folder here is one project (brand / offer). The active one is set in `config.php` → `project`
(or the `FUNNEL_PROJECT` environment variable). Default: `chime`.

```
projects/chime/
├── site.json        shared by every page: brand, logo, colors, fonts, header, footer,
│                    product images/taglines/badges, bonus, "included" list, delivery estimate
├── index.json       quiz page:      screens (grouping of API questions), on-screen text,
│                                    auto-continue answers, per-question settings (e.g. SMS consent)
├── product.json     product page:   sections, text, images, JS button text
├── checkout.json    checkout page:  form labels, order summary, reviews, legal text
├── idcheck.json     ID check page:  text around the Vouched plugin, "not verified" message
├── legal.json       legal pages (legal.php?page=<key>): page list, titles, "last updated"
├── legal/<key>.html legal page content (plain HTML: privacy-policy, terms, telehealth-consent, hipaa-notice, …)
├── not-verified.json ID check failed page (usernotverified.php): one paragraph
├── process.json     process page:   error text when the portal hand-off fails (on success the visitor goes to the portal)
└── thank-you.json   thank-you page  (not in the flow for now)
```

**To change a page, edit only its JSON file.** The PHP files in the project root and `components/`
don't need touching for content, images, section order or colors.

## How a page is built

```
product.php  ─ loads data (session, API products) ─▶ Page::load('product') reads product.json
     │
     ▼
<head>   site.json styles + product.json styles, then site/page theme colors as CSS variables
header   product.json → "header"       (components/layout/header-shop.php)
<main>   product.json → "sections"     each { "component": "product/hero", ... } → components/product/hero.php
after    product.json → "after_main"   (sticky buy bar)
footer   site.json → "footer"          (hide per page with "footer": false)
```

## Legal pages (`legal.php`)

Footer links (`site.json → footer.columns`) and the checkout agreement text (`checkout.json → legal_html`) point to
`legal.php?page=<key>`. Each page is one entry in `legal.json → pages` (`title`, `updated`) plus its content in
`legal/<key>.html` — plain HTML (`<h2>`, `<p>`, `<ul>`, `<a>`); `{brand}`, `{email}`, `{phone}` are filled from `site.json → brand`,
and `<!-- comments -->` are left out of the page. The `.html` files can't be opened directly (`.htaccess`).

- **Edit a page:** change `legal/<key>.html`; set `"updated": "September 28, 2026"` in `legal.json` to show the date.
- **Add a page:** add `"<key>": { "title": "…" }` to `legal.json → pages`, create `legal/<key>.html`, link `legal.php?page=<key>`.
- An unknown key shows a 404 page listing the existing ones.

## Page JSON keys

| Key | What it does |
|---|---|
| `title` | Browser title (shown as "Brand \| title"). |
| `body_class` | Class on `<body>`. |
| `styles` | CSS files for this page, loaded after `site.json → styles` (`assets/css/common.css`). |
| `theme.colors` | Color overrides for this page only, e.g. `{ "brand": "#e11d48" }`. |
| `header` | `{ "component": "layout/header-shop", "show_phone": true }` or `layout/header-quiz`. |
| `main_class` | Class on `<main>`. |
| `sections` | The page content, top to bottom. |
| `after_main` | Sections after `<main>` (e.g. the buy bar). |
| `footer` | `true` / `false`. |
| `text` | Text used by the page's JavaScript (buttons, messages). |

## Section rules

- `"component"` names a file in `components/` (`"product/hero"` → `components/product/hero.php`).
- `"visible": false` hides the section. Reorder the list to move sections.
- `"class": "my-class"` adds a CSS class to the section, for project-specific styling.
- Plain text is escaped. Keys ending in **`_html`** are printed as HTML (`<strong>`, `<br>`, links).
- `{placeholders}` such as `{bonus}`, `{price}`, `{months}` are filled in by the component. Each component's
  header comment lists its settings and placeholders.
- Image paths are relative to the site root (`assets/img/...` or `projects/<name>/img/...`).

## Quiz page settings (`index.json`)

| Key | What it does |
|---|---|
| `screens` | Groups API question keys into screens, with optional `heading`, `text`, `badge`, `badge_style`, `button`, `doctor`. API questions not listed are added **after** these screens, so list every question where it should appear (e.g. `target_weight` right after height/weight). |
| `skip_questions` | API question keys left out of the quiz (not shown, validated or saved), e.g. the shipping address, which is asked on checkout. |
| `doctor_image` | Clinician photo shown on screens with `"doctor": true`. |
| `auto_continue_options` | Exact answers (any case) that submit the screen by themselves, e.g. `"None of these"`, `"No"`. |
| `auto_continue_patterns` | Regular expressions tested against the start of the answer, e.g. `"^no\b"` ("No - I affirm…"), `"^i am not\b"`. In JSON a regex `\b` must be written `\b`. |
| `questions.<key>` | Display settings for one API question: `prechecked` (checkbox starts ticked), `compact` (only the checkbox line), `text` (shown instead of the API option text), `required_message`, `placeholder` (weight field), `hint` (small note under a text / weight field, e.g. target weight "You can always adjust later."). |
| `text` | Quiz wording: buttons, placeholders, BMI boxes, DQ screen, xverify messages (`xverify_email`, `xverify_phone`, `verifying`). |

Auto-continue presses the screen's own Continue button (validation, DQ, saves all run as usual) and only when
every question on the screen is answered.

## Product names and the add-on (`site.json`)

- The **product name always comes from the funnel JSON** (product `name`, e.g. "Semaglutide (Inj)"): hero picker,
  product cards, buy bar, checkout and thank-you summary.
- `site.json → products` is matched by keyword in that name (first match wins — put the longer keys, e.g.
  `"tirzepatide & tesamorelin"`, before `"tirzepatide"`) and only adds `image`, `tagline`, `badge`, `featured`.
- `site.json → bonus.match` (e.g. `"NAD"`): the add-on image (vial + "+") is only shown for products whose name
  contains it. Set `bonus` to `null` to hide the add-on everywhere.
- Which products are shown depends on the membership check: member → `membership_campaign_set_products`,
  otherwise `products` (see `PRODUCT-NEW-MIGRATION.md` §0).

## What is NOT in JSON (on purpose)

- **Products, plans, names and prices** come from the funnel API. `site.json → products` only sets how an API product
  is shown (matched by keyword in the API name).
- **Form field ids, validation, payment, CRM data** stay in PHP/JS (`submit.php`, `lib/`, `assets/*.js`).
  JSON only changes the labels.
- **Quiz questions** come from the API. `index.json → screens` only groups them into screens.
- **Settings, keys and integrations** (DB, Vrio, GHL, xverify, membership, Vouched, portal, Google Maps key, payment driver,
  test card) are in `config.php` / `.env` (or the funnel JSON's `tenant`), not in the project JSON.
- **Target weight rules** (below the current weight, BMI ≥ 18.5) and the "That is X lbs" card are in `assets/funnel.js` /
  `lib/FunnelRules.php`; their wording can be changed with `index.json → text` (`goal_lose`, `goal_note`, `goal_none`, `goal_none_note`).

## Start a new project

1. Copy `projects/chime` to `projects/<new-name>`.
2. Edit `site.json`: brand, logo, favicon, `theme.colors`, footer, `products`, `bonus`, `included`.
3. Edit each page JSON: text, images, sections to show/hide.
4. Put new images in `assets/img/` or `projects/<new-name>/img/` and point the JSON at them.
5. Need design changes beyond colors? Add `projects/<new-name>/css/product.css` and list it in
   `product.json → styles` (after `assets/css/product.css`). Don't edit the shared CSS for one project.
6. Set `project` in `config.php` (or `FUNNEL_PROJECT=<new-name>`).

## Add a new section type

1. Create `components/<group>/<name>.php`. Start it with
   `<?php defined('FUNNEL_APP') || exit;` and list its settings in the header comment.
   Inside it: `$s` = this section's JSON, `$site` = site.json, `$ctx` = data from the page PHP.
2. Use `e()` for text, `asset()` for image/CSS paths, `fill()` for `{placeholders}`, `cls('base', $s)` for classes.
3. Add its styles to the page CSS (`assets/css/<page>.css`) or `common.css` if more than one page uses it.
4. Add `{ "component": "<group>/<name>", ... }` to a page JSON.

## CSS files

| File | Loaded on |
|---|---|
| `assets/css/common.css` | every page (reset, color defaults, shared text/form parts, shop header, footer) |
| `assets/css/quiz.css` | quiz |
| `assets/css/product.css` | product page |
| `assets/css/checkout.css` | checkout, ID check, process error page, thank-you |

Colors are CSS variables (`--brand`, `--button`, `--navy`…). The defaults are in `common.css`;
`site.json → theme.colors` overrides them for the whole project, and `<page>.json → theme.colors` for one page.

JSON, PHP and `.md` files under `projects/` and everything in `components/` are blocked from the web by `.htaccess` (Apache).

## Design architecture roadmap (planned, not built yet)

Goal: a new logo / full rebrand = JSON + CSS only; a new product or checkout design = templates only, with pricing,
payment, CRM, Vouched, portal and DB code untouched. Reviewed 2026-09-28. Each phase must leave the `chime` pages
unchanged — save the rendered HTML of every page before a phase and diff it after.

```
Content / Data   projects/<brand>/*.json               text, images, section order
Configuration    projects/<brand>/site.json            brand, theme tokens, fonts
                 config.php + .env                     keys, CRM, payment, portal
Templates        projects/<brand>/components/…         per-brand overrides (phase 3)
                 components/…                          defaults
Design           assets/css/*.css + projects/<brand>/css/
──────────── contract: $ctx view data + data-js hooks ────────────
Logic            page controllers → lib/View/*          prices, plans, order summary (phase 5)
                 assets/*.js                            behaviour, bound to data-js only (phase 4)
                 submit.php, lib/*Client, Payment       API / DB / CRM / payment / Vouched / portal
```

| Phase | Change | Files |
|---|---|---|
| 1 | Document the contract: `$ctx` keys and JS hooks in each component header | `components/`, this file |
| 2 | Tokens: replace raw hex values in the CSS with variables (defaults = current values); add button and heading/body font tokens to `site.json → theme` | `assets/css/*.css`, `lib/Page.php` (`page_open`), `lib/view.php` (Trustpilot colors) |
| 3 | Per-brand overrides: `Page::component()` looks in `projects/<p>/components/` first, then `components/`; move brand images to `projects/<p>/img/` | `lib/Page.php`, `projects/chime/*.json` |
| 4 | JS hooks: add `data-js="…"` next to the classes the JS uses, switch the selectors, keep the classes for styling; move validation messages to `checkout.json → text` | `components/product/*`, `components/checkout/*`, `assets/product.js`, `assets/checkout.js` |
| 5 | View models: move plan / savings / banner / per-day math out of the templates | `lib/View/ProductView.php`, `lib/View/CheckoutView.php`, `product.php`, `checkout.php`, `components/product/plans.php`, `components/checkout/order-summary.php` |
| 6 | Multi-brand per request (host or funnel slug → project, `FUNNEL_PROJECT` as fallback); `"extends"` in `site.json` with a deep merge | `config.php`, `bootstrap.php`, `lib/Page.php` |
| 7 | New features if needed: tracking pixels from `site.json → tracking` + JS events (`funnel:plan-selected`, `funnel:checkout-success`); coupons (new `submit.php` action) | new |

JS hooks that must survive any redesign today (until phase 4):
`product.js` — `.product-card[data-product|data-name|data-image]`, `.treat-card[data-treatment]`, `.plan`, plan radios
`[data-price|data-months|data-label]`, `[data-buy]`, `.form-error`, `#buy-bar`, `#buy-bar-img|-name|-plan|-btn`, `.faq-item button`.
`checkout.js` — `#checkout-form`, `#complete-btn`, `#billing-fields`, `input[name=billing_same]`, `[data-field]` + `.fq-error`,
`.form-error`, and every field id (`shipping_*`, `billing_*`, `card_number`, `card_month`, `card_year`, `card_cvv`).
The quiz markup is built in `assets/funnel.js`; restyle it through CSS.
