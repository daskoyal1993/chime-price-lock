<?php
/**
 * Payment hook used by submit.php?action=checkout.
 *
 * Drivers: vrio (card pre-authorization, lib/VrioClient.php → authorize(), same as demo/preauth.php), test, none. A driver receives the validated order and returns
 * ['success' => bool, 'order_id' => string|null, 'message' => string].
 * Card data is only held in memory for this request; never log or store it.
 */
final class Payment
{
    public static function charge(string $driver, array $order, array $config = []): array
    {
        switch ($driver) {
            case 'vrio':
                // Card pre-authorization in Vrio (lib/VrioClient.php → authorize(), like demo/preauth.php).
                // The campaign comes from the chosen plan (member → membership_campaign_set, else campaign_set).
                $vrio = new VrioClient(['campaign_id' => (string) ($order['product']['crm_campaign_id'] ?? '')] + ($config['vrio'] ?? []));
                $missing = $vrio->missing();
                if (trim((string) ($config['vrio']['shipping_profile_id'] ?? '')) === '') $missing[] = 'FUNNEL_VRIO_SHIPPING_PROFILE_ID';
                if ($missing) {
                    return ['success' => false, 'order_id' => null, 'message' => 'Payments are not set up yet. Please contact support to complete your order.', 'log' => 'not configured: set ' . implode(', ', $missing)];
                }
                return $vrio->authorize($order);

            case 'test':
                // LOCAL TESTING ONLY. Nothing is charged.
                $number = $order['card']['number'];
                if ($number === self::testCard($config)) {   // config.php → test_card
                    return ['success' => true, 'order_id' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))), 'message' => 'Test payment approved.'];
                }
                return ['success' => false, 'order_id' => null, 'message' => 'Your card was declined. Please check the details or use another card.'];

            case 'none':
            default:
                return ['success' => false, 'order_id' => null, 'message' => 'Payments are not set up yet. Please contact support to complete your order.'];
        }
    }

    /**
     * The test card (config.php → test_card), digits only; it skips the card-number checksum.
     * "test" approves it locally; "vrio" sends it to Vrio like demo/preauth.php (a Vrio test gateway accepts it,
     * a live one declines it). Set FUNNEL_TEST_CARD='' in .env to turn it off on a live site.
     */
    public static function testCard(array $config): string
    {
        return in_array($config['payment_driver'] ?? '', ['test', 'vrio'], true) ? preg_replace('/\D/', '', (string) ($config['test_card'] ?? '')) : '';
    }

    /** Luhn check for card numbers. */
    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) $n -= 9;
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $digits !== '' && $sum % 10 === 0;
    }
}
