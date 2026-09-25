<?php
// TEMP DEBUG: remove once the 500 is diagnosed. Forces PHP fatal errors to
// print in the response body instead of dying silently as a bare 500.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Read directly off $_POST (not getProspectRecord(), which isn't defined
// yet and does more validation than a log tag needs) so every log line
// below - even ones written before the prospect record is built - can be
// grepped by this one visitor's email instead of the whole shared log.
$GLOBALS['logEmail'] = trim((string) ($_POST['email'] ?? ''));

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' script start, __DIR__=' . __DIR__);

use Application\Session;
use Application\Config;

// TEMP DEBUG: db_common.php is no longer required, so its respond()/clean()
// helpers don't exist anymore. Every respond() call below would otherwise
// fatal with "Call to undefined function respond()" — that fatal IS a 500.
// These are minimal local stand-ins so the file has zero dependency on
// db_common.php. Restore the real db_common.php-backed versions once this
// is no longer needed for isolation.
if (!function_exists('respond')) {
    function respond($success, $message, $httpCode = 200, $extra = []) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit;
    }
}

if (!function_exists('clean')) {
    function clean($value) {
        return trim(strip_tags((string) $value));
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' before require vrio-config.php');
require __DIR__ . '/vrio-config.php';
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' after require vrio-config.php');

// Needed only for the vrio_order_id save further below (see its own
// comment) - everything else in this file already avoids $con per the
// TEMP DEBUG note above.
require_once __DIR__ . '/dbConfig.php';


error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' before require library/bootstrap.php');
require __DIR__ . '/library/bootstrap.php';
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' after require library/bootstrap.php, before Bootstrap::initialize');
Bootstrap::initialize('static');
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' after Bootstrap::initialize');

$campaigns = Config::campaigns();
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' Config::campaigns() returned: ' . json_encode($campaigns));

$sessionData = Session::all();
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' Session::all() returned: ' . json_encode($sessionData));

// Reads the prospect/customer record straight from $_POST: the checkout
// form on this same request already submits firstName/lastName/email and
// the shippingAddress1/shippingCity/shippingState/shippingZip fields, so
// there's no need to depend on Session::customer being populated by an
// earlier step.
function getProspectRecord() {
    $customer = $_POST;

    $email = trim((string) ($customer['email'] ?? ''));
    $firstName = trim((string) ($customer['firstName'] ?? ''));
    $lastName = trim((string) ($customer['lastName'] ?? ''));
    $address1 = trim((string) ($customer['shippingAddress1'] ?? ''));
    $city = trim((string) ($customer['shippingCity'] ?? ''));
    $state = trim((string) ($customer['shippingState'] ?? ''));
    $postalCode = trim((string) ($customer['shippingZip'] ?? ''));
    $country = trim((string) ($customer['shippingCountry'] ?? ''));

    if ($email === '' || $address1 === '' || $city === '' || $state === '' || $postalCode === '') {
        error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' getProspectRecord: missing required field(s)');
        return null;
    }

    return [
        'email'     => $email,
        'firstName' => $firstName,
        'lastName'  => $lastName,
        'address1'  => $address1,
        'city'      => $city,
        'state'     => $state,
        'zipcode'   => $postalCode,
        'country'   => $country !== '' ? $country : 'US',
    ];
}


function setFrameworkOrderIdSession($vrioOrderId, $vrioCustomerId = null) {
    Session::set('steps.1.orderId', $vrioOrderId);
    if (!empty($vrioCustomerId)) {
        Session::set('steps.1.customerId', $vrioCustomerId);
    }
}

function resolveAffiliateValue($affiliates, $aliases) {
    foreach ($aliases as $key) {
        if (isset($affiliates[$key]) && $affiliates[$key] !== '') {
            return $affiliates[$key];
        }
    }
    return '';
}

function detectCardTypeId($cardNumber) {
    if (preg_match('/^3[47]/', $cardNumber)) {
        return 4; // American Express
    }
    if (preg_match('/^6(?:011|5)/', $cardNumber)) {
        return 3; // Discover
    }
    if (preg_match('/^5[1-5]/', $cardNumber)) {
        return 1; // Mastercard
    }
    if (preg_match('/^4/', $cardNumber)) {
        return 2; // Visa
    }
    return null;
}

