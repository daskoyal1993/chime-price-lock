# Implementing a new design — step by step

Use this when a client sends a new design (new look for one page, a few sections, or the whole funnel).
The rule: **change the look, never the behaviour.** Plans, prices, checkout, payment, CRM, Vouched, portal and tracking
code stay untouched. How the design layer works: `DESIGNER.md` → "Design layer". Background: `lib/Design.php`.

```
existing HTML (components/)  →  data-design-id  →  designs/<name>/config.json  →  classes added  →  designs/<name>/design.css
```

---

## 0. Before you start

1. Get the design files for **desktop and mobile**, the images/icons (SVG or 2x PNG/WebP), fonts and final texts.
2. Go through the design **section by section** and give each one a level:

   | Level | The design… | You do | Who |
   |---|---|---|---|
   | **1 — Styling** | same blocks, same order, new colors / spacing / fonts / radius / shadows | classes in `config.json` + CSS in `design.css` | designer |
   | **2 — Variation** | one block needs different markup (new wrapper, elements in another order, an extra icon) | a `-v2` copy of that component, mapped in `config.json` | designer + developer review |
   | **3 — New layout** | the page is structured differently | a set of `-v2` components for the page | designer + developer |
   | **Not design** | new data, new numbers, new behaviour (coupon, "Express checkout", discount line, carousel logic) | a developer task first | developer |

   Texts, images and section order are never "design work" in the code: they are edited in `projects/chime/<page>.json`
   (see `DESIGNER.md` → "Text, images, sections").
3. Write the list down (section → level) in the pull request description. Start with level 1 everywhere you can.

## 1. Branch

```bash
git checkout design
git pull origin design
git merge origin/main
```

## 2. Create the design folder

Copy the empty base design and give it a lowercase name (`client-v1`, `chime-2027` …):

```
designs/
├── default/          ← never change: it is the current look
├── chime-v2/         ← example (product page)
└── client-v1/        ← yours
    ├── config.json
    ├── design.css
    └── img/          ← images used only by this design (optional)
```

`designs/client-v1/config.json`:
```json
{
  "_help": "Client v1 design. Preview: product.php?design=client-v1",
  "extends": "default",
  "bodyClass": "theme-client-v1",
  "classes": {},
  "pages": {},
  "components": {}
}
```

`designs/client-v1/design.css`: start empty.

## 3. Preview it (nothing changes for visitors)

Open any page with `?design=client-v1`, e.g. `http://localhost/dscript/product.php?design=client-v1`.
The preview stays on for your browser session; `?design=off` ends it. Previews don't work on the live site.
Check `<body class="… theme-client-v1 design-client-v1">` in the browser's inspector.

## 4. Level 1 — page-wide look

In `design.css`, change the CSS variables for the whole design first — most of the site follows them:

```css
.theme-client-v1 {
    --brand: #7c3aed;        /* headings in brand color, links, highlights */
    --brand-soft: #f5f3ff;
    --button: #111827;       /* dark buttons */
    --ink: #1f2937;          /* body text */
    --radius: 16px;          /* card corners */
    --font: "Poppins";       /* with the font link below */
}
```

A new font: set it in `projects/chime/site.json → theme.font_url / font_family` (Google Fonts link), or `@import` it at the
top of `design.css`. All variables: top of `assets/css/common.css`.

Page-specific look: add a page body class in `config.json` and scope rules to it:
```json
"pages": { "checkout": { "bodyClass": "checkout-client-v1" } }
```
```css
.checkout-client-v1 { background: #f8fafc; }
```

Page names: `index` (quiz), `product`, `checkout`, `idcheck`, `not-verified`, `process`, `legal`, `thank-you`.

## 5. Level 1 — section by section

1. **Find the element's `data-design-id`** (inspect it in the browser, or the table in section 10).
2. **Give it classes** for this design in `config.json`:
   ```json
   "pages": {
     "product": {
       "bodyClass": "product-client-v1",
       "classes": {
         "product-hero":  "hero-client",
         "product-plans": "plans-client",
         "product-card":  "card-client"
       }
     }
   }
   ```
   `classes` at the top level applies on every page; `pages.<page>.classes` only on that page.
3. **Style them** in `design.css`, always scoped to the design's classes:
   ```css
   .product-client-v1 .hero-client { background: #faf5ff; }
   .product-client-v1 .card-client { border-radius: 24px; box-shadow: 0 12px 32px rgba(0, 0, 0, .08); }
   .product-client-v1 .card-client .plan { border-radius: 14px; }        /* children by their existing classes */
   ```
4. **No `data-design-id` on the element yet?** Add one in its component — one line, the rest stays:
   ```php
   <div class="<?= cls('summary-card', $s) ?>">                           ← before
   <div <?= design_attrs('checkout-summary', 'summary-card', $s) ?>>        ← after
   ```
   Name it `<page>-<part>` in lowercase, add it to the table in section 10, and never rename it later (designs depend on it).
   Children of an element usually don't need their own id: style them through the parent (`.card-client .plan`).

