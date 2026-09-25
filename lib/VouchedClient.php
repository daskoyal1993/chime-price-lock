<?php
/**
 * Vouched identity checks after the checkout payment — same calls as demo/identity_verify.php:
 *   POST {api_url}/identity/crosscheck   → result.confidences.identity (0–1, stored as a percentage)
 *   POST {api_url}/dob/verify            → result.dobMatch
 * Verified = crosscheck ≥ min_confidence (80) AND dobMatch = true; otherwise the visitor does the ID check (idcheck.php).
 * The private key (X-API-Key) comes from the funnel JSON (tenant.vouched) or config.php → vouched, never from the browser.
 */
final class VouchedClient
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function missing(): array
    {
        return trim((string) ($this->cfg['api_key'] ?? '')) === '' ? ['FUNNEL_VOUCHED_API_KEY (or tenant.vouched in the funnel JSON)'] : [];
    }

    /**
     * @param array $person first_name, last_name, email, phone, dob (Y-m-d), address1, city, state, zip, ip
     * @return array [verified, confidence %, dobMatch, identity job id, dob job id, log]
     * @throws RuntimeException when Vouched can't be reached
     */
    public function verify(array $person): array
    {
        $digits = preg_replace('/\D/', '', (string) $person['phone']);
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        $base = [
            'firstName' => $person['first_name'],
            'lastName'  => $person['last_name'],
            'phone'     => $digits !== '' ? '+1' . $digits : '',   // Vouched wants E.164
            'email'     => $person['email'],
            'address'   => [
                'streetAddress' => $person['address1'],
                'city'          => $person['city'],
                'state'         => $person['state'],
                'postalCode'    => $person['zip'],
                'country'       => 'US',
            ],
        ];

        [$identity, $identityRaw] = $this->post('/identity/crosscheck', $base + ['darkweb' => true, 'ipAddress' => (string) ($person['ip'] ?? '')]);
        $confidence = round((float) ($identity['result']['confidences']['identity'] ?? 0) * 100, 2);

        [$dob, $dobRaw] = $this->post('/dob/verify', $base + ['dob' => (string) $person['dob']]);
        $dobMatch = ($dob['result']['dobMatch'] ?? false) === true;

        $min = (float) ($this->cfg['min_confidence'] ?? 80);
        return [
            'verified'        => $confidence >= $min && $dobMatch,
            'confidence'      => $confidence,
            'dob_match'       => $dobMatch,
            'identity_job_id' => isset($identity['id']) ? (string) $identity['id'] : null,
            'dob_job_id'      => isset($dob['id']) ? (string) $dob['id'] : null,
            'log'             => 'crosscheck ' . $confidence . '% (need ' . $min . '), dobMatch ' . ($dobMatch ? 'true' : 'false')
                . (isset($identity['result']) ? '' : ' | crosscheck reply: ' . mb_substr($identityRaw, 0, 500))
                . (isset($dob['result']) ? '' : ' | dob reply: ' . mb_substr($dobRaw, 0, 500)),
        ];
    }

    private function post(string $path, array $payload): array
    {
        [$status, $body, $raw, $error] = Http::json('POST', rtrim((string) ($this->cfg['api_url'] ?? 'https://verify.vouched.id/api'), '/') . $path, [
            'X-API-Key: ' . $this->cfg['api_key'],
            'Accept: application/json',
        ], $payload, (int) ($this->cfg['timeout'] ?? 30));
        if ($error !== '') {
            throw new RuntimeException($path . ' failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($path . ' HTTP ' . $status . ': ' . Http::message($body, $raw));
        }
        return [is_array($body) ? $body : [], $raw];
    }
}
