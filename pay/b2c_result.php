<?php
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

// ========================
// LOG RAW CALLBACK
// ========================
$raw = file_get_contents('php://input');
file_put_contents(__DIR__.'/b2c_result.log', date('Y-m-d H:i:s')." ".$raw.PHP_EOL, FILE_APPEND);

$data = json_decode($raw, true);
if (!$data || !isset($data['Result'])) {
    exit;
}

$result = $data['Result'];
$conversationID = $result['ConversationID'] ?? '';
$resultCode = intval($result['ResultCode'] ?? -1);
$resultDesc = mysqli_real_escape_string($conn, $result['ResultDesc'] ?? 'Unknown');

// ========================
// FETCH LOAN & CUSTOMER
// ========================
$loan_query = mysqli_query($conn, "
    SELECT loans.id AS loan_id, loans.customer_id, customers.mpesa_number
    FROM loans
    JOIN customers ON customers.id = loans.customer_id
    WHERE loans.mpesa_conversation_id = '$conversationID'
    LIMIT 1
");

if (!$loan_row = mysqli_fetch_assoc($loan_query)) {
    exit;
}

$loan_id = $loan_row['loan_id'];
$customer_id = $loan_row['customer_id'];

// ========================
// UPDATE LOAN STATUS
// ========================
if ($resultCode === 0) {
    // SUCCESSFUL DISBURSEMENT
    mysqli_query($conn, "
        UPDATE loans SET
            status = 'Active',
            disbursed_at = NOW(),
            disbursed_date = CURDATE(),
            due_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY),
            mpesa_result_code = 0,
            mpesa_result_desc = '$resultDesc'
        WHERE id = '$loan_id'
    ");

    // ACTIVATE CUSTOMER
    mysqli_query($conn, "
        UPDATE customers SET status = 'Active'
        WHERE id = '$customer_id'
    ");

} else {
    // FAILED DISBURSEMENT
    mysqli_query($conn, "
        UPDATE loans SET
            status = 'Approved',
            mpesa_result_code = $resultCode,
            mpesa_result_desc = '$resultDesc'
        WHERE id = '$loan_id'
    ");
}

// ========================
// OPTIONAL: LOG B2C TRANSACTION
// ========================
mysqli_query($conn, "
    INSERT INTO mpesa_payments (
        trans_id, transaction_time, amount, phone, account_number, customer_id, loan_id, raw_callback
    ) VALUES (
        '".($result['TransactionID'] ?? 'N/A')."',
        NOW(),
        '".($result['TransactionAmount'] ?? 0)."',
        '".($loan_row['mpesa_number'])."',
        '".($result['ReceiverPartyPublicName'] ?? '')."',
        '$customer_id',
        '$loan_id',
        '".mysqli_real_escape_string($conn, $raw)."'
    )
");
