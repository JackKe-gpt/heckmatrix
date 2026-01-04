<?php
require_once __DIR__ . '/../includes/db.php';

// Get raw callback from Safaricom
$raw = file_get_contents('php://input');
file_put_contents(__DIR__.'/b2c_timeout.log', date('Y-m-d H:i:s')." ".$raw.PHP_EOL, FILE_APPEND);

// Decode JSON safely
$data = json_decode($raw, true);
$conversationID = $data['ConversationID'] ?? null;

if ($conversationID) {
    // Mark the loan as 'Pending Disbursement - Timeout' for monitoring
    mysqli_query($conn, "
        UPDATE loans 
        SET status = 'Pending Disbursement - Timeout', 
            mpesa_result_code = -1,
            mpesa_result_desc = 'B2C Timeout'
        WHERE mpesa_conversation_id = '$conversationID'
    ");
}
