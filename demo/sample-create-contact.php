<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/dbConfig.php';

if (isset($con) && $con instanceof mysqli) {
    $con->set_charset('utf8mb4');
}

header('Content-Type: application/json; charset=utf-8');

function respond($success, $message, $httpCode = 200, $extra = [])
{
    http_response_code($httpCode);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Only POST requests are accepted.', 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || empty($data)) {
    respond(false, 'No quiz data was received.', 400);
}

/*
|--------------------------------------------------------------------------
| Customer
|--------------------------------------------------------------------------
*/

$firstName  = trim($data['first_name'] ?? '');
$lastName   = trim($data['last_name'] ?? '');
$email      = trim($data['email'] ?? '');
$phone      = trim($data['phone'] ?? '');
$prospectId = $data['prospect_id'] ?? null;

if ($firstName === '' || $lastName === '' || $email === '') {
    respond(false, 'First name, last name and email are required.', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Invalid email address.', 400);
}

/*
|--------------------------------------------------------------------------
| Local storage values
|--------------------------------------------------------------------------
*/

$heightFeet   = $data['heightFeet'] ?? '';
$heightInches = $data['heightInches'] ?? '';

$height = '';

if (is_numeric($heightFeet) && is_numeric($heightInches)) {
    $height = ((int) $heightFeet) . "'" . ((int) $heightInches) . '"';
}

$weightInPounds = $data['weightLbs'] ?? '';
$gender         = $data['sex'] ?? '';
$dob            = $data['dob'] ?? '';
$bmi            = $data['bmi'] ?? '';
$goalWeightLbs  = $data['goalWeightLbs'] ?? '';
$pace  = $data['pace'] ?? '';

$healthConditions = $data['healthConditions'] ?? '';
$healthConditionsAdditional = $data['healthConditionsAdditional'] ?? '';
$moreHealthConditions = $data['moreHealthConditions'] ?? '';

$femaleHealthStatus = $data['femaleHealthStatus'] ?? '';
$heart_arrhythmia = $data['heart_arrhythmia'] ?? '';

$takenPainMedicationsOrStreetDrugs =
    $data['takenPainMedicationsOrStreetDrugs'] ?? '';

$gastricBypass6Months =
    $data['gastricBypass6Months'] ?? '';


// Step7 (S7) elderly consent - only recorded when that band's checkbox was
// actually shown and checked; consent_timestamps carries date/time as
// separate keys, sourced from server-time.php rather than the visitor's
// own device clock (see quiz-flow.js initStep7()).
$elderlyConsentTimestamp = $data['consent_timestamps']['ELDERLY CONSENT'] ?? [];
$elderlyConsentDate = $elderlyConsentTimestamp['date'] ?? '';
$elderlyConsentTime = $elderlyConsentTimestamp['time'] ?? '';

// Step7 (S7) BMI consent - automatic (not a checkbox): recorded whenever the
// visitor is both in the "obese range" BMI category and over 65 years old,
// same threshold S1's BMI card and this step's age/BMI banding already use
// (see quiz-flow.js initStep7()).
$bmiConsentTimestamp = $data['consent_timestamps']['BMI CONSENT'] ?? [];
$bmiConsentDate = $bmiConsentTimestamp['date'] ?? '';
$bmiConsentTime = $bmiConsentTimestamp['time'] ?? '';

//SMS consent..
$smsConsentTimestamp = $data['consent_timestamps']['SMS CONSENT'] ?? [];
$smsConsentDate = $smsConsentTimestamp['date'] ?? '';
$smsConsentTime = $smsConsentTimestamp['time'] ?? '';

//...step6...
// $medicationPastMonth = $data['medicationPastMonth'] ?? '';
// $medicationChoice = strtolower(trim((string) $medicationPastMonth));


// $isTirzepatide = ($medicationChoice === 'tirzepatide');

// $medicationPastMonth = $data['medicationPastMonth'] ?? '';
// $medicationChoice = strtolower(trim((string) $medicationPastMonth));
// $isTirzepatide = str_contains($medicationChoice, 'tirzepatide');

$medicationPastMonth = $data['medicationPastMonth'] ?? '';
$medicationChoice = strtolower(trim((string) $medicationPastMonth));
$isTirzepatide = (strpos($medicationChoice, 'tirzepatide') !== false);


$medicationDose = $data['medicationDose'] ?? '';
$medicationLastTaken = $data['medicationLastTaken'] ?? '';
$medicationContinuePlan = $data['medicationContinuePlan'] ?? '';

if ($isTirzepatide) {
    $medicationCustomFields = [
        [
            'id' => 'RI8sBvOI4LYhb3L7gT96',
            'key' => 'contact.what_tirzepatide_dosage_are_you_currently_taking',
            'fieldValue' => $medicationDose,
        ],
        [
            'id' => 'oGTVs9Wh01ufw29OezhW',
            'key' => 'contact.when_did_you_take_your_last_tirzepatide_dose',
            'fieldValue' => $medicationLastTaken,
        ],
        [
            'id' => '3o9lyiZjpAyLw6It0Ztd',
            'key' => 'contact.how_do_you_want_to_continue_your_tirzepatide',
            'fieldValue' => $medicationContinuePlan,
        ],
    ];
} else {
    // Semaglutide (or any unrecognised medicationPastMonth value).
    $medicationCustomFields = [
        [
            'id' => 'NnkhtHILOwzX1EyaMNrR',
            'key' => 'contact.what_semaglutide_dosage_are_you_currently_taking',
            'fieldValue' => $medicationDose,
        ],
        [
            'id' => '66Qb8R2AvnBHHVdEFD5d',
            'key' => 'contact.when_did_you_take_your_last_semaglutide_dose',
            'fieldValue' => $medicationLastTaken,
        ],
        [
            'id' => 'oeebMCuuMVNtOluPqxnC',
            'key' => 'contact.how_do_you_want_to_continue_your_semaglutide',
            'fieldValue' => $medicationContinuePlan,
        ],
    ];
}

//..Convert arrays to strings for GHL

function valueToString($value)
{
    if (is_array($value)) {
        return implode(', ', $value);
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    return (string) $value;
}

$healthConditions =
    valueToString($healthConditions);

$healthConditionsAdditional =
    valueToString($healthConditionsAdditional);

$moreHealthConditions =
    valueToString($moreHealthConditions);

$femaleHealthStatus =
    valueToString($femaleHealthStatus);


// Keep complete localStorage data


$quizJson = json_encode(
    $data,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($quizJson === false) {
    respond(false, 'Unable to prepare quiz data.', 400);
}



$record = [
    'firstName'  => $firstName,
    'lastName'   => $lastName,
    'email'      => $email,
    'phone'      => $phone,
    'source'     => 'chime-rnad-v2',
    'tags'       => '',
    'quiz'       => $quizJson,
    'address1'   => $data['shippingAddress'] ?? null,
    'postalCode' => $data['shippingZipCode'] ?? null,
    'city'       => $data['shippingCity'] ?? null,
    'state'      => $data['state'] ?? null,
    'prospectid' => $prospectId,
    'processed'  => 0,
    'purchased'  => 0,
    'product'    => $data['selectedTreatment'] ?? null,
];
//...GHL Contact
$contactData = [
    'firstName' => $firstName,
    'lastName' => $lastName,
    'name' => trim($firstName . ' ' . $lastName),
    'email' => $email,
    'phone' => $phone,
    'locationId' => 'AeheBO22OOorfyaJeD8X',
    'source' => 'chime-rnad-v2 API',
    'dateOfBirth' => $dob,
    'state' => $data['state'] ?? '',

    'tags' => [
        'lead'
    ],

    'customFields' => array_merge([
        [
            'id' => 'mQMDC9Bcj0pUgAkCebO1',
            'key' => 'contact.current_height_in',
            'fieldValue' => $height,
        ],
        [
            'id' => 'oefSxUIwlnGAtSKKNxqm',
            'key' => 'contact.male_or_female',
            'fieldValue' => $gender,
        ],
        [
            'id' => 'zXj4rsqlGdaqSnUhOyUs',
            'key' => 'contact.current_weight',
            'fieldValue' => $weightInPounds,
        ],
        [
            'id' => 'xTlZEuAqqmmzWTUYM4Wu',
            'key' => 'contact.goal_weight',
            'fieldValue' => $goalWeightLbs,
        ],
        [
            //'id' => 'QziUBgcYBjB421Uj2EVp',
            'id' => 'hcjkAqqtuXuWO1TVugNL',
            'key' => 'contact.currently_breastfeeding_or_pregnant',
            'fieldValue' => $femaleHealthStatus,
        ],
        [
            'id' => 'OPcuOApNva0QrZSAzJ0P',
            'key' => 'contact.chronic_kidney_diease_end_stage_kidney',
            'fieldValue' => $healthConditions,
        ],
        [
            'id' => 'fBGnqQRSDlLP3Z9fNlNb',
            'key' => 'contact.diagnosed_with_any_of_the_following_current_gallstones',
            'fieldValue' => $healthConditionsAdditional,
        ],
        [
            'id' => 'Pd188iVTe07z08fCrIpg',
            'key' => 'contact.have_you_taken_medication_for_weight_loss_within_the_past_month',
            'fieldValue' => $medicationPastMonth,
        ],
        [
            'id' => 'sKXhegflNJTlt2xeyhjX',
            'key' => 'contact.have_you_had_a_gastric_bypass_surgery_in_the_past_6_months',
            'fieldValue' => $gastricBypass6Months,
        ],
        [
            'id' => '9s4eqjC7dUzjgXy463Tk',
            'key' => 'contact.within_the_last_3_months_have_you_taken_opiate_pain_medications_andor_opiatebased_street_drugs',
            'fieldValue' => $takenPainMedicationsOrStreetDrugs,
        ],
        [
            'id' => '4emnLaNgsxk06KGM5ALK',
            'key' => 'contact.body_mass_index_bmi',
            'fieldValue' => $bmi,
        ],
        [
            'id' => '0oUlAEHJYYVuruTmFrgk',
            'key' => 'contact.what_pace_feels_right_for_you',
            'fieldValue' => $pace,
        ],
        [
            'id' => 'bLBLf7UNhrQtmXoHe5a7',
            'key' => 'contact.what_pace_feels_right_for_you',
            'fieldValue' => $moreHealthConditions,
        ],
        [
            'id' => 'CPJwLR4Evdmubvagk7vN',
            'key' => 'contact.date_of_elderly_consent',
            'fieldValue' => $elderlyConsentDate,
        ],
        [
            'id' => 'LEq5MGZDXLdB6dPaAJ5q',
            'key' => 'contact.time_of_elderly_consent',
            'fieldValue' => $elderlyConsentTime,
        ],
        [
            'id' => 'Xs6j5Y8vZLOrRDEyQN0g',
            'key' => 'contact.date_of_bmi_consent',
            'fieldValue' => $bmiConsentDate,
        ],
        [
            'id' => 'PaFMCXTfnGt1F6op8Nsz',
            'key' => 'contact.time_of_bmi_consent',
            'fieldValue' => $bmiConsentTime,
        ],
        //...heart_arrhythmia...
        [
            'id' => 'NS4aC2IJOSoSgM2jVmJL',
            'key' => 'contact.do_you_now_or_have_you_ever_had_any_heart_arrhythmia_or_irregular_heartbeat',
            'fieldValue' => $heart_arrhythmia,
        ],
        [
            'id' => '6OLaeQuj06NPmgUadWOc',
            'key' => 'contact.date_of_sms_consent',
            'fieldValue' => $smsConsentDate,
        ],
        [
            'id' => '22jxJGSEV0VLvqdwPOCC',
            'key' => 'contact.time_of_sms_consent',
            'fieldValue' => $smsConsentTime,
        ],
    ], $medicationCustomFields),
];

/*
|--------------------------------------------------------------------------
| Create GHL contact
|--------------------------------------------------------------------------
*/

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => 'https://services.leadconnectorhq.com/contacts/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($contactData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer pit-c395d6a9-f891-4898-8a90-e4ac12dd79e1',
        'Version: 2021-07-28',
    ],
]);

