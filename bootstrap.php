<?php
require __DIR__ . '/lib/ErrorLogger.php';   // first, so every class below can report to it
require __DIR__ . '/lib/FunnelRepository.php';
require __DIR__ . '/lib/FunnelRules.php';
require __DIR__ . '/lib/Screens.php';
require __DIR__ . '/lib/LeadRepository.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/VrioClient.php';
require __DIR__ . '/lib/GhlClient.php';
require __DIR__ . '/lib/Membership.php';
require __DIR__ . '/lib/XverifyClient.php';
require __DIR__ . '/lib/VouchedClient.php';
require __DIR__ . '/lib/PortalClient.php';
require __DIR__ . '/lib/Payment.php';
require __DIR__ . '/lib/LeadFlow.php';
require __DIR__ . '/lib/FlowTracker.php';
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

// QA error log: PHP warnings, exceptions, fatal errors, ?qa_test=start|end — see lib/ErrorLogger.php.
ErrorLogger::register($config);

// Progress tracking id (fid cookie), from the first page load on — see lib/FlowTracker.php.
FlowTracker::boot($config);

function funnel_json_response(array $body, int $status = 200): void
{
    ErrorLogger::requestError($status, (string) ($body['message'] ?? ''));   // 4xx / 5xx replies (not 422) go to the QA error log
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send the JSON response to the browser now, then run $after (slow CRM updates) on the server.
 * The session is saved first, so change $_SESSION before calling this — $after can't change it.
 * The browser (and Cloudflare's 120 s limit) doesn't wait for $after on PHP-FPM / LiteSpeed; elsewhere it runs before the reply ends.
 */
function funnel_json_response_then(array $body, int $status, callable $after): void
{
    ErrorLogger::requestError($status, (string) ($body['message'] ?? ''));
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    finish_request_then($after);
}

/** Redirect the browser now (e.g. to the patient portal), then run $after. Same rules as funnel_json_response_then(). */
function redirect_now_then(string $url, callable $after): void
{
    header('Location: ' . $url, true, 302);
    header('Content-Length: 0');
    header('Connection: close');
    finish_request_then($after);
}

/** End the response (saving the session first), then run $after and exit. */
function finish_request_then(callable $after): void
{
    session_write_close();   // the next page must not wait for the session lock
    ignore_user_abort(true);
    @set_time_limit(120);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level() > 0) @ob_end_flush();
        flush();
    }

    try {
        $after();
    } catch (Throwable $e) {
        error_log('[lead-flow] after-response work failed: ' . $e->getMessage());
        ErrorLogger::log($e, 'EXCEPTION', ['where' => 'after-response work']);
    }
    exit;
}

/** Load the funnel for a page, or show a friendly error page. */
function load_funnel_or_fail(array $config): array
{
    try {
        return (new FunnelRepository($config))->load();
    } catch (RuntimeException $e) {
        error_log('Funnel load failed: ' . $e->getMessage());
        ErrorLogger::log($e, 'FUNNEL_ERROR');
        http_response_code(503);
        $page = Page::load('index');
        page_open($page, $page['text']['unavailable_title'] ?? 'Unavailable');
        echo '<main class="quiz-main"><div class="alert-box" role="alert">' . e($page['text']['unavailable'] ?? 'We could not load this page right now. Please try again in a few minutes.') . '</div></main>';
        page_close(['after_main' => [], 'footer' => false]);
        exit;
    }
}

/**
 * The funnel without the questions listed in projects/<project>/index.json → "skip_questions"
 * (e.g. the shipping address, which is asked on checkout.php instead). They are not shown, validated or saved by the quiz.
 */
function quiz_funnel(array $funnel): array
{
    $skip = array_flip(Page::load('index')['skip_questions'] ?? []);
    if (!$skip) return $funnel;
    foreach ($funnel['steps'] as &$step) {
        $step['questions'] = array_values(array_filter($step['questions'], static function (array $q) use ($skip): bool {
            return !isset($skip[$q['key']]);
        }));
    }
    unset($step);
    return $funnel;
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
    if ($member && !empty($funnel['membership_products'])) {   // no membership set in the funnel (null): campaign_set for everyone
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
