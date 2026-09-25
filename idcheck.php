<?php
/**
 * ID check with the Vouched plugin (like demo/idcheck.php), for visitors the automatic Vouched check
 * (crosscheck ≥ 80 + DOB match, submit.php?action=checkout) didn't verify.
 * Text: projects/<project>/idcheck.json. Result → submit.php?action=idcheck → config.php → verified_url.
 * Vouched also reports the result to idcheck-callback.php.
 */
require __DIR__ . '/bootstrap.php';

$funnel = load_funnel_or_fail($config);
$record =& funnel_session($funnel);

if (empty($record['order'])) {
    redirect_to('index.php');
}
if (!empty($record['identity_verified'])) {
    redirect_to($config['verified_url']);
}

$vouched = array_merge($config['vouched'] ?? [], $funnel['vouched'] ?? []);
$page    = Page::load('idcheck');
$text    = $page['text'];
$a       = $record['answers'] ?? [];

// Vouched's callback must be a full URL on this site.
$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$dob = DateTime::createFromFormat('!Y-m-d', (string) ($a['dob'] ?? ''));

$boot = [
    'submitUrl'   => 'submit.php',
    'csrf'        => $_SESSION['funnel_csrf'],
    'appId'       => (string) ($vouched['app_id'] ?? ''),
    'callbackUrl' => $base . '/idcheck-callback.php',
    'person'      => [
        'firstName' => (string) ($a['first_name'] ?? ''),
        'lastName'  => (string) ($a['last_name'] ?? ''),
        'email'     => (string) ($record['order']['email'] ?? $a['email'] ?? ''),
        'phone'     => (string) ($a['phone'] ?? ''),
        'birthDate' => $dob ? $dob->format('m/d/Y') : '',
    ],
    'text' => $text,
];

page_open($page);
?>
<main class="<?= e($page['main_class']) ?>">
    <section class="ty-card">
        <span class="quiz-badge"><?= e($text['badge'] ?? '') ?></span>
        <h1 class="quiz-title"><?= e($text['heading'] ?? '') ?></h1>
        <p class="quiz-helper"><?= e($text['intro'] ?? '') ?></p>
        <div class="alert-box" id="idcheck-failed" role="alert" hidden>
            <strong><?= e($text['failed_heading'] ?? '') ?></strong>
            <p><?= e($text['failed_text'] ?? '') ?></p>
            <button type="button" class="btn-complete" id="idcheck-retry"><?= e($text['retry'] ?? 'Try again') ?></button>
        </div>
        <div id="vouched-element" style="min-height: 560px"></div>
    </section>
</main>
<script src="https://static.vouched.id/plugin/releases/latest/index.js"></script>
<?php
page_close($page, [], ['assets/idcheck.js'], $boot);
