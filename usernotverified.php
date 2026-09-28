<?php
/**
 * ID check not verified (like demo/usernotverified.php). idcheck.php sends the visitor here when the Vouched ID check fails
 * (submit.php?action=idcheck); the GHL contact is tagged failed_id there (LeadFlow::idFailed()).
 * Header (logo + rating) and one paragraph — text: projects/<project>/not-verified.json.
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);

if (empty($record['order'])) {
    redirect_to('index.php');
}
if (!empty($record['identity_verified'])) {
    redirect_to($config['verified_url']);   // verified after all (e.g. Vouched's callback)
}

$page = Page::load('not-verified');
$text = $page['text'];
$vars = ['phone' => site('brand.phone', ''), 'email' => site('brand.email', ''), 'order_id' => (string) ($record['order']['order_id'] ?? '')];

page_open($page);
?>
<main class="<?= e($page['main_class']) ?>">
    <p class="nv-text"><?= e(fill($text['text'] ?? '', $vars)) ?></p>
</main>
<?php
page_close($page);
