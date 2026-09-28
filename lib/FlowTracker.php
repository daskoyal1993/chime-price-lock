<?php
/**
 * Funnel progress tracking (tables from database/003_create_flow_tracking.sql): the last step each visitor reached,
 * so drop-offs can be reported. The last step recorded is the drop-off point — no unload/close events are used.
 *
 *   boot()    bootstrap.php: the visitor's tracking id from the `fid` cookie (1 year) or a new one, kept in the session
 *   hit()     a step was reached: quiz screens (funnel.js → submit.php?action=track), product.php, checkout.php
 *   link()    LeadFlow::submit(): the dynamic_remedy row id and the Vrio prospect id
 *   status()  submit.php: disqualified (intake) / completed (checkout payment approved)
 *
 * Once a visitor is completed, the row keeps last_step = checkout; a new quiz start then gets a new tracking id.
 * Nothing here can stop the funnel: every problem is logged as [TRACK] and ignored. No personal data is stored.
 */
final class FlowTracker
{
    /** step_index of the pages after the quiz (quiz screens are 1…N). */
    const PAGE_STEPS = ['product' => 900, 'checkout' => 910];

    /** @var PDO|null */
    private static $pdo;
    /** @var bool the database failed once in this request: don't wait for it again */
    private static $down = false;

    /** The visitor's tracking id: the cookie, else the session, else a new one. Call before any output. */
    public static function boot(array $config): string
    {
        $cookie = (string) ($_COOKIE[self::cookieName($config)] ?? '');
        $id = self::valid($cookie) ? $cookie : (string) ($_SESSION['flow_tracking_id'] ?? '');
        if (!self::valid($id)) {
            $id = self::newId();
        }
        $_SESSION['flow_tracking_id'] = $id;
        if ($cookie !== $id) {
            self::setCookie($config, $id);
        }
        return $id;
    }

    /**
     * Record that the visitor reached a step. $step: "step7" (quiz screen 7), "product", "checkout".
     * @param array $tracking affid / c1 from the landing URL (kept from the first hit that has them)
     */
    public static function hit(array $config, string $funnelSlug, string $step, string $page, int $index, ?string $key = null, array $tracking = []): void
    {
        if (!self::enabled($config)) return;
        try {
            $id  = self::id($config);
            $row = self::row($config, $id, $funnelSlug);
            if ($row && $row['status'] === 'completed') {
                if ($page !== 'index') return;   // after checkout: keep last_step = checkout
                $id  = self::restart($config);   // a new run of the quiz
                $row = null;
            }

            $now = gmdate('Y-m-d H:i:s');
            // Column order matters: `status` is changed last, so the IF()s above it see the old value.
            $sql = 'INSERT INTO ' . self::table($config, 'table') . ' (`tracking_id`, `funnel_slug`, `last_step`, `last_step_key`, `last_step_index`,'
                . ' `max_step_index`, `last_page`, `status`, `affid`, `c1`, `started_at`, `last_activity_at`)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, 'in_progress', ?, ?, ?, ?)"
                . ' ON DUPLICATE KEY UPDATE'
                . ' `last_step` = VALUES(`last_step`), `last_step_key` = VALUES(`last_step_key`), `last_step_index` = VALUES(`last_step_index`),'
                . ' `max_step_index` = GREATEST(`max_step_index`, VALUES(`max_step_index`)), `last_page` = VALUES(`last_page`),'
                . ' `affid` = COALESCE(`affid`, VALUES(`affid`)), `c1` = COALESCE(`c1`, VALUES(`c1`)),'
                . " `last_activity_at` = VALUES(`last_activity_at`), `status` = 'in_progress'";
            self::pdo($config)->prepare($sql)->execute([
                $id, $funnelSlug, $step, $key, $index, $index, $page,
                self::optional($tracking['affid'] ?? null, 50), self::optional($tracking['c1'] ?? null, 255), $now, $now,
            ]);
            if (!$row || $row['last_step'] !== $step || $row['status'] === 'disqualified') {   // a refresh of the same step is not a new event
                self::event($config, $id, $funnelSlug, $step, $page, $index, $key, $now);
            }
        } catch (Throwable $e) {
            self::fail($config, $e);
        }
    }

    /** Attach the dynamic_remedy row (and Vrio prospect) to the visitor's tracking row. */
    public static function link(array $config, string $funnelSlug, ?int $leadId, ?string $prospectId = null): void
    {
        if (!self::enabled($config) || (!$leadId && !$prospectId)) return;
        try {
            self::pdo($config)->prepare('UPDATE ' . self::table($config, 'table')
                . ' SET `lead_id` = COALESCE(?, `lead_id`), `prospect_id` = COALESCE(?, `prospect_id`) WHERE `tracking_id` = ? AND `funnel_slug` = ?')
                ->execute([$leadId ?: null, self::optional($prospectId, 30), self::id($config), $funnelSlug]);
        } catch (Throwable $e) {
            self::fail($config, $e);
        }
    }

