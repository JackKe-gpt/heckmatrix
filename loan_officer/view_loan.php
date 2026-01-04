<?php
session_start();
include '../includes/db.php';
include 'header.php';

// Only loan officers (and other allowed roles) can view
if (!isset($_SESSION['admin']) || !in_array($_SESSION['admin']['role'], ['loan_officer','branch_manager','super_admin'])) {
    header("Location: ../index");
    exit;
}

$loan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($loan_id <= 0) {
    http_response_code(400);
    echo "Invalid loan reference.";
    exit;
}

/*
  NOTE: Your DB schema shows `admin_users`, `loan_payments`, `loans`, and `customers`.
  We'll join with admin_users (not admins). We'll fetch payments from loan_payments.
  We use prepared statements to avoid SQL injection.
*/

// Fetch loan + customer + branch + officer info
$stmt = $conn->prepare("
    SELECT 
        l.*,
        c.first_name, c.middle_name, c.surname, c.phone_number, c.national_id AS id_number, c.address,
        b.name AS branch_name,
        au.name AS officer_name, au.id AS officer_user_id
    FROM loans l
    JOIN customers c ON c.id = l.customer_id
    LEFT JOIN branches b ON b.id = c.branch_id
    LEFT JOIN admin_users au ON au.id = l.officer_id
    WHERE l.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    http_response_code(404);
    echo "Loan not found.";
    exit;
}

// Payment totals (sum principal_amount)
$sumStmt = $conn->prepare("SELECT IFNULL(SUM(principal_amount),0) AS total_paid FROM loan_payments WHERE loan_id = ?");
$sumStmt->bind_param("i", $loan_id);
$sumStmt->execute();
$paid_row = $sumStmt->get_result()->fetch_assoc();
$total_paid = (float)($paid_row['total_paid'] ?? 0);
$sumStmt->close();

