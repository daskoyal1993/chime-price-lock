<?php
/**
 * Check the lead integrations from the command line, without creating anything:
 *
 *   php tools/check-integrations.php
 *
 *   DB    connects, checks the table/columns, then INSERTs a test row inside a transaction and rolls it back.
 *   GHL   read-only calls: lists 1 contact (token + location) and the location's custom fields
 *         (checks every field id the quiz sends exists in GHL).
 *   VRIO  checks the settings only — Vrio has no read-only call in the sample; test it with a real quiz run
 *         and watch storage/logs/lead-flow.log.
 *   MEMBERSHIP  asks the membership check about a test email.
 *
 * CLI only (and tools/ is blocked from the web by .htaccess). Secrets are never printed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(__DIR__ . '/..');
require 'lib/FunnelRepository.php';
require 'lib/LeadRepository.php';
require 'lib/Http.php';
require 'lib/Membership.php';
$config = require 'config.php';

$fails = 0;
$line = static function (string $service, ?bool $ok, string $msg) use (&$fails): void {
    if ($ok === false) $fails++;
    printf("%-11s %-5s %s\n", '[' . $service . ']', $ok === null ? 'SKIP' : ($ok ? 'OK' : 'FAIL'), $msg);
};
$mask = static function (string $s): string {
    return $s === '' ? '(empty)' : substr($s, 0, 4) . '…' . substr($s, -4) . ' (' . strlen($s) . ' chars)';
};

/* ------------------------------------------------------------------ DB */
$db = $config['db'];
if (trim((string) $db['host']) === '' || trim((string) $db['name']) === '') {
    $line('DB', null, 'not configured: set db.host / db.name in config.php (or FUNNEL_DB_HOST / FUNNEL_DB_NAME)');
} else {
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']), $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5,
        ]);
        $line('DB', true, "connected to {$db['name']} on {$db['host']} as {$db['user']}" . (is_file('.env') ? ' (settings from .env / config.php)' : ' (settings from config.php)'));
        $table = $db['table'];
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $c) $cols[$c['Field']] = strtolower($c['Type']);
        $need = ['id', 'firstName', 'lastName', 'email', 'phone', 'source', 'tags', 'quiz', 'address1', 'postalCode', 'city', 'state', 'prospectid', 'vrio_order_id', 'affid', 'c1', 'purchased', 'timing', 'updated_at'];
        $missing = array_diff($need, array_keys($cols));
        if ($missing) {
            $line('DB', false, "table `{$table}` is missing: " . implode(', ', $missing) . ' → run database/001_create_dynamic_remedy.sql (it upgrades an existing table)');
        } else {
            $isJson = strpos($cols['quiz'], 'json') !== false || strpos($cols['quiz'], 'longtext') !== false;
            $line('DB', $isJson ? true : false, "table `{$table}` has all columns; quiz is {$cols['quiz']}" . ($isJson ? '' : ' → should be json (run 002)'));
            $pdo->beginTransaction();
            $st = $pdo->prepare("INSERT INTO `{$table}` (firstName, lastName, email, phone, source, tags, quiz, timing, updated_at) VALUES ('Check', 'Script', 'check@example.com', '5550000000', 'check', '', ?, NOW(), NOW())");
            $st->execute([json_encode(['check' => true])]);
            $id = $pdo->lastInsertId();
            $pdo->rollBack();
            $line('DB', true, "test INSERT worked (row #{$id}, rolled back — nothing was saved)");
            $rows = $pdo->query("SELECT `id`, `email`, `firstName`, `lastName`, `prospectid`, `timing`, `updated_at`, JSON_LENGTH(`quiz`) AS `answers` FROM `{$table}` ORDER BY `id` DESC LIMIT 5")->fetchAll();
            $line('DB', null, $rows ? 'latest rows (times in UTC):' : 'the table is empty — no lead saved yet');
            foreach ($rows as $r) {
                printf("%18s #%-4s %-32s %-20s answers=%-3s prospect=%-10s created %s  updated %s
", '', $r['id'], $r['email'], $r['firstName'] . ' ' . $r['lastName'], $r['answers'] ?? '0', $r['prospectid'] ?: '-', $r['timing'], $r['updated_at']);
            }
        }
    } catch (Throwable $e) {
        $line('DB', false, $e->getMessage());
    }
}

