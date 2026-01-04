<?php
require_once __DIR__ . '/access_token.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Format phone number to 2547XXXXXXXX
 */
function formatPhone($phone)
{
    $phone = preg_replace('/\D/', '', $phone);

    if (strpos($phone, '254') === 0 && strlen($phone) === 12) {
        return $phone;
    }

    if (strpos($phone, '0') === 0) {
        return '254' . substr($phone, 1);
    }

    return null; // invalid
}

/**
 * Send B2C payment
 */
function mpesaB2C($loan_id, $amount)
{
    global $conn;

    // ==========================
    // GET CUSTOMER MPESA NUMBER
    // ==========================
    $loan_query = mysqli_query($conn, "
        SELECT loans.id AS loan_id, customers.mpesa_number
        FROM loans
        JOIN customers ON customers.id = loans.customer_id
        WHERE loans.id = '$loan_id'
        LIMIT 1
    ");

    if (!$loan_row = mysqli_fetch_assoc($loan_query)) {
        return [
            'error' => true,
            'message' => 'Loan not found'
        ];
    }

    $mpesa_number = formatPhone($loan_row['mpesa_number']);
    if (!$mpesa_number) {
        return [
            'error' => true,
            'message' => 'Invalid M-Pesa number'
        ];
    }

    if ($amount <= 0) {
        return [
            'error' => true,
            'message' => 'Invalid disbursement amount'
        ];
    }

    // ==========================
    // CONFIGURATION
    // ==========================
    $initiator          = "YOUR_INITIATOR_NAME";
    $securityCredential = "YOUR_SECURITY_CREDENTIAL"; // encrypted
    $shortcode          = "YOUR_PAYBILL_NUMBER";

    $token = getAccessToken();
    if (!$token) {
        return [
            'error' => true,
            'message' => 'Failed to get access token'
        ];
    }

    $payload = [
        "InitiatorName"      => $initiator,
        "SecurityCredential" => $securityCredential,
        "CommandID"          => "BusinessPayment",
        "Amount"             => intval($amount),
        "PartyA"             => $shortcode,
        "PartyB"             => $mpesa_number,
        "Remarks"            => "Loan Disbursement",
        "QueueTimeOutURL"    => "https://yourdomain.com/heckmatrix/mpesa/b2c_timeout.php",
        "ResultURL"          => "https://yourdomain.com/heckmatrix/mpesa/b2c_result.php",
        "Occasion"           => "Loan_$loan_id"
    ];

    // ==========================
    // CURL REQUEST
    // ==========================
    $ch = curl_init("https://api.safaricom.co.ke/mpesa/b2c/v1/paymentrequest");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [
            'error' => true,
            'message' => $curlError
        ];
    }

    $res = json_decode($response, true);

    // ==========================
    // SAVE REQUEST TO LOANS TABLE
    // ==========================
    if (isset($res['ConversationID'], $res['OriginatorConversationID'])) {
        mysqli_query($conn, "
            UPDATE loans SET
                mpesa_conversation_id = '{$res['ConversationID']}',
                mpesa_originator_id   = '{$res['OriginatorConversationID']}',
                status = 'Pending Disbursement'
            WHERE id = '$loan_id'
        ");
    } else {
        return [
            'error' => true,
            'message' => $res['errorMessage'] ?? 'B2C request failed',
            'raw' => $res
        ];
    }

    return $res;
}
