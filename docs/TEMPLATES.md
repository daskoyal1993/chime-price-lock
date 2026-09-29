# HTML templates — upload a new design as plain HTML

A designer can deliver a page as **plain HTML + CSS**. We drop it into `templates/<name>/`, and the site fills it with
live data. No PHP, no components, no JavaScript from the designer.

| Data | Comes from | Who edits it |
|---|---|---|
| Quiz questions, products, plans, **prices**, offer ids | the funnel JSON (API, or `data/r4mf-v1-funnel.json` locally) | nobody by hand |
| Brand (logo, phone, email, rating), each product's image / tagline / badge, bonus, included list | `projects/chime/site.json` | us |
| Layout, look, fixed texts, icons, which data appears where | `templates/<name>/<page>.html` + its CSS / images | the designer |

**Pages with templates:** product (`product.html`) and checkout (`checkout.html`). The quiz stays as it is. A template folder
can have one or both; a page without its file uses the normal page.

---

## 1. Folder

```
templates/
└── v2/                    ← one folder per design (lowercase, a–z 0–9 -)
    ├── product.html       ← full page: <html>, <head>, <body>
    ├── checkout.html
    ├── css/style.css      ← any file names; link them with relative paths
    └── img/…
```

Relative paths to files inside the folder (`css/style.css`, `img/logo.png`) are fixed automatically. External links
(Google Fonts, a CDN) stay as they are.

## 2. Switch it on / off

| | How |
|---|---|
| **Preview** (only your browser) | `product.php?template=v2` (or `checkout.php?template=v2`) — stays on while you browse; `?template=off` ends it. Not on the live site |
| **On for everyone** | `projects/chime/site.json` → `"template": "v2"` |
| **Back to the normal pages** | `"template": ""` — the normal pages are never changed by a template |

## 3. The rules for the designer

1. Build the page however you like. Only elements that show **live data** or **do something** need a **standard class**
   (lists below). Everything else — texts, badges, icons, layout — stays exactly as you wrote it.
2. Write **one** example of each repeated block (one product card, one plan). The site repeats it for every product / plan.
   Extra example copies are removed automatically.
