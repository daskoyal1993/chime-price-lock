<?php
/**
 * Email / phone verification — same API as demo/xverify.php:
 *   GET https://api.xverify.com/v2/ev/?email=…&type=json&api_key=…&domain=…   → { "status": "valid" | "invalid" | … }
 *   GET https://api.xverify.com/v2/pv/?phone=…&type=json&api_key=…&domain=…
 * Called from submit.php only (the key never reaches the browser). Results are cached in the session per value.
 */
final class XverifyClient
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function enabled(): bool
    {
        return trim((string) ($this->cfg['api_key'] ?? '')) !== '';
    }

    /** @return string xverify "status" (e.g. valid, invalid, risky, unknown) */
    public function email(string $email): string
    {
        return $this->status('ev', 'email', $email);
    }

    public function phone(string $phone): string
    {
        return $this->status('pv', 'phone', $phone);
    }

    /** True when the status means "reject this value" (config.php → xverify.block_statuses). */
    public function blocks(string $status): bool
    {
        return in_array(strtolower($status), array_map('strtolower', $this->cfg['block_statuses'] ?? ['invalid']), true);
    }

    private function status(string $endpoint, string $param, string $value): string
    {
        $url = rtrim((string) ($this->cfg['api_url'] ?? 'https://api.xverify.com/v2'), '/') . '/' . $endpoint . '/?' . http_build_query([
            $param    => $value,
            'type'    => 'json',
            'api_key' => $this->cfg['api_key'],
            'domain'  => $this->cfg['domain'] ?? '',
        ]);
        [$status, $body, $raw, $error] = Http::json('GET', $url, [], null, (int) ($this->cfg['timeout'] ?? 10));

        if ($error !== '') {
            throw new RuntimeException($param . ' check failed: ' . $error);
        }
        if ($status < 200 || $status >= 300 || !isset($body['status'])) {
            throw new RuntimeException($param . ' check HTTP ' . $status . ': ' . Http::message($body, $raw));
        }
        return (string) $body['status'];
    }
}