function buildOffersFromCampaignConfig($campaigns) {
    if (!is_array($campaigns)) {
        error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: $campaigns is not an array (got ' . gettype($campaigns) . ')');
        return [];
    }

    $postedCampaigns = $_POST['campaigns'] ?? null;
    if (!is_array($postedCampaigns)) {
        error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: $_POST[campaigns] is not an array (got ' . gettype($postedCampaigns) . ')');
        return [];
    }

    $offers = [];

    foreach ($postedCampaigns as $postedCampaign) {
        if (!is_array($postedCampaign)) {
            error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: skipping non-array posted campaign entry: ' . json_encode($postedCampaign));
            continue;
        }

        $campaignId = (string) ($postedCampaign['id'] ?? '');
        if ($campaignId === '' || !isset($campaigns[$campaignId])) {
            error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: campaign id "' . $campaignId . '" not found in $campaigns');
            continue;
        }

        $productArrayJson = $campaigns[$campaignId]['product_array'] ?? null;
        if (!is_string($productArrayJson) || $productArrayJson === '') {
            error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: campaign id "' . $campaignId . '" has no product_array string');
            continue;
        }

        $products = json_decode($productArrayJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($products)) {
            error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: campaign id "' . $campaignId . '" product_array is not valid JSON: ' . json_last_error_msg());
            continue;
        }

        foreach ($products as $product) {
            if (!is_array($product) || empty($product['offer_id'])) {
                error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' buildOffersFromCampaignConfig: campaign id "' . $campaignId . '" has a product with no offer_id: ' . json_encode($product));
                continue;
            }

            $rawQuantity = $product['product_quantity'] ?? '';
            $quantity = ($rawQuantity === '' || $rawQuantity === null) ? 1 : (int) $rawQuantity;
            if ($quantity < 1) {
                $quantity = 1;
            }

            $offers[] = [
                'offer_id'             => (int) $product['offer_id'],
                'item_id'              => (int) ($product['item_id'] ?? 0),
                'order_offer_quantity' => $quantity,
                'order_offer_price'    => (float) ($product['offer_price'] ?? 0),
            ];
        }
    }

    return $offers;
}

function callVrio($path, $body) {
    $ch = curl_init(VRIO_API_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'X-Api-Key: ' . VRIO_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        return [null, $httpStatus, $curlError];
    }

    return [json_decode($raw, true), $httpStatus, null];
}

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' REQUEST_METHOD=' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Only POST requests are accepted.', 405);
}

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' raw $_POST keys: ' . implode(', ', array_keys($_POST)));

$cardName = clean($_POST['creditCardName'] ?? '');
$cardNumber = preg_replace('/\D/', '', (string) ($_POST['creditCardNumber'] ?? ''));
$expMonth = (string) ($_POST['expmonth'] ?? '');
$expYear = (string) ($_POST['expyear'] ?? '');
$cvv = trim((string) ($_POST['CVV'] ?? ''));

error_log(sprintf(
    '[preauth][debug] email=%s cardName=%s cardNumberLen=%d expMonth=%s expYear=%s cvvLen=%d',
    $GLOBALS['logEmail'] ?? '',
    $cardName !== '' ? '(set)' : '(empty)',
    strlen($cardNumber),
    $expMonth,
    $expYear,
    strlen($cvv)
));

//if ($cardName === '' || $cardNumber === '' || $expMonth === '' || $expYear === '' || $cvv === '') {
if ($cardNumber === '' || $expMonth === '' || $expYear === '' || $cvv === '') {
    error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' failing: incomplete card fields');
    respond(false, 'Please complete all card fields.', 200);
}

$cardTypeId = detectCardTypeId($cardNumber);
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' detectCardTypeId => ' . var_export($cardTypeId, true));
if ($cardTypeId === null) {
    respond(false, 'We could not recognize your card type. Please check your card number.', 200);
}

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' last_db_insert_id in session = ' . var_export($_SESSION['last_db_insert_id'] ?? null, true));
$prospect = getProspectRecord();
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' getProspectRecord() => ' . json_encode($prospect));
if ($prospect === null) {
    error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' failing: prospect record not found / incomplete');
    respond(false, 'We could not find your information. Please go back and try again.', 200);
}

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' posted campaigns: ' . json_encode($_POST['campaigns'] ?? null));
$offers = buildOffersFromCampaignConfig($campaigns);
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' resolved offers: ' . json_encode($offers));
if (empty($offers)) {
    error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' No offers resolved for posted campaigns: ' . json_encode($_POST['campaigns'] ?? null));
}

