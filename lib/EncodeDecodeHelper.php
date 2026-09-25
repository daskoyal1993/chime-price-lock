<?php
/**
 * Signed tokens used in the funnel JSON (tenant.ghl.credentials, tenant.vouched):
 *   base64(JSON) . "." . hmac_sha256(base64, tenant secret key)
 * The secret key is the portal's Site Settings → Secret Key (config.php → tenant_secret_key / FUNNEL_TENANT_SECRET_KEY).
 * A token whose signature doesn't match the key is rejected.
 */
final class EncodeDecodeHelper
{
    public static function encodeWithToken(array $data, string $secretKey): string
    {
        $base64 = base64_encode((string) json_encode($data));
        return $base64 . '.' . hash_hmac('sha256', $base64, $secretKey);
    }

    /** @return array|false the decoded data, or false when the token is malformed or not signed with $secretKey */
    public static function decodeWithToken(string $token, string $secretKey)
    {
        if ($secretKey === '' || strpos($token, '.') === false) return false;
        [$base64, $signature] = explode('.', $token, 2);
        if (!hash_equals(hash_hmac('sha256', $base64, $secretKey), $signature)) return false;
        $data = json_decode((string) base64_decode($base64, true), true);
        return is_array($data) ? $data : false;
    }
}
