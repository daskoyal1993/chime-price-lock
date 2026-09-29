<?php
/**
 * Loads the funnel definition from the API (or the local sample) and normalizes it
 * into the shape the front end and FunnelRules expect.
 */
require_once __DIR__ . '/EncodeDecodeHelper.php';

final class FunnelRepository
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array Normalized funnel (see normalize()).
     * @throws RuntimeException when no usable definition can be loaded.
     */
    public function load(): array
    {
        $response = $this->config['api_url'] === '' ? $this->readSample() : $this->fetchRemote();

        if (empty($response['success']) || !isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException('Funnel API returned no data.');
        }

        return self::normalize($response['data'], $this->config['questionnaire_slug'] ?? null, (string) ($this->config['tenant_secret_key'] ?? ''));
    }

    private function readSample(): array
    {
        $file = $this->config['sample_file'];
        if (!is_file($file)) {
            throw new RuntimeException('Sample funnel file not found: ' . basename($file));
        }
        return self::decode((string) file_get_contents($file));
    }

    /**
     * GET the API with a short file cache. If the API is down, a stale cached copy is used.
     */
    private function fetchRemote(): array
    {
        $cacheDir  = rtrim($this->config['storage_dir'], '/\\') . '/cache';
        $cacheFile = $cacheDir . '/funnel-' . md5($this->config['api_url']) . '.json';
        $ttl       = (int) $this->config['cache_ttl'];

        if ($ttl > 0 && is_file($cacheFile) && filemtime($cacheFile) + $ttl > time()) {
            return self::decode((string) file_get_contents($cacheFile));
        }

        try {
            $body = $this->httpGet($this->config['api_url']);
            $data = self::decode($body);
        } catch (RuntimeException $e) {
            if (is_file($cacheFile)) {
                error_log('Funnel API failed, using stale cache: ' . $e->getMessage());
                if (class_exists('ErrorLogger', false)) ErrorLogger::log($e, 'FUNNEL_ERROR', ['note' => 'using the stale cached copy']);
                return self::decode((string) file_get_contents($cacheFile));
            }
            throw $e;
        }

        if ($ttl > 0 && !empty($data['success'])) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            @file_put_contents($cacheFile, $body, LOCK_EX);
        }

        return $data;
    }

    private function httpGet(string $url): string
    {
        $headers = ['Accept: application/json'];
        if ($this->config['api_token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->config['api_token'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => (int) $this->config['api_timeout'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Funnel API request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Funnel API responded with HTTP ' . $status);
        }
        return (string) $body;
    }

    private static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            if (class_exists('ErrorLogger', false)) ErrorLogger::jsonError('Funnel data (API or sample file)', json_last_error_msg(), $json);
            throw new RuntimeException('Funnel API returned invalid JSON.');
        }
        return $data;
    }

    /**
     * Turn the raw API "data" object into:
     * [
     *   id, name, slug,
     *   campaign => [id, crm_campaign_id],
     *   form     => [id, name, slug, form_type],
     *   steps    => [[id, number, title, questions => [question, ...]], ...],
     *   products => [[id, name, variants => [[id, name, form, typical_duration, display_price, offer, available], ...]], ...],
     * ]
     * Steps are sorted by step number, questions by position, and duplicate conditions
     * (the API repeats each condition once per option) are removed.
     */
    public static function normalize(array $data, ?string $questionnaireSlug = null, string $secretKey = ''): array
    {
        $sets = $data['questionnaire_sets'] ?? [];
        $set  = null;
        foreach ($sets as $candidate) {
            if ($questionnaireSlug === null || ($candidate['slug'] ?? null) === $questionnaireSlug) {
                $set = $candidate;
                break;
            }
        }
        if ($set === null) {
            throw new RuntimeException('Questionnaire set "' . $questionnaireSlug . '" not found in the funnel.');
        }

        $mainCampaign = $data['campaign_set']['main_campaign'] ?? null;
        $campaignId   = $mainCampaign['id'] ?? null;

        $steps = [];
        foreach ($set['steps'] ?? [] as $step) {
            $questions = array_map([self::class, 'normalizeQuestion'], $step['questions'] ?? []);
            usort($questions, static function ($a, $b) {
                return $a['position'] <=> $b['position'];
            });
            $number  = (int) ($step['step'] ?? count($steps) + 1);
            $steps[] = [
                'id'        => $step['id'] ?? null,
                'number'    => $number,
                'title'     => trim((string) ($step['name'] ?? '')),
                'questions' => $questions,
            ];
        }
        usort($steps, static function ($a, $b) {
            return $a['number'] <=> $b['number'];
        });

        // Each variant has an offer per campaign; the prepaid one is used when Vrio refuses a prepaid card (lib/VrioClient.php).
        $products = self::normalizeProducts($data['products'] ?? [], $campaignId, $data['campaign_set']['prepaid_campaign']['id'] ?? null);

        // Offer for visitors without a membership (see offer_funnel() in bootstrap.php).
        $memberCampaign = $data['membership_campaign_set']['main_campaign'] ?? null;
        $memberProducts = self::normalizeProducts($data['membership_campaign_set_products'] ?? [], $memberCampaign['id'] ?? null,
            $data['membership_campaign_set']['prepaid_campaign']['id'] ?? null);

        return [
            'id'       => $data['id'] ?? null,
            'name'     => (string) ($data['name'] ?? ''),
            'slug'     => (string) ($data['slug'] ?? 'funnel'),
            'campaign' => [
                'id'              => $campaignId,
                'crm_campaign_id' => $mainCampaign['crm_campaign_id'] ?? null,
                // Vrio retries a prepaid card on this campaign (demo/preauth.php); null when the set has none.
                'prepaid_crm_campaign_id' => $data['campaign_set']['prepaid_campaign']['crm_campaign_id'] ?? null,
            ],
            'form' => [
                'id'        => $set['id'] ?? null,
                'name'      => (string) ($set['name'] ?? ''),
                'slug'      => (string) ($set['slug'] ?? ''),
                'form_type' => $set['form_type'] ?? 'step_form',
            ],
            'steps'    => $steps,
            'products' => $products,
            'membership_campaign' => [
                'id'              => $memberCampaign['id'] ?? null,
                'crm_campaign_id' => $memberCampaign['crm_campaign_id'] ?? null,
                'prepaid_crm_campaign_id' => $data['membership_campaign_set']['prepaid_campaign']['crm_campaign_id'] ?? null,
            ],
            'membership_products' => $memberProducts,
            // Signed credentials from the tenant (lib/EncodeDecodeHelper.php), server side only — index.php removes them
            // before output. Empty when config.php → tenant_secret_key is set and the signature doesn't match it.
            'ghl'     => self::credentials($data['tenant']['ghl']['credentials'] ?? '', $secretKey, ['api_key' => 'token', 'location_id' => 'location_id']),
            'vouched' => self::credentials($data['tenant']['vouched'] ?? '', $secretKey, ['app_secret' => 'api_key', 'app_id' => 'app_id']),
            // GHL custom field that gets the patient portal link after process.php (tenant.ghl.portal_new_custom_field / _key).
            'ghl_portal_field' => [
                'id'  => (string) ($data['tenant']['ghl']['portal_new_custom_field'] ?? ''),
                'key' => (string) ($data['tenant']['ghl']['portal_new_custom_field_key'] ?? ''),
            ],
        ];
    }

    /**
     * Decode one signed token ({"api_key", "location_id"} for GHL, {"app_id", "app_secret"} for Vouched)
     * and rename its fields with $names. Only the values that are present are returned.
     */
    private static function credentials($token, string $secretKey, array $names): array
    {
        $data = is_string($token) ? EncodeDecodeHelper::decodeWithToken($token, $secretKey) : false;
        if ($data === false) {
            if (is_string($token) && $token !== '') {
                error_log('Funnel credentials ignored: ' . ($secretKey === '' ? 'not a valid token' : 'signature does not match FUNNEL_TENANT_SECRET_KEY'));
                if (class_exists('ErrorLogger', false)) {
                    ErrorLogger::apiError('Funnel credentials', 0, 'Credentials in the funnel JSON ignored: ' . ($secretKey === '' ? 'not a valid token' : 'signature does not match FUNNEL_TENANT_SECRET_KEY'));
                }
            }
            return [];
        }
        $out = [];
        foreach ($names as $from => $to) {
            $value = trim((string) ($data[$from] ?? ''));
            if ($value !== '') $out[$to] = $value;
        }
        return $out;
    }

    /**
     * Products with their offer (CRM price) for one campaign, plus the offer on the prepaid campaign
     * (prepaid_offer: null when the set has no prepaid campaign or the variant has no offer there).
     */
    private static function normalizeProducts(array $list, $campaignId, $prepaidCampaignId = null): array
    {
        $offerOf = static function (array $crm): array {
            return [
                'campaign_id'             => $crm['campaign_id'] ?? null,
                'crm_campaign_product_id' => $crm['crm_campaign_product_id'] ?? null,
                'crm_offer_id'            => $crm['crm_offer_id'] ?? null,
                'crm_item_id'             => $crm['crm_item_id'] ?? null,
                'price'                   => isset($crm['price']) ? (float) $crm['price'] : null,
            ];
        };
        $products = [];
        foreach ($list as $product) {
            $variants = [];
            foreach ($product['variants'] ?? [] as $variant) {
                $offer = $prepaidOffer = null;
                foreach ($variant['crm_campaigns'] ?? [] as $crm) {
                    if ($offer === null && ($campaignId === null || ($crm['campaign_id'] ?? null) == $campaignId)) {
                        $offer = $offerOf($crm);
                    }
                    if ($prepaidOffer === null && $prepaidCampaignId !== null && ($crm['campaign_id'] ?? null) == $prepaidCampaignId) {
                        $prepaidOffer = $offerOf($crm);
                    }
                }
                $variants[] = [
                    'id'               => $variant['id'],
                    'name'             => (string) ($variant['name'] ?? ''),
                    'form'             => $variant['form'] ?? null,
                    'typical_duration' => isset($variant['typical_duration']) ? (int) $variant['typical_duration'] : null,
                    'display_price'    => isset($variant['display_price']) ? (float) $variant['display_price'] : null,
                    'offer'            => $offer,
                    'prepaid_offer'    => $prepaidOffer && $prepaidOffer['crm_offer_id'] !== null && $prepaidOffer['price'] !== null ? $prepaidOffer : null,
                    // Only variants with a CRM offer and price can be sold.
                    'available'        => $offer !== null && $offer['price'] !== null && $offer['crm_offer_id'] !== null,
                ];
            }
            $products[] = [
                'id'       => $product['id'],
                'name'     => (string) ($product['name'] ?? ''),
                'type'     => $product['type'] ?? null,
                'variants' => $variants,
            ];
        }
        return $products;
    }

    private static function normalizeQuestion(array $q): array
    {
        $options = [];
        foreach ($q['options'] ?? [] as $option) {
            $options[] = [
                'id'         => $option['id'] ?? null,
                'value'      => (string) ($option['value'] ?? ''),
                'subtext'    => $option['subtext'] ?? null,
                'disqualify' => ($option['is_disqualify'] ?? 'no') === 'yes',
            ];
        }

        $conditions = [];
        foreach ($q['conditions'] ?? [] as $c) {
            $condition = [
                'key'        => (string) ($c['dependent_question_key'] ?? ''),
                'operator'   => (string) ($c['operator'] ?? 'equal_to'),
                'value'      => (string) ($c['value'] ?? ''),
                'visibility' => (string) ($c['visibility'] ?? 'show'),
            ];
            $conditions[implode("\x1F", $condition)] = $condition;
        }

        return [
            'id'          => $q['id'] ?? null,
            'key'         => (string) $q['key'],
            'label'       => (string) ($q['label'] ?? ''),
            'description' => $q['description'] ?? null,
            'type'        => (string) ($q['type'] ?? 'input'),
            'required'    => ($q['is_required'] ?? 'no') === 'yes',
            'readonly'    => ($q['is_readonly'] ?? 'no') === 'yes',
            'param'       => (string) ($q['default_params'] ?? $q['key']),
            'position'    => (int) ($q['position'] ?? 0),
            'options'     => $options,
            'conditions'  => array_values($conditions),
            // GoHighLevel custom field for this answer (server side only; index.php removes it before output).
            // A field key without an id is looked up in GHL when the contact is saved (lib/LeadFlow.php).
            'ghl'         => !empty($q['ghl']['ghl_custom_id']) || !empty($q['ghl']['ghl_field_key'])
                ? ['id' => (string) ($q['ghl']['ghl_custom_id'] ?? ''), 'key' => (string) ($q['ghl']['ghl_field_key'] ?? '')]
                : null,
        ];
    }
}
