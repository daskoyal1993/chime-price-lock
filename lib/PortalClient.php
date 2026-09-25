<?php
/**
 * Order hand-off to the patient portal after identity verification (like demo/process/process.php):
 *   POST {api_url}   X-API-Key: {api_key}   { "data": EncodeDecodeHelper::encodeWithToken(payload, tenant secret) }
 *   → { "success": true, "is_new_user": bool, "redirect_url": "https://{tenant}/redirect?data=…" }
 * Payload fields: see the "Parameter Reference" sheet (first_name … products[], answers{}, vouched_*).
 */
final class PortalClient
{
    /** @var array */
    private $cfg;
    /** @var string */
    private $secretKey;

    public function __construct(array $cfg, string $secretKey)
    {
        $this->cfg = $cfg;
        $this->secretKey = $secretKey;
    }

    public function missing(): array
    {
        $missing = [];
        if (trim((string) ($this->cfg['api_url'] ?? '')) === '') $missing[] = 'FUNNEL_PORTAL_API_URL';
        if (trim((string) ($this->cfg['api_key'] ?? '')) === '') $missing[] = 'FUNNEL_PORTAL_API_KEY';
        if ($this->secretKey === '') $missing[] = 'FUNNEL_TENANT_SECRET_KEY';
        return $missing;
    }

    /**
     * @return array [success, redirect_url, message, raw (response body), status]
     */
    public function process(array $payload): array
    {
        $token = EncodeDecodeHelper::encodeWithToken($payload, $this->secretKey);
        [$status, $body, $raw, $error] = Http::json('POST', (string) $this->cfg['api_url'], ['X-API-Key: ' . $this->cfg['api_key']], ['data' => $token], (int) ($this->cfg['timeout'] ?? 30));
        if ($error !== '') {
            return ['success' => false, 'redirect_url' => null, 'message' => 'request failed: ' . $error, 'raw' => '', 'status' => 0];
        }
        $ok = is_array($body) && !empty($body['success']);
        return [
            'success'      => $ok,
            'redirect_url' => $ok && !empty($body['redirect_url']) ? (string) $body['redirect_url'] : null,
            'message'      => $ok ? 'processed' . (isset($body['is_new_user']) ? ($body['is_new_user'] ? ' (new portal user)' : ' (existing portal user)') : '')
                                  : 'HTTP ' . $status . ': ' . Http::message($body, $raw),
            'raw'          => $raw,
            'status'       => $status,
        ];
    }
}
