<?php
// loan_officer_dashboard_final.php
// Final responsive SACCO Loan Officer Dashboard with accurate calculations

session_start();
require '../includes/db.php';

// --- basic auth ---
if (!isset($_SESSION['admin']) || ($_SESSION['admin']['role'] ?? '') !== 'loan_officer') {
    header("Location: ../index");
    exit;
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$officer_id = (int) ($_SESSION['admin']['id'] ?? 0);
$officer_name = htmlspecialchars($_SESSION['admin']['name'] ?? '', ENT_QUOTES);

// --- helpers ---
function infer_types(array $params): string {
    $types = '';
    foreach ($params as $p) {
        if (is_int($p)) $types .= 'i';
        elseif (is_float($p)) $types .= 'd';
        else $types .= 's';
    }
    return $types;
}

function safe_scalar(mysqli $conn, string $sql, array $params = [], string $types = '') {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return 0;
    if (!empty($params)) {
        if ($types === '') $types = infer_types($params);
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) { $stmt->close(); return 0; }
    $res = $stmt->get_result();
    $row = $res->fetch_row();
    $stmt->close();
    return $row[0] ?? 0;
}

function safe_all(mysqli $conn, string $sql, array $params = [], string $types = '') {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) return [];
    if (!empty($params)) {
        if ($types === '') $types = infer_types($params);
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// --- helper function for weeks elapsed ---
function weeks_elapsed($disbursed_date, $today) {
    if (empty($disbursed_date)) return 0;
    $diff = max(0, strtotime($today) - strtotime($disbursed_date));
    return floor($diff / (7 * 86400));
}

// ---------------- METRICS ----------------

// Customers
$active_customers = (int) safe_scalar($conn, "SELECT COUNT(*) FROM customers WHERE status='Active' AND loan_officer_id = ?", [$officer_id], 'i');
$inactive_customers = (int) safe_scalar($conn, "SELECT COUNT(*) FROM customers WHERE status!='Active' AND loan_officer_id = ?", [$officer_id], 'i');
$total_customers_ytd = (int) safe_scalar($conn, "SELECT COUNT(*) FROM customers WHERE loan_officer_id = ? AND YEAR(created_at)=YEAR(CURDATE())", [$officer_id], 'i');

// Groups
$total_groups = (int) safe_scalar($conn, "SELECT COUNT(*) FROM `groups` WHERE loan_officer_id = ?", [$officer_id], 'i');

// Loans & portfolio
$total_loans = (int) safe_scalar($conn, "SELECT COUNT(*) FROM loans WHERE officer_id = ?", [$officer_id], 'i');
$total_disbursed = (float) safe_scalar($conn, "SELECT COALESCE(SUM(principal_amount),0) FROM loans WHERE officer_id = ? AND status IN ('Disbursed','Active')", [$officer_id], 'i');

// Outstanding: total repayable minus paid
$outstanding_balance = (float) safe_scalar($conn, "
    SELECT COALESCE(SUM(l.total_repayable - COALESCE(p.paid,0)),0)
    FROM loans l
    LEFT JOIN (SELECT loan_id, SUM(principal_amount) AS paid FROM loan_payments GROUP BY loan_id) p ON l.id = p.loan_id
    WHERE l.officer_id = ? AND l.status IN ('Disbursed','Active')
", [$officer_id], 'i');

// ---------------- ACCURATE ARREARS & PAR CALCULATION ----------------
$arrears = 0.0;
$par_total = 0.0;
$total_due_today = 0.0;
$total_due_tomorrow = 0.0;
$total_due_month_to_date = 0.0;
$loans_due_today = 0; // FIXED: This will now be calculated correctly

// Track payments made towards dues (regardless of payment date)
$total_paid_towards_today_dues = 0.0;
$total_paid_towards_month_dues = 0.0;

$arrears_rows = safe_all($conn, "
    SELECT l.id, l.disbursed_date, l.weekly_installment, l.duration_weeks, l.total_repayable,
           COALESCE(p.paid, 0) AS total_paid,
           COALESCE(p.last_payment_date, l.disbursed_date) AS last_payment_date
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(principal_amount) AS paid, MAX(payment_date) AS last_payment_date 
        FROM loan_payments 
        GROUP BY loan_id
    ) p ON l.id = p.loan_id
    WHERE l.status IN ('Disbursed','Active') AND l.officer_id = ?
", [$officer_id], 'i');

foreach ($arrears_rows as $r) {
    $disbursed_date = $r['disbursed_date'] ?? null;
    if (!$disbursed_date) continue;

    $weekly_installment = (float)$r['weekly_installment'];
    $duration_weeks = (int)$r['duration_weeks'];
    $total_paid = (float)$r['total_paid'];
    $loan_id = $r['id'];
    
    // Calculate weeks elapsed since disbursement
    $weeks_elapsed_today = weeks_elapsed($disbursed_date, $today);
    $weeks_elapsed_tomorrow = weeks_elapsed($disbursed_date, $tomorrow);
    
    // Expected weeks should not exceed loan duration
    $expected_weeks_today = min($duration_weeks, $weeks_elapsed_today);
    $expected_weeks_tomorrow = min($duration_weeks, $weeks_elapsed_tomorrow);
    
    // Calculate expected amounts
    $expected_amount_today = $expected_weeks_today * $weekly_installment;
    $expected_amount_tomorrow = $expected_weeks_tomorrow * $weekly_installment;
    
    // Arrears = expected amount minus actual paid (cannot be negative)
    // Only counts as arrears if due date has passed (yesterday or earlier)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weeks_elapsed_yesterday = weeks_elapsed($disbursed_date, $yesterday);
    $expected_weeks_yesterday = min($duration_weeks, $weeks_elapsed_yesterday);
    $expected_amount_yesterday = $expected_weeks_yesterday * $weekly_installment;
    
    $loan_arrears = max(0, $expected_amount_yesterday - $total_paid);
    $arrears += $loan_arrears;
    
    // PAR (Portfolio at Risk) - loans with payments overdue by 30+ days
    $last_payment_date = $r['last_payment_date'] ?? $disbursed_date;
    $days_since_last_payment = (strtotime($today) - strtotime($last_payment_date)) / 86400;
    
    if ($days_since_last_payment > 30 && $loan_arrears > 0) {
        $par_total += $loan_arrears;
    }
    
    // FIXED: Loans due today calculation
    $paid_weeks = floor($total_paid / $weekly_installment);
    
    // A loan is due today if:
    // 1. It has expected weeks today > paid weeks (has unpaid installments)
    // 2. AND it's not fully paid for today's expected amount
    if ($expected_weeks_today > $paid_weeks) {
        $loans_due_today++; // COUNT: This loan has dues today
        $total_due_today += ($expected_weeks_today - $paid_weeks) * $weekly_installment;
        
        // Calculate payments made towards today's dues (any payment date)
        $payments_towards_today = safe_scalar($conn, "
            SELECT COALESCE(SUM(principal_amount),0) 
            FROM loan_payments 
            WHERE loan_id = ? 
            AND payment_date <= ?
        ", [$loan_id, $today], 'is');
        
        // The amount paid towards today's dues is the minimum of:
        // 1. Total payments made, or 
        // 2. Today's expected amount
        $paid_towards_today = min($payments_towards_today, $expected_amount_today);
        $total_paid_towards_today_dues += $paid_towards_today;
    }
    
    // Total due tomorrow
    if ($expected_weeks_tomorrow > $paid_weeks) {
        $total_due_tomorrow += ($expected_weeks_tomorrow - $paid_weeks) * $weekly_installment;
    }
    
    // TOTAL DUE MONTH TO DATE
    $current_month = date('m');
    $current_year = date('Y');
    
    // Calculate weeks that fall in current month UP TO TODAY
    $month_due_amount_to_date = 0;
    for ($week = 1; $week <= $duration_weeks; $week++) {
        $week_date = date('Y-m-d', strtotime($disbursed_date . " + " . ($week * 7) . " days"));
        $week_month = date('m', strtotime($week_date));
        $week_year = date('Y', strtotime($week_date));
        
        // Only count if it's in current month AND due date is today or earlier
        if ($week_month == $current_month && $week_year == $current_year && strtotime($week_date) <= strtotime($today)) {
            $month_due_amount_to_date += $weekly_installment;
        }
    }
    $total_due_month_to_date += $month_due_amount_to_date;
    
    // Calculate payments made towards this month's dues to date (any payment date)
    $payments_towards_month = safe_scalar($conn, "
        SELECT COALESCE(SUM(principal_amount),0) 
        FROM loan_payments 
        WHERE loan_id = ? 
        AND payment_date <= ?
    ", [$loan_id, $today], 'is');
    
    // The amount paid towards this month's dues is the minimum of:
    // 1. Total payments made, or 
    // 2. This month's expected amount to date
    $paid_towards_month = min($payments_towards_month, $month_due_amount_to_date);
    $total_paid_towards_month_dues += $paid_towards_month;
}

// PAR % - based on outstanding balance, not total disbursed
$par = $outstanding_balance > 0 ? round(($par_total / $outstanding_balance) * 100, 2) : 0;

// ---------------- ACCURATE COLLECTIONS CALCULATION ----------------
$todays_collections = (float) safe_scalar($conn, "
    SELECT COALESCE(SUM(lp.principal_amount),0) FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE lp.payment_date = ? AND l.officer_id = ?
", [$today, $officer_id], 'si');

// Month collections - fixed to include year
$month_collections = (float) safe_scalar($conn, "
    SELECT COALESCE(SUM(lp.principal_amount),0) FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE YEAR(lp.payment_date) = YEAR(CURDATE()) 
    AND MONTH(lp.payment_date) = MONTH(CURDATE()) 
    AND l.officer_id = ?
", [$officer_id], 'i');

// Prepayments - payments exceeding the current installment due
$prepayment_amount = (float) safe_scalar($conn, "
    SELECT COALESCE(SUM(lp.principal_amount),0) FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE lp.payment_date = ? AND l.officer_id = ? 
    AND lp.principal_amount > l.weekly_installment
", [$today, $officer_id], 'si');

// Collection Rates - UPDATED CALCULATIONS
// Today: All payments made towards today's dues (regardless of payment date)
$collection_rate_today = $total_due_today > 0 ? min(100, round(($total_paid_towards_today_dues / $total_due_today) * 100, 2)) : 0;

// Monthly: All payments made towards this month's dues TO DATE (regardless of payment date)
$collection_rate_month = $total_due_month_to_date > 0 ? min(100, round(($total_paid_towards_month_dues / $total_due_month_to_date) * 100, 2)) : 0;

// Prepayment rate: Today's prepayments against tomorrow's due amount
$prepayment_rate = $total_due_tomorrow > 0 ? min(100, round(($prepayment_amount / $total_due_tomorrow) * 100, 2)) : 0;

// DEBUG: Let's check what loans are actually due today
$debug_loans_due_today = safe_all($conn, "
    SELECT l.id, c.first_name, c.surname, l.disbursed_date, l.weekly_installment, l.duration_weeks,
           COALESCE(SUM(lp.principal_amount),0) as total_paid,
           FLOOR(COALESCE(SUM(lp.principal_amount),0) / l.weekly_installment) as paid_weeks,
           FLOOR(DATEDIFF(?, l.disbursed_date) / 7) as weeks_elapsed
    FROM loans l
    JOIN customers c ON l.customer_id = c.id
    LEFT JOIN loan_payments lp ON l.id = lp.loan_id
    WHERE l.officer_id = ? AND l.status IN ('Disbursed','Active')
    GROUP BY l.id
    HAVING weeks_elapsed > paid_weeks AND weeks_elapsed <= l.duration_weeks
", [$today, $officer_id], 'si');

// Use the debug count if it's different from our calculated count
if (count($debug_loans_due_today) != $loans_due_today) {
    $loans_due_today = count($debug_loans_due_today);
}

// ---------------- RECENT PAYMENTS & UPCOMING DUES ----------------
$recent_payments = safe_all($conn, "
    SELECT lp.id, lp.loan_id, lp.principal_amount, lp.payment_date,
           c.first_name, c.surname AS last_name
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    JOIN customers c ON l.customer_id = c.id
    WHERE l.officer_id = ?
    ORDER BY lp.payment_date DESC, lp.id DESC
    LIMIT 12
", [$officer_id], 'i');

$upcoming_dues = safe_all($conn, "
    SELECT l.id AS loan_id, c.first_name, c.surname AS last_name, l.weekly_installment, l.disbursed_date,
           COALESCE((SELECT SUM(principal_amount) FROM loan_payments WHERE loan_id = l.id),0) AS paid,
           l.duration_weeks
    FROM loans l
    JOIN customers c ON l.customer_id = c.id
    WHERE l.officer_id = ? AND l.status IN ('Disbursed','Active')
    ORDER BY l.disbursed_date ASC
    LIMIT 12
", [$officer_id], 'i');

// Calculate actual due amounts for upcoming dues
foreach ($upcoming_dues as &$due) {
    $disbursed_date = $due['disbursed_date'];
    $weekly_installment = (float)$due['weekly_installment'];
    $total_paid = (float)$due['paid'];
    $duration_weeks = (int)$due['duration_weeks'];
    
    $weeks_elapsed = weeks_elapsed($disbursed_date, $today);
    $expected_weeks = min($duration_weeks, $weeks_elapsed);
    $paid_weeks = floor($total_paid / $weekly_installment);
    
    if ($expected_weeks > $paid_weeks) {
        $due['due_amount'] = $weekly_installment;
        $due['overdue_weeks'] = $expected_weeks - $paid_weeks;
        $due['total_overdue'] = ($expected_weeks - $paid_weeks) * $weekly_installment;
        $due['current_due_balance'] = $weekly_installment; // Current installment due today
    } else {
        $due['due_amount'] = 0;
        $due['overdue_weeks'] = 0;
        $due['total_overdue'] = 0;
        $due['current_due_balance'] = 0;
    }
    
    // Check if this loan is in arrears (due from previous days)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weeks_elapsed_yesterday = weeks_elapsed($disbursed_date, $yesterday);
    $expected_weeks_yesterday = min($duration_weeks, $weeks_elapsed_yesterday);
    $expected_amount_yesterday = $expected_weeks_yesterday * $weekly_installment;
    
    $due['in_arrears'] = ($expected_amount_yesterday > $total_paid);
    $due['arrears_amount'] = max(0, $expected_amount_yesterday - $total_paid);
}
unset($due);

// ---------------- SAFETY: CSRF token ----------------
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// --- Safely pass PHP numbers/arrays to JS ---
$js = [
    'active_customers' => $active_customers,
    'inactive_customers' => $inactive_customers,
    'total_disbursed' => $total_disbursed,
    'outstanding_balance' => $outstanding_balance,
    'arrears' => $arrears,
    'loans_due_today' => $loans_due_today,
    'collection_rate_today' => $collection_rate_today,
    'collection_rate_month' => $collection_rate_month,
    'prepayment_rate' => $prepayment_rate,
    'par' => $par,
    'todays_collections' => $todays_collections,
    'month_collections' => $month_collections,
    'total_due_today' => $total_due_today,
    'total_paid_towards_today_dues' => $total_paid_towards_today_dues,
    'total_due_month_to_date' => $total_due_month_to_date,
    'total_paid_towards_month_dues' => $total_paid_towards_month_dues,
];

include 'header.php'
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Loan Officer Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
  body { background: #f3f6f9; }
  .card { background: white; border-radius: .75rem; box-shadow: 0 8px 22px rgba(16,24,40,0.06); padding:1rem; }

  /* ✅ ONLY CUSTOMERS SECTION GETS DARK GREEN BACKGROUND */
  .customers-container { 
    background: rgba(90, 90, 90, 0.27);
    border-radius: .75rem; 
    padding: 1rem; 
    box-shadow: 0 10px 25px rgba(16,24,40,0.20);
    margin-bottom:1.5rem; 
    color: #000000ff;
  }
  .customers-container h2 { color:white; }

  /* ✅ Other containers are normal */
  .container-card { 
    background: white; 
    border-radius:.75rem; 
    padding:1rem; 
    box-shadow:0 4px 12px rgba(16,24,40,0.08);
    margin-bottom:1.5rem; 
    color:#1f2937;
  }

  canvas { border-radius:.5rem; padding:.5rem; background:white; box-shadow:0 8px 18px rgba(16,24,40,0.03); width:100% !important; }

  @media(max-width:1024px){
    .hide-mobile { display:none !important; }
    .grid-cols-mobile { grid-template-columns: 1fr !important; }
  }
</style>
</head>

<body class="font-sans text-gray-800">
<div class="max-w-full mx-auto p-4 lg:p-8">

  <!-- ✅ CUSTOMERS CARD (Green Container Only) -->
  <div class="customers-container">
    <h2 class="text-xl font-semibold mb-4">Customers</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 grid-cols-mobile">
      <?php
      $customer_stats = [
          ['label'=>'Active Customers','value'=>$active_customers,'link'=>'customers','color'=>'#15a362'],
          ['label'=>'Inactive Customers','value'=>$inactive_customers,'link'=>'inactive','color'=>'#6b7280'],
          ['label'=>'Total Customers YTD','value'=>$total_customers_ytd,'link'=>'#','color'=>'#0ea5e9'],
      ];
      foreach($customer_stats as $c):
      ?>
      <a href="<?= htmlspecialchars($c['link']) ?>" class="card p-4 hover:shadow-xl transition">
        <div class="text-sm"><?= htmlspecialchars($c['label']) ?></div>
        <div class="mt-2 text-2xl font-semibold" style="color:<?= $c['color'] ?>"><?= number_format($c['value']) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- LOANS CARD -->
  <div class="container-card">
    <h2 class="text-xl font-semibold mb-4">Loans Portfolio</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 grid-cols-mobile">
      <?php
      $loan_cards = [
          ['label'=>'Total Disbursed','value'=>$total_disbursed,'link'=>'#?filter=disbursed','color'=>'#22c55e'],
          ['label'=>'Outstanding Balance','value'=>$outstanding_balance,'link'=>'loans?filter=outstanding','color'=>'#7c3aed'],
          ['label'=>'Arrears','value'=>$arrears,'link'=>'arrears?filter=arrears','color'=>'#ef4444'],
          ['label'=>'Loans Due Today','value'=>$loans_due_today,'link'=>'dues?filter=due_today','color'=>'#f59e0b'],
          ['label'=>'PAR (30+)','value'=>$par,'link'=>'par?filter=30plus','color'=>'#f43f5e'],
      ];
      foreach($loan_cards as $l):
      ?>
      <a href="<?= htmlspecialchars($l['link']) ?>" class="card p-3 hover:shadow-xl transition">
        <div class="text-xs text-gray-700"><?= htmlspecialchars($l['label']) ?></div>
        <div class="text-xl font-semibold" style="color:<?= $l['color'] ?>;">
          <?= ($l['label']==='PAR (30+)') ? number_format($l['value'],2).'%' : 'KSh '.number_format($l['value'],2) ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- GROUPS CARD -->
  <div class="container-card">
    <h2 class="text-xl font-semibold mb-4">Groups Handled</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 grid-cols-mobile">
      <a href="group" class="card p-4 hover:shadow-xl transition">
        <div class="text-sm text-gray-700">Groups</div>
        <div class="mt-2 text-2xl font-semibold text-[#f97316]"><?= number_format($total_groups) ?></div>
      </a>
      <a href="#" class="card p-4 hover:shadow-xl transition">
        <div class="text-sm text-gray-700">Loans Count</div>
        <div class="mt-2 text-2xl font-semibold text-[#3b82f6]"><?= number_format($total_loans) ?></div>
      </a>
    </div>
  </div>

  <!-- COLLECTIONS WITH PROGRESS -->
  <div class="container-card">
    <h2 class="text-xl font-semibold mb-4">Collections Performance</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 grid-cols-mobile">

      <div class="card p-4">
        <div class="text-sm text-gray-700">Today's Collections</div>
        <div class="text-2xl font-semibold text-[#15a362]">KSh <?= number_format($todays_collections,2) ?></div>
        <div class="text-xs text-gray-500 mt-1">From <?= $loans_due_today ?> loans due</div>
      </div>

      <div class="card p-4">
        <div class="text-sm text-gray-700">Collection Rate Today</div>
        <div class="text-2xl font-semibold text-[#15a362]"><?= number_format($collection_rate_today,2) ?>%</div>
        <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
          <div class="h-2 bg-[#15a362] rounded-full" style="width: <?= $collection_rate_today ?>%"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          KSh <?= number_format($todays_collections,2) ?> of <?= number_format($total_due_today,2) ?>
        </div>
      </div>

      <div class="card p-4">
        <div class="text-sm text-gray-700">Collection Rate Month</div>
        <div class="text-2xl font-semibold text-[#0ea5e9]"><?= number_format($collection_rate_month,2) ?>%</div>
        <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
          <div class="h-2 bg-[#0ea5e9] rounded-full" style="width: <?= $collection_rate_month ?>%"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          KSh <?= number_format($month_collections,2) ?> collected
        </div>
      </div>

      <div class="card p-4">
        <div class="text-sm text-gray-700">Prepayment Rate</div>
        <div class="text-2xl font-semibold text-[#f59e0b]"><?= number_format($prepayment_rate,2) ?>%</div>
        <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
          <div class="h-2 bg-[#f59e0b] rounded-full" style="width: <?= $prepayment_rate ?>%"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          KSh <?= number_format($prepayment_amount,2) ?> prepaid
        </div>
      </div>

    </div>
  </div>

  <!-- ANALYTICS -->
  <div class="container-card hide-mobile">
    <h2 class="text-xl font-semibold mb-4">Analytics</h2>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="card"><h4 class="font-semibold mb-2">Customer Split</h4><canvas id="statsChart" height="220"></canvas></div>
      <div class="card"><h4 class="font-semibold mb-2">Money Metrics</h4><canvas id="moneyChart" height="220"></canvas></div>
      <div class="card"><h4 class="font-semibold mb-2">Performance</h4><canvas id="performanceChart" height="220"></canvas></div>
    </div>
  </div>

  <!-- PAYMENTS + DUES -->
  <div class="container-card hide-mobile">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

      <div class="card">
        <div class="flex justify-between items-center mb-2">
          <h3 class="font-semibold">Recent Payments</h3>
          <button id="exportCsv" class="text-xs px-3 py-1 rounded border">Export CSV</button>
        </div>
        <div class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="text-left text-gray-500 text-xs">
              <tr><th>#</th><th>Borrower</th><th>Loan</th><th>Amount</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php $i=1; foreach($recent_payments as $p): ?>
              <tr class="border-t">
                <td class="py-2"><?= $i++ ?></td>
                <td><?= htmlspecialchars(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?></td>
                <td><?= htmlspecialchars($p['loan_id']) ?></td>
                <td>KSh <?= number_format($p['principal_amount'],2) ?></td>
                <td><?= htmlspecialchars($p['payment_date']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($recent_payments)): ?>
              <tr><td colspan="5" class="py-4 text-center text-gray-400">No payments yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3 class="font-semibold mb-2">Upcoming Dues</h3>
        <ul class="space-y-2">
          <?php foreach($upcoming_dues as $u):
            $due_amount = $u['due_amount'] ?? 0;
          ?>
          <li class="flex justify-between items-center p-2 rounded bg-gray-50">
            <div>
              <div class="text-sm font-medium"><?= htmlspecialchars(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?></div>
              <div class="text-xs text-gray-500">Disbursed: <?= htmlspecialchars($u['disbursed_date'] ?? '') ?></div>
            </div>
            <div class="text-right">
              <div class="text-sm font-semibold <?= $due_amount > 0 ? 'text-amber-600' : 'text-gray-400' ?>">
                KSh <?= number_format($due_amount,2) ?>
              </div>
              <div class="text-xs text-gray-400">Weekly</div>
            </div>
          </li>
          <?php endforeach; ?>
          <?php if(empty($upcoming_dues)): ?>
          <li class="py-4 text-center text-gray-400">No upcoming dues</li>
          <?php endif; ?>
        </ul>
      </div>

    </div>
  </div>

</div>

<!-- Scripts -->
<script>
  const PHP = <?= json_encode($js, JSON_NUMERIC_CHECK) ?>;

  new Chart(document.getElementById('statsChart'), {
    type:'doughnut',
    data:{ 
      labels:['Active','Inactive'], 
      datasets:[{ 
        data:[PHP.active_customers||0,PHP.inactive_customers||0], 
        backgroundColor:['#15a362','#6b7280'],
        borderWidth: 2,
        borderColor: '#ffffff'
      }] 
    },
    options:{ 
      responsive:true, 
      plugins:{ 
        legend:{ 
          position:'bottom' 
        } 
      },
      cutout: '60%'
    }
  });

  new Chart(document.getElementById('moneyChart'), {
    type:'bar',
    data:{ 
      labels:['Total Disbursed','Outstanding','Arrears'], 
      datasets:[{ 
        label:'KSh', 
        data:[PHP.total_disbursed||0,PHP.outstanding_balance||0,PHP.arrears||0], 
        backgroundColor:['#22c55e','#7c3aed','#ef4444'] 
      }] 
    },
    options:{ 
      responsive:true, 
      plugins:{ 
        legend:{ 
          display:false 
        } 
      }, 
      scales:{ 
        y:{ 
          beginAtZero:true,
          ticks: {
            callback: function(value) {
              return 'KSh ' + (value / 1000).toFixed(0) + 'K';
            }
          }
        } 
      } 
    }
  });

  new Chart(document.getElementById('performanceChart'), {
    type:'line',
    data:{ 
      labels:['Today','Month','Prepayment','PAR(30+)'], 
      datasets:[{ 
        label:'%', 
        data:[PHP.collection_rate_today||0,PHP.collection_rate_month||0,PHP.prepayment_rate||0,PHP.par||0], 
        borderColor:'#15a362', 
        backgroundColor:'#15a36233', 
        fill:true, 
        tension:0.35,
        pointBackgroundColor: '#15a362',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 2,
        pointRadius: 5
      }] 
    },
    options:{ 
      responsive:true, 
      plugins:{ 
        legend:{ 
          display:false 
        } 
      }, 
      scales:{ 
        y:{ 
          beginAtZero:true,
          max: 100,
          ticks: {
            callback: function(value) {
              return value + '%';
            }
          }
        } 
      } 
    }
  });

  document.getElementById('exportCsv').addEventListener('click',()=> {
    const rows=Array.from(document.querySelectorAll('table tbody tr'));
    const header=['#','Borrower','Loan','Amount','Date'];
    const data=rows.map(r=>Array.from(r.querySelectorAll('td')).map(td=>td.innerText.trim()));
    const csv=[header,...data].map(r=>r.join(',')).join('\n');
    const blob=new Blob([csv],{type:'text/csv'});
    const a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='recent_payments_<?= date('Y-m-d') ?>.csv';
    a.click();
  });
</script>

</body>
</html>