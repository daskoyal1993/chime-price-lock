<?php
/**
 * Saves the visitor's contact details and quiz answers to the `dynamic_remedy` table (config.php → db).
 *
 * One row per visitor per funnel run:
 *   - the row id is kept in the PHP session after the first save, and every later save updates that row;
 *   - without a session id, the newest not-yet-purchased row with the same email is reused;
 *   - otherwise a new row is inserted.
 * `quiz` holds all answers as one JSON object keyed by API question key. New answers are merged into the
 * stored JSON, so earlier answers are kept.
 *
 * Every method throws RuntimeException on a database problem; callers log it and carry on, so the funnel never
 * breaks because of the database.
 */
final class LeadRepository
{
    /** @var array */
    private $db;
    /** @var PDO|null */
    private $pdo;

    public function __construct(array $db)
    {
        $this->db = $db;
    }

    /** False when no database is configured (config.php → db.host / FUNNEL_DB_HOST empty). */
    public function enabled(): bool
    {
        return trim((string) ($this->db['host'] ?? '')) !== '' && trim((string) ($this->db['name'] ?? '')) !== '';
    }

    /**
     * Check the contact fields the table needs. Returns [field => message]; empty when valid.
     */
    public static function validateContact(array $answers): array
    {
        $errors = [];
        foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $key => $label) {
            $v = trim((string) ($answers[$key] ?? ''));
            if ($v === '') {
                $errors[$key] = $label . ' is required.';
            } elseif (mb_strlen($v) > 100) {
                $errors[$key] = $label . ' must be 100 characters or less.';
            }
        }
        $email = trim((string) ($answers['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen(self::phoneDigits($answers['phone'] ?? '')) !== 10) {
            $errors['phone'] = 'Enter a valid 10-digit US phone number.';
        }
        return $errors;
    }

    /**
     * Insert or update the visitor's row.
     *
     * @param int|null $id       row id from the session (null on the first save)
     * @param array    $answers  answers keyed by question key (already validated by FunnelRules)
     * @param array    $tracking prospect_id / affid / c1 from the landing URL
     * @param array    $remove   question keys to drop from the stored JSON (answers now hidden by conditions)
     * @return int the row id
     */
    public function save(?int $id, array $answers, array $tracking = [], array $remove = []): int
    {
        $errors = self::validateContact($answers);
        if ($errors) {
            throw new InvalidArgumentException('Invalid contact details: ' . implode(', ', array_keys($errors)));
        }

        $pdo   = $this->pdo();
        $table = $this->table();
        $email = strtolower(trim((string) $answers['email']));

        try {
            $pdo->beginTransaction();

            $row = null;
            if ($id) {
                $st = $pdo->prepare("SELECT `id`, `quiz` FROM {$table} WHERE `id` = ? FOR UPDATE");
                $st->execute([$id]);
                $row = $st->fetch() ?: null;
            }
            if ($row === null) {
                $st = $pdo->prepare("SELECT `id`, `quiz` FROM {$table} WHERE `email` = ? AND (`purchased` IS NULL OR `purchased` = 0) ORDER BY `id` DESC LIMIT 1 FOR UPDATE");
                $st->execute([$email]);
                $row = $st->fetch() ?: null;
            }

            $quiz = $row ? self::decodeQuiz((string) $row['quiz'], (int) $row['id']) : [];
            foreach ($remove as $key) {
                unset($quiz[$key]);
            }
            $quiz = array_merge($quiz, $answers);
            $json = json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Quiz answers could not be encoded as JSON: ' . json_last_error_msg());
            }

            $address = trim(($answers['address_line1'] ?? '') . ' ' . ($answers['address_line2'] ?? ''));
            $fields = [
                'firstName'  => mb_substr(trim((string) $answers['first_name']), 0, 100),
                'lastName'   => mb_substr(trim((string) $answers['last_name']), 0, 100),
                'email'      => $email,
                'phone'      => self::phoneDigits($answers['phone']),
                'quiz'       => $json,
                'address1'   => $address !== '' ? mb_substr($address, 0, 100) : null,
                'postalCode' => self::optional($answers['zip'] ?? null),
                'city'       => self::optional($answers['city'] ?? null),
                'state'      => self::optional($answers['shipping_state'] ?? null),
                'prospectid' => self::optional($tracking['prospect_id'] ?? null, 30),
                'affid'      => self::optional($tracking['affid'] ?? null, 50),
                'c1'         => self::optional($tracking['c1'] ?? null, 255),
            ];
            $now = gmdate('Y-m-d H:i:s');

            if ($row) {
                // Optional columns keep their stored value until the quiz provides one.
                $sets = [];
                foreach ($fields as $col => $value) {
                    $sets[] = in_array($col, ['firstName', 'lastName', 'email', 'phone', 'quiz'], true)
                        ? "`{$col}` = :{$col}"
                        : "`{$col}` = COALESCE(:{$col}, `{$col}`)";
                }
                $st = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . ", `updated_at` = :now WHERE `id` = :id");
                $st->execute($fields + ['now' => $now, 'id' => (int) $row['id']]);
                $id = (int) $row['id'];
            } else {
                $fields += ['source' => (string) ($this->db['source'] ?? 'dynamic'), 'tags' => (string) ($this->db['tags'] ?? ''), 'timing' => $now, 'updated_at' => $now];
                $cols = array_keys($fields);
                $st = $pdo->prepare("INSERT INTO {$table} (`" . implode('`, `', $cols) . "`) VALUES (:" . implode(', :', $cols) . ')');
                $st->execute($fields);
                $id = (int) $pdo->lastInsertId();
            }

            $pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Saving the lead failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The Vrio ids stored on a row: [prospectid, vrio_order_id] (null when not found). */
    public function crmIds(int $id): ?array
    {
        try {
            $st = $this->pdo()->prepare("SELECT `prospectid`, `vrio_order_id` FROM {$this->table()} WHERE `id` = ?");
            $st->execute([$id]);
            $row = $st->fetch();
        } catch (PDOException $e) {
            throw new RuntimeException('Reading the lead failed: ' . $e->getMessage(), 0, $e);
        }
        return $row ? [$row['prospectid'], $row['vrio_order_id']] : null;
    }

    /** Store the Vrio prospect / order id on the row. */
    public function setCrmIds(int $id, string $prospectId, ?string $orderId): void
    {
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `prospectid` = ?, `vrio_order_id` = COALESCE(?, `vrio_order_id`), `updated_at` = ? WHERE `id` = ?");
            $st->execute([mb_substr($prospectId, 0, 30), $orderId !== null ? mb_substr($orderId, 0, 50) : null, gmdate('Y-m-d H:i:s'), $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the Vrio ids failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Store the Vrio order id from the checkout pre-authorization on the row. */
    public function setOrderId(int $id, string $orderId): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `vrio_order_id` = ?, `updated_at` = ? WHERE `id` = ?");
            $st->execute([mb_substr($orderId, 0, 50), gmdate('Y-m-d H:i:s'), $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the Vrio order id failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            if (!$this->enabled()) {
                throw new RuntimeException('Database is not configured.');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->db['host'], (int) ($this->db['port'] ?? 3306), $this->db['name']);
            try {
                $this->pdo = new PDO($dsn, (string) ($this->db['user'] ?? ''), (string) ($this->db['pass'] ?? ''), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 5,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
            }
        }
        return $this->pdo;
    }

    /** `table` from config, quoted. Only letters, digits, _ and - are allowed. */
    private function table(): string
    {
        $table = (string) ($this->db['table'] ?? 'dynamic_remedy');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $table)) {
            throw new RuntimeException('Invalid table name in config.php → db.table');
        }
        return '`' . $table . '`';
    }

    /** Stored quiz JSON as an array; an unreadable value is logged and replaced. */
    private static function decodeQuiz(string $json, int $id): array
    {
        if (trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            error_log('dynamic_remedy #' . $id . ': stored quiz is not a JSON object, it will be replaced.');
            return [];
        }
        return $data;
    }

    private static function phoneDigits($phone): string
    {
        $digits = preg_replace('/\D/', '', is_scalar($phone) ? (string) $phone : '');
        return strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;
    }

    private static function optional($value, int $max = 100): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
