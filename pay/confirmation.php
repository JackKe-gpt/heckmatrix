<?php
// confirmation.php – LIVE C2B V2 CALLBACK
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

// ----------------------------
// 1) Read & Log Raw Callback
// ----------------------------
$raw = file_get_contents('php://input');
$logFile = __DIR__ . '/logs/confirmation_' . date('Ymd_His') . '.json';
file_put_contents($logFile, $raw . PHP_EOL, FILE_APPEND);

$payload = json_decode($raw, true);

// Always respond success even if no payload (to avoid M-Pesa retries)
if (!$payload) {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

// ----------------------------
// 2) Extract M-Pesa Fields
// ----------------------------
$TransID     = $payload['TransID']     ?? $payload['TransactionID'] ?? null;
$TransAmount = $payload['TransAmount'] ?? $payload['Amount'] ?? 0;
$TransAmount = floatval($TransAmount);

$MSISDN      = $payload['MSISDN']      ?? $payload['Msisdn']       ?? null;
$BillRef     = $payload['BillRefNumber'] ?? $payload['BillRefNo'] ?? $payload['AccountNumber'] ?? null;

// Clean MSISDN + billref
$cleanMSISDN = $MSISDN ? ltrim($MSISDN, '+') : null;
$cleanRef    = $BillRef ? ltrim($BillRef, '+') : null;

// ----------------------------
// 3) Duplicate Transaction Check
// ----------------------------
if ($TransID) {
    $stmt = $conn->prepare("SELECT id FROM mpesa_payments WHERE trans_id = ? LIMIT 1");
    $stmt->bind_param("s", $TransID);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Duplicate']);
        exit;
    }
}

// ----------------------------
// 4) Identify Customer (national_id OR phone_number OR mpesa_number)
// ----------------------------
$customer_id = null;

if ($cleanRef) {
    $q = $conn->prepare("
        SELECT id 
        FROM customers 
        WHERE national_id = ?
           OR phone_number = ?
           OR mpesa_number = ?
        LIMIT 1
    ");
    $q->bind_param("sss", $cleanRef, $cleanRef, $cleanRef);
    $q->execute();
    $r = $q->get_result();

    if ($r && $r->num_rows === 1) {
        $customer_id = intval($r->fetch_assoc()['id']);
    }
}

// fallback — try MSISDN if no match yet
if (!$customer_id && $cleanMSISDN) {
    $q2 = $conn->prepare("
        SELECT id 
        FROM customers 
        WHERE phone_number = ?
           OR mpesa_number = ?
        LIMIT 1
    ");
    $q2->bind_param("ss", $cleanMSISDN, $cleanMSISDN);
    $q2->execute();
    $r2 = $q2->get_result();

    if ($r2 && $r2->num_rows === 1) {
        $customer_id = intval($r2->fetch_assoc()['id']);
    }
}

// ----------------------------
// 5) Get ACTIVE Loan for This Customer
// ----------------------------
$loan_id = null;

if ($customer_id) {
    $lq = $conn->prepare("
        SELECT id 
        FROM loans 
        WHERE customer_id = ?
          AND status = 'Active'
        LIMIT 1
    ");
    $lq->bind_param("i", $customer_id);
    $lq->execute();
    $lr = $lq->get_result();

    if ($lr && $lr->num_rows === 1) {
        $loan_id = intval($lr->fetch_assoc()['id']);
    }
}

// ----------------------------
// 6) Insert Raw Payment Into mpesa_payments
// ----------------------------
$raw_json = json_encode($payload);

$ins = $conn->prepare("
    INSERT INTO mpesa_payments
    (trans_id, transaction_time, amount, phone, account_number, customer_id, loan_id, raw_callback, created_at)
    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, NOW())
");
$ins->bind_param(
    "sdssiis",
    $TransID,
    $TransAmount,
    $cleanMSISDN,
    $cleanRef,
    $customer_id,
    $loan_id,
    $raw_json
);
$ins->execute();

// ----------------------------
// 7) Post Payment to Loan (if linked)
// ----------------------------
if ($customer_id && $loan_id && $TransAmount > 0) {

    // Insert into loan_payments
    $blank_id = "";
    $lp = $conn->prepare("
        INSERT INTO loan_payments
        (loan_id, customer_id, id_number, principal_amount, payment_date, method, created_at, created_by)
        VALUES (?, ?, ?, ?, CURDATE(), 'Mpesa', NOW(), 0)
    ");
    $lp->bind_param("iids", $loan_id, $customer_id, $blank_id, $TransAmount);
    $lp->execute();

    // Update customer balance
    $up = $conn->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
    $up->bind_param("di", $TransAmount, $customer_id);
    $up->execute();
}

// ----------------------------
// 8) Final Response to Safaricom
// ----------------------------
echo json_encode([
    "ResultCode" => 0,
    "ResultDesc" => "Accepted"
]);
exit;

?>
