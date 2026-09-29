<?php
/**
 * HTML templates from the designer: templates/<name>/<page>.html (+ its css/, img/…), filled with live data.
 *
 * The designer's HTML is kept as it is (layout, texts, icons). Only elements with a STANDARD CLASS are filled from the
 * funnel JSON (products, plans, prices) and site.json (brand, product images/taglines, bonus, included list):
 *   - a repeated block (.product-card, .product-option, …) is written once; it is repeated for every product / plan
 *   - value classes (.product-name, .product-option-price, …) get the value (text, or src for an <img>)
 *   - condition classes (.if-featured, .if-save, .if-bonus, …) are removed where they don't apply
 * Then the page scripts are added (page data, product.js, the QA error script); the designer's own <script>s are removed
 * (functionality is always ours) unless they carry data-keep. Relative paths to the template's own files are fixed.
 *
 * Active template: site.json → "template" (empty = the normal pages). Preview: ?template=<name> for this browser
 * session, ?template=off to end it (not on FUNNEL_ENV=live). Standard classes: docs/TEMPLATES.md.
 */
final class Template
{
    /** @var DOMDocument */
    private $doc;
    /** @var DOMXPath */
    private $xp;
    /** @var string */
    private $name;
    /** @var string */
    private $page;

    /* ================================================================ which template */

    /** The template for this page, or null → render the normal page. Handles ?template= previews. */
    public static function active(array $config, string $page): ?string
    {
        $name = (string) site('template', '');
        if (($config['qa_errors']['env'] ?? 'local') !== 'live' && PHP_SAPI !== 'cli') {
            $preview = (string) ($_GET['template'] ?? '');
            if ($preview === 'off') {
                unset($_SESSION['template_preview']);
                $name = '';
            } elseif ($preview !== '' && self::exists($preview, $page)) {
                $_SESSION['template_preview'] = $preview;
            }
            if (isset($_SESSION['template_preview'])) $name = (string) $_SESSION['template_preview'];
        }
        return $name !== '' && self::exists($name, $page) ? $name : null;
    }

    private static function exists(string $name, string $page): bool
    {
        return (bool) preg_match('/^[a-z0-9-]{1,40}$/', $name) && is_file(self::dir($name) . '/' . $page . '.html');
    }

    private static function dir(string $name): string
    {
        return dirname(__DIR__) . '/templates/' . $name;
    }

    /* ================================================================ loading / output */

