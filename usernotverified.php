<?php
/**
 * ID check not verified (like demo/usernotverified.php). idcheck.php sends the visitor here when the Vouched ID check fails
 * (submit.php?action=idcheck); the GHL contact is tagged failed_id there (LeadFlow::idFailed()).
 * Text: projects/<project>/not-verified.json ({phone}, {email} from site.json → brand).
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
$vars = ['phone' => site('brand.phone', ''), 'tel' => preg_replace('/[^\d+]/', '', (string) site('brand.phone', '')), 'email' => site('brand.email', ''), 'order_id' => (string) ($record['order']['order_id'] ?? '')];

page_open($page);
?>
<main class="<?= e($page['main_class']) ?>">
    <section class="ty-card">
        <span class="quiz-badge quiz-badge-warn"><?= e($text['badge'] ?? '') ?></span>
        <h1 class="quiz-title"><?= e($text['heading'] ?? '') ?></h1>
        <p class="quiz-helper"><?= e($text['text'] ?? '') ?></p>
        <?php if (!empty($text['contact_html'])): ?>
            <p class="quiz-helper"><?= fill($text['contact_html'], $vars, true) ?></p>
        <?php endif; ?>
        <?php if ($vars['order_id'] !== '' && !empty($text['order'])): ?>
            <p class="quiz-helper"><?= e(fill($text['order'], $vars)) ?></p>
        <?php endif; ?>
    </section>
</main>
<?php
page_close($page);
