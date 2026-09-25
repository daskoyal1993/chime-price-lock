<?php
/**
 * Order hand-off to the patient portal (like demo/process/process.php), after the card is authorized and the
 * identity is verified (submit.php?action=checkout / idcheck.php → config.php → verified_url).
 * LeadFlow::process() posts the order to the portal's offer/process API; on success the visitor is redirected
 * to the portal's redirect_url and the link is saved on the GHL contact. On failure: an error page
 * (text: projects/<project>/process.json). A refresh after success goes straight to the portal again.
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);

if (empty($record['order'])) {
    redirect_to('index.php');
}
if (empty($record['identity_verified'])) {
    redirect_to($config['idcheck_url']);
}
if (!empty($record['portal_redirect'])) {
    header('Location: ' . $record['portal_redirect'], true, 302);   // already processed: duplicates are rejected by the portal
    exit;
}

$result = LeadFlow::process($config, $funnel, $record);

if ($result['success']) {
    $record['portal_redirect'] = $result['redirect_url'];
    $snapshot = $record;
    redirect_now_then($result['redirect_url'], static function () use ($config, $funnel, $snapshot): void {
        LeadFlow::portalLink($config, $funnel, $snapshot, $snapshot['portal_redirect']);
    });
}

$page = Page::load('process');
$text = $page['text'];
page_open($page);
?>
<main class="<?= e($page['main_class']) ?>">
    <section class="ty-card">
        <h1 class="quiz-title"><?= e($text['error_heading'] ?? '') ?></h1>
        <p class="quiz-helper"><?= e($result['message']) ?></p>
        <p class="quiz-helper"><?= e(fill($text['error_order'] ?? '', ['order_id' => (string) ($record['order']['order_id'] ?? '')])) ?></p>
        <a class="btn-complete" href="<?= e(with_query('process.php')) ?>"><?= e($text['retry'] ?? 'Try again') ?></a>
    </section>
</main>
<?php
page_close($page);