// Optional affiliate/tracking params, carried in $sessionData['affiliates']
$affiliates = is_array($sessionData['affiliates'] ?? null) ? $sessionData['affiliates'] : [];
$tracking1 = resolveAffiliateValue($affiliates, ['affId', 'affid', 'aff_id']);
$tracking2 = resolveAffiliateValue($affiliates, ['sub1', 'subId', 'sub_id']);
$tracking3 = resolveAffiliateValue($affiliates, ['clickId', 'click_id', 'clickid']);

// Scrap flow is known up front from session (unlike prepaid, which we only
// find out about from Vrio's own rejection below), so this one is decided
// before the first call rather than via a retry.
$isScrapFlow = ((int) Session::get('steps.meta.isScrapFlow') === 1);
$campaignId = $isScrapFlow ? VRIO_SRP_CAMPAIGN_ID : VRIO_CAMPAIGN_ID;
error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' isScrapFlow=' . var_export($isScrapFlow, true) . ' campaignId resolved to ' . $campaignId);

$orderBody = [
    'connection_id' => VRIO_CONNECTION_ID,
    'campaign_id' => $campaignId,
    'offer_funnel_slug' => "chime-main",
    'crm_campaign_id'=> $campaignId ,
    'email' => $prospect['email'],
    'offers' => $offers,
    'shipping_profile_id' => VRIO_SHIPPING_PROFILE_ID,
    'bill_fname' => $prospect['firstName'],
    'bill_lname' => $prospect['lastName'],
    'bill_address1' => $prospect['address1'],
    'bill_city' => $prospect['city'],
    'bill_state' => $prospect['state'],
    'bill_country' => $prospect['country'],
    'bill_zipcode' => $prospect['zipcode'],
    'tracking1' => $tracking1,
    'tracking2' => $tracking2,
    'tracking3' => $tracking3,
    'same_address' => true,
    'payment_method_id' => 1,
    'card_type_id' => $cardTypeId,
    'card_number' => $cardNumber,
    'card_cvv' => $cvv,
    'card_exp_month' => (int) $expMonth,
    'card_exp_year' => (int) $expYear,
    'action' => 'authorize'
];

// Card number/CVV are redacted here even server-side - PCI scope, not just
$debugOrderBody = $orderBody;
$debugOrderBody['card_number'] = '***REDACTED***';
$debugOrderBody['card_cvv'] = '***REDACTED***';
error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio /orders request payload: ' . json_encode($debugOrderBody));

error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' VRIO_API_BASE_URL=' . (defined('VRIO_API_BASE_URL') ? VRIO_API_BASE_URL : '(undefined)'));
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' about to call VRIO /orders');
[$response, $httpStatus, $curlError] = callVrio('/orders', $orderBody);
error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' callVrio returned, httpStatus=' . var_export($httpStatus, true) . ' curlError=' . var_export($curlError, true));

if ($curlError) {
    error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio request failed: ' . $curlError);
    respond(false, 'We could not process your payment. Please try again.', 200);
}

error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio /orders response (HTTP ' . $httpStatus . '): ' . json_encode($response));

// Vrio rejects prepaid cards on the standard campaign (e.g. "code":
// "campaign_prepaid_not_allowed", "message": "Prepaid cards not allowed on
// campaign 15"). Rather than requiring the funnel to know up front whether
// a card is prepaid, retry the identical order against the prepaid-specific
// campaign whenever Vrio's rejection mentions prepaid.
// Vrio's actual /orders error shape nests code/message under "error"
// (confirmed from the live log: {"error":{"code":"campaign_prepaid_not_allowed",
// "message":"..."}}) - "message" is checked too since that shape showed up
// during earlier testing and may still occur for other error types.
$vrioErrorBlock = is_array($response['error'] ?? null)
    ? $response['error']
    : (is_array($response['message'] ?? null) ? $response['message'] : []);
$vrioErrorCode = (string) ($vrioErrorBlock['code'] ?? ($response['code'] ?? ''));
$vrioErrorText = (string) ($vrioErrorBlock['message'] ?? (is_string($response['message'] ?? null) ? $response['message'] : ''));
$isPrepaidRejection = stripos($vrioErrorCode, 'prepaid') !== false || stripos($vrioErrorText, 'prepaid') !== false;

