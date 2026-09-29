<?php
/**
 * Product page. Layout, text and images: projects/<project>/product.json (sections → components/),
 * or a designer's HTML template filled by lib/Template.php (templates/<name>/product.html, site.json → "template").
 * Product cards + plan rows come from the API; the hero uses the visitor's answers.
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);
if (empty($record['answers']) || !empty($record['disqualified'])) {
    redirect_to('index.php');
}

FlowTracker::hit($config, $funnel['slug'], 'product', 'product', FlowTracker::PAGE_STEPS['product']);

$page = Page::load('product');
$ctx  = [
    'answers'   => $record['answers'],
    'products'  => sellable_products(offer_funnel($funnel, $record, $config)),   // member → campaign_set, else membership_campaign_set
    'chosen_id' => $record['product']['variant_id'] ?? null,
];
$boot = ['submitUrl' => 'submit.php', 'csrf' => $_SESSION['funnel_csrf'], 'text' => $page['text']];

// A designer's HTML template (templates/<name>/product.html, site.json → "template") — else the normal page.
if ($template = Template::active($config, 'product')) {
    Template::product($template, $ctx, $page, $boot);
    exit;
}
render_page($page, $ctx, ['assets/product.js'], $boot);
