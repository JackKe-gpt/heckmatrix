<?php
ob_start();
session_start();
include '../includes/db.php';
include 'header.php';

// --- Access Control ---
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'branch_manager') {
    header("Location: ../index");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];
$manager_name = $_SESSION['admin']['name'] ?? 'Branch Manager';
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// --- Officer Filter ---
$selected_officer = $_GET['officer_id'] ?? 0;
$where_officer_customers = $selected_officer ? " AND loan_officer_id=$selected_officer" : "";
$where_officer_loans = $selected_officer ? " AND l.officer_id=$selected_officer" : "";

// --- Helper Function ---
function fetch_value($query, $key) {
    global $conn;
    $res = mysqli_query($conn, $query);
    if (!$res) return 0;
    $row = mysqli_fetch_assoc($res);
    return $row[$key] ?? 0;
}

function fetch_all($query) {
    global $conn;
    $res = mysqli_query($conn, $query);
    if (!$res) return [];
    return $res->fetch_all(MYSQLI_ASSOC);
}

// --- Helper function for weeks elapsed ---
function weeks_elapsed($disbursed_date, $today) {
    if (empty($disbursed_date)) return 0;
    $diff = max(0, strtotime($today) - strtotime($disbursed_date));
    return floor($diff / (7 * 86400));
}

// ---------------- METRICS ----------------

// Customers
$active_customers = fetch_value("SELECT COUNT(*) AS total FROM customers WHERE status='Active' AND branch_id=$branch_id $where_officer_customers", 'total');
$inactive_customers = fetch_value("SELECT COUNT(*) AS total FROM customers WHERE status!='Active' AND branch_id=$branch_id $where_officer_customers", 'total');
$total_customers_ytd = fetch_value("SELECT COUNT(*) AS total FROM customers WHERE branch_id=$branch_id AND YEAR(created_at)=YEAR(CURDATE()) $where_officer_customers", 'total');

// --- Loan Officers & Groups ---
$total_officers = fetch_value("SELECT COUNT(*) AS total FROM admin_users WHERE role='loan_officer' AND branch_id=$branch_id", 'total');
$total_groups = fetch_value("SELECT COUNT(*) AS total FROM groups WHERE branch_id=$branch_id", 'total');

// --- Loans ---
$total_loans = fetch_value("SELECT COUNT(*) AS total FROM loans l WHERE branch_id=$branch_id AND status IN ('Disbursed','Active') $where_officer_loans", 'total');
$total_disbursed = fetch_value("SELECT COALESCE(SUM(principal_amount),0) AS total FROM loans l WHERE branch_id=$branch_id AND status IN ('Disbursed','Active') $where_officer_loans", 'total');

// --- Outstanding Balance ---
$outstanding_balance = fetch_value("
    SELECT COALESCE(SUM(l.total_repayable - COALESCE(lp.paid,0)),0) AS total
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(principal_amount) AS paid
        FROM loan_payments
        GROUP BY loan_id
    ) lp ON l.id = lp.loan_id
    WHERE l.branch_id=$branch_id AND l.status IN ('Disbursed','Active') $where_officer_loans
", 'total');

// ---------------- ACCURATE ARREARS & PAR CALCULATION ----------------
$arrears_total = 0.0;
$par_total = 0.0;
$loans_due_today = 0;
$total_due_today = 0.0;
$total_due_tomorrow = 0.0;
$total_due_month_to_date = 0.0;

// NEW: Track payments made towards dues (regardless of payment date)
$total_paid_towards_today_dues = 0.0;
$total_paid_towards_month_dues = 0.0;