// Exact reason this did or didn't retry - so a "why was Vrio only called
// once" question can be answered straight from the log instead of guessing.
error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' prepaid-retry check: campaignId=' . $campaignId
    . ' vrioErrorCode=' . var_export($vrioErrorCode, true)
    . ' vrioErrorText=' . var_export($vrioErrorText, true)
    . ' isPrepaidRejection=' . var_export($isPrepaidRejection, true)
    . ' willRetry=' . var_export($isPrepaidRejection && $campaignId !== VRIO_PP_CAMPAIGN_ID, true));

if ($isPrepaidRejection && $campaignId !== VRIO_PP_CAMPAIGN_ID) {
    error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio rejected campaign ' . $campaignId . ' for a prepaid card (' . $vrioErrorCode . '); retrying with VRIO_PP_CAMPAIGN_ID');

    $campaignId = VRIO_PP_CAMPAIGN_ID;
    $orderBody['campaign_id'] = $campaignId;
    $debugOrderBody['campaign_id'] = $campaignId;
    error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio /orders retry payload: ' . json_encode($debugOrderBody));

    [$response, $httpStatus, $curlError] = callVrio('/orders', $orderBody);
    error_log('[preauth][debug] email=' . ($GLOBALS['logEmail'] ?? '') . ' callVrio retry returned, httpStatus=' . var_export($httpStatus, true) . ' curlError=' . var_export($curlError, true));

    if ($curlError) {
        error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio retry request failed: ' . $curlError);
        respond(false, 'We could not process your payment. Please try again.', 200);
    }

    error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Vrio /orders retry response (HTTP ' . $httpStatus . '): ' . json_encode($response));
}

$rawMessage = $response['message'] ?? null;
$declineMessage = is_array($rawMessage)
    ? ($rawMessage['message'] ?? null)
    : $rawMessage;
$declineMessage = $declineMessage
    ?? $response['error']
    ?? (is_array($response['errors'] ?? null) ? implode(' ', $response['errors']) : null);

$transaction = $rawMessage['transaction'] ?? $response['transaction'] ?? [];
$orderId = $response['order_id'] ?? ($transaction['order_id'] ?? null);
$customerId = $response['customer_id'] ?? ($transaction['customer_id'] ?? null);
$responseCode = $response['response_code'] ?? ($transaction['response_code'] ?? null);

if ($httpStatus !== 200 || empty($orderId) || $declineMessage) {
    respond(false, $declineMessage ?: 'We could not process your payment. Please try again.', 200);
}

if (!empty($responseCode) && (int) $responseCode === 101) {
    respond(false, 'Additional verification is required for this card. Please try a different card or contact support.', 200);
}

// Persists the VRIO order_id onto this visitor's `chime-health-rnad-v2` row (the same
// table identity_verify.php already writes Vouched results into, keyed by
// email) so process.php can resolve crm_order_id from the database instead
// of needing order_id passed through the URL. Best-effort: a failure here
// is only logged, never allowed to block the card-auth response below.
if (!empty($prospect['email']) && isset($con) && $con instanceof \mysqli) {
    try {
        $vrioOrderIdStmt = $con->prepare('UPDATE `chime-health-rnad-v2` SET `vrio_order_id` = ? WHERE `email` = ?');
        if ($vrioOrderIdStmt) {
            $vrioOrderIdStmt->bind_param('ss', $orderId, $prospect['email']);
            $vrioOrderIdStmt->execute();
            error_log("[preauth] email={$prospect['email']} vrio_order_id saved: affected_rows={$vrioOrderIdStmt->affected_rows}, order_id={$orderId}.");
            $vrioOrderIdStmt->close();
        } else {
            error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' vrio_order_id update prepare failed: ' . $con->error);
        }
    } catch (\Throwable $e) {
        error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' vrio_order_id update threw: ' . $e->getMessage());
    }
} else {
    error_log('[preauth] email=' . ($GLOBALS['logEmail'] ?? '') . ' Skipping vrio_order_id save: missing prospect email or no DB connection.');
}

setFrameworkOrderIdSession($orderId, $customerId);

respond(true, 'Card authorized.', 200, ['redirect' => 'thank-you.php']);
