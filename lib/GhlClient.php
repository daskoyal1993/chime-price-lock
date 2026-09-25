<?php
/**
 * GoHighLevel contact create/update — same API as demo/sample-create-contact.php:
 *   POST https://services.leadconnectorhq.com/contacts/   (Bearer token, Version: 2021-07-28)
 *   400 "duplicated contacts" → the existing id is in meta.contactId and is reused.
 * Updates use PUT /contacts/{id} (without locationId and tags, so existing tags are kept).
 * The token comes from config.php → ghl (environment variable), never from the browser.
 */
final class GhlClient
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function missing(): array
    {
        $missing = [];
        if (trim((string) ($this->cfg['token'] ?? '')) === '') $missing[] = 'FUNNEL_GHL_TOKEN';
        if (trim((string) ($this->cfg['location_id'] ?? '')) === '') $missing[] = 'FUNNEL_GHL_LOCATION_ID';
        return $missing;
    }

    /**
     * Create the contact, or update it when $contactId is known or GHL reports a duplicate.
     *
     * @return array [contact id, 'created' | 'updated' | 'updated existing']
     * @throws RuntimeException on failure
     */
    public function save(?string $contactId, array $contact): array
    {
        if ($contactId) {
            try {
                $this->update($contactId, $contact);
                return [$contactId, 'updated'];
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'HTTP 404') !== 0 && strpos($e->getMessage(), 'HTTP 400') !== 0) {
                    throw $e;
                }
                // The stored id is no longer valid: create again below.
            }
        }

        [$status, $body, $raw, $error] = $this->request('POST', '/contacts/', $contact + ['locationId' => $this->cfg['location_id']]);
        if ($error !== '') {
            throw new RuntimeException('create failed: ' . $error);
        }
        if (($status === 200 || $status === 201) && isset($body['contact']['id'])) {
            return [(string) $body['contact']['id'], 'created'];
        }
        if ($status === 400 && isset($body['meta']['contactId'])) {
            $existing = (string) $body['meta']['contactId'];
            $this->update($existing, $contact);
            return [$existing, 'updated existing'];
        }
        throw new RuntimeException('create HTTP ' . $status . ': ' . Http::message($body, $raw));
    }

    /**
     * The location's custom field ids by field key ("contact.date_of_bmi_consent" => "Xs6j…"), like demo/check-custom-field.php:
     *   GET /locations/{location_id}/customFields
     * Cached in $cacheFile for $ttl seconds; an old cache is used when GHL can't be reached.
     *
     * @throws RuntimeException when GHL fails and there is no cache
     */
    public function fieldIds(string $cacheFile, int $ttl = 86400): array
    {
        if (is_file($cacheFile) && filemtime($cacheFile) + $ttl > time()) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
        [$status, $body, $raw, $error] = $this->request('GET', '/locations/' . rawurlencode((string) $this->cfg['location_id']) . '/customFields', null);
        if ($error === '' && $status === 200 && is_array($body['customFields'] ?? null)) {
            $ids = [];
            foreach ($body['customFields'] as $field) {
                if (!empty($field['fieldKey']) && !empty($field['id'])) $ids[(string) $field['fieldKey']] = (string) $field['id'];
            }
            if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0775, true);
            @file_put_contents($cacheFile, json_encode($ids), LOCK_EX);
            return $ids;
        }
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cached)) return $cached;
        throw new RuntimeException('custom fields HTTP ' . $status . ': ' . ($error !== '' ? $error : Http::message($body, $raw)));
    }

    private function update(string $id, array $contact): void
    {
        unset($contact['locationId'], $contact['tags']);
        [$status, $body, $raw, $error] = $this->request('PUT', '/contacts/' . rawurlencode($id), $contact);
        if ($error !== '') {
            throw new RuntimeException('update failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status . ' on update of ' . $id . ': ' . Http::message($body, $raw));
        }
    }

    private function request(string $method, string $path, ?array $payload): array
    {
        return Http::json($method, rtrim((string) $this->cfg['api_url'], '/') . $path, [
            'Authorization: Bearer ' . $this->cfg['token'],
            'Version: ' . ($this->cfg['version'] ?? '2021-07-28'),
        ], $payload, (int) ($this->cfg['timeout'] ?? 30));
    }
}
