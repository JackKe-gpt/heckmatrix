<?php
require_once 'auth.php';
require_login();

include 'includes/db.php';
require_once __DIR__ . '/../pay/access_token.php'; // For B2C

// Format phone number for MPESA
function formatPhone($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (strpos($phone, '254') === 0) return $phone;
    if (strpos($phone, '0') === 0) return '254' . substr($phone, 1);
    return $phone;
}

// Send B2C request
function mpesaB2C($loan_id, $phone, $amount) {
    $initiator = "YOUR_INITIATOR_NAME";
    $securityCredential = "YOUR_SECURITY_CREDENTIAL";
    $shortcode = "YOUR_PAYBILL_NUMBER";

    $token = getAccessToken();
    $phone = formatPhone($phone);

    $payload = [
        "InitiatorName" => $initiator,
        "SecurityCredential" => $securityCredential,
        "CommandID" => "BusinessPayment",
        "Amount" => intval($amount),
        "PartyA" => $shortcode,
        "PartyB" => $phone,
        "Remarks" => "Loan Disbursement",
        "QueueTimeOutURL" => "https://yourdomain.com/heckmatrix/mpesa/b2c_timeout.php",
        "ResultURL" => "https://yourdomain.com/heckmatrix/mpesa/b2c_result.php",
        "Occasion" => "Loan_$loan_id"
    ];

    $ch = curl_init("https://api.safaricom.co.ke/mpesa/b2c/v1/paymentrequest");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($response, true);

    if (isset($res['ConversationID'])) {
        mysqli_query($GLOBALS['conn'], "
            UPDATE loans SET
                mpesa_conversation_id = '{$res['ConversationID']}',
                mpesa_originator_id = '{$res['OriginatorConversationID']}',
                status = 'Pending Disbursement'
            WHERE id = '$loan_id'
        ");
    }

    return $res;
}

// Handle disbursement submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loan_id'])) {
    $loan_id = $_POST['loan_id'];
    $transaction_id = $_POST['transaction_id'];
    $disbursed_at = date('Y-m-d H:i:s');

    // Fetch loan and customer info
    $loan_result = mysqli_query($conn, "
        SELECT loans.*, customers.customer_account_balance, customers.id AS customer_id, customers.national_id, customers.mpesa_number
        FROM loans 
        JOIN customers ON customers.id = loans.customer_id 
        WHERE loans.id = '$loan_id'
        LIMIT 1
    ");
    $loan_row = mysqli_fetch_assoc($loan_result);

    if ($loan_row) {
        $customer_id = $loan_row['customer_id'];
        $customer_balance = floatval($loan_row['customer_account_balance']);
        $total_repayable = floatval($loan_row['total_repayable']);
        $national_id = $loan_row['national_id'];
        $phone = $loan_row['mpesa_number'];

        // Optionally send via MPESA B2C
        $b2c_response = mpesaB2C($loan_id, $phone, $loan_row['principal_amount']);

        // Record manual disbursement (for tracking in DB)
        mysqli_query($conn, "
            UPDATE loans 
            SET 
                transaction_id = '$transaction_id',
                disbursed_at = '$disbursed_at',
                disbursed_date = CURDATE(),
                due_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            WHERE id = '$loan_id'
        ");

        // Activate customer
        mysqli_query($conn, "UPDATE customers SET status='Active' WHERE id='$customer_id'");

        // Apply any overpayment from customer_account_balance
        if ($customer_balance > 0) {
            $paid = mysqli_fetch_assoc(mysqli_query($conn, "
                SELECT IFNULL(SUM(principal_amount),0) AS total_paid 
                FROM loan_payments 
                WHERE loan_id = '$loan_id'
            "))['total_paid'];
            $remaining = $total_repayable - floatval($paid);

            $apply_amount = min($customer_balance, $remaining);
            if ($apply_amount > 0) {
                mysqli_query($conn, "
                    INSERT INTO loan_payments (loan_id, customer_id, id_number, principal_amount, payment_date)
                    VALUES ('$loan_id','$customer_id','$national_id','$apply_amount', CURDATE())
                ");
                mysqli_query($conn, "
                    UPDATE customers 
                    SET customer_account_balance = customer_account_balance - $apply_amount
                    WHERE id='$customer_id'
                ");

                $new_paid = mysqli_fetch_assoc(mysqli_query($conn, "
                    SELECT IFNULL(SUM(principal_amount),0) AS total_paid 
                    FROM loan_payments 
                    WHERE loan_id = '$loan_id'
                "))['total_paid'];

                if (round($new_paid,2) >= round($total_repayable,2)) {
                    mysqli_query($conn, "UPDATE loans SET status='Inactive' WHERE id='$loan_id'");
                }
            }
        }

        echo "<script>alert('Loan disbursed successfully! Any available overpayment applied.'); window.location.href='disburse.php';</script>";
        exit;
    }
}

// ===== Loan Fetch with status filter =====
$status_filter = "";
$status_label = "All Disbursements";
if (isset($_GET['status']) && $_GET['status'] !== "") {
    $status = mysqli_real_escape_string($conn, $_GET['status']);
    $status_filter = "AND loans.status = '$status'";
    $status_label = $status;
} else {
    $status_filter = "AND loans.status IN ('Approved','Pending Disbursement')";
}

$loans = mysqli_query($conn, "
    SELECT loans.*, customers.first_name, customers.middle_name, customers.surname 
    FROM loans 
    JOIN customers ON customers.id = loans.customer_id 
    WHERE 1=1 $status_filter
    ORDER BY loans.id DESC
");

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Loan Disbursement - HeckMatrix</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
.brand { color:#15a362; }
.brand-bg { background-color:#15a362; }
.brand-hover:hover { background-color:#128d51; }
</style>
</head>
<body class="bg-gray-100 p-6">
<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
<h1 class="text-xl font-bold brand mb-6">Disburse Loans (<?= htmlspecialchars($status_label) ?>)</h1>

<?php if (mysqli_num_rows($loans) > 0): ?>
<table class="w-full text-sm text-left text-gray-700 mb-8">
<thead class="bg-gray-100 text-xs uppercase">
<tr>
<th class="px-4 py-3">#</th>
<th class="px-4 py-3">Customer</th>
<th class="px-4 py-3">Amount</th>
<th class="px-4 py-3">Installment</th>
<th class="px-4 py-3">Duration</th>
<th class="px-4 py-3">Status</th>
<th class="px-4 py-3">Disburse</th>
</tr>
</thead>
<tbody class="divide-y">
<?php $i=1; while($row=mysqli_fetch_assoc($loans)): ?>
<tr>
<td class="px-4 py-2"><?= $i++ ?></td>
<td class="px-4 py-2"><?= $row['first_name'].' '.$row['middle_name'].' '.$row['surname'] ?></td>
<td class="px-4 py-2">KES <?= number_format($row['principal_amount'],2) ?></td>
<td class="px-4 py-2">KES <?= number_format($row['weekly_installment'],2) ?></td>
<td class="px-4 py-2"><?= $row['duration_weeks'] ?> weeks</td>
<td class="px-4 py-2"><?= $row['status'] ?></td>
<td class="px-4 py-2">
<?php if($row['status']=='Approved'||$row['status']=='Pending Disbursement'): ?>
<form method="POST" class="flex flex-col md:flex-row gap-2 items-start md:items-center">
<input type="hidden" name="loan_id" value="<?= $row['id'] ?>" />
<input type="text" name="transaction_id" placeholder="Transaction ID" required class="px-2 py-1 border rounded" />
<button type="submit" class="brand-bg text-white px-3 py-1 rounded brand-hover">Disburse</button>
</form>
<?php else: ?>
<span class="text-gray-500">N/A</span>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<?php else: ?>
<p class="text-gray-600">No loans available for disbursement.</p>
<?php endif; ?>
</div>
</body>
</html>