    private function __construct(string $name, string $page)
    {
        $this->name = $name;
        $this->page = $page;
        $html = (string) file_get_contents(self::dir($name) . '/' . $page . '.html');
        $this->doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);   // HTML5 tags (section, header…) are fine; don't report them
        $this->doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        foreach (iterator_to_array($this->doc->childNodes) as $n) {
            if ($n->nodeType === XML_PI_NODE) $this->doc->removeChild($n);
        }
        $this->xp = new DOMXPath($this->doc);
    }

    /** Scripts out (unless data-keep), ours in, paths fixed, then print the page. */
    private function output(array $boot, array $scripts): void
    {
        foreach (iterator_to_array($this->doc->getElementsByTagName('script')) as $s) {
            if (!$s->hasAttribute('data-keep') && strtolower($s->getAttribute('type')) !== 'application/ld+json') $s->parentNode->removeChild($s);
        }
        $this->fixPaths();
        $head = $this->doc->getElementsByTagName('head')->item(0);
        $body = $this->doc->getElementsByTagName('body')->item(0);
        if (!$head || !$body) {
            throw new RuntimeException('templates/' . $this->name . '/' . $this->page . '.html needs <head> and <body>');
        }
        $this->ensureMeta($head);
        if ($qa = ErrorLogger::clientConfig()) {
            $head->insertBefore($this->jsonScript('qa-errors-config', $qa), $head->firstChild);
            $head->insertBefore($this->script(asset_url('assets/qa-errors.js'), false), $head->firstChild->nextSibling);
        }
        $body->appendChild($this->jsonScript('page-data', $boot));
        foreach ($scripts as $src) $body->appendChild($this->script(asset_url($src), true));

        $html = $this->doc->saveHTML();
        echo stripos(ltrim($html), '<!doctype') === 0 ? $html : "<!DOCTYPE html>\n" . $html;
    }

    private function ensureMeta(DOMElement $head): void
    {
        if (!$this->xp->query('//meta[@charset]')->length) {
            $m = $this->doc->createElement('meta');
            $m->setAttribute('charset', 'utf-8');
            $head->insertBefore($m, $head->firstChild);
        }
        if (!$this->xp->query('//meta[@name="robots"]')->length) {
            $m = $this->doc->createElement('meta');
            $m->setAttribute('name', 'robots');
            $m->setAttribute('content', 'noindex, nofollow');
            $head->appendChild($m);
        }
    }

    private function jsonScript(string $id, array $data): DOMElement
    {
        $s = $this->doc->createElement('script');
        $s->setAttribute('id', $id);
        $s->setAttribute('type', 'application/json');
        // ASCII-only JSON: the HTML writer would turn "…" into "&hellip;" inside the <script>.
        $s->appendChild($this->doc->createTextNode(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES)));
        return $s;
    }

    private function script(string $src, bool $defer): DOMElement
    {
        $s = $this->doc->createElement('script');
        $s->setAttribute('src', $src);
        if ($defer) $s->setAttribute('defer', 'defer');
        return $s;
    }

    /** A relative href/src that points at a file in the template folder → templates/<name>/<path>. */
    private function fixPaths(): void
    {
        foreach (['link' => 'href', 'img' => 'src', 'source' => 'src', 'script' => 'src', 'video' => 'poster', 'image' => 'href'] as $tag => $attr) {
            foreach ($this->doc->getElementsByTagName($tag) as $el) {
                $v = $el->getAttribute($attr);
                if ($v === '' || preg_match('~^([a-z]+:|//|/|#|data:)~i', $v)) continue;
                $path = preg_replace('~[?#].*$~', '', $v);
                if (is_file(self::dir($this->name) . '/' . $path)) {
                    $el->setAttribute($attr, 'templates/' . $this->name . '/' . $v);
                }
            }
        }
    }

    /* ================================================================ DOM helpers */

    private static function classXpath(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /** Elements with a class inside $root (or the whole page). */
    private function all(string $class, ?DOMNode $root = null): array
    {
        $q = $root ? './/*[' . self::classXpath($class) . ']' : '//*[' . self::classXpath($class) . ']';
        $out = [];
        foreach ($this->xp->query($q, $root) ?: [] as $n) $out[] = $n;
        return $out;
    }

    /** Every element with $class inside $root: text replaced (or src for <img>, or the first <img> inside). */
    private function set(DOMNode $root, string $class, $value, bool $image = false): void
    {
        foreach ($this->all($class, $root) as $el) {
            if ($image) {
                $img = $el->nodeName === 'img' ? $el : ($this->xp->query('.//img', $el)->item(0));
                if ($img) {
                    $img->setAttribute('src', (string) $value);
                    if (!$img->hasAttribute('alt')) $img->setAttribute('alt', '');
                } else {
                    $el->setAttribute('style', trim($el->getAttribute('style') . ";background-image:url('" . str_replace("'", '', (string) $value) . "')", ';'));
                }
                continue;
            }
            $this->text($el, (string) $value);
        }
    }

    private function text(DOMElement $el, string $value): void
    {
        while ($el->firstChild) $el->removeChild($el->firstChild);
        $el->appendChild($this->doc->createTextNode($value));
    }

    /** Remove every element with $class inside $root when $keep is false; when true, keep them (the class stays). */
    private function when(DOMNode $root, string $class, bool $keep): void
    {
        if ($keep) return;
        foreach ($this->all($class, $root) as $el) {
            if ($el->parentNode) $el->parentNode->removeChild($el);
        }
    }

    /**
     * The first element with $class inside $root is the pattern; it is repeated for each item ($fill(clone, item, i))
     * in its place. Any further copies the designer left in the HTML (example cards) are removed.
     */
    private function repeat(DOMNode $root, string $class, array $items, callable $fill): void
    {
        $found = array_values(array_filter($this->all($class, $root), function ($el) use ($class) {
            // only the outermost ones (a card inside a card is not a second pattern)
            for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                if (preg_match('/(^|\s)' . preg_quote($class, '/') . '(\s|$)/', $p->getAttribute('class'))) return false;
            }
            return true;
        }));
        if (!$found) return;
        $pattern = $found[0];
        $parent  = $pattern->parentNode;
        foreach (array_values($items) as $i => $item) {
            $clone = $pattern->cloneNode(true);
            $parent->insertBefore($clone, $pattern);
            $fill($clone, $item, $i);
        }
        foreach ($found as $el) {
            if ($el->parentNode) $el->parentNode->removeChild($el);
        }
    }

    private function addClass(DOMElement $el, string $class): void
    {
        $el->setAttribute('class', trim($el->getAttribute('class') . ' ' . $class));
    }

    /* ================================================================ shared: brand, visitor */

    /** .site-* and .user-* anywhere on the page (site.json → brand, the visitor's answers). */
    private function fillSite(array $answers): void
    {
        $b    = site('brand', []);
        $root = $this->doc->documentElement;
        $this->set($root, 'site-logo', asset_url((string) ($b['logo_alt'] ?? $b['logo'] ?? '')), true);
        $this->set($root, 'site-logo-quiz', asset_url((string) ($b['logo'] ?? '')), true);
        $this->set($root, 'site-name', (string) ($b['name'] ?? ''));
        $this->set($root, 'site-rating', (string) ($b['rating'] ?? ''));
        $this->set($root, 'site-members', (string) ($b['members'] ?? ''));
        $this->set($root, 'site-year', date('Y'));
        foreach ($this->all('site-phone') as $el) {   // <a class="site-phone"> also gets the tel: link
            $this->text($el, (string) ($b['phone'] ?? ''));
            if ($el->nodeName === 'a') $el->setAttribute('href', 'tel:' . preg_replace('/[^\d+]/', '', (string) ($b['phone'] ?? '')));
        }
        foreach ($this->all('site-email') as $el) {
            $this->text($el, (string) ($b['email'] ?? ''));
            if ($el->nodeName === 'a') $el->setAttribute('href', 'mailto:' . ($b['email'] ?? ''));
        }
        $this->set($root, 'user-first-name', (string) ($answers['first_name'] ?? ''));
        // Links to our pages: <a class="link-privacy"> etc. (legal.php)
        foreach (['privacy' => 'privacy-policy', 'terms' => 'terms', 'telehealth' => 'telehealth-consent', 'hipaa' => 'hipaa-notice', 'refund' => 'refund-policy', 'shipping' => 'shipping-policy'] as $k => $key) {
            foreach ($this->all('link-' . $k) as $a) $a->setAttribute('href', 'legal.php?page=' . $key);
        }
        foreach ($this->all('link-home') as $a) $a->setAttribute('href', with_query('index.php'));
    }

    /* ================================================================ product page */

    /**
     * templates/<name>/product.html. $ctx = product.php's context (answers, products = sellable_products(), chosen_id),
     * $page = product.json (plan texts), $boot = the page data for product.js.
     */
    public static function product(string $name, array $ctx, array $page, array $boot): void
    {
        $t = new self($name, 'product');
        $t->fillSite($ctx['answers'] ?? []);
        $root  = $t->doc->documentElement;
        $pl    = [];
        foreach ($page['sections'] as $s) {
            if (($s['component'] ?? '') === 'product/plans') $pl = $s['plan'] ?? [];
        }
        $bonus = site('bonus');
        $products = $ctx['products'];

        // Hero treatment picker (optional): one .product-picker-item per product.
        $t->repeat($root, 'product-picker-item', $products, function (DOMElement $el, array $item) use ($t) {
            $look = $item['look'];
            $el->setAttribute('data-product', $look['keyword']);
            $t->set($el, 'product-picker-name', $look['title']);
            $t->set($el, 'product-picker-image', asset_url($look['image']), true);
            $t->set($el, 'product-picker-tagline', $look['tagline']);
            $t->set($el, 'product-picker-price', money(lowest_monthly($item['variants']), false));
            $t->when($el, 'if-badge', $look['badge'] !== '');
            $t->set($el, 'product-picker-badge', $look['badge']);
        });

        $t->when($root, 'if-no-products', !$products);
        $t->when($root, 'if-products', (bool) $products);

        // One .product-card per product.
        $t->repeat($root, 'product-card', $products, function (DOMElement $card, array $item) use ($t, $ctx, $pl, $bonus) {
            $p = $item['product']; $look = $item['look']; $variants = $item['variants'];
            $card->setAttribute('data-product', $look['keyword']);
            $card->setAttribute('data-name', $look['title']);
            $card->setAttribute('data-image', asset_url($look['image']));
            $card->setAttribute('data-product-id', (string) $p['id']);
            if (!$card->hasAttribute('id')) $card->setAttribute('id', 'product-' . $look['keyword']);
            if (!empty($look['featured'])) $t->addClass($card, 'is-featured-product');

            $monthly = null;
            foreach ($variants as $v) { if (plan_months($v) === 1) { $monthly = $v; break; } }
            $featured = featured_variant($variants);

            $t->set($card, 'product-image', asset_url($look['image']), true);
            $t->set($card, 'product-name', $look['title']);
            $t->set($card, 'product-content', $look['tagline']);
            $t->set($card, 'product-tagline', $look['tagline']);
            $t->set($card, 'product-form', (string) ($variants[0]['form'] ?? ''));
            $t->set($card, 'product-badge', $look['badge']);
            $t->when($card, 'if-badge', $look['badge'] !== '');
            $t->set($card, 'product-rating', (string) site('brand.rating', ''));
            $t->set($card, 'product-price-from', money(lowest_monthly($variants), false));
            $hasBonus = $bonus && $look['has_bonus'];
            $t->when($card, 'if-bonus', (bool) $hasBonus);
            if ($hasBonus) {
                $t->set($card, 'product-bonus-name', (string) $bonus['name']);
                $t->set($card, 'product-bonus-value', money($bonus['value'], false));
                $t->set($card, 'product-bonus-image', asset_url((string) $bonus['image']), true);
            }

            // Plans: <… class="product-options" data-order="longest-first"> shows the longest plan first.
            $optionsEl = $t->all('product-options', $card)[0] ?? $card;
            $list = $variants;
            if ($optionsEl instanceof DOMElement && $optionsEl->getAttribute('data-order') === 'longest-first') $list = array_reverse($list);

            $t->repeat($card, 'product-option', $list, function (DOMElement $opt, array $v) use ($t, $p, $ctx, $pl, $monthly, $featured) {
                $months   = plan_months($v);
                $price    = (float) $v['offer']['price'];
                $perMonth = $price / $months;
                $perDay   = round($price / (30 * $months), 2);
                $save     = $monthly && $months > 1 ? max(0, (float) $monthly['offer']['price'] * $months - $price) : 0.0;
                $savePct  = $monthly && $months > 1 ? (int) round((1 - $perMonth / (float) $monthly['offer']['price']) * 100) : 0;
                $isBest   = $featured && $featured['id'] === $v['id'];
                $label    = $months === 1 ? (string) ($pl['monthly_name'] ?? 'Monthly Plan') : fill((string) ($pl['kicker'] ?? '{months} Month Plan'), ['months' => $months]);

                // The radio product.js reads — created when the designer's option has none (e.g. a card with only buttons).
                $input = $t->all('product-option-input', $opt)[0] ?? null;
                if (!$input) {
                    $input = $t->doc->createElement('input');
                    $input->setAttribute('class', 'product-option-input');
                    $input->setAttribute('hidden', 'hidden');
                    $opt->insertBefore($input, $opt->firstChild);
                }
                $input->setAttribute('type', 'radio');
                $input->setAttribute('name', 'plan-' . $p['id']);
                $input->setAttribute('value', (string) $v['id']);
                $input->setAttribute('data-price', (string) $price);
                $input->setAttribute('data-months', (string) $months);
                $input->setAttribute('data-label', $label);
                $input->setAttribute('data-per-day', (string) $perDay);
                if ($ctx['chosen_id'] === $v['id']) $input->setAttribute('checked', 'checked'); else $input->removeAttribute('checked');

                $opt->setAttribute('data-months', (string) $months);
                if ($isBest) $t->addClass($opt, 'is-featured');
                $t->set($opt, 'product-option-name', $label);
                $t->set($opt, 'product-option-months', (string) $months);
                $t->set($opt, 'product-option-price', money($price, false));
                $t->set($opt, 'product-option-price-month', money(round($perMonth, 2), false));
                $t->set($opt, 'product-option-per-day', money($perDay));
                $t->set($opt, 'product-option-save', money($save, false));
                $t->set($opt, 'product-option-save-percent', $savePct . '%');
                $t->when($opt, 'if-featured', (bool) $isBest);
                $t->when($opt, 'if-not-featured', !$isBest);
                $t->when($opt, 'if-save', $save > 0);
                $t->when($opt, 'if-monthly', $months === 1);
                $t->when($opt, 'if-multi-month', $months > 1);
                $t->when($opt, 'if-free-month', (bool) preg_match('/free/i', (string) $v['name']));
                // if-months-12, if-months-4 …: kept only on the plan with that many months (e.g. bullets per plan length)
                foreach ($t->xp->query(".//*[contains(concat(' ', normalize-space(@class)), ' if-months-')]", $opt) ?: [] as $el) {
                    if (preg_match('/(?:^|\s)if-months-(\d+)(?:\s|$)/', $el->getAttribute('class'), $m) && (int) $m[1] !== $months && $el->parentNode) {
                        $el->parentNode->removeChild($el);
                    }
                }
            });

            // Card-level price summary and messages (filled / shown by product.js).
            foreach ($t->all('product-pricing', $card) as $el) $el->setAttribute('hidden', 'hidden');
            foreach ($t->all('product-error', $card) as $el) { $el->setAttribute('hidden', 'hidden'); $el->setAttribute('role', 'alert'); }
            foreach ($t->all('product-cta', $card) as $el) {
                if ($el->nodeName === 'button' && !$el->hasAttribute('type')) $el->setAttribute('type', 'button');
            }

            // Included list (site.json → included): one .included-item per row.
            $t->repeat($card, 'included-item', site('included', []), function (DOMElement $row, array $inc) use ($t) {
                $t->set($row, 'included-text', (string) ($inc['text'] ?? ''));
                $t->set($row, 'included-value', money((float) ($inc['value'] ?? 0), false));
                if (!empty($inc['icon'])) $t->set($row, 'included-icon', asset_url((string) $inc['icon']), true);
            });
        });

        foreach ($t->all('buy-bar') as $el) $el->setAttribute('hidden', 'hidden');
        $t->output($boot, ['assets/product.js']);
    }

    /* ================================================================ checkout page */

    /**
     * templates/<name>/checkout.html. $ctx = checkout.php's context (answers, order, look, per_day, states, product_url),
     * $boot = the page data for checkout.js. The form is found by field NAME (shipping_first_name … card_cvv).
     */
    public static function checkout(string $name, array $ctx, array $boot): void
    {
        $t = new self($name, 'checkout');
        $a = $ctx['answers'] ?? [];
        $t->fillSite($a);
        $root  = $t->doc->documentElement;
        $order = $ctx['order'];
        $look  = $ctx['look'];
        $bonus = site('bonus');

        // ---- form: prefilled from the quiz, states from the funnel, every .field gets a .field-error
        foreach ($t->all('checkout-form') as $form) {
            if ($form->nodeName === 'form') $form->setAttribute('novalidate', 'novalidate');
        }
        $prefill = [
            'shipping_first_name' => $a['first_name'] ?? '', 'shipping_last_name' => $a['last_name'] ?? '',
            'shipping_address1' => $a['address_line1'] ?? '', 'shipping_address2' => $a['address_line2'] ?? '',
            'shipping_city' => $a['city'] ?? '', 'shipping_zip' => $a['zip'] ?? '',
            'shipping_phone' => $a['phone'] ?? '', 'shipping_email' => $a['email'] ?? '',
        ];
        foreach ($t->xp->query('//input[@name] | //select[@name] | //textarea[@name]') ?: [] as $el) {
            $fieldName = $el->getAttribute('name');
            if (!$el->hasAttribute('id')) $el->setAttribute('id', $fieldName);
            if ($el->nodeName === 'input' && isset($prefill[$fieldName]) && (string) $prefill[$fieldName] !== '') {
                $el->setAttribute('value', (string) $prefill[$fieldName]);
            }
            if ($el->nodeName === 'select' && preg_match('/^(shipping|billing)_state$/', $fieldName)) {
                $t->fillStates($el, $ctx['states'] ?? [], $fieldName === 'shipping_state' ? (string) ($a['shipping_state'] ?? '') : '');
            }
        }
        foreach ($t->all('field') as $wrap) {
            if (!$t->all('field-error', $wrap)) {
                $p = $t->doc->createElement('p');
                $p->setAttribute('class', 'field-error');
                $wrap->appendChild($p);
            }
        }
        foreach ($t->all('field-error') as $el) $el->setAttribute('hidden', 'hidden');
        foreach ($t->all('checkout-error') as $el) { $el->setAttribute('hidden', 'hidden'); $el->setAttribute('role', 'alert'); }
        foreach ($t->all('billing-fields') as $el) $el->setAttribute('hidden', 'hidden');
        foreach ($t->all('checkout-cta') as $el) {
            if ($el->nodeName === 'button') $el->setAttribute('type', 'submit');
        }
        foreach ($t->all('link-product') as $el) $el->setAttribute('href', with_query((string) ($ctx['product_url'] ?? 'product.php')));

        // ---- order summary (the plan chosen on the product page)
        $pl = [];
        foreach (Page::load('product')['sections'] as $s) {
            if (($s['component'] ?? '') === 'product/plans') $pl = $s['plan'] ?? [];
        }
        $months = max(1, (int) $order['months']);
        $price  = (float) $order['price'];
        $plan   = $months === 1 ? (string) ($pl['monthly_name'] ?? 'Monthly Plan') : fill((string) ($pl['kicker'] ?? '{months} Month Plan'), ['months' => $months]);
        $t->set($root, 'order-image', asset_url((string) $look['image']), true);
        $t->set($root, 'order-name', (string) $look['title']);
        $t->set($root, 'order-tagline', (string) $look['tagline']);
        $t->set($root, 'order-badge', (string) $look['badge']);
        $t->when($root, 'if-badge', $look['badge'] !== '');
        $t->set($root, 'order-plan', $plan);
        $t->set($root, 'order-months', (string) $months);
        $t->set($root, 'order-price', money($price, false));
        $t->set($root, 'order-total', money($price, false));
        $t->set($root, 'order-price-month', money(round($price / $months, 2), false));
        $t->set($root, 'order-per-day', money((float) $ctx['per_day']));
        $t->when($root, 'if-monthly', $months === 1);
        $t->when($root, 'if-multi-month', $months > 1);
        $hasBonus = $bonus && !empty($look['has_bonus']);
        $t->when($root, 'if-bonus', (bool) $hasBonus);
        if ($hasBonus) {
            $t->set($root, 'order-bonus-name', (string) $bonus['name']);
            $t->set($root, 'order-bonus-value', money($bonus['value'], false));
            $t->set($root, 'order-bonus-image', asset_url((string) $bonus['image']), true);
        }
        $t->repeat($root, 'included-item', site('included', []), function (DOMElement $row, array $inc) use ($t) {
            $t->set($row, 'included-text', (string) ($inc['text'] ?? ''));
            $t->set($row, 'included-value', money((float) ($inc['value'] ?? 0), false));
            if (!empty($inc['icon'])) $t->set($row, 'included-icon', asset_url((string) $inc['icon']), true);
        });

        $t->output($boot, ['assets/checkout.js']);
    }

    /** A state <select>: the designer's first option stays as the placeholder, the rest = the states the funnel ships to. */
    private function fillStates(DOMElement $select, array $states, string $selected): void
    {
        $first = null;
        foreach (iterator_to_array($select->getElementsByTagName('option')) as $i => $opt) {
            if ($i === 0 && $opt->getAttribute('value') === '') { $first = $opt; continue; }
            $opt->parentNode->removeChild($opt);
        }
        if (!$first) {
            $first = $this->doc->createElement('option');
            $first->setAttribute('value', '');
            $this->text($first, 'Select');
            $select->appendChild($first);
        }
        foreach ($states as $st) {
            $o = $this->doc->createElement('option');
            $o->setAttribute('value', (string) $st);
            $this->text($o, (string) $st);
            if ((string) $st === $selected) $o->setAttribute('selected', 'selected');
            $select->appendChild($o);
        }
    }
}

/** Asset URL for DOM attributes (not HTML-escaped — the DOM escapes it), with the cache-busting version. */
function asset_url(string $path): string
{
    if ($path === '' || preg_match('~^([a-z]+:|//)~i', $path)) return $path;
    $file = dirname(__DIR__) . '/' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}
