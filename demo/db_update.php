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

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body) || !is_array($body['sessionData'] ?? null)) {
    respond(false, 'No session data was received.', 400);
}

// The front end posts the full amerilean_funnel blob here. Steps 17/18 have
// already merged their answers into that same blob client-side (via
// FunnelState.save()), so this is already a superset of what db_insert.php
// wrote at step16 — no server-side merging of step17/18 fields is needed,
// only overwriting the stored `quiz` column with this fuller snapshot.
$data = $body['sessionData'];

$prospectId = trim((string) ($data['prospect_id'] ?? ''));

if ($prospectId === '') {
    respond(false, 'Missing prospect_id.', 400);
}

$purchased = !empty($_GET['purchased']) ? 1 : 0;

/*
|--------------------------------------------------------------------------
| Look up the row db_insert.php created at step16
|--------------------------------------------------------------------------
*/

$stmt = $con->prepare(
    'SELECT id, quiz FROM `chime-health-rnad-v2` WHERE `prospectid` = ? ORDER BY id DESC LIMIT 1'
);

if (!$stmt) {
    error_log('[db_update] Prepare (select) failed: ' . $con->error);
    respond(false, 'Unable to update your information right now.', 500);
}

$stmt->bind_param('s', $prospectId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    error_log('[db_update] No remedy row found for prospectid=' . $prospectId);
    respond(false, 'We could not find your information. Please go back and try again.', 200);
}

$insertId = (int) $row['id'];

$existingQuiz = json_decode($row['quiz'] ?? '', true);
if (!is_array($existingQuiz)) {
    $existingQuiz = [];
}

// New data wins on any overlapping key; nothing already stored is dropped.
$mergedQuiz = array_merge($existingQuiz, $data);

