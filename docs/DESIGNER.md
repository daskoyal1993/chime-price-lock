# Designer guide

You can change the look and the wording of every page without touching the funnel logic (quiz rules, payments, CRM).
Everything you need is in four places:

| Change | Edit |
|---|---|
| Logo, brand name, phone, email, colors, fonts, header/footer text, product images and taglines | `projects/chime/site.json` |
| A page's text, images, which sections show and in what order | `projects/chime/<page>.json` (table below) |
| Styling | `assets/css/common.css` (all pages) and `quiz.css`, `product.css`, `checkout.css` |
| Section HTML (layout of one block) | `components/<group>/<name>.php` — with the rules in "Don't break" below |
| Images | `assets/img/` (shared) or `projects/chime/img/` (this brand only) |
| **A new design** (extra classes + CSS, component variants) | `designs/<design>/config.json` + `design.css` — see "Design layer" |
| Legal pages (privacy, terms, …) | `projects/chime/legal/<page>.html` |

Setup on your PC: follow `README.md` → "Local setup" once (XAMPP + clone). Then open `http://localhost/dscript/`.

---

## Pages and their files

| Page | URL | Text / sections | CSS | Header |
|---|---|---|---|---|
| Quiz | `index.php` | `index.json` (screen headings, buttons, messages — the questions themselves come from the funnel API) | `quiz.css` | `layout/header-quiz` |
| Product | `product.php` | `product.json` → sections `product/hero`, `product/plans`, `product/next-steps`, `product/all-in-one`, `product/members`, `product/delivery`, `common/faq`; sticky `product/buy-bar` | `product.css` | `layout/header-shop` |
| Checkout | `checkout.php` | `checkout.json` → `checkout/form`, `checkout/sidebar` (order summary, included list, reviews) | `checkout.css` | `layout/header-shop` |
| ID check | `idcheck.php` | `idcheck.json` (text around the Vouched widget — the widget itself is Vouched's) | `checkout.css` | `layout/header-shop` |
| ID not verified | `usernotverified.php` | `not-verified.json` (one paragraph) | `quiz.css` (bottom rules) | `layout/header-quiz` |
| Order error | `process.php` (only shown when the portal hand-off fails) | `process.json` | `checkout.css` | `layout/header-shop` |
| Legal pages | `legal.php?page=privacy-policy` etc. | `legal.json` (titles) + `legal/<page>.html` (content) | `common.css` → "LEGAL PAGES" | `layout/header-shop` |
| Footer (all shop pages) | — | `site.json → footer` (columns, links, copyright) | `common.css` → "FOOTER" | — |

The product and checkout pages need a finished quiz in the same browser; the ID check / not-verified pages need
a test order (ask a developer for a test run, or use the test card from `README.md`).

---

## Design layer (new designs without rewriting HTML) — `designs/`

Step-by-step guide for a client's new design: **[`NEW-DESIGN.md`](NEW-DESIGN.md)**.

A design only **adds** classes and CSS to the existing HTML, or swaps one component for a variant. Behaviour (plans, checkout,
payments, CRM) is untouched.

```
designs/
├── default/     config.json (adds nothing) + design.css (empty)
└── chime-v2/    example: product page (hero, product section, product cards)
    ├── config.json
    └── design.css
```

**`config.json`**
```json
{
  "extends": "default",
  "bodyClass": "theme-v2",
  "classes": { "product-card": "modern-card" },
  "pages": {
    "product": {
      "bodyClass": "product-v2",
      "classes": { "product-hero": "hero-v2 rounded-section", "product-plans": "plans-v2", "product-card": "modern-card" }
    }
  },
  "components": { "product/hero": "product/hero-v2" }
}
```
- `bodyClass` → `<body>` on every page; `pages.<page>.bodyClass` → one page (`product`, `checkout`, `index`, `idcheck`, …).
  The body also gets `design-<name>`.
- `classes` / `pages.<page>.classes` → added to the element with that **`data-design-id`**. The original classes stay.
- `components` → render another template (level 2/3, below). A missing variant falls back to the original.
- `design.css` is loaded after the page CSS. **Scope every rule** to the design's classes (`.product-v2 .modern-card { … }`),
  so nothing leaks into other designs. CSS variables (`--brand`, `--radius`, …) can be changed under `.theme-v2`.

**Elements with a `data-design-id`** (added with `design_attrs()` in the component):

| data-design-id | Element | Component |
|---|---|---|
| `product-hero` | hero section | `components/product/hero.php` |
| `product-plans` | products + plans section | `components/product/plans.php` |
| `product-card` | each product card | `components/product/plans.php` |

More ids are added as pages are migrated: `<section <?= design_attrs('checkout-form', 'co-main', $s) ?>>` instead of
`<section class="<?= cls('co-main', $s) ?>">`. Ids are lowercase, `<page>-<part>`, and never change once used.

**Choosing the design:** `site.json → "design": "chime-v2"`, or `FUNNEL_DESIGN=chime-v2` in `.env` (wins).
**Preview** without switching: `product.php?design=chime-v2` (kept for your browser session; `?design=off` ends it; not on live).

**Three levels — use the smallest that works:**
1. **Styling change** → classes in `config.json` + `design.css`. No HTML change.
2. **Component variation** (a block needs different markup) → copy the component to `components/<group>/<name>-v2.php`, change the
   markup (keep ids / `data-*` / JS classes — "Don't break" above), map it in `"components"`.
3. **Completely different layout** → new `-v2` components for the page, listed in `"components"` (or a page JSON that uses them).
   No JavaScript that moves or rebuilds the page.

`assets/design-manager.js` applies the same classes to HTML the browser creates later (quiz screens). PHP adds them on
render, so there is no flash of the old design.

## Rebranding (logo, colors, fonts)

`projects/chime/site.json`:

```json
"brand": { "name": "Chime Health", "logo": "assets/img/logo.png", "logo_alt": "assets/img/logo-chime.svg",
           "favicon": "assets/img/favicon.png", "phone": "…", "email": "…", "rating": "4.7", "members": "300,000+" },
"theme": {
  "font_url": "https://fonts.googleapis.com/css2?family=Inter:…",
  "font_family": "",
  "colors": { "brand": "#2f45c8", "button": "#0f172a", "navy": "#121f82", "footer": "#262626", "radius": "12px" }
}
```

- `logo` = quiz header; `logo_alt` = shop header + footer.
- **Colors are CSS variables.** Any name in `theme.colors` becomes `--<name>` on every page (write it without `--`).
  Defaults are at the top of `assets/css/common.css`: `--ink`, `--ink-strong`, `--ink-soft`, `--muted`, `--line`,
  `--line-soft`, `--card-line`, `--button`, `--track`, `--fill`, `--brand`, `--brand-soft`, `--green`, `--green-bg`,
  `--green-line`, `--teal`, `--emerald`, `--red`, `--orange`, `--danger`, `--slate-50/100/200`, `--navy`, `--footer`, `--radius`.
- One page only: `"theme": { "colors": { "brand": "#e11d48" } }` in that page's JSON.
- Font: set `font_url` (Google Fonts link) and `font_family` (e.g. `"Poppins"`).
- Some colors are still written directly in the page CSS files (not variables yet) — change them there, or ask a developer
  to turn them into variables (planned: `projects/README.md` → "Design architecture roadmap").

## Text, images, sections

- Each page JSON has `"sections": [ { "component": "product/hero", "visible": true, … } ]`.
  Reorder the list to move a section; `"visible": false` hides it; `"class": "my-class"` adds a CSS class.
- Plain text is escaped. Keys ending in **`_html`** may contain `<strong>`, `<br>`, `<a>`, `<span class="brand">`.
- `{placeholders}` like `{price}`, `{months}`, `{bonus}` are filled in by the page — keep them.
- **Product names, plans and prices come from the funnel API**, not from JSON. `site.json → products` only sets each
  product's image, tagline and badge (matched by keyword in the product name).
- JavaScript messages (buttons like "Continue with …", errors) are under each page's `"text"`.

## Legal pages

- Edit `projects/chime/legal/<page>.html` — plain HTML (`<h2>`, `<p>`, `<ul>`, `<a>`). `{brand}`, `{email}`, `{phone}` are filled in.
- Show a date: `"updated": "September 28, 2026"` for that page in `legal.json`.
- New page: add it to `legal.json → pages`, create `legal/<key>.html`, link `legal.php?page=<key>` from the footer (`site.json`).

---

## Don't break (the funnel's JavaScript and forms depend on these)

- **Keep ids, `data-*` attributes and these classes** when you change component HTML (each component's header comment lists what it needs):
  - Product (`product.js`): `.product-card` + `data-product` / `data-name` / `data-image`, `.treat-card` + `data-treatment`,
    `.plan`, the plan radio inputs with `data-price` / `data-months` / `data-label`, `[data-buy]`, `.form-error`,
    `#buy-bar`, `#buy-bar-img`, `#buy-bar-name`, `#buy-bar-plan`, `#buy-bar-btn`, `.faq-item button`.
  - Checkout (`checkout.js`): `#checkout-form`, `#complete-btn`, `#billing-fields`, `input[name=billing_same]`,
    `[data-field]` + `.fq-error`, `.form-error`, and every field id (`shipping_*`, `billing_*`, `card_number`, `card_month`, `card_year`, `card_cvv`).
  - ID check: `#vouched-element`, `#idcheck-failed`, `#idcheck-retry`.
- The **quiz HTML is built by `assets/funnel.js`** — restyle it with `quiz.css` only.
- **Don't edit:** `lib/`, `submit.php`, `config.php`, `.env`, `assets/*.js` (ask a developer), `data/` (funnel data), `database/`.
- Don't put one brand's styling into the shared CSS: brand-only rules go in `projects/chime/css/<page>.css`, listed in
  that page's `"styles"` after the shared file.
- JSON must stay valid (commas, quotes). If a page shows "Invalid JSON in …", check the file named in the message.
- Test at 375 px, 768 px and desktop, and in Safari (iPhone) as well as Chrome.

---

## Your Git branch: `design`

You work on the **`design`** branch only. `main` is protected: it goes live on every push, so only the project lead
merges into it.

```bash
git clone git@github.com:INV2025/dynamicquiz.git dscript     # once, into C:/xampp/htdocs
cd dscript
git checkout design
```

Every time you start:
```bash
git checkout design
git pull origin design
git merge origin/main          # get the latest code
```

When a change is ready:
```bash
git add projects/ components/ assets/css/ assets/img/
git commit -m "Product page: new hero layout"
git push origin design
```

Then on GitHub: **Pull requests → New pull request → base `main` ← compare `design`**, describe the change (with a
screenshot). The project lead reviews and merges it; the live site updates after that.

Questions about behaviour or data → ask a developer; see `DEVELOPER.md`.
