<?php
/**
 * QA error log: every error in one place, grouped by QA test run. Errors only — no page or journey tracking.
 *
 *   register()        bootstrap.php: PHP warnings/notices (set_error_handler), uncaught exceptions (set_exception_handler),
 *                     fatal errors (register_shutdown_function), and ?qa_test=start / end (the test id cookie)
 *   httpFailure()     lib/Http.php: every external call that fails — cURL error, HTTP >= 400, reply that isn't JSON
 *   fromLeadFlow()    lib/LeadFlow.php → log(): every FAILED step (DB, Vrio, GHL, Vouched, portal, pre-auth, tracking…)
 *   requestError()    bootstrap.php → funnel_json_response(): submit.php error replies (not 422 form validation)
 *   log() / apiError() / databaseError() / jsonError()   for direct use
 *   clientErrors()    qa-log.php: JavaScript and AJAX errors from assets/qa-errors.js
 *
 * Stored as one JSON line per error in storage/logs/qa-errors.log (always) and in the error_logs table when it exists
 * (database/004_create_error_logs.sql). Read with `php tools/qa-errors.php` or qa-errors.php (key protected).
 * Passwords, keys, tokens, Authorization headers, card numbers, CVV, emails and phones are masked before anything is written.
 * The logger never throws and never changes what the application does.
 */
final class ErrorLogger
{
    const LEVELS = ['ERROR', 'WARNING', 'NOTICE', 'INFO'];

    /** @var array|null */
    private static $cfg;
    /** @var array */
    private static $db = [];
    /** @var bool */
    private static $busy = false;
    /** @var PDO|false|null  false = the table can't be used in this request */
    private static $pdo;
    /** @var string */
    private static $root = '';

    /* ================================================================ setup */

    /** Turn the logger on (bootstrap.php, after the session starts and before any output). */
    public static function register(array $config): void
    {
        $cfg = $config['qa_errors'] ?? [];
        if (($cfg['enabled'] ?? true) === false) {
            return;
        }
        self::$cfg  = $cfg + ['env' => 'local', 'key' => '', 'file' => '', 'table' => 'error_logs', 'max_bytes' => 5242880, 'viewer_on_live' => false];
        self::$db   = $config['db'] ?? [];
        self::$root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/';

        set_error_handler([self::class, 'onPhpError']);
        set_exception_handler([self::class, 'onException']);
        register_shutdown_function([self::class, 'onShutdown']);

        if (PHP_SAPI !== 'cli') {
            self::handleTestParam();
        }
    }

    public static function enabled(): bool
    {
        return self::$cfg !== null;
    }

    /** Settings for assets/qa-errors.js (lib/Page.php → page_open()). */
    public static function clientConfig(): ?array
    {
        if (!self::enabled()) return null;
        return ['endpoint' => 'qa-log.php', 'csrf' => (string) ($_SESSION['funnel_csrf'] ?? ''), 'testId' => self::testId()];
    }

    /* ================================================================ QA test id */

    /** The running QA test id (qa_test cookie), or "no-test". */
    public static function testId(): string
    {
        $id = (string) ($_COOKIE['qa_test'] ?? '');
        return preg_match('/^TEST-\d{8}-\d{3}$/', $id) ? $id : 'no-test';
    }

    /** ?qa_test=start → new id TEST-YYYYMMDD-NNN (cookie, 12 h); ?qa_test=end → closes it. On live the key is required. */
    private static function handleTestParam(): void
    {
        try {
            $action = (string) ($_GET['qa_test'] ?? '');
            if ($action !== 'start' && $action !== 'end') return;
            if (!self::keyOk((string) ($_GET['key'] ?? ''), self::$cfg['env'] === 'live')) return;
            if ($action === 'start') {
                $id = self::nextTestId();
                self::setTestCookie($id, time() + 12 * 3600);
                self::write(['level' => 'INFO', 'type' => 'TEST_START', 'message' => 'QA test started', 'test_id' => $id]);
            } elseif (self::testId() !== 'no-test') {
                self::write(['level' => 'INFO', 'type' => 'TEST_END', 'message' => 'QA test ended']);
                self::setTestCookie('', time() - 3600);
            }
        } catch (Throwable $e) {
            // never break a page
        }
    }