    /** "disqualified" (quiz answer) or "completed" (checkout payment approved; last_step stays checkout). */
    public static function status(array $config, string $funnelSlug, string $status): void
    {
        if (!self::enabled($config) || !in_array($status, ['disqualified', 'completed'], true)) return;
        try {
            $id  = self::id($config);
            $now = gmdate('Y-m-d H:i:s');
            $set = $status === 'completed'
                ? "`status` = 'completed', `completed_at` = ?, `last_activity_at` = ?, `last_step` = 'checkout', `last_step_key` = NULL,"
                    . ' `last_step_index` = ' . self::PAGE_STEPS['checkout'] . ', `max_step_index` = GREATEST(`max_step_index`, ' . self::PAGE_STEPS['checkout'] . "), `last_page` = 'checkout'"
                : "`status` = 'disqualified', `last_activity_at` = ?";
            $params = $status === 'completed' ? [$now, $now] : [$now];
            $st = self::pdo($config)->prepare('UPDATE ' . self::table($config, 'table') . ' SET ' . $set
                . " WHERE `tracking_id` = ? AND `funnel_slug` = ? AND `status` <> 'completed'");
            $st->execute(array_merge($params, [$id, $funnelSlug]));
            if ($st->rowCount() > 0) {
                self::event($config, $id, $funnelSlug, $status, $status === 'completed' ? 'checkout' : 'index',
                    $status === 'completed' ? self::PAGE_STEPS['checkout'] : 0, null, $now);
            }
        } catch (Throwable $e) {
            self::fail($config, $e);
        }
    }

    /* ---------------------------------------------------------------- internals */

    private static function enabled(array $config): bool
    {
        return !self::$down && ($config['tracking']['enabled'] ?? true) !== false
            && trim((string) ($config['db']['host'] ?? '')) !== '' && trim((string) ($config['db']['name'] ?? '')) !== '';
    }

    private static function id(array $config): string
    {
        $id = (string) ($_SESSION['flow_tracking_id'] ?? '');
        return self::valid($id) ? $id : self::boot($config);
    }

    /** A new tracking id for a new run after a completed one (same browser). */
    private static function restart(array $config): string
    {
        $id = self::newId();
        $_SESSION['flow_tracking_id'] = $id;
        self::setCookie($config, $id);
        return $id;
    }

    private static function row(array $config, string $id, string $funnelSlug): ?array
    {
        $st = self::pdo($config)->prepare('SELECT `status`, `last_step` FROM ' . self::table($config, 'table') . ' WHERE `tracking_id` = ? AND `funnel_slug` = ?');
        $st->execute([$id, $funnelSlug]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private static function event(array $config, string $id, string $funnelSlug, string $step, string $page, int $index, ?string $key, string $now): void
    {
        self::pdo($config)->prepare('INSERT INTO ' . self::table($config, 'events_table')
            . ' (`tracking_id`, `funnel_slug`, `step`, `step_key`, `step_index`, `page`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $funnelSlug, $step, $key, $index, $page, $now]);
    }

    private static function pdo(array $config): PDO
    {
        if (self::$pdo === null) {
            $db  = $config['db'];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) ($db['port'] ?? 3306), $db['name']);
            self::$pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 2,   // a page never waits long for tracking
            ]);
        }
        return self::$pdo;
    }

    private static function table(array $config, string $which): string
    {
        $table = (string) ($config['tracking'][$which] ?? ($which === 'table' ? 'flow_tracking' : 'flow_tracking_events'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $table)) {
            throw new RuntimeException('Invalid table name in config.php → tracking.' . $which);
        }
        return '`' . $table . '`';
    }

    private static function fail(array $config, Throwable $e): void
    {
        if ($e instanceof PDOException && self::$pdo === null) {
            self::$down = true;   // no connection: skip tracking for the rest of this request
        }
        LeadFlow::log($config, 'TRACK', false, $e->getMessage());
    }

    private static function cookieName(array $config): string
    {
        return (string) ($config['tracking']['cookie'] ?? 'fid');
    }

    private static function setCookie(array $config, string $id): void
    {
        if (headers_sent()) return;
        $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
        setcookie(self::cookieName($config), $id, [
            'expires'  => time() + 86400 * (int) ($config['tracking']['cookie_days'] ?? 365),
            'path'     => $path,
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::cookieName($config)] = $id;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function valid(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    private static function optional($value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
