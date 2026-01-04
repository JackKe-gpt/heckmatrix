<?php
// test.php – simulate M-Pesa C2B callback

// ----------------------------
// CONFIG: set your confirmation URL
// ----------------------------
$confirmation_url = 'https://e-chama.gt.tc/pay/confirmation.php';

// ----------------------------
// SIMULATED PAYMENT DATA
// ----------------------------
$payload = [
    "TransID" => "TEST" . rand(1000, 9999),
    "TransAmount" => 100, // test amount
    "MSISDN" => "254700000000",
    "BillRefNumber" => "42187416", // national_id OR phone_number of test customer
    "TransactionType" => "PayBill",
    "TransTime" => date('YmdHis')
];

// Convert payload to JSON
$data_json = json_encode($payload);

// ----------------------------
// CURL POST to confirmation.php
// ----------------------------
$ch = curl_init($confirmation_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

// ----------------------------
// SHOW RESPONSE
// ----------------------------
if ($err) {
    echo "cURL Error: $err";
} else {
    echo "Response from confirmation.php:\n";
    echo $response;
}
?>