    /** The next number for today, from a small counter file next to the log. */
    private static function nextTestId(): string
    {
        $day  = gmdate('Ymd');
        $file = dirname(self::file()) . '/qa-test-counter.json';
        $n    = 1;
        $fh   = @fopen($file, 'c+');
        if ($fh && flock($fh, LOCK_EX)) {
            $data = json_decode((string) stream_get_contents($fh), true);
            $n = (is_array($data) && ($data['day'] ?? '') === $day) ? (int) $data['n'] + 1 : 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(['day' => $day, 'n' => $n]));
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);
        return sprintf('TEST-%s-%03d', $day, min($n, 999));
    }

    private static function setTestCookie(string $value, int $expires): void
    {
        if (headers_sent()) return;
        $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
        setcookie('qa_test', $value, ['expires' => $expires, 'path' => $path, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false, 'samesite' => 'Lax']);
        $_COOKIE['qa_test'] = $value;
    }

    /** FUNNEL_QA_KEY check. $required: refuse when no key is configured. */
    public static function keyOk(string $given, bool $required): bool
    {
        $key = (string) (self::$cfg['key'] ?? '');
        if ($key === '') return !$required;
        return hash_equals($key, $given);
    }

    /* ================================================================ PHP handlers */

    /** Warnings, notices, deprecations. Returns false so PHP handles them exactly as before. */
    public static function onPhpError(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $errno)) return false;   // silenced with @ (intentional in the code)
        $names = [E_WARNING => 'PHP_WARNING', E_USER_WARNING => 'PHP_WARNING', E_CORE_WARNING => 'PHP_WARNING', E_COMPILE_WARNING => 'PHP_WARNING',
                  E_NOTICE => 'PHP_NOTICE', E_USER_NOTICE => 'PHP_NOTICE', E_DEPRECATED => 'PHP_DEPRECATED', E_USER_DEPRECATED => 'PHP_DEPRECATED',
                  E_USER_ERROR => 'PHP_ERROR', E_RECOVERABLE_ERROR => 'PHP_ERROR'];
        $type  = $names[$errno] ?? 'PHP_ERROR';
        $level = $type === 'PHP_ERROR' ? 'ERROR' : ($type === 'PHP_WARNING' ? 'WARNING' : 'NOTICE');
        self::write(['level' => $level, 'type' => $type, 'message' => $message, 'file' => $file, 'line' => $line]);
        return false;
    }

    /** An exception nobody caught: logged, then a plain error page instead of a blank one. */
    public static function onException(Throwable $e): void
    {
        self::log($e);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n");
            exit(255);
        }
        if (!headers_sent()) {
            http_response_code(500);
            $json = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
            header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
            echo $json
                ? json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.'])
                : '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong</title></head>'
                  . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 16px;color:#1f2937"><h1 style="font-size:22px">Something went wrong</h1>'
                  . '<p>Please try again in a moment. If it keeps happening, contact support.</p></body></html>';
        }
    }

    /** Fatal errors never reach the handlers above; they are picked up here at the end of the request. */
    public static function onShutdown(): void
    {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            self::write(['level' => 'ERROR', 'type' => 'FATAL_ERROR', 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
        }
    }

    /* ================================================================ entry points */

    /** An exception (caught or not). */
    public static function log(Throwable $e, string $type = 'EXCEPTION', array $context = []): void
    {
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 5) as $f) {
            $trace[] = (isset($f['file']) ? self::relative($f['file']) . ':' . ($f['line'] ?? 0) . ' ' : '') . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()';
        }
        self::write(['level' => 'ERROR', 'type' => $type, 'message' => get_class($e) . ': ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            'context' => $context + ['trace' => $trace]]);
    }

    /** A failed external call. */
    public static function apiError(string $service, int $status, string $message, array $context = [], string $level = 'ERROR'): void
    {
        [$file, $line] = self::caller(1);
        self::write(['level' => $level, 'type' => 'API_ERROR', 'service' => $service, 'http_status' => $status, 'message' => $message,
            'file' => $file, 'line' => $line, 'context' => $context]);
    }

    /** A database operation that failed. */
    public static function databaseError(string $operation, string $message, array $context = []): void
    {
        [$file, $line] = self::caller(1);
        self::write(['level' => 'ERROR', 'type' => 'DATABASE_ERROR', 'service' => 'DB', 'message' => $operation . ': ' . $message,
            'file' => $file, 'line' => $line, 'context' => $context]);
    }

    /** JSON that couldn't be parsed. */
    public static function jsonError(string $what, string $error, string $sample = ''): void
    {
        [$file, $line] = self::caller(1);
        self::write(['level' => 'ERROR', 'type' => 'JSON_ERROR', 'message' => $what . ': ' . $error, 'file' => $file, 'line' => $line,
            'context' => $sample !== '' ? ['sample' => mb_substr($sample, 0, 200)] : []]);
    }

    /**
     * lib/Http.php: cURL error, HTTP >= 400, or a reply that isn't JSON. Replies that are part of the normal flow
     * (GHL "duplicated contacts" → the existing contact is updated; Vrio "prepaid not allowed" → retried) are WARNINGs.
     */
    public static function httpFailure(string $method, string $url, int $status, string $curlError, string $raw, ?array $body): void
    {
        if (!self::enabled()) return;
        $bad = $curlError !== '' || $status >= 400 || ($raw !== '' && $body === null && json_last_error() !== JSON_ERROR_NONE);
        if (!$bad) return;

        $host    = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path    = (string) parse_url($url, PHP_URL_PATH);
        $service = self::serviceOf($host);
        $code    = (string) (is_array($body['error'] ?? null) ? ($body['error']['code'] ?? '') : ($body['code'] ?? ''));
        $message = $curlError !== '' ? 'Request failed: ' . $curlError
            : ($status >= 400 ? 'HTTP ' . $status . ': ' . Http::message($body, $raw) : 'Reply is not JSON (HTTP ' . $status . ')');
        $level   = 'ERROR';
        if (($status === 400 && isset($body['meta']['contactId'])) || stripos($code . ' ' . $message, 'prepaid') !== false) {   // GHL duplicate / Vrio prepaid
            $level = 'WARNING';   // handled by the code (update existing contact / prepaid retry)
        }
        [$file, $line] = self::caller(2);
        self::write(['level' => $level, 'type' => 'API_ERROR', 'service' => $service . ' ' . $method . ' ' . preg_replace('~/\d+~', '/{id}', $path),
            'http_status' => $status, 'message' => $message, 'file' => $file, 'line' => $line,
            'context' => array_filter(['code' => $code, 'curl_error' => $curlError, 'response' => mb_substr($raw, 0, 1000)], 'strlen')]);
    }

    /** lib/LeadFlow.php → log() with $ok === false: the step's failure, typed by its service tag. */
    public static function fromLeadFlow(string $service, string $message): void
    {
        if (!self::enabled()) return;
        $types = ['DB' => 'DATABASE_ERROR', 'TRACK' => 'DATABASE_ERROR', 'VRIO-PREAUTH' => 'PAYMENT_ERROR', 'PORTAL' => 'PORTAL_ERROR',
                  'VOUCHED' => 'IDENTITY_ERROR', 'IDCHECK' => 'IDENTITY_ERROR', 'IDCHECK-CALLBACK' => 'IDENTITY_ERROR'];
        $type  = $types[$service] ?? 'INTEGRATION_ERROR';
        $level = 'ERROR';
        if ($service === 'VRIO-PREAUTH' && (strpos($message, 'declined:') !== false || strpos($message, 'form errors') !== false)) {
            [$type, $level] = [strpos($message, 'declined:') !== false ? 'PAYMENT_DECLINED' : 'VALIDATION', 'WARNING'];   // the card / the form, not a bug
        } elseif ($type === 'IDENTITY_ERROR' && preg_match('/→ (false|ID check)|\(need \d+\)/u', $message)) {
            [$type, $level] = ['IDENTITY_RESULT', 'INFO'];   // the person wasn't verified — an outcome, not an error
        } elseif ($service === 'IDCHECK-CALLBACK' && strpos($message, 'no row with this job id yet') !== false) {
            $level = 'WARNING';   // Vouched's callback came before the browser's report
        }
        [$file, $line] = self::caller(2);
        self::write(['level' => $level, 'type' => $type, 'service' => $service, 'message' => $message, 'file' => $file, 'line' => $line]);
    }

    /** bootstrap.php → funnel_json_response(): an error reply to the browser (422 form validation is skipped). */
    public static function requestError(int $status, string $message): void
    {
        if (!self::enabled() || $status < 400 || $status === 422) return;
        [$file, $line] = self::caller(2);
        self::write(['level' => $status === 402 ? 'WARNING' : 'ERROR', 'type' => $status === 409 ? 'MISSING_DATA' : 'REQUEST_ERROR',
            'http_status' => $status, 'message' => $message !== '' ? $message : 'HTTP ' . $status, 'file' => $file, 'line' => $line]);
    }

    /** qa-log.php: up to 20 JavaScript / AJAX errors sent by assets/qa-errors.js. */
    public static function clientErrors(array $errors, array $client): int
    {
        if (!self::enabled()) return 0;
        $n = 0;
        foreach (array_slice($errors, 0, 20) as $err) {
            if (!is_array($err)) continue;
            $kind = ($err['kind'] ?? '') === 'ajax' ? 'AJAX_ERROR' : 'JS_ERROR';
            self::write([
                'level'       => 'ERROR',
                'type'        => $kind,
                'service'     => 'Browser',
                'page'        => mb_substr((string) ($err['page'] ?? ''), 0, 200),
                'http_status' => isset($err['status']) ? (int) $err['status'] : null,
                'message'     => mb_substr((string) ($err['message'] ?? ''), 0, 500),
                'file'        => mb_substr((string) preg_replace('~^https?://[^/]+~i', '', (string) ($err['file'] ?? '')), 0, 200),   // path only
                'line'        => (int) ($err['line'] ?? 0),
                'context'     => array_filter([
                    'column'   => (int) ($err['column'] ?? 0),
                    'url'      => mb_substr((string) ($err['url'] ?? ''), 0, 200),
                    'response' => mb_substr((string) ($err['response'] ?? ''), 0, 500),
                    'stack'    => mb_substr((string) ($err['stack'] ?? ''), 0, 800),
                    'browser'  => mb_substr((string) ($client['ua'] ?? ''), 0, 200),
                    'screen'   => mb_substr((string) ($client['screen'] ?? ''), 0, 20),
                ]),
            ]);
            $n++;
        }
        return $n;
    }

    /* ================================================================ storage */

    /** Mask, then append to the file and (when it exists) the error_logs table. Never throws. */
    private static function write(array $entry): void
    {
        if (!self::enabled() || self::$busy) return;
        self::$busy = true;
        try {
            $entry = [
                'time'        => gmdate('Y-m-d H:i:s'),
                'env'         => (string) self::$cfg['env'],
                'test_id'     => (string) ($entry['test_id'] ?? self::testId()),
                'level'       => in_array($entry['level'] ?? '', self::LEVELS, true) ? $entry['level'] : 'ERROR',
                'type'        => (string) ($entry['type'] ?? 'ERROR'),
                'service'     => (string) ($entry['service'] ?? ''),
                'page'        => (string) ($entry['page'] ?? self::page()),
                'http_status' => isset($entry['http_status']) ? (int) $entry['http_status'] : null,
                'message'     => mb_substr(self::scrub((string) ($entry['message'] ?? '')), 0, 1000),
                'file'        => self::relative((string) ($entry['file'] ?? '')),
                'line'        => (int) ($entry['line'] ?? 0),
                'context'     => self::sanitize($entry['context'] ?? []),
            ];
            self::toFile($entry);
            self::toDb($entry);
        } catch (Throwable $e) {
            // the logger must never break the application
        } finally {
            self::$busy = false;
        }
    }

    private static function file(): string
    {
        $file = (string) (self::$cfg['file'] ?? '');
        return $file !== '' ? $file : dirname(__DIR__) . '/storage/logs/qa-errors.log';
    }

    private static function toFile(array $entry): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        if (@filesize($file) > (int) self::$cfg['max_bytes']) {
            @rename($file, preg_replace('/\.log$/', '-1.log', $file));   // keep one older file
        }
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function toDb(array $entry): void
    {
        if (self::$pdo === false || trim((string) (self::$db['host'] ?? '')) === '' || trim((string) (self::$db['name'] ?? '')) === '') return;
        $table = (string) self::$cfg['table'];
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)) return;
        try {
            if (self::$pdo === null) {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', self::$db['host'], (int) (self::$db['port'] ?? 3306), self::$db['name']);
                self::$pdo = new PDO($dsn, (string) (self::$db['user'] ?? ''), (string) (self::$db['pass'] ?? ''),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2, PDO::ATTR_EMULATE_PREPARES => false]);
            }
            self::$pdo->prepare("INSERT INTO `{$table}` (`test_id`, `environment`, `level`, `error_type`, `service`, `page`, `message`, `file`, `line`, `http_status`, `context`, `created_at`)"
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$entry['test_id'], $entry['env'], $entry['level'], $entry['type'], mb_substr($entry['service'], 0, 100), mb_substr($entry['page'], 0, 255),
                    $entry['message'], mb_substr($entry['file'], 0, 255), $entry['line'], $entry['http_status'],
                    $entry['context'] ? json_encode($entry['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null, $entry['time']]);
        } catch (Throwable $e) {
            self::$pdo = false;   // no DB / no table: the file has the entry; don't try again in this request
        }
    }

    /** All entries in the log files (older file first), for tools/qa-errors.php and qa-errors.php. */
    public static function entries(array $config): array
    {
        $file = (string) ($config['qa_errors']['file'] ?? '') ?: dirname(__DIR__) . '/storage/logs/qa-errors.log';
        $out  = [];
        foreach ([preg_replace('/\.log$/', '-1.log', $file), $file] as $f) {
            if (!is_file($f)) continue;
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
                $e = json_decode($row, true);
                if (is_array($e)) $out[] = $e;
            }
        }
        return $out;
    }

    /**
     * Entries grouped by QA run, newest run first: [test_id => ['started', 'ended', 'entries' => [...], 'counts' => [level => n]]].
     * $filter: a test id, "today", "all", or "" (= all). $errorsOnly drops WARNING / NOTICE / INFO.
     */
    public static function runs(array $entries, string $filter = '', bool $errorsOnly = false): array
    {
        $runs = [];
        foreach ($entries as $e) {
            $id = (string) ($e['test_id'] ?? 'no-test');
            if ($filter === 'today' && strpos((string) ($e['time'] ?? ''), gmdate('Y-m-d')) !== 0) continue;
            if ($filter !== '' && $filter !== 'today' && $filter !== 'all' && $id !== $filter) continue;
            $runs[$id] = $runs[$id] ?? ['started' => $e['time'] ?? '', 'ended' => '', 'entries' => [], 'counts' => ['ERROR' => 0, 'WARNING' => 0, 'NOTICE' => 0, 'INFO' => 0]];
            $type = (string) ($e['type'] ?? '');
            if ($type === 'TEST_START') { $runs[$id]['started'] = $e['time'] ?? ''; continue; }
            if ($type === 'TEST_END') { $runs[$id]['ended'] = $e['time'] ?? ''; continue; }
            $level = (string) ($e['level'] ?? 'ERROR');
            $runs[$id]['counts'][$level] = ($runs[$id]['counts'][$level] ?? 0) + 1;
            if ($errorsOnly && $level !== 'ERROR') continue;
            $runs[$id]['entries'][] = $e;
        }
        uasort($runs, static function ($a, $b) { return strcmp((string) $b['started'], (string) $a['started']); });
        return $runs;
    }

    /* ================================================================ masking */

    /** Arrays: sensitive keys removed or masked; every string scrubbed. */
    public static function sanitize($value, string $key = '')
    {
        if (preg_match('/pass|secret|token|authori[sz]ation|api[_-]?key|app[_-]?secret|signature|cvv|cvc|csc/i', $key)) {
            return '[removed]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = self::sanitize($v, (string) $k);
            return $out;
        }
        if (!is_string($value)) return $value;
        if (preg_match('/card[_-]?number|^number$/i', $key)) {
            $d = preg_replace('/\D/', '', $value);
            return str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4);
        }
        return self::scrub($value);
    }

    /** Free text: card numbers, emails, phone numbers, bearer / API tokens and JSON secrets masked. */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/("(?:[a-z_]*(?:pass|secret|token|api_?key|authorization|cvv|cvc)[a-z_]*)"\s*:\s*)"[^"]*"/i', '$1"[removed]"', $text);
        $text = preg_replace('/("card_number"\s*:\s*)"[^"]*?(\d{4})"/i', '$1"************$2"', $text);
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [removed]', $text);
        $text = preg_replace('/\b(pit-[0-9a-f-]{8,}|eyJ[\w-]+\.[\w-]+\.[\w-]+|(?:prv|pub)\.[A-Za-z0-9_-]{8,})\b/', '[token removed]', $text);
        $text = preg_replace_callback('/\b\d(?:[ -]?\d){11,18}\b/', static function ($m) {
            $d = preg_replace('/\D/', '', $m[0]);
            return str_repeat('*', strlen($d) - 4) . substr($d, -4);
        }, $text);
        $text = preg_replace_callback('/\b[\w.+-]+@([\w-]+\.[\w.-]+)\b/', static function ($m) {
            return mb_substr($m[0], 0, 1) . '***@' . $m[1];
        }, $text);
        return preg_replace_callback('/(?<![\d*])(?:\+?1[ -]?)?\(?\d{3}\)?[ .-]?\d{3}[ .-]?\d{4}(?!\d)/', static function ($m) {
            $d = preg_replace('/\D/', '', $m[0]);
            return '******' . substr($d, -4);
        }, $text);
    }

    /* ================================================================ helpers */

    private static function serviceOf(string $host): string
    {
        $map = ['leadconnectorhq' => 'GHL', 'vrio' => 'Vrio', 'vouched' => 'Vouched', 'axismd' => 'Portal', 'medaxis' => 'Portal', 'xverify' => 'xverify'];
        foreach ($map as $needle => $name) {
            if (strpos($host, $needle) !== false) return $name;
        }
        return $host !== '' ? $host : 'HTTP';
    }

    /** File and line of the code that called into the logging point ($depth frames up). */
    private static function caller(int $depth): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth + 2);
        $frame = $trace[$depth] ?? end($trace);
        return [(string) ($frame['file'] ?? ''), (int) ($frame['line'] ?? 0)];
    }

    /** The current page, e.g. "submit.php?action=checkout". */
    private static function page(): string
    {
        if (PHP_SAPI === 'cli') return 'cli:' . basename((string) ($_SERVER['argv'][0] ?? ''));
        $page   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $action = (string) ($_GET['action'] ?? $_GET['page'] ?? '');
        return $page . (preg_match('/^[a-z0-9_-]{1,40}$/i', $action) ? '?' . (isset($_GET['action']) ? 'action' : 'page') . '=' . $action : '');
    }

    private static function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        return self::$root !== '' && stripos($file, self::$root) === 0 ? substr($file, strlen(self::$root)) : $file;
    }
}
