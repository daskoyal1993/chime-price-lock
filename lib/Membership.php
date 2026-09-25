<?php
/**
 * Does this email already have a membership? Decides which offer the visitor gets (offer_funnel()):
 *   member (true)      → membership_campaign_set  (funnel "membership_campaign" / "membership_products")
 *   not member (false) → campaign_set             (funnel "campaign" / "products")
 *
 * config.php → membership:
 *   api_url  empty = built-in sample (never a member for now, same answer as sample-api/membership-check.php);
 *            set    = POST {"email": "..."} to that URL; the answer is the response's "success" (true/false).
 *   fallback used when the API can't be reached or answers badly.
 * PLACEHOLDER: the real membership API isn't available yet — see NOTES-OPEN-ITEMS.md.
 */
final class Membership
{
    /**
     * @return array [bool is member, string how it was decided (for the log)]
     */
    public static function check(array $cfg, string $email): array
    {
        $url = trim((string) ($cfg['api_url'] ?? ''));
        if ($url === '') {
            return [self::sampleAnswer($email), 'built-in sample'];
        }

        $headers = [];
        if (!empty($cfg['api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $cfg['api_token'];
        }
        [$status, $body, $raw, $error] = Http::json('POST', $url, $headers, ['email' => $email], (int) ($cfg['timeout'] ?? 10));

        if ($error !== '') {
            throw new RuntimeException('request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300 || !is_array($body) || !array_key_exists('success', $body)) {
            throw new RuntimeException('HTTP ' . $status . ', unexpected answer: ' . mb_substr($raw, 0, 200));
        }
        return [filter_var($body['success'], FILTER_VALIDATE_BOOLEAN), $url];
    }

    /** Sample answer until the real API exists: false = no membership → campaign_set / products. */
    public static function sampleAnswer(string $email): bool
    {
        return false;
    }
}