/* ------------------------------------------------------------------ GHL */
$g = ($config["ghl"] ?? []) + ["token" => "", "api_url" => "", "version" => "", "location_id" => "", "field_map" => []];
if (trim((string) $g['token']) === '') {
    $line('GHL', null, 'no token: set ghl.token in config.php (or FUNNEL_GHL_TOKEN)');
} else {
    $headers = ['Authorization: Bearer ' . $g['token'], 'Version: ' . $g['version']];
    $base = rtrim($g['api_url'], '/');
    $line('GHL', null, 'token ' . $mask((string) $g['token']) . ', location ' . $g['location_id']);

    [$status, $body, $raw, $error] = Http::json('GET', $base . '/contacts/?locationId=' . rawurlencode($g['location_id']) . '&limit=1', $headers);
    if ($error !== '') {
        $line('GHL', false, 'request failed: ' . $error);
    } elseif ($status === 200) {
        $line('GHL', true, 'token and location accepted (contacts readable, total ' . ($body['meta']['total'] ?? '?') . ')');
    } else {
        $line('GHL', false, "HTTP {$status}: " . Http::message($body, $raw));
    }

    // Every custom field id the quiz sends must exist in this location.
    $funnel = (new FunnelRepository($config))->load();
    $sent = [];
    foreach ($funnel['steps'] as $step) foreach ($step['questions'] as $q) if (!empty($q['ghl']['id'])) $sent[$q['ghl']['id']] = $q['key'] . ' → ' . $q['ghl']['key'];
    foreach ($g['field_map'] as $key => $f) $sent[$f['id']] = $key . ' → ' . $f['key'];

    [$status, $body, $raw, $error] = Http::json('GET', $base . '/locations/' . rawurlencode($g['location_id']) . '/customFields', $headers);
    if ($status === 200 && isset($body['customFields'])) {
        $exists = array_column($body['customFields'], 'name', 'id');
        $unknown = array_diff_key($sent, $exists);
        $line('GHL', !$unknown, count($sent) - count($unknown) . ' of ' . count($sent) . ' mapped custom fields exist in GHL');
        foreach ($unknown as $id => $what) $line('GHL', false, "  field {$id} not found ({$what})");
    } else {
        $line('GHL', null, "could not list custom fields (HTTP {$status}: " . Http::message($body, $raw) . ') — the token may lack the custom-fields scope');
    }
}

/* ------------------------------------------------------------------ VRIO */
$v = ($config["vrio"] ?? []) + ["api_key" => "", "connection_id" => "", "api_url" => ""];
$missing = [];
if (trim((string) $v['api_key']) === '') $missing[] = 'vrio.api_key (FUNNEL_VRIO_API_KEY)';
if (trim((string) $v['connection_id']) === '') $missing[] = 'vrio.connection_id (FUNNEL_VRIO_CONNECTION_ID)';
if ($missing) {
    $line('VRIO', false, 'missing: ' . implode(', ', $missing) . ' in config.php');
} else {
    $line('VRIO', true, 'key ' . $mask((string) $v['api_key']) . ', connection ' . $v['connection_id'] . ', url ' . $v['api_url'] . ' (not called: it would create a prospect)');
}

/* ------------------------------------------------------------------ MEMBERSHIP */
try {
    [$member, $source] = Membership::check($config['membership'] ?? [], 'check@example.com');
    $line('MEMBERSHIP', true, 'check@example.com → ' . ($member ? 'member (membership_campaign_set)' : 'not a member (campaign_set)') . ' via ' . $source);
} catch (Throwable $e) {
    $line('MEMBERSHIP', false, $e->getMessage());
}

echo $fails ? "\n{$fails} problem(s) found.\n" : "\nAll checks passed.\n";
exit($fails ? 1 : 0);
