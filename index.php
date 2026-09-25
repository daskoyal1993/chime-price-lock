<?php
/**
 * Quiz: one screen at a time, built from the funnel API (see assets/funnel.js).
 * Screen grouping and on-screen text: projects/<project>/index.json.
 */
require __DIR__ . '/bootstrap.php';

$funnel = quiz_funnel(load_funnel_or_fail($config));
$page   = Page::load('index');

// The browser gets the questions without the server-side GHL field mapping.
$public = $funnel;
unset($public['ghl'], $public['vouched']);   // GHL / Vouched credentials stay on the server
foreach ($public['steps'] as &$step) {
    foreach ($step['questions'] as &$q) {
        unset($q['ghl']);
    }
}
unset($step, $q);

$boot = [
    'funnel'  => $public,
    'screens' => Screens::build($funnel, $page['screens'] ?? []),
    'config'  => [
        'submitUrl'       => 'submit.php',
        'csrf'            => $_SESSION['funnel_csrf'],
        'minAge'          => (int) $config['min_age'],
        'weightMin'       => (int) $config['weight_min'],
        'weightMax'       => (int) $config['weight_max'],
        'disqualifiedUrl' => (string) $config['disqualified_url'],
        'doctorImage'     => (string) ($page['doctor_image'] ?? ''),
        'autoContinue'    => array_values($page['auto_continue_options'] ?? []),
        'autoPatterns'    => array_values($page['auto_continue_patterns'] ?? []),
        'questions'       => (object) ($page['questions'] ?? []),
        'text'            => $page['text'],
    ],
];

page_open($page, $funnel['form']['name'] ?: $page['title']);
?>
<div class="quiz-progress" id="funnel-progress" role="progressbar" aria-label="Form progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
    <span></span>
</div>
<main class="quiz-main" id="funnel" aria-live="polite">
    <noscript><div class="alert-box"><?= e($page['text']['noscript'] ?? '') ?></div></noscript>
</main>
<script id="funnel-data" type="application/json"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php
page_close($page, [], ['assets/funnel.js']);
