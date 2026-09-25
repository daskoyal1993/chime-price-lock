<?php
require __DIR__ . '/vrio-config.php';
header('Content-Type: application/json');

$apiKey = VRIO_API_KEY;
$apiUrl = 'https://api.vrio.app/orders';

$payload = [
    'connection_id' => VRIO_CONNECTION_ID,
    'campaign_id'   => VRIO_CAMPAIGN_ID,

    'first_name' => $_POST['first_name'] ?? '',
    'last_name'  => $_POST['last_name'] ?? '',
    'email'      => $_POST['email'] ?? '',
    'phone'      => $_POST['phone'] ?? '',
];

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'X-Api-Key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

if ($error) {
    echo json_encode([
        'success' => false,
        'message' => $error,
    ]);
    exit;
}

$result = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode([
        'success'     => true,
        'prospect_id' => $result['data']['customer_id']
            ?? $result['customer_id']
            ?? null,
        'order_id' => $result['data']['order_id']
            ?? $result['order_id']
            ?? null,
        'vrio_response' => $result,
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => $result['error']['message']
        ?? $result['message']
        ?? 'Unable to create Vrio prospect.',
    'vrio_response' => $result,
]);