$loan_details = fetch_all("
    SELECT 
        l.id, 
        l.disbursed_date, 
        l.weekly_installment, 
        l.duration_weeks,
        l.total_repayable,
        COALESCE(p.total_paid, 0) AS total_paid,
        COALESCE(p.last_payment_date, l.disbursed_date) AS last_payment_date
    FROM loans l
    LEFT JOIN (
        SELECT 
            loan_id, 
            SUM(principal_amount) AS total_paid,
            MAX(payment_date) AS last_payment_date
        FROM loan_payments 
        GROUP BY loan_id
    ) p ON l.id = p.loan_id
    WHERE l.branch_id=$branch_id AND l.status IN ('Disbursed','Active') $where_officer_loans
");

foreach ($loan_details as $loan) {
    $disbursed_date = $loan['disbursed_date'];
    $weekly_installment = (float)$loan['weekly_installment'];
    $duration_weeks = (int)$loan['duration_weeks'];
    $total_paid = (float)$loan['total_paid'];
    $loan_id = $loan['id'];
    
    if (!$disbursed_date) continue;
    
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
    $arrears_total += $loan_arrears;
    
    // PAR (Portfolio at Risk) - loans with payments overdue by 30+ days
    // PAR (Portfolio at Risk) - ALL loans with arrears (no aging)
if ($loan_arrears > 0) {
    // Cap PAR to remaining outstanding balance for safety
    $outstanding_loan_balance = max(
        0,
        $loan['total_repayable'] - $total_paid
    );

    $par_total += min($loan_arrears, $outstanding_loan_balance);
}

    
    // Loans due today (have unpaid installments for current period)
    $paid_weeks = floor($total_paid / $weekly_installment);
    if ($expected_weeks_today > $paid_weeks) {
        $loans_due_today++;
        $total_due_today += ($expected_weeks_today - $paid_weeks) * $weekly_installment;
        
        // NEW: Calculate payments made towards today's dues (any payment date)
        $payments_towards_today = fetch_value("
            SELECT COALESCE(SUM(principal_amount),0) AS total 
            FROM loan_payments 
            WHERE loan_id = $loan_id 
            AND payment_date <= '$today'
        ", 'total');
        
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
    
    // TOTAL DUE MONTH TO DATE (Only dues up to today in current month)
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
    $payments_towards_month = fetch_value("
        SELECT COALESCE(SUM(principal_amount),0) AS total 
        FROM loan_payments 
        WHERE loan_id = $loan_id 
        AND payment_date <= '$today'
    ", 'total');
    
    // The amount paid towards this month's dues is the minimum of:
    // 1. Total payments made, or 
    // 2. This month's expected amount to date
    $paid_towards_month = min($payments_towards_month, $month_due_amount_to_date);
    $total_paid_towards_month_dues += $paid_towards_month;
}

// PAR % - based on outstanding balance, not total disbursed
$par_percent = $outstanding_balance > 0 ? round(($par_total / $outstanding_balance) * 100, 2) : 0;

// ---------------- ACCURATE COLLECTIONS CALCULATION ----------------
$todays_collections = fetch_value("
    SELECT COALESCE(SUM(lp.principal_amount),0) AS total 
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE l.branch_id=$branch_id AND DATE(lp.payment_date)='$today' $where_officer_loans
", 'total');

// Month collections - fixed to include year
$month_collections = fetch_value("
    SELECT COALESCE(SUM(lp.principal_amount),0) AS total 
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE l.branch_id=$branch_id 
    AND YEAR(lp.payment_date) = YEAR(CURDATE()) 
    AND MONTH(lp.payment_date) = MONTH(CURDATE()) 
    $where_officer_loans
", 'total');

// Prepayments - payments exceeding the current installment due
$prepayment_amount = fetch_value("
    SELECT COALESCE(SUM(lp.principal_amount),0) AS total 
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    WHERE l.branch_id=$branch_id 
    AND DATE(lp.payment_date)='$today' 
    AND lp.principal_amount > l.weekly_installment
    $where_officer_loans
", 'total');

// Collection Rates - UPDATED CALCULATIONS
// Today: All payments made towards today's dues (regardless of payment date)
$collection_rate_today = $total_due_today > 0 ? min(100, round(($total_paid_towards_today_dues / $total_due_today) * 100, 2)) : 0;

// Monthly: All payments made towards this month's dues TO DATE (regardless of payment date)
$collection_rate_month = $total_due_month_to_date > 0 ? min(100, round(($total_paid_towards_month_dues / $total_due_month_to_date) * 100, 2)) : 0;

// Prepayment rate: Today's prepayments against tomorrow's due amount
$prepayment_rate = $total_due_tomorrow > 0 ? min(100, round(($prepayment_amount / $total_due_tomorrow) * 100, 2)) : 0;

// DEBUG: Let's check what loans are actually due today
$debug_loans_due_today = fetch_all("
    SELECT l.id, c.first_name, c.surname, l.disbursed_date, l.weekly_installment, l.duration_weeks,
           COALESCE(SUM(lp.principal_amount),0) as total_paid,
           FLOOR(COALESCE(SUM(lp.principal_amount),0) / l.weekly_installment) as paid_weeks,
           FLOOR(DATEDIFF('$today', l.disbursed_date) / 7) as weeks_elapsed
    FROM loans l
    JOIN customers c ON l.customer_id = c.id
    LEFT JOIN loan_payments lp ON l.id = lp.loan_id
    WHERE l.branch_id=$branch_id AND l.status IN ('Disbursed','Active') $where_officer_loans
    GROUP BY l.id
    HAVING weeks_elapsed > paid_weeks AND weeks_elapsed <= l.duration_weeks
");

// Use the debug count if it's different from our calculated count
if (count($debug_loans_due_today) != $loans_due_today) {
    $loans_due_today = count($debug_loans_due_today);
}

// --- Recent Payments & Upcoming Dues ---
$recent_payments = fetch_all("
    SELECT lp.*, c.first_name, c.surname, l.weekly_installment
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id=l.id
    JOIN customers c ON l.customer_id=c.id
    WHERE l.branch_id=$branch_id $where_officer_loans
    ORDER BY lp.payment_date DESC, lp.id DESC
    LIMIT 10
");

$upcoming_dues = fetch_all("
    SELECT l.*, c.first_name, c.surname, 
           COALESCE((SELECT SUM(principal_amount) FROM loan_payments WHERE loan_id=l.id),0) AS paid,
           l.duration_weeks
    FROM loans l
    JOIN customers c ON l.customer_id=c.id
    WHERE l.branch_id=$branch_id AND l.status IN ('Disbursed','Active')
    ORDER BY l.disbursed_date ASC
    LIMIT 10
");

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

// --- Loan Officers List ---
$officers = $conn->query("SELECT id, name FROM admin_users WHERE role='loan_officer' AND branch_id=$branch_id ORDER BY name ASC");

// --- 5-Day Trends ---
$dates = $disbursed_data = $collected_data = [];
for ($i=4; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = $date;
    $disbursed_data[] = fetch_value("SELECT COALESCE(SUM(principal_amount),0) AS total FROM loans l WHERE branch_id=$branch_id AND DATE(disbursed_date)='$date' AND status IN ('Disbursed','Active') $where_officer_loans", 'total');
    $collected_data[] = fetch_value("SELECT COALESCE(SUM(lp.principal_amount),0) AS total FROM loan_payments lp JOIN loans l ON lp.loan_id=l.id WHERE l.branch_id=$branch_id AND DATE(lp.payment_date)='$date' $where_officer_loans", 'total');
}

// --- JS Data ---
$js = [
    'active_customers'=>$active_customers,
    'inactive_customers'=>$inactive_customers,
    'total_disbursed'=>$total_disbursed,
    'outstanding_balance'=>$outstanding_balance,
    'arrears'=>$arrears_total,
    'loans_due_today'=>$loans_due_today,
    'collection_rate_today'=>$collection_rate_today,
    'collection_rate_month'=>$collection_rate_month,
    'prepayment_rate'=>$prepayment_rate,
    'par'=>$par_percent,
    'trend_dates'=>$dates,
    'disbursed_data'=>$disbursed_data,
    'collected_data'=>$collected_data,
    'todays_collections'=>$todays_collections,
    'month_collections'=>$month_collections,
    'total_due_today'=>$total_due_today,
    'total_paid_towards_today_dues'=>$total_paid_towards_today_dues,
    'total_due_month_to_date'=>$total_due_month_to_date,
    'total_paid_towards_month_dues'=>$total_paid_towards_month_dues,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Branch Manager Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body { background: #f3f6f9; }
.card { background: white; border-radius: .75rem; box-shadow: 0 8px 22px rgba(16,24,40,0.06); padding:1rem; }
.container-card { background:white; border-radius:.75rem; padding:1rem; box-shadow:0 10px 25px rgba(16,24,40,0.08); margin-bottom:1.5rem; }
canvas { border-radius:.5rem; padding:.5rem; background:white; box-shadow:0 8px 18px rgba(16,24,40,0.03); width:100% !important; }
.progress-bar { background: #e5e7eb; border-radius: 1rem; overflow: hidden; height: 0.5rem; }
.progress-fill { height: 100%; border-radius: 1rem; transition: width 0.5s ease; }
@media(max-width:1024px){ .hide-mobile{ display:none !important; } .grid-cols-mobile{ grid-template-columns:1fr !important; } }
</style>
</head>
<body class="font-sans text-gray-800">
<div class="max-w-full mx-auto p-4 lg:p-8">

  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    
    <!-- Officer Filter -->
    <form method="get" class="mb-4">
      <label class="text-sm text-gray-500 mr-2">Filter by Officer:</label>
      <select name="officer_id" onchange="this.form.submit()" class="border px-3 py-2 rounded-lg">
        <option value="0">All Officers</option>
        <?php while($officer = $officers->fetch_assoc()): ?>
          <option value="<?= $officer['id'] ?>" <?= $selected_officer==$officer['id']?'selected':'' ?>>
            <?= htmlspecialchars($officer['name']) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </form>
  </div>

  <!-- Overview Cards -->
  <div class="container-card">
    <h2 class="text-xl font-semibold mb-4">Branch Overview</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 grid-cols-mobile">
      <?php
      $overview_stats = [
        ['label'=>'Active Customers','value'=>$active_customers,'link'=>'customers.php','color'=>'#15a362',],
        ['label'=>'Inactive Customers','value'=>$inactive_customers,'link'=>'inactive','color'=>'#6b7280',],
        ['label'=>'Total Groups','value'=>$total_groups,'link'=>'groups','color'=>'#f97316',],
        ['label'=>'Loan Officers','value'=>$total_officers,'link'=>'officers','color'=>'#3b82f6',],
      ];
      foreach($overview_stats as $c):
      ?>
      <a href="<?= htmlspecialchars($c['link']) ?>" class="card p-4 hover:shadow-xl transition group">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-sm text-gray-500"><?= htmlspecialchars($c['label']) ?></div>
            <div class="mt-1 text-2xl font-bold" style="color:<?= $c['color'] ?>"><?= number_format($c['value']) ?></div>
          </div>
          <div class="text-2xl opacity-80 group-hover:scale-110 transition-transform">
    <?= $c['icon'] ?? '' ?>
</div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Loans Portfolio Cards -->
  <div class="container-card">
    <h2 class="text-xl font-semibold mb-4">Loans Portfolio</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 grid-cols-mobile">
      <?php
      $loan_cards = [
        ['label'=>'Total Disbursed','value'=>$total_disbursed,'link'=>'#?filter=disbursed','color'=>'#22c55e',],
        ['label'=>'Outstanding Balance','value'=>$outstanding_balance,'link'=>'loans?filter=outstanding','color'=>'#7c3aed',],
        ['label'=>'Arrears','value'=>$arrears_total,'link'=>'arrears?filter=arrears','color'=>'#ef4444',],
        ['label'=>'PAR (30+)','value'=>$par_percent,'link'=>'loans.php?filter=par','color'=>'#f59e0b','is_percent'=>true,],
        ['label'=>'Loans Due Today','value'=>$loans_due_today,'link'=>'dues?filter=due_today','color'=>'#f97316',],
      ];
      foreach($loan_cards as $l):
      ?>
      <a href="<?= htmlspecialchars($l['link']) ?>" class="card p-4 hover:shadow-xl transition group">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-xs text-gray-500"><?= htmlspecialchars($l['label']) ?></div>
            <div class="mt-1 text-xl font-bold" style="color:<?= $l['color'] ?>;">
              <?= isset($l['is_percent']) && $l['is_percent'] ? number_format($l['value'],2).'%' : 'KSh '.number_format($l['value'],2) ?>
            </div>
          </div>
          <div class="text-2xl opacity-80 group-hover:scale-110 transition-transform">
    <?= $c['icon'] ?? '' ?>
</div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Collections Cards -->
  <div class="container-card">
    <h2 class="text-xl font-semibold mb-4">Collections Performance</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 grid-cols-mobile">
      <div class="card p-4">
        <div class="text-sm text-gray-500">Today's Collections</div>
        <div class="text-2xl font-semibold text-[#15a362]">KSh <?= number_format($todays_collections,2) ?></div>
        <div class="text-xs text-gray-500 mt-1">From <?= $loans_due_today ?> loans due</div>
      </div>
      
      <div class="card p-4">
        <div class="text-sm text-gray-500">Collection Rate Today</div>
        <div class="text-2xl font-semibold text-[#15a362]"><?= number_format($collection_rate_today,2) ?>%</div>
        <div class="progress-bar mt-2">
          <div class="progress-fill bg-[#15a362]" style="width: <?= $collection_rate_today ?>%"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          KSh <?= number_format($todays_collections,2) ?> of <?= number_format($total_due_today,2) ?>
        </div>
      </div>
      
      <div class="card p-4">
        <div class="text-sm text-gray-500">Collection Rate Month</div>
        <div class="text-2xl font-semibold text-[#0ea5e9]"><?= number_format($collection_rate_month,2) ?>%</div>
        <div class="progress-bar mt-2">
          <div class="progress-fill bg-[#0ea5e9]" style="width: <?= $collection_rate_month ?>%"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          KSh <?= number_format($month_collections,2) ?> collected
        </div>
      </div>
      
      <div class="card p-4">
        <div class="text-sm text-gray-500">Prepayment Rate</div>
        <div class="text-2xl font-semibold text-[#f59e0b]"><?= number_format($prepayment_rate,2) ?>%</div>
        <div class="progress-bar mt-2">
          <div class="progress-fill bg-[#f59e0b]" style="width: <?= $prepayment_rate ?>%"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1">
          KSh <?= number_format($prepayment_amount,2) ?> prepaid
        </div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="container-card hide-mobile">
    <h2 class="text-xl font-semibold mb-4">Analytics</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="card">
        <h4 class="font-semibold mb-3">Customer Distribution</h4>
        <canvas id="statsChart" height="250"></canvas>
      </div>
      <div class="card">
        <h4 class="font-semibold mb-3">Financial Overview</h4>
        <canvas id="moneyChart" height="250"></canvas>
      </div>
      <div class="card">
        <h4 class="font-semibold mb-3">Performance Metrics</h4>
        <canvas id="performanceChart" height="250"></canvas>
      </div>
      <div class="card">
        <h4 class="font-semibold mb-3">Disbursed vs Collected (5 Days)</h4>
        <canvas id="trendChart" height="250"></canvas>
      </div>
    </div>
  </div>

  <!-- Recent Payments & Upcoming Dues -->
  <div class="container-card hide-mobile">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="card">
        <div class="flex justify-between items-center mb-4">
          <h3 class="font-semibold">Recent Payments</h3>
          <button id="exportCsv" class="text-xs px-3 py-2 rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors">
            📥 Export CSV
          </button>
        </div>
        <div class="overflow-auto max-h-96">
          <table class="w-full text-sm">
            <thead class="text-left text-gray-500 text-xs border-b">
              <tr>
                <th class="pb-2">#</th>
                <th class="pb-2">Borrower</th>
                <th class="pb-2">Loan ID</th>
                <th class="pb-2">Amount</th>
                <th class="pb-2">Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($recent_payments)): ?>
              <tr>
                <td colspan="5" class="py-6 text-center text-gray-400">
                  No payments recorded yet
                </td>
              </tr>
              <?php else: ?>
                <?php $i=1; foreach($recent_payments as $p): ?>
                <tr class="border-b border-gray-100 hover:bg-gray-50">
                  <td class="py-3"><?= $i++ ?></td>
                  <td class="py-3 font-medium">
                    <?= htmlspecialchars(($p['first_name'] ?? '') . ' ' . ($p['surname'] ?? '')) ?>
                  </td>
                  <td class="py-3 text-gray-600">#<?= htmlspecialchars($p['loan_id']) ?></td>
                  <td class="py-3 font-semibold text-green-600">
                    KSh <?= number_format($p['principal_amount'], 2) ?>
                  </td>
                  <td class="py-3 text-gray-500 text-xs">
                    <?= htmlspecialchars(date('M j, Y', strtotime($p['payment_date']))) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h3 class="font-semibold mb-4">Upcoming Loan Dues</h3>
        <div class="space-y-3 max-h-96 overflow-auto">
          <?php if(empty($upcoming_dues)): ?>
          <div class="text-center py-6 text-gray-400">
            No upcoming dues
          </div>
          <?php else: ?>
            <?php foreach($upcoming_dues as $u): ?>
            <div class="flex justify-between items-center p-3 rounded-lg bg-gray-50 border border-gray-100 hover:bg-white transition-colors">
              <div class="flex-1">
                <div class="text-sm font-medium text-gray-800">
                  <?= htmlspecialchars(($u['first_name'] ?? '').' '.($u['surname'] ?? '')) ?>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                  Disbursed: <?= htmlspecialchars(date('M j, Y', strtotime($u['disbursed_date'] ?? ''))) ?>
                </div>
              </div>
              <div class="text-right">
                <div class="text-sm font-semibold <?= ($u['due_amount'] > 0) ? 'text-amber-600' : 'text-gray-400' ?>">
                  KSh <?= number_format($u['due_amount'], 2) ?>
                </div>
                <div class="text-xs text-gray-400">Weekly</div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
const PHP = <?= json_encode($js, JSON_NUMERIC_CHECK) ?>;

// Customer Split
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
        position:'bottom',
        labels: {
          padding: 20,
          usePointStyle: true
        }
      } 
    },
    cutout: '65%'
  }
});

// Money Metrics
new Chart(document.getElementById('moneyChart'), {
  type:'bar',
  data:{ 
    labels:['Total Disbursed','Outstanding','Arrears'], 
    datasets:[{ 
      label:'KSh', 
      data:[PHP.total_disbursed||0,PHP.outstanding_balance||0,PHP.arrears||0], 
      backgroundColor:['#22c55e','#7c3aed','#ef4444'],
      borderWidth: 0,
      borderRadius: 4
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
        ticks:{ 
          callback: function(value) {
            return 'KSh ' + (value / 1000).toFixed(0) + 'K';
          }
        } 
      } 
    } 
  }
});

// Performance
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
    scales:{ 
      y:{ 
        beginAtZero:true,
        max: 100,
        ticks:{ 
          callback:function(v){
            return v+'%';
          } 
        } 
      } 
    }, 
    plugins:{ 
      legend:{ 
        display:false 
      } 
    } 
  }
});

// Disbursed vs Collected (5 Days)
new Chart(document.getElementById('trendChart'), {
  type:'line',
  data:{
    labels:PHP.trend_dates,
    datasets:[
      { 
        label:'Disbursed', 
        data:PHP.disbursed_data, 
        borderColor:'#22c55e', 
        backgroundColor:'#22c55e33', 
        fill:true, 
        tension:0.3,
        pointBackgroundColor: '#22c55e',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 2
      },
      { 
        label:'Collected', 
        data:PHP.collected_data, 
        borderColor:'#3b82f6', 
        backgroundColor:'#3b82f633', 
        fill:true, 
        tension:0.3,
        pointBackgroundColor: '#3b82f6',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 2
      }
    ]
  },
  options:{ 
    responsive:true, 
    scales:{ 
      y:{ 
        beginAtZero:true, 
        ticks:{ 
          callback:function(v){
            return 'KSh '+Number(v).toLocaleString();
          } 
        } 
      } 
    } 
  }
});

// Export CSV
document.getElementById('exportCsv').addEventListener('click',()=>{
  const rows=Array.from(document.querySelectorAll('table tbody tr'));
  const header=['#','Borrower','Loan ID','Amount','Date'];
  const data=rows.map(r=>Array.from(r.querySelectorAll('td')).map(td=>td.innerText.trim().replace(/,/g,'')));
  const csvContent = [header, ...data]
    .map(row => row.map(field => `"${field}"`).join(','))
    .join('\n');
  const blob=new Blob([csvContent],{type:'text/csv;charset=utf-8;'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a'); 
  a.href=url; 
  a.download='recent_payments_<?= date('Y-m-d') ?>.csv'; 
  document.body.appendChild(a); 
  a.click(); 
  a.remove(); 
  URL.revokeObjectURL(url);
});
</script>
</body>
</html>