// Payment history (latest first)
$paymentsStmt = $conn->prepare("
    SELECT id, loan_id, customer_id, id_number, principal_amount, payment_date, method, created_at, created_by
    FROM loan_payments
    WHERE loan_id = ?
    ORDER BY payment_date DESC, id DESC
");
$paymentsStmt->bind_param("i", $loan_id);
$paymentsStmt->execute();
$payments = $paymentsStmt->get_result();

// Derived calculations (safe guards for division by zero)
$total_repayable = (float)($loan['total_repayable'] ?? 0);
$weekly_installment = (float)($loan['weekly_installment'] ?? 0);
$disbursed_date = $loan['disbursed_date'] ? strtotime($loan['disbursed_date']) : null;
$today_ts = strtotime(date('Y-m-d'));
$weeks_passed = 0;
$days_passed = 0;
if ($disbursed_date) {
    $days_passed = (int)floor(($today_ts - $disbursed_date) / (60*60*24));
    $weeks_passed = (int)floor($days_passed / 7);
}
$expected_payment = min($weeks_passed * $weekly_installment, $total_repayable);
$arrears_amount = max(0, $expected_payment - $total_paid);
$loan_balance = max(0, $total_repayable - $total_paid);
$paid_percent = $total_repayable > 0 ? ($total_paid / $total_repayable) * 100 : 0;
$next_payment_date = $loan['disbursed_date'] ? date('d M Y', strtotime($loan['disbursed_date'] . " + {$weeks_passed} week")) : 'N/A';

$full_name = trim(($loan['first_name'] ?? '') . ' ' . ($loan['middle_name'] ?? '') . ' ' . ($loan['surname'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>View Loan — <?= htmlspecialchars($full_name ?: 'Loan') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .brand { color: #15a362; }
    .brand-bg { background-color: #15a362; }
    .brand-hover:hover { background-color: #128d51; }
  </style>
</head>
<body class="bg-gray-100 p-4 sm:p-8 font-sans">
  <div class="max-w-6xl mx-auto bg-white rounded-lg shadow p-6">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl font-bold brand">Loan Details</h1>
        <p class="text-sm text-gray-500 mt-1">Loan reference: <span class="font-medium"><?= htmlspecialchars($loan['loan_code'] ?? '') ?></span></p>
      </div>

    </div>

    <!-- Top summary cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div class="p-4 bg-gray-50 rounded-lg border">
        <div class="text-sm text-gray-500">Customer</div>
        <div class="text-lg font-semibold"><?= htmlspecialchars($full_name) ?></div>
        <div class="text-sm text-gray-600"><?= htmlspecialchars($loan['phone_number'] ?? '') ?></div>
        <div class="text-xs text-gray-500 mt-2">ID: <?= htmlspecialchars($loan['id_number'] ?? '') ?></div>
      </div>

      <div class="p-4 bg-gray-50 rounded-lg border">
        <div class="text-sm text-gray-500">Loan Summary</div>
        <div class="text-lg font-semibold">KES <?= number_format((float)$loan['principal_amount'], 2) ?></div>
        <div class="flex gap-2 text-xs text-gray-600 mt-2">
          <div>Interest rate: <?= htmlspecialchars($loan['interest_rate']) ?>%</div>
          <div>&middot;</div>
          <div>Duration: <?= (int)$loan['duration_weeks'] ?> weeks</div>
        </div>
        <div class="text-xs text-gray-600 mt-1">Weekly Inst: KES <?= number_format($weekly_installment, 2) ?></div>
        <div class="text-xs text-gray-600 mt-1">Disbursed: <?= $loan['disbursed_date'] ? date('d M Y', strtotime($loan['disbursed_date'])) : 'N/A' ?></div>
      </div>

      <div class="p-4 bg-gray-50 rounded-lg border">
        <div class="text-sm text-gray-500">Officer & Branch</div>
        <div class="text-lg font-semibold"><?= htmlspecialchars($loan['officer_name'] ?? 'Unassigned') ?></div>
        <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($loan['branch_name'] ?? '') ?></div>
      </div>
    </div>

    <!-- Payment summary -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="p-4 bg-white rounded-lg border text-center">
        <div class="text-xs text-gray-500">Total Paid</div>
        <div class="text-xl font-bold">KES <?= number_format($total_paid, 2) ?></div>
      </div>

      <div class="p-4 bg-white rounded-lg border text-center">
        <div class="text-xs text-gray-500">Loan Balance</div>
        <div class="text-xl font-bold text-blue-600">KES <?= number_format($loan_balance, 2) ?></div>
      </div>

      <div class="p-4 bg-white rounded-lg border text-center">
        <div class="text-xs text-gray-500">In Arrears</div>
        <div class="text-xl font-bold text-red-600">KES <?= number_format($arrears_amount, 2) ?></div>
      </div>

      <div class="p-4 bg-white rounded-lg border text-center">
        <div class="text-xs text-gray-500">Next Payment</div>
        <div class="text-xl font-bold"><?= htmlspecialchars($next_payment_date) ?></div>
      </div>
    </div>

    <!-- Loan details card -->
    <div class="mb-6 p-4 bg-white rounded-lg border">
      <h2 class="font-semibold text-gray-700 mb-3">Loan Details</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-gray-700">
        <div>
          <div class="text-xs text-gray-500">Principal Amount</div>
          <div class="font-medium">KES <?= number_format((float)$loan['principal_amount'], 2) ?></div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Total Repayable</div>
          <div class="font-medium">KES <?= number_format($total_repayable, 2) ?></div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Weekly Installment</div>
          <div class="font-medium">KES <?= number_format($weekly_installment, 2) ?></div>
        </div>

        <div>
          <div class="text-xs text-gray-500">Total Interest</div>
          <div class="font-medium">KES <?= number_format((float)$loan['total_interest'], 2) ?></div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Weeks Passed</div>
          <div class="font-medium"><?= $weeks_passed ?></div>
        </div>
        <div>
          <div class="text-xs text-gray-500">Paid (%)</div>
          <div class="font-medium"><?= number_format($paid_percent, 1) ?>%</div>
        </div>
      </div>
    </div>

    <!-- Payment history -->
    <div class="mb-6">
      <h2 class="font-semibold text-gray-700 mb-3">Payment History</h2>

      <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-sm divide-y">
          <thead class="bg-gray-50 text-xs text-gray-600 uppercase">
            <tr>
              <th class="px-4 py-3 text-left">Date</th>
              <th class="px-4 py-3 text-right">Principal (KES)</th>
              <th class="px-4 py-3 text-left">Method</th>
              <th class="px-4 py-3 text-left">Created At</th>
              <th class="px-4 py-3 text-left">Created By</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <?php
            if ($payments->num_rows === 0): ?>
              <tr>
                <td colspan="5" class="text-center py-6 text-gray-500">No payments recorded for this loan.</td>
              </tr>
            <?php
            else:
                // Reset pointer (in case used elsewhere)
                $payments->data_seek(0);
                while ($p = $payments->fetch_assoc()):
            ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                <td class="px-4 py-3 text-right">KES <?= number_format((float)$p['principal_amount'], 2) ?></td>
                <td class="px-4 py-3"><?= htmlspecialchars($p['method']) ?></td>
                <td class="px-4 py-3"><?= date('d M Y H:i', strtotime($p['created_at'])) ?></td>
                <td class="px-4 py-3">
                  <?php
                    // Attempt to show creator name if available
                    $created_by = (int)($p['created_by'] ?? 0);
                    if ($created_by > 0) {
                        $uQ = $conn->prepare("SELECT name FROM admin_users WHERE id = ? LIMIT 1");
                        $uQ->bind_param("i", $created_by);
                        $uQ->execute();
                        $uR = $uQ->get_result()->fetch_assoc();
                        $uQ->close();
                        echo htmlspecialchars($uR['name'] ?? 'User #' . $created_by);
                    } else {
                        echo '-';
                    }
                  ?>
                </td>
              </tr>
            <?php
                endwhile;
            endif;
            ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Notes (optional, read-only if not implemented) -->
    <div class="mb-6">
      <h2 class="font-semibold text-gray-700 mb-2">Officer Notes</h2>
      <?php
        // If you have a loan_notes table, replace this with an actual fetch. For now we show placeholder.
      ?>
      <div class="p-4 bg-white border rounded text-sm text-gray-600">
        No notes available. <!-- Replace with dynamic content if you have notes implemented -->
      </div>
    </div>

  </div>
</body>
</html>
