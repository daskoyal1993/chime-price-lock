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

    /**
     * Checkout (like demo/db_update.php): the shipping address into its columns and the quiz JSON,
     * `purchased` = 1 once the card is authorized, and the Vrio order id.
     *
     * @param array $shipping first_name, last_name, address1, address2, city, state, zip, email, phone
     */
    public function saveCheckout(int $id, array $shipping, bool $purchased, ?string $orderId): void
    {
        $pdo   = $this->pdo();
        $table = $this->table();
        $address = trim($shipping['address1'] . ' ' . ($shipping['address2'] ?? ''));
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT `quiz` FROM {$table} WHERE `id` = ? FOR UPDATE");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('row #' . $id . ' not found');
            }
            // Same keys as the quiz's address questions, so the stored JSON reads the same as before.
            $quiz = array_merge(self::decodeQuiz((string) $row['quiz'], $id), array_filter([
                'address_line1'  => $shipping['address1'],
                'address_line2'  => $shipping['address2'] ?? '',
                'city'           => $shipping['city'],
                'zip'            => $shipping['zip'],
                'shipping_state' => $shipping['state'],
            ], static function ($v) { return $v !== ''; }));
            $json = json_encode($quiz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Quiz answers could not be encoded as JSON: ' . json_last_error_msg());
            }
            $st = $pdo->prepare("UPDATE {$table} SET `quiz` = :quiz, `address1` = :address1, `postalCode` = :zip, `city` = :city, `state` = :state,"
                . " `purchased` = GREATEST(COALESCE(`purchased`, 0), :purchased), `vrio_order_id` = COALESCE(:order_id, `vrio_order_id`), `updated_at` = :now WHERE `id` = :id");
            $st->execute([
                'quiz'      => $json,
                'address1'  => mb_substr($address, 0, 100),
                'zip'       => self::optional($shipping['zip']),
                'city'      => self::optional($shipping['city']),
                'state'     => self::optional($shipping['state']),
                'purchased' => $purchased ? 1 : 0,
                'order_id'  => $orderId !== null ? mb_substr($orderId, 0, 50) : null,
                'now'       => gmdate('Y-m-d H:i:s'),
                'id'        => $id,
            ]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Saving the checkout failed: ' . $e->getMessage(), 0, $e);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
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

    /**
     * True when an earlier row for this email already passed Vouched (same rule as demo/identity_verify.php):
     * ID check job + status 'true', crosscheck ≥ $minConfidence and a DOB match. Then Vouched isn't called again.
     */
    public function verifiedBefore(string $email, float $minConfidence = 80): bool
    {
        try {
            $st = $this->pdo()->prepare("SELECT `id` FROM {$this->table()} WHERE `email` = ?"
                . " AND `idcheck_vouched_job_id` IS NOT NULL AND `idcheck_vouched_job_id` <> '' AND `idcheck_vouched_status` = 'true'"
                . " AND CAST(`vouched_crosscheck_persent` AS DECIMAL(6,2)) >= ?"
                . " AND `vouched_dob_status` IS NOT NULL AND `vouched_dob_status` NOT IN ('', '0') ORDER BY `id` DESC LIMIT 1");
            $st->execute([strtolower(trim($email)), $minConfidence]);
            return (bool) $st->fetch();
        } catch (PDOException $e) {
            throw new RuntimeException('Reading the Vouched status failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Crosscheck + DOB results on the row (columns as in demo/identity_verify.php). */
    public function saveIdentity(int $id, array $result): void
    {
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `info_vouched_job_id` = ?, `info_vouched_status` = ?, `info_vouched_dob_id` = ?,"
                . " `vouched_crosscheck_persent` = ?, `vouched_dob_status` = ?, `updated_at` = ? WHERE `id` = ?");
            $st->execute([
                $result['identity_job_id'], $result['verified'] ? 'success' : 'failed', $result['dob_job_id'],
                (string) $result['confidence'], $result['dob_match'] ? '1' : '0', gmdate('Y-m-d H:i:s'), $id,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the Vouched result failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** ID check (idcheck.php) job id and, when known, its result 'true' / 'false' — like demo/idcheck-update.php. */
    public function saveIdcheck(int $id, string $jobId, ?bool $success): void
    {
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `idcheck_vouched_job_id` = ?, `idcheck_vouched_status` = COALESCE(?, `idcheck_vouched_status`), `updated_at` = ? WHERE `id` = ?");
            $st->execute([mb_substr($jobId, 0, 255), $success === null ? null : ($success ? 'true' : 'false'), gmdate('Y-m-d H:i:s'), $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the ID check failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Vouched's own callback (idcheck-callback.php): the result for a job id. Returns the number of rows updated. */
    public function saveIdcheckResult(string $jobId, bool $success): int
    {
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `idcheck_vouched_status` = ?, `updated_at` = ? WHERE `idcheck_vouched_job_id` = ?");
            $st->execute([$success ? 'true' : 'false', gmdate('Y-m-d H:i:s'), $jobId]);
            return $st->rowCount();
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the ID check result failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The whole row (process.php reads the address, Vouched results and order ids from it). */
    public function find(int $id): ?array
    {
        try {
            $st = $this->pdo()->prepare("SELECT * FROM {$this->table()} WHERE `id` = ?");
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (PDOException $e) {
            throw new RuntimeException('Reading the lead failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function setCustomerId(int $id, string $customerId): void
    {
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `customer_id` = ?, `updated_at` = ? WHERE `id` = ?");
            $st->execute([mb_substr($customerId, 0, 255), gmdate('Y-m-d H:i:s'), $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the customer id failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The exact payload sent to the portal's offer/process API (like demo/process/save_processed_payload.php). */
    public function saveProcessPayload(int $id, string $json): void
    {
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `portal_processed_payload` = ?, `updated_at` = ? WHERE `id` = ?");
            $st->execute([$json, gmdate('Y-m-d H:i:s'), $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the process payload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The portal's answer (like demo/process/mark_portal_processed.php): processed / portal_processed = 1 on success. */
    public function markProcessed(int $id, bool $success, string $rawResponse): void
    {
        $now = gmdate('Y-m-d H:i:s');
        try {
            $st = $this->pdo()->prepare("UPDATE {$this->table()} SET `processed` = ?, `portal_processed` = ?, `portal_process_response` = ?,"
                . " `processedtiming` = ?, `updated_at` = ? WHERE `id` = ?");
            $st->execute([$success ? 1 : 0, $success ? '1' : '0', self::jsonOrNull($rawResponse), $now, $now, $id]);
        } catch (PDOException $e) {
            throw new RuntimeException('Saving the process result failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The column is JSON on MySQL: store the reply as-is when it is JSON, else wrapped as {"raw": "..."}. */
    private static function jsonOrNull(string $raw): ?string
    {
        if (trim($raw) === '') return null;
        json_decode($raw);
        return json_last_error() === JSON_ERROR_NONE ? $raw : json_encode(['raw' => mb_substr($raw, 0, 5000)]);
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
