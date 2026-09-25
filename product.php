<?php
/**
 * Product page. Layout, text and images: projects/<project>/product.json (sections → components/).
 * Product cards + plan rows come from the API; the hero uses the visitor's answers.
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);
if (empty($record['answers']) || !empty($record['disqualified'])) {
    redirect_to('index.php');
}

$page = Page::load('product');
$ctx  = [
    'answers'   => $record['answers'],
    'products'  => sellable_products(offer_funnel($funnel, $record, $config)),   // member → campaign_set, else membership_campaign_set
    'chosen_id' => $record['product']['variant_id'] ?? null,
];
$boot = ['submitUrl' => 'submit.php', 'csrf' => $_SESSION['funnel_csrf'], 'text' => $page['text']];

render_page($page, $ctx, ['assets/product.js'], $boot);
