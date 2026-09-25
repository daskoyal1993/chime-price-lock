<?php
/**
 * Vouched job callback for the ID check (like demo/idcheck-callback.php).
 * Vouched POSTs the finished job { id, result: { success } }; the row with that idcheck_vouched_job_id
 * (saved by submit.php?action=idcheck) gets idcheck_vouched_status 'true' / 'false'.
 * Logged in storage/logs/lead-flow.log as [IDCHECK-CALLBACK] (not in a public text file).
 */
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(['error' => 'Use POST.'], 405);
}
$data = json_decode((string) file_get_contents('php://input'), true);
$jobId = is_array($data) ? trim((string) ($data['id'] ?? '')) : '';
if ($jobId === '') {
    $respond(['error' => 'Invalid JSON'], 400);
}
$success = ($data['result']['success'] ?? false) === true;

$repo = new LeadRepository($config['db'] ?? []);
if (!$repo->enabled()) {
    LeadFlow::log($config, 'IDCHECK-CALLBACK', null, 'job ' . $jobId . ' → ' . ($success ? 'true' : 'false') . ' (no database configured)');
    $respond(['message' => 'OK']);
}
try {
    $rows = $repo->saveIdcheckResult(mb_substr($jobId, 0, 255), $success);
    LeadFlow::log($config, 'IDCHECK-CALLBACK', $rows > 0, 'job ' . $jobId . ' → ' . ($success ? 'true' : 'false') . ($rows > 0 ? ' (' . $rows . ' row)' : ' (no row with this job id yet)'));
} catch (Throwable $e) {
    LeadFlow::log($config, 'IDCHECK-CALLBACK', false, 'job ' . $jobId . ': ' . $e->getMessage());
    $respond(['error' => 'Could not save'], 500);
}
$respond(['message' => 'OK']);
