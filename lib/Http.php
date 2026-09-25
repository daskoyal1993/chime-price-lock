<?php
/**
 * Small JSON-over-HTTPS helper for the server-side API calls (Vrio, GHL).
 * SSL verification stays on; XAMPP uses the CA bundle set in php.ini (curl.cainfo).
 */
final class Http
{
    /**
     * @return array [status (int, 0 = no response), body (decoded array|null), raw (string), error (string)]
     */
    public static function json(string $method, string $url, array $headers, ?array $payload = null, int $timeout = 30): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = $raw === false ? curl_error($ch) : '';
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($raw === false && in_array($errno, [60, 77], true)) {
            $error .= ' (SSL CA bundle problem: check curl.cainfo / openssl.cafile in php.ini)';
        }
        $raw = $raw === false ? '' : (string) $raw;
        $body = json_decode($raw, true);

        return [$status, is_array($body) ? $body : null, $raw, $error];
    }

    /** First useful error message in an API response body. */
    public static function message(?array $body, string $raw): string
    {
        $msg = $body['error']['message'] ?? $body['message'] ?? $body['error'] ?? null;
        if (is_array($msg)) {
            $msg = implode('; ', array_map('strval', $msg));
        }
        return $msg !== null && $msg !== '' ? (string) $msg : mb_substr($raw, 0, 300);
    }
}
