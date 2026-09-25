<?php
/**
 * Vrio prospect creation — same request as demo/create-prospect.php:
 *   POST https://api.vrio.app/orders, header X-Api-Key,
 *   { connection_id, campaign_id, first_name, last_name, email, phone }
 *   → prospect id = data.customer_id ?? customer_id, order id = data.order_id ?? order_id
 * Credentials come from config.php → vrio (environment variables), never from the browser.
 */
final class VrioClient
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /** Missing settings, empty when ready. */
    public function missing(): array
    {
        $missing = [];
        foreach (['api_key' => 'FUNNEL_VRIO_API_KEY', 'connection_id' => 'FUNNEL_VRIO_CONNECTION_ID', 'campaign_id' => 'crm_campaign_id in the funnel campaign set'] as $key => $env) {
            if (trim((string) ($this->cfg[$key] ?? '')) === '') $missing[] = $env;
        }
        return $missing;
    }

    /**
     * Card pre-authorization at checkout — same request as demo/preauth.php:
     *   POST {api_url} { connection_id, campaign_id, offer_funnel_slug, crm_campaign_id, email, offers[], shipping_profile_id,
     *                    bill_*, tracking1-3, same_address, payment_method_id: 1, card_type_id, card_*, action: "authorize" }
     * A prepaid-card rejection is retried once on the prepaid campaign (when the funnel has one).
     *
     * @param array $order submit.php checkout order: product, billing, billing_same, email, card, tracking, funnel_slug
     * @return array [success, order_id, customer_id, message, log]  (log = what happened, card data never included)
     */
    public function authorize(array $order): array
    {
        $p = $order['product'];
        $b = $order['billing'];
        $card = $order['card'];
        $campaign = (string) $p['crm_campaign_id'];
        $cardType = self::cardTypeId($card['number']);
        if ($cardType === null) {
            return ['success' => false, 'order_id' => null, 'customer_id' => null, 'message' => 'We could not recognize your card type. Please check your card number.', 'log' => 'unknown card type'];
        }

        $body = [
            'connection_id'       => $this->cfg['connection_id'],
            'campaign_id'         => $campaign,
            'offer_funnel_slug'   => (string) ($order['funnel_slug'] ?? ''),
            'crm_campaign_id'     => $campaign,
            'email'               => $order['email'],
            'offers'              => [[
                'offer_id'             => (int) $p['crm_offer_id'],
                'item_id'              => (int) $p['crm_item_id'],
                'order_offer_quantity' => 1,
                'order_offer_price'    => (float) $p['price'],
            ]],
            'shipping_profile_id' => $this->cfg['shipping_profile_id'],
            'bill_fname'          => $b['first_name'],
            'bill_lname'          => $b['last_name'],
            'bill_address1'       => trim($b['address1'] . ' ' . ($b['address2'] ?? '')),
            'bill_city'           => $b['city'],
            'bill_state'          => $b['state'],
            'bill_country'        => 'US',
            'bill_zipcode'        => $b['zip'],
            'tracking1'           => (string) ($order['tracking']['affid'] ?? ''),
            'tracking2'           => (string) ($order['tracking']['sub1'] ?? $order['tracking']['c1'] ?? ''),
            'tracking3'           => (string) ($order['tracking']['click_id'] ?? ''),
            'same_address'        => true,   // as in demo/preauth.php (see NOTES-OPEN-ITEMS.md for a different billing address)
            'payment_method_id'   => 1,
            'card_type_id'        => $cardType,
            'card_number'         => $card['number'],
            'card_cvv'            => $card['cvv'],
            'card_exp_month'      => (int) $card['month'],
            'card_exp_year'       => (int) $card['year'],
            'action'              => 'authorize',
        ];

        $result = $this->sendAuthorize($body);
        $prepaid = (string) ($p['prepaid_crm_campaign_id'] ?? '');
        if ($result['prepaid'] && $prepaid !== '' && $prepaid !== $campaign) {
            $body['campaign_id'] = $body['crm_campaign_id'] = $prepaid;
            $retry = $this->sendAuthorize($body);
            $retry['log'] = $result['log'] . '; prepaid card → retried on campaign ' . $prepaid . ': ' . $retry['log'];
            $result = $retry;
        }
        unset($body);
        return $result;
    }

    /**
     * The customer id of an order (like demo/process/get_vrio_customer_id_using_order_id.php): GET {api_url}/{order_id}.
     * @throws RuntimeException when Vrio fails or the reply has no customer id
     */
    public function customerIdForOrder(string $orderId): string
    {
        [$status, $body, $raw, $error] = Http::json('GET', rtrim((string) $this->cfg['api_url'], '/') . '/' . rawurlencode($orderId), ['X-Api-Key: ' . $this->cfg['api_key']], null, (int) ($this->cfg['timeout'] ?? 30));
        if ($error !== '') throw new RuntimeException('order lookup failed: ' . $error);
        $id = is_array($body) ? ($body['customer_id'] ?? $body['order']['customer_id'] ?? $body['transaction']['customer_id'] ?? null) : null;
        if ($status !== 200 || empty($id)) {
            throw new RuntimeException('order lookup HTTP ' . $status . ': no customer_id (' . self::logBody($raw) . ')');
        }
        return (string) $id;
    }

    /** Vrio's raw reply for the log: one line, max 2000 chars, anything that looks like a card number masked. */
    private static function logBody(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        $raw = preg_replace('/\b\d{12,19}\b/', '[masked]', $raw);
        if ($raw === '') return '(empty)';
        return strlen($raw) > 2000 ? substr($raw, 0, 2000) . '…' : $raw;
    }

    private function sendAuthorize(array $body): array
    {
        [$status, $response, $raw, $error] = Http::json('POST', (string) $this->cfg['api_url'], ['X-Api-Key: ' . $this->cfg['api_key']], $body, (int) ($this->cfg['timeout'] ?? 30));
        // What was sent, for the log on a refusal — ids and offer only, no card or personal data.
        $sent = json_encode(array_intersect_key($body, array_flip(['connection_id', 'campaign_id', 'crm_campaign_id', 'offer_funnel_slug', 'offers', 'shipping_profile_id', 'card_type_id', 'bill_state', 'bill_country'])), JSON_UNESCAPED_SLASHES);
        $fail = static function (string $message, string $log, bool $prepaid = false) use ($sent): array {
            return ['success' => false, 'order_id' => null, 'customer_id' => null, 'message' => $message, 'log' => $log . ' sent=' . $sent, 'prepaid' => $prepaid];
        };
        if ($error !== '') {
            return $fail('We could not process your payment. Please try again.', 'request failed: ' . $error);
        }
        $response = is_array($response) ? $response : [];

        // Error shapes seen from Vrio (demo/preauth.php): {"error":{"code","message"}} or {"message":{...}} / {"message":"..."}.
        $errorBlock = is_array($response['error'] ?? null) ? $response['error'] : (is_array($response['message'] ?? null) ? $response['message'] : []);
        $errorCode  = (string) ($errorBlock['code'] ?? ($response['code'] ?? ''));
        $errorText  = (string) ($errorBlock['message'] ?? (is_string($response['message'] ?? null) ? $response['message'] : ''));
        $prepaid    = stripos($errorCode, 'prepaid') !== false || stripos($errorText, 'prepaid') !== false;

        $rawMessage = $response['message'] ?? null;
        $decline = is_array($rawMessage) ? ($rawMessage['message'] ?? null) : $rawMessage;
        $decline = $decline ?? (is_string($response['error'] ?? null) ? $response['error'] : ($errorBlock['message'] ?? null))
            ?? (is_array($response['errors'] ?? null) ? implode(' ', array_map('strval', $response['errors'])) : null);

        $transaction  = (is_array($rawMessage) ? ($rawMessage['transaction'] ?? null) : null) ?? ($response['transaction'] ?? []);
        $orderId      = $response['order_id'] ?? ($transaction['order_id'] ?? null);
        $customerId   = $response['customer_id'] ?? ($transaction['customer_id'] ?? null);
        $responseCode = $response['response_code'] ?? ($transaction['response_code'] ?? null);
        $log = 'HTTP ' . $status . ($errorCode !== '' ? ' code=' . $errorCode : '') . ($decline ? ' message=' . $decline : '') . ($orderId ? ' order_id=' . $orderId : '');

        if ($status !== 200 || empty($orderId) || $decline) {
            return $fail($decline ?: 'We could not process your payment. Please try again.', $log . ' response=' . self::logBody($raw), $prepaid);
        }
        if (!empty($responseCode) && (int) $responseCode === 101) {
            return $fail('Additional verification is required for this card. Please try a different card or contact support.', $log . ' response_code=101 response=' . self::logBody($raw));
        }
        return ['success' => true, 'order_id' => (string) $orderId, 'customer_id' => $customerId !== null ? (string) $customerId : null, 'message' => 'Card authorized.', 'log' => $log, 'prepaid' => false];
    }

    /** Vrio card_type_id, as in demo/preauth.php: 1 Mastercard, 2 Visa, 3 Discover, 4 Amex. */
    public static function cardTypeId(string $number): ?int
    {
        if (preg_match('/^3[47]/', $number)) return 4;
        if (preg_match('/^6(?:011|5)/', $number)) return 3;
        if (preg_match('/^5[1-5]/', $number)) return 1;
        if (preg_match('/^4/', $number)) return 2;
        return null;
    }

    /**
     * @return array [prospect_id, order_id]
     * @throws RuntimeException with the HTTP status / Vrio message on failure
     */
    public function createProspect(string $firstName, string $lastName, string $email, string $phone): array
    {
        [$status, $body, $raw, $error] = Http::json('POST', (string) $this->cfg['api_url'], ['X-Api-Key: ' . $this->cfg['api_key']], [
            'connection_id' => $this->cfg['connection_id'],
            'campaign_id'   => $this->cfg['campaign_id'],
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'email'         => $email,
            'phone'         => $phone,
        ], (int) ($this->cfg['timeout'] ?? 30));

        if ($error !== '') {
            throw new RuntimeException('request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status . ': ' . Http::message($body, $raw));
        }

        $prospectId = $body['data']['customer_id'] ?? $body['customer_id'] ?? null;
        $orderId    = $body['data']['order_id'] ?? $body['order_id'] ?? null;
        if ($prospectId === null || $prospectId === '') {
            throw new RuntimeException('HTTP ' . $status . ' but no customer_id in the response: ' . mb_substr($raw, 0, 300));
        }
        return [(string) $prospectId, $orderId !== null ? (string) $orderId : null];
    }
}
