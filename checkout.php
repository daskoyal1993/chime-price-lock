<?php
/**
 * Checkout. Layout, text and images: projects/<project>/checkout.json (sections → components/).
 * Order summary comes from the plan chosen on product.php (session).
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);
if (empty($record['answers']) || !empty($record['disqualified'])) {
    redirect_to('index.php');
}
if (empty($record['product'])) {
    redirect_to($config['product_url']);
}

FlowTracker::hit($config, $funnel['slug'], 'checkout', 'checkout', FlowTracker::PAGE_STEPS['checkout']);

$order   = $record['product'];
$product = null;
foreach (offer_funnel($funnel, $record, $config)['products'] as $p) {
    if ($p['id'] === $order['product_id']) {
        $product = $p;
    }
}
// "As low as $x/day": the price charged for the chosen plan over 30 days per month — 1M ÷ 30, 2M ÷ 60, 3M ÷ 90, 6M ÷ 180
// (e.g. monthly $299 → $9.97/day). Not the funnel's display_price, which is a different ("was") price.
$days = 30 * max(1, (int) $order['months']);
$shownPrice = (float) $order['price'];

$states = [];
foreach ($funnel['steps'] as $step) {
    foreach ($step['questions'] as $q) {
        if ($q['key'] === 'shipping_state') $states = array_column($q['options'], 'value');
    }
}

$page = Page::load('checkout');
$ctx  = [
    'answers'     => $record['answers'],
    'order'       => $order,
    'look'        => product_look($product ?: ['id' => $order['product_id'], 'name' => $order['product_name']]),
    'per_day'     => $shownPrice / $days,
    'states'      => $states,
    'product_url' => $config['product_url'],
];
$boot = [
    'submitUrl'  => 'submit.php',
    'csrf'       => $_SESSION['funnel_csrf'],
    'prospectId' => (string) ($_GET['prospect_id'] ?? $record['vrio_prospect_id'] ?? ''),
    // Test driver only: the test card skips the card-number checksum in the browser too (empty otherwise)
    'testCard'   => Payment::testCard($config),
    // Google Places autocomplete (browser key — see config.php → google_maps)
    'maps'       => ['key' => (string) ($config['google_maps']['api_key'] ?? ''), 'country' => (string) ($config['google_maps']['country'] ?? 'us')],
    'text'       => $page['text'],
];

render_page($page, $ctx, ['assets/checkout.js'], $boot);