$quizJson = json_encode($mergedQuiz, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($quizJson === false) {
    respond(false, 'Unable to prepare quiz data.', 400);
}

/*
|--------------------------------------------------------------------------
| Update the remedy row
|--------------------------------------------------------------------------
*/

// address1/city/state/postalCode come from the checkout page's shipping
// form (posted fresh, not from the amerilean_funnel blob) — this is the
// first and only place this funnel collects a shipping address, so these
// always overwrite whatever (nothing) was there before.



$address1   = trim((string) ($data['address1'] ?? ''));
$postalCode = trim((string) ($data['postalCode'] ?? ''));
$city       = trim((string) ($data['city'] ?? ''));
$state      = trim((string) ($data['state'] ?? ''));

$stmt = $con->prepare(
    'UPDATE `chime-health-rnad-v2` SET `quiz` = ?, `purchased` = ?, `address1` = ?, `postalCode` = ?, `city` = ?, `state` = ?, `timing` = NOW() WHERE `id` = ?'
);

if (!$stmt) {
    error_log('[db_update] Prepare (update) failed: ' . $con->error);
    respond(false, 'Unable to update your information right now.', 500);
}

$stmt->bind_param(
    'sissssi',
    $quizJson,
    $purchased,
    $address1,
    $postalCode,
    $city,
    $state,
    $insertId
);

if (!$stmt->execute()) {
    error_log('[db_update] Execute (update) failed: ' . $stmt->error);
    $stmt->close();
    respond(false, 'Unable to update your information right now.', 500);
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Convert arrays to strings for GHL (same helper as db_insert.php)
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Update GHL contact
|--------------------------------------------------------------------------
| db_insert.php now stores the GHL contact id it gets back from creating
| the contact into $_SESSION['ghlcontactId']. As long as the visitor's
| session cookie is still around at checkout (same browser, same site),
| that session value is available here too, so we can PUT straight to that
| contact instead of guessing/searching for it.
|--------------------------------------------------------------------------
*/

$email = trim((string) ($mergedQuiz['email'] ?? ''));
$firstName = trim((string) ($mergedQuiz['first_name'] ?? ''));
$lastName = trim((string) ($mergedQuiz['last_name'] ?? ''));
$phone = trim((string) ($mergedQuiz['phone'] ?? ''));

$ghlContactId = $_SESSION['ghlcontactId'] ?? '';
$ghlHttpCode = null;

if ($ghlContactId !== '') {
    $heightFeet = $mergedQuiz['heightFeet'] ?? '';
    $heightInches = $mergedQuiz['heightInches'] ?? '';
    $height = '';
    if (is_numeric($heightFeet) && is_numeric($heightInches)) {
        $height = ((int) $heightFeet) . "'" . ((int) $heightInches) . '"';
    }

    /*
    |----------------------------------------------------------------------
    | Step17 / Step18 answers
    |----------------------------------------------------------------------
    | Pulled into their own variables so they're easy to wire into
    | customFields below. TODO: replace the 'id' placeholders with the real
    | GHL custom field IDs once you have them — the 'key' names are just
    | guesses at GHL's naming convention and can be changed too.
    |----------------------------------------------------------------------
    */
    $bloodPressure = $mergedQuiz['bloodPressure'] ?? '';
    $restingHeartRate = $mergedQuiz['restingHeartRate'] ?? '';
    $lastMedicalEvaluation = $mergedQuiz['lastMedicalEvaluation'] ?? '';
    $lastLabTests = $mergedQuiz['lastLabTests'] ?? '';
    $prescriptionMedications = $mergedQuiz['prescriptionMedications'] ?? '';
    $prescriptionMedicationsInfo = $mergedQuiz['prescriptionMedications_info'] ?? '';
    $medicationAllergiesAnswer = $mergedQuiz['medicationAllergies'] ?? '';
    $medicationAllergiesInfo = $mergedQuiz['medicationAllergies_info'] ?? '';
    $additionalDocInformation = $mergedQuiz['additionalDocInformation'] ?? '';
    $additionalDocInformationInfo = $mergedQuiz['additionalDocInformation_info'] ?? '';
    $please_affirm_you_are_not_currently_on_any_of_the_following_medications = valueToString($mergedQuiz['current_glucose_medications'] ?? '');


    $medicationAllergiesList = valueToString($mergedQuiz['medication_allergies'] ?? '');
    $glp1_allergies = valueToString($mergedQuiz['glp1_allergies'] ?? '');

    $currentMedicationsList = valueToString($mergedQuiz['current_medications'] ?? '');
    $doctorNote = $mergedQuiz['doctor_note'] ?? '';
    $consentsList = valueToString($mergedQuiz['consents'] ?? '');

    /*
    |----------------------------------------------------------------------
    | Step18 consent timestamps -> GHL date/time custom fields
    |----------------------------------------------------------------------
    | Step18 (quiz-flow.js) saves consent_timestamps into FunnelState keyed
    | by the GHL-facing consent name (TRUTHFULNESS, TELEHEALTH, INFORMED
    | CONSENT, GLP CONSENT), each holding a server-time {date, time} pair.
    | That blob rides along in sessionData/mergedQuiz just like every other
    | quiz answer, so it's read the same way here and mapped to the actual
    | GHL custom field ids for each consent's date and time fields.
    |----------------------------------------------------------------------
    */
    $consentTimestamps = is_array($mergedQuiz['consent_timestamps'] ?? null)
        ? $mergedQuiz['consent_timestamps']
        : [];

    $consentFieldMap = [
        'TRUTHFULNESS' => [
            'date' => ['id' => 'Q9c4SwKWPFLhwjj1JhZB', 'key' => 'contact.date_of_truthfulness_consent'],
            'time' => ['id' => 'acxfTVqN4TfjQnUGsAMC', 'key' => 'contact.time_of_truthfulness_consent'],
        ],
        'TELEHEALTH' => [
            'date' => ['id' => 'MsS99uHwV50GVmCrDomF', 'key' => 'contact.date_of_telehealth_consent'],
            'time' => ['id' => 'NKR9rEn5Kjkvy51M3dFA', 'key' => 'contact.time_of_telehealth_consent'],
        ],
        'INFORMED CONSENT' => [
            'date' => ['id' => 'ovEsLaYN6wdBpW8DQfMv', 'key' => 'contact.date_of_informed_consent'],
            'time' => ['id' => 'dEDxQkqz0ApZIk8FzTNv', 'key' => 'contact.time_of_informed_consent'],
        ],
        'GLP CONSENT' => [
            'date' => ['id' => 'EBwjhHrmWoH3mSN6Af7n', 'key' => 'contact.date_of_glp_consent'],
            'time' => ['id' => 'rAR9CnMm6fGcuAVEeHEA', 'key' => 'contact.time_of_glp_consent'],
        ],
    ];

    $consentCustomFields = [];
    foreach ($consentFieldMap as $consentLabel => $fieldIds) {
        $entry = is_array($consentTimestamps[$consentLabel] ?? null) ? $consentTimestamps[$consentLabel] : [];
        $consentDate = trim((string) ($entry['date'] ?? ''));
        $consentTime = trim((string) ($entry['time'] ?? ''));

        if ($consentDate !== '') {
            $consentCustomFields[] = [
                'id' => $fieldIds['date']['id'],
                'key' => $fieldIds['date']['key'],
                'fieldValue' => $consentDate,
            ];
        }
        if ($consentTime !== '') {
            $consentCustomFields[] = [
                'id' => $fieldIds['time']['id'],
                'key' => $fieldIds['time']['key'],
                'fieldValue' => $consentTime,
            ];
        }
    }

    // NOTE: these are the same GHL custom field IDs db_insert.php already
    // uses for step1-6 answers, recomputed from the freshest merged data.
    $contactData = [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'name' => trim($firstName . ' ' . $lastName),
        'email' => $email,
        'phone' => $phone,
        'address1'=> $address1,
        'city' => $city,
        'postalCode' => $postalCode,
        'source' => 'chime-rnad-v2 API',

        'customFields' => [

            // ---- Step17 (medical background) — TODO: replace 'id' placeholders below.
            [
                'id' => '6u6VbRNCIo5Vl2ApnKvr',
                'key' => 'contact.what_is_your_blood_pressure_range',
                'fieldValue' => $bloodPressure,
            ],
            [
                'id' => 'I9bIKwFBZuMU45mykrZW',
                'key' => 'contact.how_about_your_average_resting_heart_rate',
                'fieldValue' => $restingHeartRate,
            ],
            [
                'id' => 'gu59bf88zaue6wFjGiWz',
                'key' => 'contact.when_was_the_last_time_you_had_an_in_person_medical_evaluation',
                'fieldValue' => $lastMedicalEvaluation,
            ],
            [
                'id' => 'GZDmNvsBKWrAKXiCkYfx',
                'key' => 'contact.when_was_the_last_time_you_had_lab_tests_done',
                'fieldValue' => $lastLabTests,
            ],
            [
                'id' => 'lTPXz06QbvoiKqSFaS8F',
                'key' => 'contact.are_you_currently_taking_any_prescription_medications',
                'fieldValue' => $prescriptionMedications,
            ],
            // [
            //     'id' => 'TODO_PRESCRIPTION_MEDICATIONS_INFO_FIELD_ID',
            //     'key' => 'contact.prescription_medications_details',
            //     'fieldValue' => $prescriptionMedicationsInfo,
            // ],
            [
                'id' => 'E5uDMuVAQ1zrKOthruOn',
                'key' => 'contact.do_you_have_any_medication_allergies',
                'fieldValue' => $medicationAllergiesAnswer,
            ],
            // [
            //     'id' => 'TODO_MEDICATION_ALLERGIES_INFO_FIELD_ID',
            //     'key' => 'contact.medication_allergies_details',
            //     'fieldValue' => $medicationAllergiesInfo,
            // ],
            // [
            //     'id' => 'TODO_ADDITIONAL_DOC_INFORMATION_FIELD_ID',
            //     'key' => 'contact.additional_info_for_medical_team',
            //     'fieldValue' => $additionalDocInformation,
            // ],
            // [
            //     'id' => 'TODO_ADDITIONAL_DOC_INFORMATION_INFO_FIELD_ID',
            //     'key' => 'contact.additional_info_for_medical_team_details',
            //     'fieldValue' => $additionalDocInformationInfo,
            // ],

            // ---- Step18 (medication history + consents) — TODO: replace 'id' placeholders below.
            [
                'id' => 'aFyn372YkzAL89vLqQJ8',
                'key' => 'contact.are_you_allergic_to_any_of_the_following_medications_sema_tirz_liraglutide_dulaglutide',
                'fieldValue' => $glp1_allergies,
            ],
            [
                'id' => 'n9B589auiWyGQqoDSKNk',
                'key' => 'contact.current_medications_list',
                'fieldValue' => $please_affirm_you_are_not_currently_on_any_of_the_following_medications,
            ],
            [
                'id' => 'RBNEmCsIGxUCJ400Suh9',
                'key' => 'contact.is_there_anything_you_want_your_doctor_to_know',
                'fieldValue' => $doctorNote,
            ],
            // [
            //     'id' => 'TODO_CONSENTS_FIELD_ID',
            //     'key' => 'contact.consents_agreed',
            //     'fieldValue' => $consentsList,
            // ],
        ],
    ];

    // Only sent when step18 actually recorded that consent's timestamp.
    $contactData['customFields'] = array_merge($contactData['customFields'], $consentCustomFields);

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://services.leadconnectorhq.com/contacts/{$ghlContactId}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'PUT',
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
        error_log('[db_update] GHL error: ' . curl_error($curl));
    } else {
        error_log('[db_update] GHL update HTTP ' . $ghlHttpCode . ': ' . $ghlResponse);
    }

    curl_close($curl);
} else {
    error_log('[db_update] Skipping GHL update: no ghlcontactId in session for prospectid=' . $prospectId);
}

respond(true, 'Your information has been updated successfully.', 200, [
    'id' => $insertId,
    'prospect_id' => $prospectId,
    'ghl_http_code' => $ghlHttpCode,
]);
