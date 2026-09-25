<?php
/**
 * What happens when the visitor's contact details (and later answers) are submitted:
 *
 *   0. MEMBERSHIP  does the email have a membership?      (Membership) → which campaign set / products (offer_funnel())
 *   1. DB    save / update the dynamic_remedy row          (LeadRepository)
 *   2. VRIO  create the prospect once per visitor          (VrioClient, like demo/create-prospect.php)
 *   3. GHL   create the contact, then keep it up to date   (GhlClient, like demo/sample-create-contact.php)
 *
 * Each step runs on its own: a failure is logged with its name ([DB], [VRIO], [GHL]) and the next step still runs.
 * Nothing here is sent to the browser. Log: storage/logs/lead-flow.log (config.php → lead_log).
 *
 * Session record keys used: has_membership, membership_email, lead_id, vrio_prospect_id, vrio_order_id, ghl_contact_id, consents.
 */
final class LeadFlow
{
    /**
     * @param array $funnel   normalized funnel (question GHL field ids, campaign)
     * @param array $record   the visitor's session record (updated in place)
     * @param array $answers  valid answers keyed by question key
     * @param array $tracking prospect_id / affid / c1 from the landing URL
     * @param array $remove   question keys to drop from the stored quiz JSON
     * @return array ['db' => bool|null, 'vrio' => bool|null, 'ghl' => bool|null]  (null = skipped / not configured)
     */
    public static function submit(array $config, array $funnel, array &$record, array $answers, array $tracking, array $remove = []): array
    {
        $status = ['db' => null, 'vrio' => null, 'ghl' => null];
        if (LeadRepository::validateContact($answers)) {
            return $status;   // no complete name / email / phone yet
        }

        $answers = self::withConsentTimes($config, $record, $answers);
        $email   = strtolower(trim((string) $answers['email']));
        $phone   = preg_replace('/\D/', '', (string) $answers['phone']);
        if (strlen($phone) === 11 && $phone[0] === '1') $phone = substr($phone, 1);

        // 0. Membership (once per email) — decides the campaign set used for Vrio and the product page
        self::membership($config, $record, $email);

        // 1. Database
        $repo = new LeadRepository($config['db'] ?? []);
        if ($repo->enabled()) {
            try {
                $isNew = empty($record['lead_id']);
                $record['lead_id'] = $repo->save($record['lead_id'] ?? null, $answers, $tracking, $remove);
                $status['db'] = true;
                self::log($config, 'DB', true, 'row #' . $record['lead_id'] . ($isNew ? ' saved' : ' updated') . ' for ' . $email);
            } catch (Throwable $e) {
                $status['db'] = false;
                self::log($config, 'DB', false, $e->getMessage());
            }
        } else {
            self::log($config, 'DB', null, 'skipped: FUNNEL_DB_HOST / FUNNEL_DB_NAME not set');
        }

        // 2. Vrio prospect (once per visitor)
        $status['vrio'] = self::vrio($config, $funnel, $record, $repo, $answers, $email, $phone, $tracking);

        // 3. GHL contact
        $status['ghl'] = self::ghl($config, $funnel, $record, $answers, $email, $phone);

        return $status;
    }

    /**
     * After the checkout payment attempt (like demo/db_update.php):
     *   1. DB   shipping address, purchased (when authorized) and the Vrio order id on the visitor's row
     *   2. GHL  PUT the contact with the shipping address (and the checkout's email / phone)
     * Each step is logged on its own and never changes the payment result.
     *
     * @param array $shipping first_name, last_name, address1, address2, city, state, zip, email, phone
     * @param array $payment  Payment::charge() result
     */
    public static function checkout(array $config, array &$record, array $shipping, array $payment): void
    {
        $purchased = !empty($payment['success']);
        $orderId   = $purchased ? (string) $payment['order_id'] : null;

        $repo = new LeadRepository($config['db'] ?? []);
        if (!$repo->enabled()) {
            self::log($config, 'DB', null, 'checkout skipped: FUNNEL_DB_HOST / FUNNEL_DB_NAME not set');
        } elseif (empty($record['lead_id'])) {
            self::log($config, 'DB', null, 'checkout skipped: no saved lead in this session for ' . $shipping['email']);
        } else {
            try {
                $repo->saveCheckout((int) $record['lead_id'], $shipping, $purchased, $orderId);
                self::log($config, 'DB', true, 'row #' . $record['lead_id'] . ' checkout: address saved, purchased = ' . ($purchased ? 1 : 0) . ($orderId ? ', vrio_order_id = ' . $orderId : ''));
            } catch (Throwable $e) {
                self::log($config, 'DB', false, 'checkout: ' . $e->getMessage());
            }
        }

        $cfg    = $config['ghl'] ?? [];
        $client = new GhlClient($cfg);
        if ($missing = $client->missing()) {
            self::log($config, 'GHL', null, 'checkout skipped: set ' . implode(', ', $missing));
            return;
        }
        $contact = array_filter([
            'firstName'  => $shipping['first_name'],
            'lastName'   => $shipping['last_name'],
            'name'       => trim($shipping['first_name'] . ' ' . $shipping['last_name']),
            'email'      => strtolower($shipping['email']),
            'phone'      => $shipping['phone'],
            'address1'   => trim($shipping['address1'] . ' ' . ($shipping['address2'] ?? '')),
            'city'       => $shipping['city'],
            'state'      => $shipping['state'],
            'postalCode' => $shipping['zip'],
            'country'    => 'US',
            'source'     => (string) ($cfg['source'] ?? ''),
        ], static function ($v) { return $v !== ''; });
        try {
            [$id, $action] = $client->save($record['ghl_contact_id'] ?? null, $contact);
            $record['ghl_contact_id'] = $id;
            self::log($config, 'GHL', true, 'contact ' . $id . ' ' . $action . ' at checkout (address' . ($purchased ? ', purchased' : '') . ') for ' . $contact['email']);
        } catch (Throwable $e) {
            self::log($config, 'GHL', false, 'checkout: ' . $e->getMessage());
        }
    }

