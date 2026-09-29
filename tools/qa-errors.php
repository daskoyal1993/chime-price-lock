<?php
/**
 * QA error log viewer (command line). Reads storage/logs/qa-errors.log (lib/ErrorLogger.php).
 *
 *   php tools/qa-errors.php                         the last 20 QA runs with their error counts
 *   php tools/qa-errors.php TEST-20260929-001       every entry of that run
 *   php tools/qa-errors.php today                   today's runs with their entries
 *   php tools/qa-errors.php all                     everything
 *   … --errors-only                                 leave out WARNING / NOTICE / INFO
 *   … --details                                     also print context (response, code, trace, browser)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(__DIR__ . '/..');
require 'lib/Env.php';
require 'lib/ErrorLogger.php';
$config = require 'config.php';

$args       = array_slice($argv, 1);
$errorsOnly = in_array('--errors-only', $args, true);
$details    = in_array('--details', $args, true);
$filter     = '';
foreach ($args as $a) {
    if ($a[0] !== '-') $filter = $a;
}

$runs = ErrorLogger::runs(ErrorLogger::entries($config), $filter, $errorsOnly);
if (!$runs) {
    echo $filter === '' ? "No entries in the QA error log yet.\n" : "Nothing found for \"$filter\".\n";
    exit(0);
}

if ($filter === '') {   // summary
    printf("%-20s %-20s %-20s %6s %8s %6s\n", 'TEST', 'STARTED (UTC)', 'ENDED', 'ERRORS', 'WARNINGS', 'OTHER');
    foreach (array_slice($runs, 0, 20, true) as $id => $r) {
        printf("%-20s %-20s %-20s %6d %8d %6d\n", $id, $r['started'], $r['ended'] ?: ($id === 'no-test' ? '-' : 'open'),
            $r['counts']['ERROR'], $r['counts']['WARNING'], $r['counts']['NOTICE'] + $r['counts']['INFO']);
    }
    echo "\nDetails: php tools/qa-errors.php <TEST-ID> [--errors-only] [--details]\n";
    exit(0);
}

foreach ($runs as $id => $r) {
    $c = $r['counts'];
    echo "\n$id   started {$r['started']}" . ($r['ended'] ? "  ended {$r['ended']}" : '') . "   ({$c['ERROR']} errors, {$c['WARNING']} warnings)\n";
    if (!$r['entries']) {
        echo "  → No errors found\n";
        continue;
    }
    foreach ($r['entries'] as $e) {
        printf("  %s  %-7s %-16s %-26s %-4s %s\n", substr((string) $e['time'], 11), $e['level'], $e['type'],
            mb_substr(trim(($e['service'] ?? '') . ' · ' . ($e['page'] ?? ''), ' ·'), 0, 26), $e['http_status'] ?? '', $e['message']);
        if (!empty($e['file'])) echo str_repeat(' ', 12) . $e['file'] . ':' . $e['line'] . "\n";
        if ($details && !empty($e['context'])) echo str_repeat(' ', 12) . json_encode($e['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