3. Put example values inside the value elements (e.g. `<h2 class="product-name">Tirzepatide</h2>`) — they are replaced.
4. `<script>` tags are removed (the page's behaviour is ours). A script that is only decoration and must stay: add `data-keep`.
5. A standard class can sit next to your own classes: `<h2 class="card-title product-name">`.

## 4. Product page — standard classes

### Repeated blocks

| Class | Repeated for | Notes |
|---|---|---|
| `.product-card` | each product | gets `data-product`, `data-name`, `data-image`, `data-product-id`; `.is-featured-product` on the featured one; `.is-active` when chosen |
| `.product-option` (inside a card) | each plan of that product | gets `data-months`; `.is-featured` on the best-value plan; `.is-checked` when chosen |
| `.product-picker-item` | each product (optional quick picker) | clicking it highlights and scrolls to that product's card |
| `.included-item` (inside a card) | each row of `site.json → included` | |

Plan order: shortest first. Put `data-order="longest-first"` on the element with `.product-options` for longest first.

### Values — product (inside `.product-card`)

| Class | Filled with |
|---|---|
| `.product-image` | product image — an `<img>` gets `src`; any other element gets its first `<img>` updated (or a background image) |
| `.product-name` | product name (funnel) |
| `.product-content`, `.product-tagline` | tagline (`site.json → products`) |
| `.product-badge` | badge ("Recommended for you") |
| `.product-form` | form ("Injectable") |
| `.product-rating` | brand rating ("4.7") |
| `.product-price-from` | lowest price per month ("$186.75") |
| `.product-bonus-name`, `.product-bonus-value`, `.product-bonus-image` | the free add-on (`site.json → bonus`) |

### Values — plan (inside `.product-option`)

| Class | Filled with | Example |
|---|---|---|
| `.product-option-name` | plan name (`product.json → plan.monthly_name` / `kicker`) | "4 Month Plan" |
| `.product-option-months` | number of months | "4" |
| `.product-option-price` | total price of the plan | "$747" |
| `.product-option-price-month` | price per month | "$186.75" |
| `.product-option-per-day` | price per day (price ÷ 30 × months, same as checkout) | "$6.23" |
| `.product-option-save` | saving vs paying monthly | "$449" |
| `.product-option-save-percent` | saving in % | "38%" |
| `.product-option-input` | the radio the script reads — optional: created (hidden) if missing | |

### Buttons and messages

| Class | Where | Does |
|---|---|---|
| `.product-cta` inside a `.product-option` | on each plan | chooses that plan and goes to checkout |
| `.product-cta` in the card (not in a plan) | once per card | "Choose a plan length" → "Continue with … — $…" once a plan is picked; goes to checkout |
| `.product-pricing` → `.product-price`, `.product-price-per-day` | in the card | hidden until a plan is picked, then "$747 due today · $6.23/day" |
| `.product-error` | in the card | error message (hidden until needed) |
| `.buy-bar` → `.buy-bar-image`, `.buy-bar-name`, `.buy-bar-plan`, `.buy-bar-cta` | optional sticky bar | shown once a product is chosen |

Use one style of button or both: plan buttons (each plan buys itself) and/or one card button with radio-style plans.

### Show only when … (condition classes)

An element with one of these classes is **removed** when the condition is false. Several on one element = all must be true.

| Class | Kept when |
|---|---|
| `.if-featured` / `.if-not-featured` | the plan is / isn't the best-value plan |
| `.if-save` | the plan saves money vs monthly |
| `.if-monthly` / `.if-multi-month` | the plan is 1 month / longer |
| `.if-months-4`, `.if-months-12`, … | the plan has exactly that many months (e.g. bullets per plan length) |
| `.if-free-month` | the plan's name mentions a free month |
| `.if-badge` | the product has a badge |
| `.if-bonus` | the product includes the free add-on |
| `.if-products` / `.if-no-products` | there are / aren't any plans to sell (e.g. a "no plans available" message) |

## 5. Checkout page — standard names and classes

**Form fields are found by their `name`** (the id is added automatically). Values from the quiz are filled in.

| `name` | Field | Notes |
|---|---|---|
| `shipping_first_name`, `shipping_last_name` | name | prefilled |
| `shipping_address1`, `shipping_address2`, `shipping_city`, `shipping_zip` | address | address2 optional; Google address suggestions on address1 |
| `shipping_state` | `<select>` | the options are replaced by the states the funnel ships to; keep a first `<option value="">Select</option>` |
| `shipping_phone`, `shipping_email` | contact | prefilled, masked / checked |
| `card_number`, `card_cvv` | card | |
| `card_expiry` **or** `card_month` + `card_year` | expiry | one "MM / YY" field, or two selects — both work |
| `billing_same` | radios `1` / `0` or a checkbox | optional — without it billing = shipping address |
| `billing_first_name`, `billing_last_name`, `billing_address1`, `billing_city`, `billing_state`, `billing_zip` | billing address | only needed with `billing_same`; put them in `.billing-fields` |

| Class | Where | Does |
|---|---|---|
| `.checkout-form` | the `<form>` | required |
| `.field` | around each input (label + input) | gets `.fq-invalid` on an error; a `.field-error` is added if missing |
| `.field-error` | inside `.field` | the field's error message |
| `.billing-fields` | around the billing inputs | shown when "billing same as shipping" = no |
| `.checkout-cta` | the submit button | while paying its `.checkout-cta-label` (or the whole button) shows "Processing…" |
| `.checkout-error` | above/below the button | payment / server message |
| `a.link-product` | e.g. a "Choose" breadcrumb | back to the product page |

**Order summary** (the plan chosen on the product page):

| Class | Filled with | Example |
|---|---|---|
| `.order-image` | product image | |
| `.order-name` | product name | "Semaglutide (Inj)" |
| `.order-tagline`, `.order-badge` (+ `.if-badge`) | tagline, badge | |
| `.order-plan` | plan name | "4 Month Plan" |
| `.order-months` | months | "4" |
| `.order-price`, `.order-total` | price due today | "$747" |
| `.order-price-month` | per month | "$186.75" |
| `.order-per-day` | per day (price ÷ 30 × months) | "$6.23" |
| `.order-bonus-name`, `.order-bonus-value`, `.order-bonus-image` (+ `.if-bonus`) | the free add-on | |
| `.included-item` → `.included-text`, `.included-value`, `.included-icon` | benefits list (`site.json → included`), repeated | |
| `.if-monthly` / `.if-multi-month` | kept for a 1-month / longer plan | |

Fixed lines (fees, "FREE", shipping, guarantee, FSA badge) are just text in the HTML. **Not supported yet** (need
functionality first): promo codes / discounts, express checkout (Apple / Google Pay), a billing ZIP without a billing address,
the review carousel.

## 6. Any page — standard classes

| Class | Filled with |
|---|---|
| `.site-logo` / `.site-logo-quiz` | logo (`site.json → brand.logo_alt` / `logo`) — on an `<img>` or an element containing one |
| `.site-name`, `.site-rating`, `.site-members`, `.site-year` | brand name, rating, members count, current year |
| `.site-phone`, `.site-email` | phone, email — on an `<a>` also the `tel:` / `mailto:` link |
| `.user-first-name` | the visitor's first name from the quiz |
| `a.link-home` | link to the quiz start |
| `a.link-privacy`, `a.link-terms`, `a.link-telehealth`, `a.link-hipaa`, `a.link-refund`, `a.link-shipping` | links to our legal pages |

## 7. Example

`templates/v2/checkout.html` is a complete checkout example (shipping form, card box with MM / YY, order summary,
benefits). `templates/v2/product.html` (in the repo) is a complete example shaped like the "Choose Your Treatment" v2 design:
two product cards, plans longest first with a BEST VALUE plan, saving chips, bullets per plan length, a Select button per
plan, "What happens next", footer with phone / email / legal links. Preview: `product.php?template=v2`.

A minimal card:
```html
<article class="product-card">
  <div class="product-image"><img src="img/vial.png" alt=""></div>
  <h2 class="product-name">Tirzepatide</h2>
  <p class="product-content">Stronger appetite control.</p>
  <p class="if-badge">★ <span class="product-badge">Recommended</span></p>
  <div class="product-options" data-order="longest-first">
    <div class="product-option">
      <span class="if-featured">BEST VALUE</span>
      <h3 class="product-option-name">12-Month Plan</h3>
      <p><span class="product-option-price-month">$119</span>/month · <span class="product-option-per-day">$3.92</span>/day</p>
      <p class="if-save">Save <span class="product-option-save">$360</span></p>
      <button class="product-cta">Select</button>
    </div>
  </div>
  <p class="product-error"></p>
</article>
```

## 8. Checklist before switching a template on

- [ ] Preview with `?template=<name>` at 375 px, 768 px and desktop; Safari (iPhone) + Chrome.
- [ ] Every product from the funnel shows; plans and prices match the normal page (`?template=off`).
- [ ] Choosing a plan / pressing Select goes to checkout with the right plan (check the checkout's order summary).
- [ ] Checkout: fields prefilled, errors under the right fields, test card payment works (`FUNNEL_PAYMENT_DRIVER=test`).
- [ ] Phone, email and legal links work.
- [ ] QA run (`?qa_test=start` … `?qa_test=end`) shows no new errors (`php tools/qa-errors.php`).
- [ ] Then `site.json → "template": "<name>"` — and back to `""` if anything is wrong.

For developers: the filler is `lib/Template.php` (PHP's DOM parser); `product.php` / `checkout.php` call `Template::product()` /
`Template::checkout()` when a template is active; `assets/product.js` and `assets/checkout.js` use only the standard names
(their `SEL` maps and field names), so the normal pages and the templates share the same scripts.