    private static function membership(array $config, array &$record, string $email): void
    {
        if (isset($record['has_membership']) && ($record['membership_email'] ?? null) === $email) {
            return;   // already checked for this email
        }
        try {
            [$member, $source] = Membership::check($config['membership'] ?? [], $email);
            self::log($config, 'MEMBERSHIP', true, $email . ' → ' . ($member ? 'member (membership_campaign_set)' : 'not a member (campaign_set)') . ' via ' . $source);
        } catch (Throwable $e) {
            $member = (bool) ($config['membership']['fallback'] ?? false);
            self::log($config, 'MEMBERSHIP', false, $e->getMessage() . ' — using fallback: ' . ($member ? 'member' : 'not a member'));
        }
        if (isset($record['has_membership']) && $record['has_membership'] !== $member) {
            $record['product'] = null;   // the email changed to one with a different offer: the chosen plan no longer applies
        }
        $record['has_membership']   = $member;
        $record['membership_email'] = $email;
    }

    private static function vrio(array $config, array $funnel, array &$record, LeadRepository $repo, array $answers, string $email, string $phone, array $tracking): ?bool
    {
        if (!empty($record['vrio_prospect_id'])) {
            return null;   // already created for this visitor
        }
        if (!empty($tracking['prospect_id'])) {
            $record['vrio_prospect_id'] = (string) $tracking['prospect_id'];
            self::log($config, 'VRIO', null, 'skipped: prospect_id ' . $record['vrio_prospect_id'] . ' came with the landing URL');
            return null;
        }
        // A row found by email may already have a prospect from an earlier visit.
        if (!empty($record['lead_id']) && $repo->enabled()) {
            try {
                $ids = $repo->crmIds((int) $record['lead_id']);
                if ($ids && trim((string) $ids[0]) !== '') {
                    $record['vrio_prospect_id'] = (string) $ids[0];
                    $record['vrio_order_id']    = $ids[1];
                    self::log($config, 'VRIO', null, 'skipped: row #' . $record['lead_id'] . ' already has prospect ' . $ids[0]);
                    return null;
                }
            } catch (Throwable $e) {
                self::log($config, 'DB', false, $e->getMessage());
            }
        }

        // member → membership_campaign_set, not a member → campaign_set
        $cfg = $config['vrio'] ?? [];
        $cfg['campaign_id'] = (string) (offer_funnel($funnel, $record, $config)['campaign']['crm_campaign_id'] ?? '');
        $client = new VrioClient($cfg);
        if ($missing = $client->missing()) {
            self::log($config, 'VRIO', null, 'skipped: set ' . implode(', ', $missing));
            return null;
        }

        try {
            [$prospectId, $orderId] = $client->createProspect(trim((string) $answers['first_name']), trim((string) $answers['last_name']), $email, $phone);
            $record['vrio_prospect_id'] = $prospectId;
            $record['vrio_order_id']    = $orderId;
            self::log($config, 'VRIO', true, 'prospect ' . $prospectId . ($orderId ? ', order ' . $orderId : '') . ' created (campaign ' . $cfg['campaign_id'] . ') for ' . $email);
        } catch (Throwable $e) {
            self::log($config, 'VRIO', false, $e->getMessage());
            return false;
        }

        if (!empty($record['lead_id']) && $repo->enabled()) {
            try {
                $repo->setCrmIds((int) $record['lead_id'], $record['vrio_prospect_id'], $record['vrio_order_id']);
            } catch (Throwable $e) {
                self::log($config, 'DB', false, $e->getMessage());
            }
        }
        return true;
    }