**CSS rules**
- Every rule starts with the design's body class (`.theme-client-v1` / `.product-client-v1`), so `default` never changes.
- Don't edit `assets/css/*.css` for one design, and don't use `!important` unless a rule truly can't win otherwise.
- Mobile first: check 375 px, then add `@media (min-width: 768px) { … }` for tablet/desktop, like the existing CSS.
- Images: `designs/client-v1/img/…`, referenced from `design.css` as `url("img/hero-bg.webp")`.

## 6. Level 2 — one section needs different HTML

1. Copy the component: `components/checkout/order-summary.php` → `components/checkout/order-summary-v2.php`.
2. Change the markup in the copy. **Keep**:
   - the element ids, `data-*` attributes and classes the JavaScript uses (`DESIGNER.md` → "Don't break"), and every form field id;
   - `design_attrs()` on the root with the same `data-design-id`;
   - the data it already uses (`$ctx[...]`, `$s[...]`) — new wording goes into the page JSON, new numbers are a developer task;
   - `e()` for text, `asset()` for images, `fill()` for `{placeholders}`.
3. Map it in `config.json`:
   ```json
   "components": { "checkout/order-summary": "checkout/order-summary-v2" }
   ```
   With `?design=off` (or any other design) the original is used, so both versions can be compared side by side.
   A variant file that doesn't exist falls back to the original and is reported in the QA error log.

## 7. Level 3 — a new layout for a page

Same as level 2 for each block that changes (e.g. `checkout/form-v2`, `checkout/sidebar-v2`, a new `checkout/steps`), all mapped in
`"components"`. A completely new block that doesn't exist yet (e.g. a progress stepper) is a new component file plus an entry
in the page JSON's `"sections"` — ask a developer to add it with `"visible": false` for the default design if needed.

Don't use JavaScript to move, wrap or rebuild parts of the page to reach a layout: make a variant component instead.

## 8. Test

| Check | How |
|---|---|
| Looks like the design | desktop 1280 px, tablet 768 px, phone 375 px; Chrome, Safari (iPhone), Firefox |
| Old design untouched | same pages with `?design=off` look exactly as before |
| Everything still works | quiz (Back, auto-continue, disqualification screen), product (pick treatment, pick plan, buy bar, FAQ), checkout (validation messages, billing toggle, address suggestions, **test card** payment), ID check, not-verified page |
| No errors | start a run with `?qa_test=start`, go through the flow, `?qa_test=end`, then `php tools/qa-errors.php TEST-…` (or `qa-errors.php?key=…`) — no new errors |
| Accessibility basics | text contrast, visible keyboard focus, tap targets ≥ 44 px on mobile |

## 9. Go live

1. Commit and push to `design`; open a pull request `design → main` with screenshots (desktop + mobile, before/after) and the
   section → level list from step 0.
2. After review and merge, switch the design on:
   - for everyone on this brand: `projects/chime/site.json` → `"design": "client-v1"` (a normal commit), **or**
   - per server: `FUNNEL_DESIGN=client-v1` in that server's `.env` (e.g. staging first, live later).
3. **Rollback:** set it back to `"default"` (or remove `FUNNEL_DESIGN`). No code to restore.
4. When the new design is the only one in use for a while, variant components can replace the originals and the old
   ones be removed — a separate clean-up pull request.

## 10. Elements you can style today (`data-design-id`)

| data-design-id | Page | Element | Component |
|---|---|---|---|
| `product-hero` | product | hero section | `components/product/hero.php` |
| `product-plans` | product | products + plans section | `components/product/plans.php` |
| `product-card` | product | each product card | `components/product/plans.php` |

Planned next (add them as you need them, same naming): `checkout-form`, `checkout-shipping`, `checkout-payment`,
`checkout-summary`, `checkout-included`, `product-steps`, `product-allin`, `product-members`, `product-delivery`, `product-faq`,
`product-buy-bar`, `site-header`, `site-footer`, `quiz-screen`, `quiz-option` (the quiz is built by `assets/funnel.js`; its ids are
a developer task), `idcheck-card`, `not-verified-text`, `legal-article`.

## 11. Quick reference

| I want to… | Do |
|---|---|
| Try a design without affecting anyone | `?design=<name>` in the URL |
| Change colors / radius / font everywhere | CSS variables under `.theme-<name>` in `design.css` |
| Change one page's background | `pages.<page>.bodyClass` + a rule on that class |
| Restyle one section | `pages.<page>.classes.<data-design-id>` + scoped CSS |
| Change a section's HTML | `-v2` component + `"components"` mapping |
| Change text / images / section order | `projects/chime/<page>.json` (not the design) |
| Add a new number or behaviour | developer task |
| Switch the live design | `site.json → "design"` or `FUNNEL_DESIGN` |
| Undo | back to `"default"` |

Worked example in the repo: `designs/chime-v2/` (product page: rounded hero card, teal brand color, modern product cards).
Preview: `product.php?design=chime-v2`.
