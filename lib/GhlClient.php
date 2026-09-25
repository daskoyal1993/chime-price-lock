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

    private function request(string $method, string $path, array $payload): array
    {
        return Http::json($method, rtrim((string) $this->cfg['api_url'], '/') . $path, [
            'Authorization: Bearer ' . $this->cfg['token'],
            'Version: ' . ($this->cfg['version'] ?? '2021-07-28'),
        ], $payload, (int) ($this->cfg['timeout'] ?? 30));
    }
}
