<?php
/**
 * Shared view helpers: escaping, assets, money, icons and product presentation.
 * Page layout lives in lib/Page.php and components/; text and images come from projects/<project>/*.json.
 */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Asset URL with a cache-busting version from the file's modification time. */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . $path;
    return e($path . (is_file($file) ? '?v=' . filemtime($file) : ''));
}

function money($amount, bool $cents = true): string
{
    $amount = (float) $amount;
    if (!$cents && floor($amount) == $amount) {
        return '$' . number_format($amount, 0);
    }
    return '$' . number_format($amount, 2);
}

/** "$187" with a superscript dollar sign, as on the live plan rows. */
function money_big($amount): string
{
    $amount = (float) $amount;
    $whole  = floor($amount) == $amount ? number_format($amount, 0) : number_format($amount, 2);
    return '<sup>$</sup>' . e($whole);
}

/** Keep the visitor's query string (e.g. prospect_id) on internal links. */
function with_query(string $url): string
{
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if ($query === '') {
        return $url;
    }
    return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
}

function redirect_to(string $url): void
{
    header('Location: ' . with_query($url), true, 302);
    exit;
}

function icon(string $name, string $class = ''): string
{
    $icons = [
        'arrow-left'   => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'arrow-right'  => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'lock'         => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'check'        => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'info'         => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'alert'        => '<path d="M12 3 22 20H2L12 3Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'plus'         => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'gift'         => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'shield'       => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'mail'         => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'star'         => '<path d="M11.5 2.3a.5.5 0 0 1 .9 0l2.3 4.7a2 2 0 0 0 1.5 1.1l5.2.8a.5.5 0 0 1 .3.9l-3.8 3.7a2 2 0 0 0-.6 1.8l.9 5.2a.5.5 0 0 1-.8.6l-4.6-2.5a2 2 0 0 0-1.9 0L4.3 21a.5.5 0 0 1-.8-.6l.9-5.2a2 2 0 0 0-.6-1.8L.1 9.7a.5.5 0 0 1 .3-.9l5.2-.8A2 2 0 0 0 7 7Z"/>',
    ];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}

function select_arrow(): string
{
    return '<svg class="select-arrow" width="12" height="7" viewBox="0 0 12 7" aria-hidden="true"><path fill="currentColor" d="M.24.24a.83.83 0 0 1 1.18 0l4.41 4.41L10.24.24a.83.83 0 1 1 1.18 1.18l-5 5a.83.83 0 0 1-1.18 0l-5-5a.83.83 0 0 1 0-1.18Z"/></svg>';
}

/** Trustpilot-style green star boxes; $rating fills the last box partially. */
function trustpilot_stars(float $rating = 4.7, int $size = 12): string
{
    static $n = 0;
    $html = '<span class="tp-stars" role="img" aria-label="Rated ' . e($rating) . ' out of 5">';
    for ($i = 0; $i < 5; $i++) {
        $fill = max(0, min(1, $rating - $i)) * 100;
        $id   = 'tpg' . (++$n);
        $html .= '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 20 20" aria-hidden="true">'
            . '<defs><linearGradient id="' . $id . '"><stop offset="' . $fill . '%" stop-color="#00B67A"/><stop offset="' . $fill . '%" stop-color="#DDDDE7"/></linearGradient></defs>'
            . '<path d="M20 0H0V20H20Z" fill="url(#' . $id . ')"/>'
            . '<path d="M13 12.3 10 13.1l4.3 3.1Z" fill="#fff"/><path d="M11.6 8H17l-4.3 3.1L10 13.1l-4.3 3.1 1.6-5L3 8h5.4L10 3Z" fill="#fff"/></svg>';
    }
    return $html . '</span>';
}

/* ---------------------------------------------------------------- products */

/**
 * Presentation for an API product. The name always comes from the funnel JSON (product "name");
 * site.json → products (matched by keyword in that name) adds image, tagline, badge and featured.
 * has_bonus: the product name mentions the free add-on (site.json → bonus.match, e.g. "NAD"), so its image is shown.
 */
function product_look(array $product): array
{
    $name  = trim((string) $product['name']);
    $lower = strtolower($name);
    $look  = null;
    foreach (site('products', []) as $keyword => $settings) {
        if (strpos($lower, strtolower($keyword)) !== false) {
            $look = $settings + ['keyword' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($keyword)), '-')];   // used in HTML ids
            break;
        }
    }
    if ($look === null) {
        $look = ['keyword' => 'product-' . $product['id'], 'image' => site('product_fallback_image', ''), 'tagline' => '', 'badge' => '', 'featured' => false];
    }

    $bonus = site('bonus');
    $match = $bonus ? (string) ($bonus['match'] ?? preg_replace('/[^A-Za-z0-9]/', '', (string) $bonus['name'])) : '';

    return array_merge(['tagline' => '', 'badge' => '', 'featured' => false], $look, [
        'title'     => $name,
        'combo'     => $name,
        'short'     => $name,
        'has_bonus' => $match !== '' && stripos($name, $match) !== false,
    ]);
}

function plan_months(array $variant): int
{
    return $variant['typical_duration'] ? max(1, (int) round($variant['typical_duration'] / 28)) : 1;
}

/** Sellable variants (CRM offer + price), shortest plan first. */
function sellable_variants(array $product): array
{
    $list = array_values(array_filter($product['variants'], static function ($v) {
        return $v['available'];
    }));
    usort($list, static function ($a, $b) {
        return plan_months($a) <=> plan_months($b);
    });
    return $list;
}

/** The variant to highlight: a "free month" plan if sellable, otherwise the lowest monthly price. */
function featured_variant(array $variants): ?array
{
    $best = null;
    foreach ($variants as $v) {
        if (plan_months($v) < 2) continue;
        if (preg_match('/free/i', $v['name'])) return $v;
        if ($best === null || $v['offer']['price'] / plan_months($v) < $best['offer']['price'] / plan_months($best)) {
            $best = $v;
        }
    }
    return $best;
}

function lowest_monthly(array $variants): ?float
{
    $min = null;
    foreach ($variants as $v) {
        $m = $v['offer']['price'] / plan_months($v);
        $min = $min === null ? $m : min($min, $m);
    }
    return $min;
}

/** Products that have at least one sellable variant, featured ones first. */
function sellable_products(array $funnel): array
{
    $list = [];
    foreach ($funnel['products'] as $p) {
        $variants = sellable_variants($p);
        if ($variants) {
            $list[] = ['product' => $p, 'look' => product_look($p), 'variants' => $variants];
        }
    }
    return $list;
}
