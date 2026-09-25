<?php
/**
 * SAMPLE membership API — stands in for the real one until it exists (see NOTES-OPEN-ITEMS.md).
 *
 *   POST sample-api/membership-check.php   { "email": "user@example.com" }
 *   → 200 { "success": false, "email": "user@example.com" }   no membership (always, for now) → campaign_set / products
 *         ("success": true would mean a member → membership_campaign_set / membership_campaign_set_products)
 *   → 422 { "success": false, "message": "..." }              bad request
 *
 * To call it over HTTP instead of the built-in sample, set config.php → membership.api_url to its full URL,
 * e.g. http://localhost/dscript/sample-api/membership-check.php (Apache; the PHP dev server can't call itself).
 */
require __DIR__ . '/../lib/Membership.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(['success' => false, 'message' => 'Use POST.'], 405);
}
$input = json_decode((string) file_get_contents('php://input'), true);
$email = trim((string) ($input['email'] ?? $_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $respond(['success' => false, 'message' => 'A valid email is required.'], 422);
}

$respond(['success' => Membership::sampleAnswer($email), 'email' => $email]);
