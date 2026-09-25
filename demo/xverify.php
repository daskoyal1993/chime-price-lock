<?php
header("Content-Type: application/json");

$email = $_GET['email'] ?? null;
$phone = $_GET['phone'] ?? null;

$api_key = "1024692-6A65809C";
//$domain  = "inv-health.com";
$domain  = "amerilean.com";

$response = [
    "email_status" => null,
    "phone_status" => null
];

/* -------------------------------
   EMAIL VERIFICATION (if sent)
--------------------------------*/
if ($email) {
    $emailUrl = "https://api.xverify.com/v2/ev/?email=" . urlencode($email) .
                "&type=json&api_key={$api_key}&domain={$domain}";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $emailUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET"
    ]);

    $emailResponse = curl_exec($curl);
    curl_close($curl);

    $emailData = json_decode($emailResponse, true);
    $response["email_status"] = $emailData["status"] ?? "unknown";
}

/* -------------------------------
   PHONE VERIFICATION (if sent)
--------------------------------*/
if ($phone) {
    $phoneUrl = "https://api.xverify.com/v2/pv/?phone=" . urlencode($phone) .
                "&type=json&api_key={$api_key}&domain={$domain}";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $phoneUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET"
    ]);

    $phoneResponse = curl_exec($curl);
    curl_close($curl);

    $phoneData = json_decode($phoneResponse, true);
    $response["phone_status"] = $phoneData["status"] ?? "unknown";
}

echo json_encode($response);
exit;