$ghlResponse = curl_exec($curl);
$ghlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

if (curl_errno($curl)) {
    error_log('[db_insert] GHL error: ' . curl_error($curl));
} else {
    $ghlDecoded = json_decode($ghlResponse, true);

    // Store the GHL contact id in session so db_update.php (called later,
    // at checkout) can PUT updates to this exact contact instead of having
    // to search for it.
    if (
        ($ghlHttpCode == 200 || $ghlHttpCode == 201) &&
        isset($ghlDecoded['contact']['id'])
    ) {
        $_SESSION['ghlcontactId'] = $ghlDecoded['contact']['id'];
    } elseif (
        // GHL rejects a create when this location already has a contact
        // with this email ("This location does not allow duplicated
        // contacts.") - it hands back that existing contact's id right in
        // the error body, so reuse it instead of leaving the session unset
        // and losing every later GHL update for a repeat-email visitor.
        $ghlHttpCode == 400 &&
        isset($ghlDecoded['meta']['contactId'])
    ) {
        $_SESSION['ghlcontactId'] = $ghlDecoded['meta']['contactId'];
        error_log('[db_insert] GHL contact already existed for this email - reusing contact id ' . $ghlDecoded['meta']['contactId']);
    } else {
        error_log('[db_insert] GHL contact id not found in response (HTTP ' . $ghlHttpCode . '): ' . $ghlResponse);
    }
}