    private static function ghl(array $config, array $funnel, array &$record, array $answers, string $email, string $phone): ?bool
    {
        $cfg    = $config['ghl'] ?? [];
        $client = new GhlClient($cfg);
        if ($missing = $client->missing()) {
            self::log($config, 'GHL', null, 'skipped: set ' . implode(', ', $missing));
            return null;
        }

        $first   = trim((string) $answers['first_name']);
        $last    = trim((string) $answers['last_name']);
        $contact = array_filter([
            'firstName'   => $first,
            'lastName'    => $last,
            'name'        => trim($first . ' ' . $last),
            'email'       => $email,
            'phone'       => $phone,
            'source'      => (string) ($cfg['source'] ?? ''),
            'dateOfBirth' => (string) ($answers['dob'] ?? ''),
            'state'       => (string) ($answers['shipping_state'] ?? ''),
        ], static function ($v) { return $v !== ''; });
        $contact['tags']         = array_values($cfg['tags'] ?? []);
        $contact['customFields'] = self::customFields($cfg, $funnel, $answers);

        try {
            [$id, $action] = $client->save($record['ghl_contact_id'] ?? null, $contact);
            $record['ghl_contact_id'] = $id;
            self::log($config, 'GHL', true, 'contact ' . $id . ' ' . $action . ' (' . count($contact['customFields']) . ' custom fields) for ' . $email);
            return true;
        } catch (Throwable $e) {
            self::log($config, 'GHL', false, $e->getMessage());
            return false;
        }
    }

    /**
     * GHL custom fields: the field id from the funnel API (question → ghl.ghl_custom_id), or config.php → ghl.field_map
     * for questions the API has no id for. Only answered fields are sent, so an update never clears a field.
     */
    private static function customFields(array $cfg, array $funnel, array $answers): array
    {
        $map = [];
        foreach ($funnel['steps'] as $step) {
            foreach ($step['questions'] as $q) {
                if (!empty($q['ghl']['id'])) {
                    // A consent question mapped to a "date_of_…" / "time_of_…" field gets the time it was given, not its text.
                    $suffix = preg_match('/\.date_of_/', $q['ghl']['key']) ? '@date' : (preg_match('/\.time_of_/', $q['ghl']['key']) ? '@time' : '');
                    $map[$q['key'] . $suffix] = ['id' => $q['ghl']['id'], 'key' => $q['ghl']['key']];
                }
            }
        }
        foreach ($cfg['field_map'] ?? [] as $key => $field) {
            if (!isset($map[$key])) $map[$key] = $field;
        }

        $fields = [];
        foreach ($map as $key => $field) {
            $value = self::fieldValue($key, $answers);
            if ($value !== '') {
                $fields[] = ['id' => $field['id'], 'key' => $field['key'], 'fieldValue' => $value];
            }
        }
        return $fields;
    }

    /** Answer as the text GHL expects (as in the sample: lists joined with ", ", height as 5'6"). */
    private static function fieldValue(string $key, array $answers): string
    {
        // "<question>@date" / "<question>@time" = when that consent was given (see withConsentTimes()).
        if (preg_match('/^(.+)@(date|time)$/', $key, $m)) {
            return (string) ($answers['consent_timestamps'][$m[1]][$m[2]] ?? '');
        }
        $value = $answers[$key] ?? '';
        if (is_array($value) && array_key_exists('feet', $value)) {
            return is_numeric($value['feet']) && is_numeric($value['inches']) ? (int) $value['feet'] . "'" . (int) $value['inches'] . '"' : '';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        return trim((string) $value);
    }

    /**
     * Server time of the first save where each consent checkbox was ticked (config.php → consent_questions),
     * kept in the session and stored in the quiz JSON as consent_timestamps (like the sample).
     */
    private static function withConsentTimes(array $config, array &$record, array $answers): array
    {
        $tz = new DateTimeZone((string) ($config['ghl']['consent_timezone'] ?? 'UTC'));
        foreach ($config['ghl']['consent_questions'] ?? [] as $key) {
            if (!empty($answers[$key]) && empty($record['consents'][$key])) {
                $now = new DateTimeImmutable('now', $tz);
                // Same format as the live site's server-time.php: "September 24, 2026" / "2:49 PM UTC".
                $record['consents'][$key] = [
                    'date' => $now->format((string) ($config['ghl']['consent_date_format'] ?? 'F j, Y')),
                    'time' => $now->format((string) ($config['ghl']['consent_time_format'] ?? 'g:i A T')),
                ];
            }
        }
        if (!empty($record['consents'])) {
            $answers['consent_timestamps'] = $record['consents'];
        }
        return $answers;
    }

    /** One line per step: "2026-09-24 12:00:00 [GHL] OK contact abc created …". Also sent to the PHP error log on failure. */
    public static function log(array $config, string $service, ?bool $ok, string $message): void
    {
        $line = gmdate('Y-m-d H:i:s') . ' UTC [' . $service . '] ' . ($ok === null ? 'SKIP' : ($ok ? 'OK' : 'FAILED')) . ' ' . $message;
        if ($ok === false) {
            error_log('[lead-flow] ' . $line);
        }
        $file = (string) ($config['lead_log'] ?? '');
        if ($file !== '') {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
