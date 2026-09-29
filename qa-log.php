<?php
/**
 * Receives JavaScript / AJAX errors from assets/qa-errors.js (navigator.sendBeacon) for the QA error log (lib/ErrorLogger.php).
 * POST {csrf, errors: [{kind, message, file, line, column, url, status, response, stack, page}], client: {ua, screen}}
 * The page's CSRF token must match; at most 20 errors per request and 200 per session. Always answers 204.
 */
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !ErrorLogger::enabled()) {
    http_response_code(204);
    exit;
}
$input = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
if (is_array($input) && hash_equals((string) ($_SESSION['funnel_csrf'] ?? ''), (string) ($input['csrf'] ?? ''))) {
    $count = (int) ($_SESSION['qa_log_count'] ?? 0);
    if ($count < 200 && is_array($input['errors'] ?? null)) {
        $_SESSION['qa_log_count'] = $count + ErrorLogger::clientErrors(array_slice($input['errors'], 0, 200 - $count), is_array($input['client'] ?? null) ? $input['client'] : []);
    }
}
http_response_code(204);