curl_close($curl);

/*
|--------------------------------------------------------------------------
| Insert remedy
|--------------------------------------------------------------------------
*/

$columns = array_map(
    fn ($column) => "`{$column}`",
    array_keys($record)
);

$placeholders = implode(
    ', ',
    array_fill(0, count($record), '?')
);

$sql = 'INSERT INTO `chime-health-rnad-v2` (' .
    implode(', ', $columns) .
    ', `timing`) VALUES (' .
    $placeholders .
    ', NOW())';

$types = '';
$values = [];

foreach ($record as $value) {
    if (is_array($value)) {
        $value = implode(', ', $value);
    }

    if (is_bool($value)) {
        $value = $value ? 1 : 0;
    }

    $types .= is_int($value) ? 'i' : 's';
    $values[] = $value;
}

$stmt = $con->prepare($sql);

if (!$stmt) {
    error_log('[db_insert] Prepare failed: ' . $con->error);
    respond(false, 'Unable to save your information right now.', 500);
}

$stmt->bind_param($types, ...$values);

if (!$stmt->execute()) {
    error_log('[db_insert] Execute failed: ' . $stmt->error);
    $stmt->close();

    respond(false, 'Unable to save your information right now.', 500);
}

$insertId = $stmt->insert_id;

// Lets any later page in this same browser session (e.g. process.php) look
// this `remedy` row back up without needing prospect_id on the URL.
$_SESSION['last_db_insert_id'] = $insertId;

$stmt->close();

// The caller (quiz-flow.js) may not always pass a prospect_id through from
// create_prospect.php — fall back to this row's own id so `prospectid` is
// never left empty. db_update.php (called later at checkout) looks rows up
// by this column, so it must always be set to something.
if (trim((string) $prospectId) === '') {
    $prospectId = (string) $insertId;

    $backfillStmt = $con->prepare('UPDATE `chime-health-rnad-v2` SET `prospectid` = ? WHERE `id` = ?');
    if ($backfillStmt) {
        $backfillStmt->bind_param('si', $prospectId, $insertId);
        $backfillStmt->execute();
        $backfillStmt->close();
    } else {
        error_log('[db_insert] Failed to backfill prospectid: ' . $con->error);
    }
}

respond(true, 'Your information has been saved successfully.', 200, [
    'id' => $insertId,
    'prospect_id' => $prospectId,
    'ghl_http_code' => $ghlHttpCode,
]);