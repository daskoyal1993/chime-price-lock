<?php
/**
 * POST endpoint for the quiz, product and checkout pages. All actions need the X-CSRF-Token header.
 *
 *   ?action=lead      { answers: {key: value}, tracking: {prospect_id, affid, c1} }   (sent in the background from the contact screen on)
 *       → 200 { success, saved }   | 422 { success: false, errors: {key: message} }
 *       saves the dynamic_remedy row, creates the Vrio prospect (once) and creates/updates the GHL contact
 *   ?action=verify    { answers: {email, phone} }   (contact screen, before moving on)
 *       → 200 { success }   | 422 { success: false, errors: {email|phone: message} }  — xverify, cached per value
 *   ?action=intake    { answers: {key: value}, tracking: {...} }
 *       → 200 { success, disqualified: [], redirect }   | 422 { success: false, errors: {key: message} }
 *   ?action=track     { step: "step7", key: "dob", index: 7, tracking: {...} }   (every quiz screen shown, lib/FlowTracker.php)
 *       → 200 { success }
 *   ?action=product   { variant_id }
 *       → 200 { success, redirect }
 *   ?action=idcheck   { job_id, success: true|false|null }   (idcheck.php, Vouched plugin)
 *       → 200 { success, redirect }   true → verified_url, false → not_verified_url (+ GHL tag failed_id), null → submitted only
 *   ?action=checkout  { shipping: {...}, billing_same: bool, billing: {...}, card: {number, month, year, cvv} }
 *       → 200 { success, redirect }   | 422 { success: false, errors: {field: message} } | 402 { success: false, message }
 */
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    funnel_json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
if (!hash_equals($_SESSION['funnel_csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    funnel_json_response(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 419);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    funnel_json_response(['success' => false, 'message' => 'Invalid request.'], 400);
}

try {
    $funnel = quiz_funnel((new FunnelRepository($config))->load());
} catch (RuntimeException $e) {
    error_log('Funnel load failed: ' . $e->getMessage());
    funnel_json_response(['success' => false, 'message' => 'The form is temporarily unavailable.'], 503);
}

$record =& funnel_session($funnel);
$action = $_GET['action'] ?? '';

/** prospect_id / affid / c1 from the landing URL, as sent by funnel.js. */
function lead_tracking(array $input): array
{
    $in  = is_array($input['tracking'] ?? null) ? $input['tracking'] : [];
    $out = [];
    foreach (['prospect_id', 'affid', 'c1'] as $key) {
        if (isset($in[$key]) && is_scalar($in[$key])) $out[$key] = (string) $in[$key];
    }
    return $out;
}

/**
 * Contact details / answers submitted: 1. DB row, 2. Vrio prospect, 3. GHL contact (lib/LeadFlow.php).
 * Each step is logged on its own (storage/logs/lead-flow.log) and none of them can stop the funnel.
 * Returns true when the DB row was saved. Nothing about Vrio/GHL is sent back to the browser.
 */
function save_lead(array $config, array $funnel, array &$record, array $answers, array $tracking, array $remove = []): bool
{
    try {
        $status = LeadFlow::submit($config, $funnel, $record, $answers, $tracking, $remove);
    } catch (Throwable $e) {
        error_log('[lead-flow] unexpected error: ' . $e->getMessage());
        ErrorLogger::log($e, 'EXCEPTION', ['where' => 'save_lead']);
        return false;
    }
    return $status['db'] === true;
}

/**
 * xverify the email and phone (lib/XverifyClient.php, like demo/xverify.php). Each value is checked once per session.
 * Returns [question key => message] for values xverify rejects; an API problem is logged and never blocks.
 */
function xverify_errors(array $config, array &$record, array $answers): array
{
    $client = new XverifyClient($config['xverify'] ?? []);
    if (!$client->enabled()) {
        return [];
    }
    $text   = Page::load('index')['text'];
    $phone  = preg_replace('/\D/', '', (string) ($answers['phone'] ?? ''));
    if (strlen($phone) === 11 && $phone[0] === '1') $phone = substr($phone, 1);
    $values = ['email' => strtolower(trim((string) ($answers['email'] ?? ''))), 'phone' => $phone];
    $errors = [];

    foreach ($values as $field => $value) {
        if ($value === '') continue;
        $cache = $field . ':' . $value;
        if (!isset($record['xverify'][$cache])) {
            try {
                $status = $field === 'email' ? $client->email($value) : $client->phone($value);
                LeadFlow::log($config, 'XVERIFY', true, $field . ' ' . $value . ' → ' . $status . ($client->blocks($status) ? ' (rejected)' : ''));
            } catch (Throwable $e) {
                $status = 'error';   // API problem: let the visitor through
                LeadFlow::log($config, 'XVERIFY', false, $e->getMessage() . ' — not blocking');
            }
            $record['xverify'][$cache] = $status;
        }
        if ($client->blocks($record['xverify'][$cache])) {
            $errors[$field] = $text['xverify_' . $field] ?? ($field === 'email' ? 'Please enter a valid email address.' : 'Please enter a valid phone number.');
        }
    }
    return $errors;
}

if ($action === 'track') {
    // Quiz screen shown (funnel.js). Screens are numbered in index.json order: step1 … stepN.
    $index = (int) ($input['index'] ?? 0);
    $key   = (string) ($input['key'] ?? '');
    if ($index >= 1 && $index < FlowTracker::PAGE_STEPS['product'] && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key)) {
        FlowTracker::hit($config, $funnel['slug'], 'step' . $index, 'index', $index, $key, lead_tracking($input));
    }
    funnel_json_response(['success' => true]);
}

if ($action === 'verify') {
    $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
    $errors  = xverify_errors($config, $record, $answers);
    funnel_json_response($errors ? ['success' => false, 'errors' => $errors] : ['success' => true], $errors ? 422 : 200);
}

if ($action === 'lead') {
    // Answers so far: only valid, visible answers are kept (later screens are not answered yet).
    $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
    $result  = FunnelRules::validate($funnel, $answers, $config);
    $errors  = LeadRepository::validateContact($result['answers']) ?: xverify_errors($config, $record, $result['answers']);
    if ($errors) {
        funnel_json_response(['success' => false, 'errors' => $errors], 422);
    }
    $saved = save_lead($config, $funnel, $record, $result['answers'], lead_tracking($input));
    funnel_json_response(['success' => true, 'saved' => $saved]);
}

if ($action === 'intake') {
    $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
    $result  = FunnelRules::validate($funnel, $answers, $config);

    if ($result['errors']) {
        funnel_json_response(['success' => false, 'errors' => $result['errors']], 422);
    }
    if ($errors = xverify_errors($config, $record, $result['answers'])) {
        funnel_json_response(['success' => false, 'errors' => $errors], 422);
    }

    // Keep the ids of the DB row, Vrio prospect and GHL contact created for this visitor.
    $keep   = array_intersect_key($record, array_flip(['xverify', 'has_membership', 'membership_email', 'lead_id', 'vrio_prospect_id', 'vrio_order_id', 'ghl_contact_id', 'consents']));
    $record = $keep + [
        'answers'      => $result['answers'],
        'params'       => $result['params'],
        'disqualified' => $result['disqualified'],
        'completed_at' => gmdate('c'),
        'product'      => null,
        'order'        => null,
    ];

    // Final save of the validated answers. Answers now hidden by conditions are removed from the stored JSON.
    $hidden = [];
    foreach ($funnel['steps'] as $step) {
        foreach ($step['questions'] as $q) {
            if (!array_key_exists($q['key'], $result['answers'])) $hidden[] = $q['key'];
        }
    }
    save_lead($config, $funnel, $record, $result['answers'], lead_tracking($input), $hidden);

    if ($result['disqualified']) {
        FlowTracker::status($config, $funnel['slug'], 'disqualified');
        funnel_json_response([
            'success'      => true,
            'disqualified' => $result['disqualified'],
            'redirect'     => $config['disqualified_url'] ?: null,
        ]);
    }

    // Intake is valid and saved to dynamic_remedy (above). This is where the answers ($result['params'],
    // keyed by default_params) would be sent on to the CRM, e.g. to create the prospect.

    funnel_json_response(['success' => true, 'disqualified' => [], 'redirect' => $config['product_url']]);
}

if (empty($record['answers']) || !empty($record['disqualified'])) {
    funnel_json_response(['success' => false, 'message' => 'Please complete the questionnaire first.'], 409);
}

if ($action === 'product') {
    // Only plans of the visitor's offer (member → campaign_set, otherwise membership_campaign_set) can be chosen.
    $offer = offer_funnel($funnel, $record, $config);
    $variantId = (int) ($input['variant_id'] ?? 0);
    $selected  = null;
    foreach ($offer['products'] as $product) {
        foreach ($product['variants'] as $variant) {
            if ($variant['id'] === $variantId && $variant['available']) {
                $selected = ['product' => $product, 'variant' => $variant];
            }
        }
    }
    if ($selected === null) {
        funnel_json_response(['success' => false, 'message' => 'That plan is not available. Please choose another.'], 422);
    }

    $v = $selected['variant'];
    $record['product'] = [
        'product_id'              => $selected['product']['id'],
        'product_name'            => $selected['product']['name'],
        'variant_id'              => $v['id'],
        'name'                    => $v['name'],
        'months'                  => plan_months($v),
        'days'                    => $v['typical_duration'],
        'price'                   => $v['offer']['price'],
        'campaign_id'             => $offer['campaign']['id'],
        'crm_campaign_id'         => $offer['campaign']['crm_campaign_id'],
        'prepaid_crm_campaign_id' => $offer['campaign']['prepaid_crm_campaign_id'] ?? null,
        'crm_campaign_product_id' => $v['offer']['crm_campaign_product_id'],
        'crm_offer_id'            => $v['offer']['crm_offer_id'],
        'crm_item_id'             => $v['offer']['crm_item_id'],
        // This plan's offer on the prepaid campaign (a prepaid card is retried there, lib/VrioClient.php); null = same offer.
        'prepaid_offer'           => $v['prepaid_offer'] ?? null,
    ];
    $record['order'] = null;

    funnel_json_response(['success' => true, 'redirect' => $config['checkout_url']]);
}

if ($action === 'checkout') {
    if (empty($record['product'])) {
        LeadFlow::log($config, 'VRIO-PREAUTH', false, 'not sent: no plan chosen in this session (driver ' . $config['payment_driver'] . ')');
        funnel_json_response(['success' => false, 'message' => 'Please choose a plan first.'], 409);
    }

    $states = [];
    foreach ($funnel['steps'] as $step) {
        foreach ($step['questions'] as $q) {
            if ($q['key'] === 'shipping_state') $states = array_column($q['options'], 'value');
        }
    }

    $errors = [];
    $clean  = static function ($v, int $max = 120): string {
        return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
    };
    $address = static function (array $in, string $prefix) use ($clean, $states, &$errors): array {
        $a = [
            'first_name' => $clean($in['first_name'] ?? ''),
            'last_name'  => $clean($in['last_name'] ?? ''),
            'address1'   => $clean($in['address1'] ?? '', 200),
            'address2'   => $clean($in['address2'] ?? '', 200),
            'city'       => $clean($in['city'] ?? ''),
            'state'      => $clean($in['state'] ?? '', 2),
            'zip'        => $clean($in['zip'] ?? '', 10),
        ];
        foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'address1' => 'Address', 'city' => 'City'] as $f => $label) {
            if ($a[$f] === '') $errors[$prefix . $f] = $label . ' is required.';
        }
        if ($states && !in_array($a['state'], $states, true)) $errors[$prefix . 'state'] = 'Select a state we ship to.';
        if (!preg_match('/^\d{5}$/', $a['zip'])) $errors[$prefix . 'zip'] = 'Enter a 5-digit ZIP code.';
        return $a;
    };

    $shipping = $address(is_array($input['shipping'] ?? null) ? $input['shipping'] : [], 'shipping_');
    $email = $clean($input['shipping']['email'] ?? '', 200);
    $phone = preg_replace('/\D/', '', (string) ($input['shipping']['phone'] ?? ''));
    if (strlen($phone) === 11 && $phone[0] === '1') $phone = substr($phone, 1);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['shipping_email'] = 'Enter a valid email address.';
    if (strlen($phone) !== 10) $errors['shipping_phone'] = 'Enter a valid 10-digit US phone number.';

    $billingSame = !empty($input['billing_same']);
    $billing = $billingSame ? $shipping : $address(is_array($input['billing'] ?? null) ? $input['billing'] : [], 'billing_');

    $card   = is_array($input['card'] ?? null) ? $input['card'] : [];
    $number = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
    $month  = (int) ($card['month'] ?? 0);
    $year   = (int) ($card['year'] ?? 0);
    $cvv    = preg_replace('/\D/', '', (string) ($card['cvv'] ?? ''));
    $isTestCard = $number !== '' && $number === Payment::testCard($config);   // test / vrio driver: no checksum for the test card
    if (strlen($number) < 13 || strlen($number) > 19 || (!$isTestCard && !Payment::luhn($number))) $errors['card_number'] = 'Enter a valid card number.';
    if ($month < 1 || $month > 12) $errors['card_month'] = 'Select the expiry month.';
    if ($year < (int) date('Y') || $year > (int) date('Y') + 25) $errors['card_year'] = 'Select the expiry year.';
    elseif ($year === (int) date('Y') && $month < (int) date('n')) $errors['card_month'] = 'This card has expired.';
    if (strlen($cvv) < 3 || strlen($cvv) > 4) $errors['card_cvv'] = 'Enter the 3 or 4 digit security code.';

    if ($errors) {
        // Field names only — never the card data.
        LeadFlow::log($config, 'VRIO-PREAUTH', false, $email . ' not sent: form errors in ' . implode(', ', array_keys($errors)) . ' (driver ' . $config['payment_driver'] . ')');
        funnel_json_response(['success' => false, 'errors' => $errors], 422);
    }

    $tracking = [];
    foreach (['affid', 'sub1', 'c1', 'click_id'] as $k) {
        if (isset($input['tracking'][$k]) && is_scalar($input['tracking'][$k])) $tracking[$k] = $clean($input['tracking'][$k], 100);
    }
    $result = Payment::charge($config['payment_driver'], [
        'product'      => $record['product'],
        'shipping'     => $shipping + ['email' => $email, 'phone' => $phone],
        'billing'      => $billing,
        'billing_same' => $billingSame,
        'email'        => $email,
        'card'         => ['number' => $number, 'month' => $month, 'year' => $year, 'cvv' => $cvv],
        'params'       => $record['params'],
        'prospect_id'  => $clean($input['prospect_id'] ?? '', 64),
        'tracking'     => $tracking,
        'funnel_slug'  => $funnel['slug'],
    ], $config);
    unset($number, $cvv, $card, $input);

    // Each request sent to Vrio (card number masked to the last 4 digits, no CVV) and Vrio's reply — 2 of each after a prepaid retry.
    foreach ($result['calls'] ?? [] as $i => $call) {
        $attempt = $email . ' attempt ' . ($i + 1) . ' campaign ' . $call['campaign_id'];
        LeadFlow::log($config, 'VRIO-PREAUTH', null, $attempt . ' payload: ' . $call['request'], 'SENT');
        LeadFlow::log($config, 'VRIO-PREAUTH', $call['status'] === 200, $attempt . ' response (HTTP ' . $call['status'] . '): ' . $call['response'], 'REPLY');
    }
    $attempts = count($result['calls'] ?? []);
    unset($result['calls']);

    // The checkout's result in one line (never card data): storage/logs/lead-flow.log. With Vrio, the SENT / REPLY lines above
    // hold the requests and replies, so this only says what happened, on the campaign that was actually charged.
    $charged = (string) ($result['crm_campaign_id'] ?? $record['product']['crm_campaign_id']);
    $summary = $attempts === 0 ? ($result['log'] ?? $result['message'])
        : ($result['success'] ? 'approved, order ' . $result['order_id'] : 'declined: ' . $result['message'])
          . (!empty($result['prepaid_campaign']) ? ' (prepaid card: refused on campaign ' . $record['product']['crm_campaign_id'] . ', retried on ' . $charged . ')' : '')
          . (strpos((string) ($result['log'] ?? ''), 'not retried') !== false ? ' (prepaid card: the funnel has no prepaid campaign)' : '');
    LeadFlow::log($config, 'VRIO-PREAUTH', $result['success'], $email . ' ' . $record['product']['name'] . ' $' . $record['product']['price']
        . ' campaign ' . $charged . ' (driver ' . $config['payment_driver'] . '): ' . $summary);
    // DB row + GHL contact get the shipping address, purchased and the order id (like demo/db_update.php).
    // Runs after the reply is sent, so slow CRM calls can't time the checkout out (Cloudflare 524 after 120 s).
    $contact = $shipping + ['email' => $email, 'phone' => $phone];
    $crmUpdate = static function () use ($config, $funnel, $record, $contact, $result): void {
        LeadFlow::checkout($config, $funnel, $record, $contact, $result);
    };

    if (!$result['success']) {
        funnel_json_response_then(['success' => false, 'message' => $result['message']], 402, $crmUpdate);
    }

    $record['order'] = [
        'order_id' => $result['order_id'],
        'vrio_customer_id' => $result['customer_id'] ?? null,
        'crm_campaign_id'  => $result['crm_campaign_id'] ?? $record['product']['crm_campaign_id'],   // the prepaid one after a prepaid retry
        'prepaid_campaign' => !empty($result['prepaid_campaign']),
        'total'    => $record['product']['price'],
        'email'    => $email,
        'placed_at'=> gmdate('c'),
    ];
    FlowTracker::status($config, $funnel['slug'], 'completed');   // payment approved: the funnel counts as completed

    // Vouched identity check (like demo/identity_verify.php): verified → verified_url, otherwise → the ID check page.
    try {
        $verified = LeadFlow::identity($config, $funnel, $record, [
            'first_name' => $shipping['first_name'],
            'last_name'  => $shipping['last_name'],
            'email'      => $email,
            'phone'      => $phone,
            'dob'        => (string) ($record['answers']['dob'] ?? ''),
            'address1'   => trim($shipping['address1'] . ' ' . $shipping['address2']),
            'city'       => $shipping['city'],
            'state'      => $shipping['state'],
            'zip'        => $shipping['zip'],
            'ip'         => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[lead-flow] identity check failed: ' . $e->getMessage());
        ErrorLogger::log($e, 'IDENTITY_ERROR', ['where' => 'Vouched check after payment']);
        $verified = false;
    }
    $record['identity_verified'] = $verified;

    funnel_json_response_then(['success' => true, 'redirect' => $verified ? $config['verified_url'] : $config['idcheck_url']], 200, $crmUpdate);
}

if ($action === 'idcheck') {
    // ID check result from idcheck.php (like demo/idcheck-update.php), saved on this visitor's row.
    // Vouched's own callback (idcheck-callback.php) confirms the result for the job id.
    if (empty($record['order'])) {
        funnel_json_response(['success' => false, 'message' => 'Please complete checkout first.'], 409);
    }
    $jobId   = mb_substr(trim((string) ($input['job_id'] ?? '')), 0, 255);
    $success = isset($input['success']) ? filter_var($input['success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    if ($jobId === '') {
        funnel_json_response(['success' => false, 'message' => 'Missing job id.'], 422);
    }
    $repo = new LeadRepository($config['db'] ?? []);
    if ($repo->enabled() && !empty($record['lead_id'])) {
        try {
            $repo->saveIdcheck((int) $record['lead_id'], $jobId, $success);
            LeadFlow::log($config, 'IDCHECK', $success, 'row #' . $record['lead_id'] . ' job ' . $jobId . ' → ' . ($success === null ? 'submitted' : ($success ? 'true' : 'false')));
        } catch (Throwable $e) {
            LeadFlow::log($config, 'DB', false, $e->getMessage());
        }
    }
    if ($success === true) {
        $record['identity_verified'] = true;
    }
    if ($success === false) {
        // Not verified (like demo/idcheck.php → usernotverified.php): the not-verified page, and the GHL contact gets the
        // failed_id tag once per session — after the reply, so the visitor doesn't wait for GHL.
        $tag = empty($record['ghl_failed_id_tagged']);
        $record['ghl_failed_id_tagged'] = true;
        $snapshot = $record;
        funnel_json_response_then(['success' => true, 'redirect' => $config['not_verified_url']], 200, static function () use ($config, $funnel, $snapshot, $tag): void {
            if ($tag) LeadFlow::idFailed($config, $funnel, $snapshot);
        });
    }
    funnel_json_response(['success' => true, 'redirect' => $success === true ? $config['verified_url'] : null]);
}

funnel_json_response(['success' => false, 'message' => 'Unknown action.'], 400);
