<?php
require_once '../includes/db.php';
require_once 'auth.php';
require_login();

// Date filter
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Fetch C2B transactions with reconciliation info
$query = "
    SELECT mpesa_payments.*, customers.first_name, customers.middle_name, customers.surname, customers.customer_code, loans.id AS loan_id
    FROM mpesa_payments
    LEFT JOIN customers ON customers.id = mpesa_payments.customer_id
    LEFT JOIN loans ON loans.customer_id = customers.id AND loans.status IN ('Active','Approved','Pending Disbursement')
    WHERE DATE(mpesa_payments.transaction_time) BETWEEN '$start_date' AND '$end_date'
    ORDER BY mpesa_payments.transaction_time DESC
";
$result = mysqli_query($conn, $query);

// Totals
$total_amount = 0;
$total_matched = 0;
$total_unmatched = 0;
$transactions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $total_amount += floatval($row['amount']);
    $raw = $row['raw_callback'];
    $billRef = null;
    if ($raw) {
        $callback = json_decode($raw, true);
        if (isset($callback['BillRefNumber'])) $billRef = $callback['BillRefNumber'];
    }
    // Determine if payment matches a customer code (loan)
    $matched = ($billRef && $row['customer_code'] && $billRef === $row['customer_code'] && $row['loan_id'] ? 'Matched' : 'Unmatched');
    if ($matched === 'Matched') $total_matched += floatval($row['amount']);
    else $total_unmatched += floatval($row['amount']);

    $transactions[] = [
        'trans_id' => $row['trans_id'],
        'customer_name' => $row['first_name'].' '.$row['middle_name'].' '.$row['surname'],
        'phone' => $row['phone'],
        'amount' => $row['amount'],
        'bill_ref' => $billRef ?? '-',
        'loan_id' => $row['loan_id'] ?? null,
        'date' => $row['transaction_time'],
        'status' => $matched
    ];
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>C2B Reconciliation - Faida SACCO</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
.brand { color: #15a362; }
.brand-bg { background-color: #15a362; }
.brand-hover:hover { background-color: #128d51; }
</style>
</head>
<body class="bg-gray-100 p-6">

<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <h1 class="text-xl font-bold brand mb-6">C2B Reconciliation</h1>

    <form method="GET" class="flex gap-2 mb-6">
        <label>Start Date: <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="border px-2 py-1 rounded"></label>
        <label>End Date: <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="border px-2 py-1 rounded"></label>
        <button type="submit" class="brand-bg text-white px-3 py-1 rounded brand-hover">Filter</button>
    </form>

    <?php if (count($transactions) > 0): ?>
    <table class="w-full text-sm text-left text-gray-700 mb-6">
        <thead class="bg-gray-100 text-xs uppercase">
            <tr>
                <th class="px-4 py-2">#</th>
                <th class="px-4 py-2">Customer</th>
                <th class="px-4 py-2">Phone</th>
                <th class="px-4 py-2">Amount</th>
                <th class="px-4 py-2">Bill Ref</th>
                <th class="px-4 py-2">Loan ID</th>
                <th class="px-4 py-2">Transaction ID</th>
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php $i = 1; foreach ($transactions as $row): ?>
            <tr>
                <td class="px-4 py-2"><?= $i++ ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['customer_name']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['phone']) ?></td>
                <td class="px-4 py-2">KES <?= number_format($row['amount'], 2) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['bill_ref']) ?></td>
                <td class="px-4 py-2"><?= $row['loan_id'] ?? '-' ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($row['trans_id']) ?></td>
                <td class="px-4 py-2"><?= date('Y-m-d H:i:s', strtotime($row['date'])) ?></td>
                <td class="px-4 py-2 <?= $row['status'] === 'Matched' ? 'text-green-600' : 'text-red-600' ?>"><?= $row['status'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="bg-gray-100 font-bold">
                <td colspan="3" class="px-4 py-2">Totals</td>
                <td class="px-4 py-2">KES <?= number_format($total_amount, 2) ?></td>
                <td colspan="1">Matched: KES <?= number_format($total_matched, 2) ?></td>
                <td colspan="2">Unmatched: KES <?= number_format($total_unmatched, 2) ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
        <p class="text-gray-600">No transactions found for the selected period.</p>
    <?php endif; ?>
</div>

</body>
</html>
