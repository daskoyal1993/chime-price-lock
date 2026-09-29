<?php
/**
 * QA error log viewer (browser): qa-errors.php?key=<FUNNEL_QA_KEY>[&test=TEST-20260929-001][&errors=1]
 * Same data as `php tools/qa-errors.php` (storage/logs/qa-errors.log, lib/ErrorLogger.php).
 * Access: needs FUNNEL_QA_KEY (without a key only on FUNNEL_ENV=local); off on live unless FUNNEL_QA_VIEWER_ON_LIVE=1.
 */
require __DIR__ . '/bootstrap.php';

$qa   = $config['qa_errors'] ?? [];
$env  = (string) ($qa['env'] ?? 'local');
$key  = (string) ($_GET['key'] ?? '');
$open = ErrorLogger::enabled()
    && ($env !== 'live' || !empty($qa['viewer_on_live']))
    && ErrorLogger::keyOk($key, $env !== 'local');
if (!$open) {
    http_response_code(404);
    exit;
}
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$test       = preg_match('/^(TEST-\d{8}-\d{3}|no-test|today|all)$/', (string) ($_GET['test'] ?? '')) ? (string) $_GET['test'] : '';
$errorsOnly = !empty($_GET['errors']);
$runs       = ErrorLogger::runs(ErrorLogger::entries($config), $test, $errorsOnly);
$link       = static function (array $q) use ($key): string {
    return 'qa-errors.php?' . http_build_query(array_filter(['key' => $key] + $q, 'strlen'));
};
$colors = ['ERROR' => '#b91c1c', 'WARNING' => '#b45309', 'NOTICE' => '#475569', 'INFO' => '#2563eb'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>QA errors<?= $test ? ' · ' . e($test) : '' ?></title>
<style>
    body { margin: 0; padding: 24px 16px 48px; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111827; background: #f8fafc; }
    .wrap { max-width: 1200px; margin: 0 auto; }
    h1 { margin: 0 0 4px; font-size: 22px; } .sub { margin: 0 0 20px; color: #64748b; }
    a { color: #2563eb; } .bar a { margin-right: 14px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; margin: 8px 0 24px; }
    th, td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; font-size: 12px; text-transform: uppercase; color: #475569; }
    .lvl { font-weight: 700; font-size: 12px; } .file { color: #64748b; font-size: 12px; } .none { color: #15803d; font-weight: 600; }
    details summary { cursor: pointer; color: #475569; font-size: 12px; } pre { white-space: pre-wrap; word-break: break-word; font-size: 12px; background: #f8fafc; padding: 8px; margin: 6px 0 0; }
    h2 { margin: 24px 0 0; font-size: 16px; } .muted { color: #64748b; font-weight: 400; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>QA error log</h1>
    <p class="sub">Environment: <strong><?= e($env) ?></strong> · times in UTC · storage/logs/qa-errors.log</p>
    <p class="bar">
        <a href="<?= e($link([])) ?>">All runs</a>
        <a href="<?= e($link(['test' => 'today'])) ?>">Today</a>
        <a href="<?= e($link(['test' => $test, 'errors' => $errorsOnly ? '' : '1'])) ?>"><?= $errorsOnly ? 'Show warnings too' : 'Errors only' ?></a>
    </p>

<?php if (!$runs): ?>
    <p>No entries<?= $test ? ' for ' . e($test) : ' yet' ?>.</p>
<?php elseif ($test === ''): ?>
    <table>
        <tr><th>Test</th><th>Started</th><th>Ended</th><th>Errors</th><th>Warnings</th><th>Other</th></tr>
        <?php foreach (array_slice($runs, 0, 50, true) as $id => $r): ?>
            <tr>
                <td><a href="<?= e($link(['test' => $id])) ?>"><?= e($id) ?></a></td>
                <td><?= e($r['started']) ?></td>
                <td><?= e($r['ended'] ?: ($id === 'no-test' ? '–' : 'open')) ?></td>
                <td style="color:<?= $r['counts']['ERROR'] ? '#b91c1c' : '#15803d' ?>;font-weight:700"><?= (int) $r['counts']['ERROR'] ?></td>
                <td><?= (int) $r['counts']['WARNING'] ?></td>
                <td><?= (int) ($r['counts']['NOTICE'] + $r['counts']['INFO']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <?php foreach ($runs as $id => $r): ?>
        <h2><?= e($id) ?> <span class="muted">started <?= e($r['started']) ?><?= $r['ended'] ? ' · ended ' . e($r['ended']) : '' ?> · <?= (int) $r['counts']['ERROR'] ?> errors, <?= (int) $r['counts']['WARNING'] ?> warnings</span></h2>
        <?php if (!$r['entries']): ?>
            <p class="none">→ No errors found</p>
        <?php else: ?>
            <table>
                <tr><th>Time</th><th>Level</th><th>Type</th><th>Service · page</th><th>HTTP</th><th>Message</th></tr>
                <?php foreach ($r['entries'] as $x): ?>
                    <tr>
                        <td><?= e(substr((string) $x['time'], 11)) ?></td>
                        <td class="lvl" style="color:<?= $colors[$x['level']] ?? '#111827' ?>"><?= e($x['level']) ?></td>
                        <td><?= e($x['type']) ?></td>
                        <td><?= e(trim(($x['service'] ?? '') . ' · ' . ($x['page'] ?? ''), ' ·')) ?></td>
                        <td><?= e((string) ($x['http_status'] ?? '')) ?></td>
                        <td>
                            <?= e($x['message']) ?>
                            <?php if (!empty($x['file'])): ?><div class="file"><?= e($x['file'] . ':' . $x['line']) ?></div><?php endif; ?>
                            <?php if (!empty($x['context'])): ?>
                                <details><summary>details</summary><pre><?= e(json_encode($x['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
</div>
</body>
</html>
