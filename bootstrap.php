<?php
require __DIR__ . '/lib/FunnelRepository.php';
require __DIR__ . '/lib/FunnelRules.php';
require __DIR__ . '/lib/Screens.php';
require __DIR__ . '/lib/LeadRepository.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/VrioClient.php';
require __DIR__ . '/lib/GhlClient.php';
require __DIR__ . '/lib/Membership.php';
require __DIR__ . '/lib/XverifyClient.php';
require __DIR__ . '/lib/Payment.php';
require __DIR__ . '/lib/LeadFlow.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/Page.php';

define('FUNNEL_APP', true);   // components/ refuse to run when requested directly

$config  = require __DIR__ . '/config.php';

// Design + content: projects/<project>/site.json and one JSON file per page.
Page::boot(__DIR__ . '/projects/' . basename((string) $config['project']));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

if (empty($_SESSION['funnel_csrf'])) {
    $_SESSION['funnel_csrf'] = bin2hex(random_bytes(32));
}

function funnel_json_response(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Load the funnel for a page, or show a friendly error page. */
function load_funnel_or_fail(array $config): array
{
    try {
        return (new FunnelRepository($config))->load();
    } catch (RuntimeException $e) {
        error_log('Funnel load failed: ' . $e->getMessage());
        http_response_code(503);
        $page = Page::load('index');
        page_open($page, $page['text']['unavailable_title'] ?? 'Unavailable');
        echo '<main class="quiz-main"><div class="alert-box" role="alert">' . e($page['text']['unavailable'] ?? 'We could not load this page right now. Please try again in a few minutes.') . '</div></main>';
        page_close(['after_main' => [], 'footer' => false]);
        exit;
    }
}

/**
 * The funnel with the offer for this visitor (membership check in lib/LeadFlow.php, stored in the session):
 *   member (API true)      → membership_campaign_set: "campaign" + "products" replaced by the membership ones
 *   not member (API false) → campaign_set:            "campaign" + "products" unchanged
 * Not checked yet (no email submitted) → config.php → membership.fallback.
 */
function offer_funnel(array $funnel, array $record, array $config): array
{
    $member = $record['has_membership'] ?? (bool) ($config['membership']['fallback'] ?? false);
    if ($member) {
        $funnel['campaign'] = $funnel['membership_campaign'];
        $funnel['products'] = $funnel['membership_products'];
    }
    return $funnel;
}

/** The visitor's funnel record in the session: answers, product choice, order. */
function &funnel_session(array $funnel): array
{
    $key = 'funnel_' . $funnel['slug'];
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    return $_SESSION[$key];
}
