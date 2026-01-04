<?php
// register_urls.php
// Use this to register Validation & Confirmation URLs with Safaricom.
// Update $env and $validationURL / $confirmationURL as needed.

require_once __DIR__ . '/access_token.php';

$shortCode = '4168673';                // your shortcode (from you)
$env = 'production';                      // or 'production' when ready
$validationURL = 'https://e-chama.gt.tc/pay/validation.php';
$confirmationURL = 'https://e-chama.gt.tc/pay/confirmation.php';

$mgr = new MpesaToken();
$mgr->env = $env;
$token = $mgr->getToken();

if (!$token) {
    echo json_encode(['error' => 'Failed to obtain access token']);
    exit;
}

$endpoint = $env === 'production'
    ? 'https://api.safaricom.co.ke/mpesa/c2b/v2/registerurl'
    : 'https://sandbox.safaricom.co.ke/mpesa/c2b/v2/registerurl';

$payload = [
    'ShortCode' => $shortCode,
    'ResponseType' => 'Completed',
    'ValidationURL' => $validationURL,
    'ConfirmationURL' => $confirmationURL
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$res = curl_exec($ch);
if (curl_errno($ch)) {
    echo json_encode(['error' => curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

echo $res;
