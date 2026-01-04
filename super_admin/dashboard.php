<?php
require_once 'auth.php';
require_login();
include 'includes/db.php';
include 'header.php';

// --- Helper function ---
function fetch_value($query, $key) {
    global $conn;
    $res = @mysqli_query($conn, $query);
    if (!$res) return 0;
    $result = mysqli_fetch_assoc($res);
    return $result[$key] ?? 0;
}

$today = date('Y-m-d');

// --- Filters ---
$branches = mysqli_query($conn, "SELECT id, name FROM branches ORDER BY name ASC");
$selected_branch = $_GET['branch_id'] ?? '';
$selected_officer = $_GET['officer_id'] ?? '';

// Branch + Officer conditions
$branch_condition_customers = $selected_branch ? " AND c.branch_id = '$selected_branch' " : "";
$branch_condition_loans     = $selected_branch ? " AND c.branch_id = '$selected_branch' " : "";
$officer_condition = $selected_officer ? " AND l.officer_id = '$selected_officer' " : "";

// Fetch officers only when branch selected
$officers = null;
if ($selected_branch) {
    $officers = mysqli_query($conn, "SELECT id, name FROM admin_users WHERE role='loan_officer' AND branch_id='$selected_branch' ORDER BY name ASC");
}

// ================== Members ==================
$all_customers_ytd = fetch_value("SELECT COUNT(*) AS cnt FROM customers c WHERE YEAR(created_at)=YEAR(CURDATE()) $branch_condition_customers",'cnt');
$active_members    = fetch_value("SELECT COUNT(*) AS cnt FROM customers c WHERE status='Active' $branch_condition_customers",'cnt');
$inactive_members  = fetch_value("SELECT COUNT(*) AS cnt FROM customers c WHERE status='Inactive' $branch_condition_customers",'cnt');
$pending_accounts  = fetch_value("SELECT COUNT(*) AS cnt FROM customers c WHERE status='Pending' $branch_condition_customers",'cnt');
$total_members     = $active_members + $inactive_members + $pending_accounts;

// ================== Loan Stats ==================
$total_disbursed_amt   = fetch_value("
    SELECT SUM(l.principal_amount) AS total 
    FROM loans l 
    JOIN customers c ON l.customer_id=c.id 
    WHERE l.status IN ('Active','Disbursed','Closed') $branch_condition_loans $officer_condition
",'total');

$total_disbursed_loans = fetch_value("
    SELECT COUNT(*) AS cnt 
    FROM loans l 
    JOIN customers c ON l.customer_id=c.id 
    WHERE l.status IN ('Active','Disbursed','Closed') $branch_condition_loans $officer_condition
",'cnt');

$fully_paid_loans      = fetch_value("
    SELECT COUNT(*) AS cnt 
    FROM loans l 
    JOIN customers c ON l.customer_id=c.id 
    WHERE l.status='Closed' $branch_condition_loans $officer_condition
",'cnt');

$pending_approvals     = fetch_value("
    SELECT COUNT(*) AS cnt 
    FROM loans l 
    JOIN customers c ON l.customer_id=c.id 
    WHERE l.status='Pending' $branch_condition_loans $officer_condition
",'cnt');

$pending_disburse      = fetch_value("
    SELECT COUNT(*) AS cnt 
    FROM loans l 
    JOIN customers c ON l.customer_id=c.id 
    WHERE l.status IN ('Pending Disbursement','Approved') $branch_condition_loans $officer_condition
",'cnt');

// ================== Balances ==================
$loan_balance = fetch_value("
    SELECT SUM(l.total_repayable - IFNULL(p.total_paid,0)) AS balance
    FROM loans l
    JOIN customers c ON l.customer_id=c.id
    LEFT JOIN (
        SELECT loan_id, SUM(principal_amount) AS total_paid 
        FROM loan_payments 
        GROUP BY loan_id
    ) p ON l.id=p.loan_id
    WHERE l.status IN ('Active','Disbursed') $branch_condition_loans $officer_condition
",'balance');

// ================== Arrears & Today's Due ==================
$arrears = 0; $today_due = 0;
$loan_q_sql = "
    SELECT l.*, 
        (SELECT IFNULL(SUM(lp.principal_amount),0) FROM loan_payments lp WHERE lp.loan_id=l.id) AS total_paid
    FROM loans l
    JOIN customers c ON l.customer_id=c.id
    WHERE l.status IN ('Active','Disbursed') $branch_condition_loans $officer_condition
";
$loan_q = mysqli_query($conn, $loan_q_sql);
if ($loan_q) {
    while ($row = mysqli_fetch_assoc($loan_q)) {
        $weeks_elapsed = floor((strtotime($today)-strtotime($row['disbursed_date']))/(86400*7));
        $expected = min($row['duration_weeks'], $weeks_elapsed) * $row['weekly_installment'];
        $arrears_due = $expected - $row['total_paid'];
        if ($arrears_due > 0) $arrears += $arrears_due;

        // today's due
        for ($w=1; $w<=$row['duration_weeks']; $w++) {
            $due_date = date('Y-m-d', strtotime($row['disbursed_date']." +{$w} weeks"));
            if ($due_date === $today) {
                $expected_prev = ($w-1)*$row['weekly_installment'];
                $already_paid = min($row['total_paid'], $expected_prev);
                $installment_due = $row['weekly_installment'] - ($row['total_paid'] - $already_paid);
                if ($installment_due > 0) $today_due += $installment_due;
                break;
            }
        }
    }
}

// ================== Collections ==================
$money_in = fetch_value("
    SELECT SUM(lp.principal_amount) AS total 
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id=l.id
    JOIN customers c ON l.customer_id=c.id
    WHERE 1=1 $branch_condition_loans $officer_condition
",'total');

$money_out = fetch_value("
    SELECT SUM(l.principal_amount) AS total 
    FROM loans l
    JOIN customers c ON l.customer_id=c.id
    WHERE l.status IN ('Active','Disbursed','Closed') $branch_condition_loans $officer_condition
",'total');

// ================== PAR ==================
$par_query = mysqli_query($conn, "
    SELECT 
        SUM(GREATEST(
            LEAST(l.duration_weeks, FLOOR(DATEDIFF('$today', l.disbursed_date)/7)) * l.weekly_installment
            - IFNULL(p.total_paid,0), 0
        )) AS total_arrears,
        SUM(l.total_repayable - IFNULL(p.total_paid,0)) AS total_outstanding
    FROM loans l
    JOIN customers c ON l.customer_id=c.id
    LEFT JOIN (
        SELECT loan_id, SUM(principal_amount) AS total_paid 
        FROM loan_payments 
        GROUP BY loan_id
    ) p ON l.id = p.loan_id
    WHERE l.status IN ('Active','Disbursed') $branch_condition_loans $officer_condition
");
$row = mysqli_fetch_assoc($par_query);
$total_arrears = $row['total_arrears'] ?? 0;
$total_outstanding = $row['total_outstanding'] ?? 0;
$par_percent = ($total_outstanding > 0) ? number_format(($total_arrears / $total_outstanding) * 100, 2) : 0;

// ================== Collection Rates ==================
$collection_rate_today = 0;
$collection_rate_week = 0;
$collection_rate_month = 0;

// Fetch all active/disbursed loans
$loans_sql = "
    SELECT l.id, l.disbursed_date, l.weekly_installment, l.duration_weeks
    FROM loans l
    JOIN customers c ON l.customer_id = c.id
    WHERE l.status IN ('Active','Disbursed')
    $branch_condition_loans $officer_condition
";
$loans_res = mysqli_query($conn, $loans_sql);

$today_due = 0;
$week_due = 0;
$month_due = 0;
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

$today_date = date('Y-m-d');

if ($loans_res) {
    while ($loan = mysqli_fetch_assoc($loans_res)) {
        $disbursed_date = $loan['disbursed_date'];
        $installment = $loan['weekly_installment'];
        $duration = $loan['duration_weeks'];

        // Loop through each week's installment
        for ($week = 1; $week <= $duration; $week++) {
            $due_date = date('Y-m-d', strtotime("$disbursed_date +$week weeks"));

            // Today's due
            if ($due_date == $today_date) {
                $today_due += $installment;
            }

            // This week's due
            if ($due_date >= $week_start && $due_date <= $week_end) {
                $week_due += $installment;
            }

            // This month's due
            if ($due_date >= $month_start && $due_date <= $month_end) {
                $month_due += $installment;
            }
        }
    }
}

// ================== Collected Amounts ==================
// Today
$money_in_today = fetch_value("
    SELECT SUM(lp.principal_amount + lp.interest_amount) AS total 
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    JOIN customers c ON l.customer_id = c.id
    WHERE DATE(lp.payment_date) = '$today_date'
    $branch_condition_loans $officer_condition
",'total');

// This week
$week_collected = fetch_value("
    SELECT SUM(lp.principal_amount + lp.interest_amount) AS total_collected
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    JOIN customers c ON l.customer_id = c.id
    WHERE DATE(lp.payment_date) BETWEEN '$week_start' AND '$week_end'
    $branch_condition_loans $officer_condition
",'total_collected');

// This month
$month_collected = fetch_value("
    SELECT SUM(lp.principal_amount + lp.interest_amount) AS total_collected
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id = l.id
    JOIN customers c ON l.customer_id = c.id
    WHERE DATE(lp.payment_date) BETWEEN '$month_start' AND '$month_end'
    $branch_condition_loans $officer_condition
",'total_collected');

// ================== Calculate Rates ==================
$collection_rate_today = $today_due > 0 ? min(($money_in_today / $today_due) * 100, 100) : 0;
$collection_rate_week = $week_due > 0 ? min(($week_collected / $week_due) * 100, 100) : 0;
$collection_rate_month = $month_due > 0 ? min(($month_collected / $month_due) * 100, 100) : 0;

// ================== Charts (5 Days) ==================
$dates=[]; $disbursed_data=[]; $collected_data=[];
for($i=4;$i>=0;$i--){
    $date=date('Y-m-d',strtotime("-$i days"));
    $dates[]=$date;
    $disbursed=fetch_value("
        SELECT SUM(l.principal_amount) AS total 
        FROM loans l 
        JOIN customers c ON l.customer_id=c.id 
        WHERE DATE(l.disbursed_date)='$date' $branch_condition_loans $officer_condition
    ",'total');
    $collected=fetch_value("
        SELECT SUM(lp.principal_amount) AS total 
        FROM loan_payments lp 
        JOIN loans l ON lp.loan_id=l.id 
        JOIN customers c ON l.customer_id=c.id 
        WHERE DATE(lp.payment_date)='$date' $branch_condition_loans $officer_condition
    ",'total');
    $disbursed_data[]=$disbursed?:0;
    $collected_data[]=$collected?:0;
}

// ================== Loan Status Distribution ==================
$status_query = mysqli_query($conn, "
    SELECT l.status, COUNT(*) AS cnt 
    FROM loans l 
    JOIN customers c ON l.customer_id=c.id
    WHERE 1=1 $branch_condition_loans $officer_condition
    GROUP BY l.status
");
$loan_status_labels=[]; $loan_status_data=[];
while($s=mysqli_fetch_assoc($status_query)){
    $loan_status_labels[]=$s['status'];
    $loan_status_data[]=(int)$s['cnt'];
}

// ================== Finance Cards ==================

// MPESA deposits today from savings_transactions
$mpesa_from_savings = fetch_value("
    SELECT IFNULL(SUM(s.amount),0) AS total
    FROM savings_transactions s
    JOIN customers c ON s.customer_id = c.id
    WHERE DATE(s.transaction_date) = '$today' AND s.method='M-Pesa'
    ".($selected_branch ? " AND c.branch_id='$selected_branch'" : '')."
",'total');

// MPESA deposits today from payments
$mpesa_from_payments = fetch_value("
    SELECT IFNULL(SUM(p.amount),0) AS total
    FROM payments p
    JOIN customers c ON p.customer_id=c.id
    WHERE DATE(p.payment_date) = '$today'
    ".($selected_branch ? " AND c.branch_id='$selected_branch'" : '')."
",'total');

$mpesa_deposits_today = $mpesa_from_savings + $mpesa_from_payments;

// MPESA withdrawals today (expenses cannot filter by branch)
$mpesa_withdrawals_today = fetch_value("
    SELECT IFNULL(SUM(amount),0) AS total 
    FROM expenses 
    WHERE DATE(expense_date) = '$today' AND (payment_method LIKE '%mpesa%' OR payment_method LIKE '%Mpesa%')
",'total');

// Cash at hand
$cash_savings = fetch_value("
    SELECT IFNULL(SUM(s.amount),0) AS total
    FROM savings_transactions s
    JOIN customers c ON s.customer_id = c.id
    WHERE DATE(s.transaction_date) <= '$today' AND s.method='Cash'
    ".($selected_branch ? " AND c.branch_id='$selected_branch'" : '')."
",'total');

$cash_expenses = fetch_value("
    SELECT IFNULL(SUM(amount),0) AS total
    FROM expenses
    WHERE DATE(expense_date) <= '$today' AND (payment_method LIKE '%Cash%' OR payment_method LIKE '%cash%')
",'total');

$cash_at_hand = $cash_savings - $cash_expenses;

// Bank balance
$bank_balance = fetch_value("
    SELECT IFNULL(SUM(s.amount),0) AS total
    FROM savings_transactions s
    JOIN customers c ON s.customer_id=c.id
    WHERE s.method='Bank'
    ".($selected_branch ? " AND c.branch_id='$selected_branch'" : '')."
",'total');

// Interest earned
$interest_earned = fetch_value("
    SELECT IFNULL(SUM(total_interest),0) AS total 
    FROM loans l
    JOIN customers c ON l.customer_id=c.id
    WHERE 1=1 $branch_condition_loans $officer_condition
",'total');

// Total payments count
$total_payments_count = fetch_value("
    SELECT COUNT(*) AS cnt 
    FROM loan_payments lp
    JOIN loans l ON lp.loan_id=l.id
    JOIN customers c ON l.customer_id=c.id
    WHERE 1=1 $branch_condition_loans $officer_condition
",'cnt');

// ================== Officer & Branch Performance ==================
// Best collectors today
$best_collectors_today = [];
$best_res = mysqli_query($conn, "
    SELECT u.id, u.name, IFNULL(SUM(lp.principal_amount),0) AS collected_today
    FROM admin_users u
    LEFT JOIN loan_payments lp ON lp.created_by = u.id AND DATE(lp.payment_date) = '$today'
    WHERE u.role IN ('loan_officer','branch_manager','cashier')
    GROUP BY u.id
    ORDER BY collected_today DESC
    LIMIT 10
");
if ($best_res) while ($r = mysqli_fetch_assoc($best_res)) $best_collectors_today[] = $r;

// Worst collectors today
$worst_collectors_today = [];
$worst_res = mysqli_query($conn, "
    SELECT u.id, u.name, IFNULL(SUM(lp.principal_amount),0) AS collected_today
    FROM admin_users u
    LEFT JOIN loan_payments lp ON lp.created_by = u.id AND DATE(lp.payment_date) = '$today'
    WHERE u.role IN ('loan_officer','branch_manager','cashier')
    GROUP BY u.id
    ORDER BY collected_today ASC
    LIMIT 10
");
if ($worst_res) while ($r = mysqli_fetch_assoc($worst_res)) $worst_collectors_today[] = $r;

// Top branches by collections (this month)
$top_branches = [];
$branch_res = mysqli_query($conn, "
    SELECT b.id, b.name, IFNULL(SUM(lp.principal_amount),0) AS collected
    FROM branches b
    LEFT JOIN customers c ON c.branch_id = b.id
    LEFT JOIN loans l ON l.customer_id = c.id
    LEFT JOIN loan_payments lp ON lp.loan_id = l.id AND DATE_FORMAT(lp.payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    GROUP BY b.id
    ORDER BY collected DESC
    LIMIT 10
");
if ($branch_res) while ($br = mysqli_fetch_assoc($branch_res)) $top_branches[] = $br;

// Worst branches by collections (this month)
$worst_branches = [];
$worst_branch_res = mysqli_query($conn, "
    SELECT b.id, b.name, IFNULL(SUM(lp.principal_amount),0) AS collected
    FROM branches b
    LEFT JOIN customers c ON c.branch_id = b.id
    LEFT JOIN loans l ON l.customer_id = c.id
    LEFT JOIN loan_payments lp ON lp.loan_id = l.id AND DATE_FORMAT(lp.payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    GROUP BY b.id
    ORDER BY collected ASC
    LIMIT 10
");
if ($worst_branch_res) while ($br = mysqli_fetch_assoc($worst_branch_res)) $worst_branches[] = $br;

// Officer ranking by collections (this month)
$top_officers_collections = [];
$ofc_res = mysqli_query($conn, "
    SELECT u.id, u.name, IFNULL(SUM(lp.principal_amount),0) AS collected
    FROM admin_users u
    LEFT JOIN loans l ON l.officer_id = u.id
    LEFT JOIN loan_payments lp ON lp.loan_id = l.id AND DATE_FORMAT(lp.payment_date,'%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    WHERE u.role = 'loan_officer'
    GROUP BY u.id
    ORDER BY collected DESC
    LIMIT 20
");
if ($ofc_res) while ($r = mysqli_fetch_assoc($ofc_res)) $top_officers_collections[] = $r;

// Officer ranking by disbursements (this month)
$top_officers_disbursements = [];
$ofc_disb_res = mysqli_query($conn, "
    SELECT u.id, u.name, IFNULL(SUM(l.principal_amount),0) AS disbursed
    FROM admin_users u
    LEFT JOIN loans l ON l.officer_id = u.id AND DATE_FORMAT(l.disbursed_date,'%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    WHERE u.role = 'loan_officer'
    GROUP BY u.id
    ORDER BY disbursed DESC
    LIMIT 20
");
if ($ofc_disb_res) while ($r = mysqli_fetch_assoc($ofc_disb_res)) $top_officers_disbursements[] = $r;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loan Management Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #01b43dff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #8b5cf6;
        }
        
        body { 
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        .dashboard-container {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .dashboard-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .kpi-grid {
            display: grid;
            gap: 1rem;
        }
        
        .kpi-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .kpi-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .kpi-value {
            font-weight: 700;
            font-size: 1.5rem;
            line-height: 1.2;
            color: #1e293b;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0 0.5rem 0;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.8s ease;
        }
        
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        
        .performance-table th,
        .performance-table td {
            padding: 0.875rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            text-align: left;
        }
        
        .performance-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .performance-table tr:hover {
            background: #f8fafc;
        }
        
        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        
        /* Responsive Grid System */
        .grid-cols-responsive {
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }
        
        .grid-cols-responsive-2 {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        
        .grid-cols-responsive-3 {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }
        
        /* Mobile Optimizations */
        @media (max-width: 640px) {
            .dashboard-container {
                padding: 1rem;
                border-radius: 12px;
                margin-bottom: 1rem;
            }
            
            .section-title {
                font-size: 1.125rem;
                margin-bottom: 1rem;
            }
            
            .kpi-card {
                padding: 1rem;
            }
            
            .kpi-value {
                font-size: 1.25rem;
            }
            
            .performance-table {
                font-size: 0.8rem;
            }
            
            .performance-table th,
            .performance-table td {
                padding: 0.75rem 0.5rem;
            }
            
            .chart-container {
                height: 240px;
            }
        }
        
        @media (max-width: 768px) {
            .filter-section {
                padding: 1rem;
            }
            
            .mobile-stack {
                flex-direction: column;
            }
            
            .mobile-full {
                width: 100%;
            }
        }
        
        @media (max-width: 1024px) {
            .hide-tablet {
                display: none !important;
            }
        }
        
        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            
            .dashboard-container {
                box-shadow: none;
                border: 1px solid #e2e8f0;
            }
        }
        
        /* Loading Animation */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 8px;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>
<body class="font-sans text-gray-800 antialiased">
<div class="max-w-full mx-auto p-4 lg:p-6">

    <!-- Filters -->
    <div class="filter-section">
        <form method="get" class="flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-building mr-2"></i>Branch
                    </label>
                    <select name="branch_id" onchange="this.form.submit()" 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">All Branches</option>
                        <?php 
                        mysqli_data_seek($branches, 0);
                        while($b=mysqli_fetch_assoc($branches)): ?>
                            <option value="<?= $b['id'] ?>" <?= $selected_branch==$b['id']?'selected':'' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <?php if($selected_branch && $officers): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user-tie mr-2"></i>Loan Officer
                    </label>
                    <select name="officer_id" onchange="this.form.submit()" 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">All Officers</option>
                        <?php 
                        mysqli_data_seek($officers, 0);
                        while($o=mysqli_fetch_assoc($officers)): ?>
                            <option value="<?= $o['id'] ?>" <?= $selected_officer==$o['id']?'selected':'' ?>>
                                <?= htmlspecialchars($o['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="flex items-end">
                    <button type="submit" 
                            class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-filter"></i>
                        Apply Filters
                    </button>
                </div>
                
                <div class="flex items-end">
                    <a href="?" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-refresh"></i>
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- MEMBERS OVERVIEW -->
    <div class="dashboard-container">
        <div class="section-title">
            <i class="fas fa-users text-blue-600"></i>
            Members Overview
        </div>
        <div class="kpi-grid grid-cols-responsive-3">
            <?php
            $member_cards = [
                ['label'=>'Total Members','value'=>$total_members,'color'=>'#3b82f6','icon'=>'users'],
                ['label'=>'Active Members','value'=>$active_members,'color'=>'#10b981','icon'=>'user-check'],
                ['label'=>'Inactive Members','value'=>$inactive_members,'color'=>'#6b7280','icon'=>'user-slash'],
                ['label'=>'New This Year','value'=>$all_customers_ytd,'color'=>'#f59e0b','icon'=>'user-plus'],
            ];
            foreach($member_cards as $m): ?>
            <div class="kpi-card" style="border-left-color: <?= $m['color'] ?>">
                <div class="kpi-label">
                    <i class="fas fa-<?= $m['icon'] ?>"></i>
                    <?= $m['label'] ?>
                </div>
                <div class="kpi-value" style="color: <?= $m['color'] ?>">
                    <?= number_format($m['value']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- LOANS & FINANCE -->
    <div class="dashboard-container">
        <div class="section-title">
            <i class="fas fa-hand-holding-usd text-green-600"></i>
            Loans & Finance Overview
        </div>
        <div class="kpi-grid grid-cols-responsive">
            <?php
            $average_loan_size = $total_disbursed_loans > 0 ? $total_disbursed_amt / $total_disbursed_loans : 0;
            $loan_cards = [
                ['label'=>'Total Disbursed','value'=>$total_disbursed_amt,'color'=>'#10b981','icon'=>'money-bill-wave','is_money'=>true],
                ['label'=>'Active Loans','value'=>$total_disbursed_loans,'color'=>'#3b82f6','icon'=>'file-invoice'],
                ['label'=>'Fully Paid','value'=>$fully_paid_loans,'color'=>'#6b7280','icon'=>'check-circle'],
                ['label'=>'Outstanding','value'=>$loan_balance,'color'=>'#8b5cf6','icon'=>'balance-scale','is_money'=>true],
                ['label'=>'Loans in Arrears','value'=>fetch_value("SELECT COUNT(*) AS cnt FROM loans l JOIN customers c ON l.customer_id=c.id LEFT JOIN (SELECT loan_id, SUM(principal_amount) AS total_paid FROM loan_payments GROUP BY loan_id) p ON l.id=p.loan_id WHERE l.status IN ('Active','Disbursed') AND ( (FLOOR(DATEDIFF('$today', l.disbursed_date)/7) * l.weekly_installment) > IFNULL(p.total_paid,0) ) $branch_condition_loans $officer_condition",'cnt'),'color'=>'#ef4444','icon'=>'exclamation-triangle'],
            ];
            foreach($loan_cards as $l): ?>
            <div class="kpi-card" style="border-left-color: <?= $l['color'] ?>">
                <div class="kpi-label">
                    <i class="fas fa-<?= $l['icon'] ?>"></i>
                    <?= $l['label'] ?>
                </div>
                <div class="kpi-value" style="color: <?= $l['color'] ?>">
                    <?= isset($l['is_money']) ? 'KSh '.number_format($l['value'],2) : number_format($l['value']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- COLLECTIONS & FINANCE -->
    <div class="dashboard-container">
        <div class="section-title">
            <i class="fas fa-chart-bar text-purple-600"></i>
            Collections & Finance
        </div>
        <div class="kpi-grid grid-cols-responsive">
            <?php
            $collection_cards = [
                ['label'=>"Money In",'value'=>$money_in,'color'=>'#10b981','icon'=>'arrow-down','is_money'=>true],
                ['label'=>"Money Out",'value'=>$money_out,'color'=>'#ef4444','icon'=>'arrow-up','is_money'=>true],
                ['label'=>'Arrears Amount','value'=>$arrears,'color'=>'#f59e0b','icon'=>'clock','is_money'=>true],
                ['label'=>"Today's Due",'value'=>$today_due,'color'=>'#3b82f6','icon'=>'calendar-day','is_money'=>true],
                ['label'=>"Interest Earned",'value'=>$interest_earned,'color'=>'#8b5cf6','icon'=>'percent','is_money'=>true],
            ];
            foreach($collection_cards as $c): ?>
            <div class="kpi-card" style="border-left-color: <?= $c['color'] ?>">
                <div class="kpi-label">
                    <i class="fas fa-<?= $c['icon'] ?>"></i>
                    <?= $c['label'] ?>
                </div>
                <div class="kpi-value" style="color: <?= $c['color'] ?>">
                    <?= isset($c['is_money']) ? 'KSh '.number_format($c['value'],2) : htmlspecialchars($c['value']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- COLLECTION RATES -->
    <div class="dashboard-container">
        <div class="section-title">
            <i class="fas fa-tachometer-alt text-yellow-600"></i>
            Collection Performance
        </div>
        <div class="kpi-grid grid-cols-responsive-2">
            <?php
            $rate_cards = [
                [
                    'label' => "Today's Collection Rate",
                    'rate' => $collection_rate_today,
                    'collected' => $money_in_today,
                    'due' => $today_due,
                    'icon' => 'sun'
                ],
                [
                    'label' => "This Week Collection Rate",
                    'rate' => $collection_rate_week,
                    'collected' => $week_collected,
                    'due' => $week_due,
                    'icon' => 'calendar-week'
                ],
                [
                    'label' => "This Month Collection Rate",
                    'rate' => $collection_rate_month,
                    'collected' => $month_collected,
                    'due' => $month_due,
                    'icon' => 'calendar-alt'
                ]
            ];
            
            foreach($rate_cards as $card): 
                $color = $card['rate'] >= 80 ? '#10b981' : ($card['rate'] >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="kpi-card" style="border-left-color: <?= $color ?>">
                <div class="kpi-label">
                    <i class="fas fa-<?= $card['icon'] ?>"></i>
                    <?= $card['label'] ?>
                </div>
                <div class="kpi-value" style="color: <?= $color ?>">
                    <?= number_format($card['rate'], 1) ?>%
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= min($card['rate'], 100) ?>%; background: <?= $color ?>"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-600 mt-2">
                    <span>Collected: KSh <?= number_format($card['collected'], 2) ?></span>
                    <span>Due: KSh <?= number_format($card['due'], 2) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- PERFORMANCE INDICATORS -->
    <div class="dashboard-container">
        <div class="section-title">
            <i class="fas fa-chart-line text-red-600"></i>
            Performance Indicators
        </div>
        <div class="kpi-grid grid-cols-responsive-3">
            <div class="kpi-card" style="border-left-color: #f59e0b">
                <div class="kpi-label">
                    <i class="fas fa-exclamation-triangle"></i>
                    PAR (30+)
                </div>
                <div class="kpi-value" style="color: #f59e0b">
                    <?= $par_percent ?>%
                </div>
                <div class="text-xs text-gray-500 mt-1">Portfolio at risk — 30+ days</div>
            </div>
            <div class="kpi-card" style="border-left-color: #ef4444">
                <div class="kpi-label">
                    <i class="fas fa-clock"></i>
                    Total Arrears
                </div>
                <div class="kpi-value" style="color: #ef4444">
                    KSh <?= number_format($total_arrears,2) ?>
                </div>
                <div class="text-xs text-gray-500 mt-1">Outstanding overdue amount</div>
            </div>
            <div class="kpi-card" style="border-left-color: #8b5cf6">
                <div class="kpi-label">
                    <i class="fas fa-scale-balanced"></i>
                    Total Outstanding
                </div>
                <div class="kpi-value" style="color: #8b5cf6">
                    KSh <?= number_format($total_outstanding,2) ?>
                </div>
                <div class="text-xs text-gray-500 mt-1">Active loan portfolio</div>
            </div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="dashboard-container hide-tablet">
        <div class="section-title">
            <i class="fas fa-chart-pie text-indigo-600"></i>
            Analytics & Insights
        </div>
        <div class="kpi-grid grid-cols-responsive-2 lg:grid-cols-3 gap-4">
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-chart-pie text-blue-600"></i>
                    Loan Status Distribution
                </h4>
                <div class="chart-container">
                    <canvas id="loanStatusChart"></canvas>
                </div>
            </div>
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-chart-bar text-green-600"></i>
                    Members Overview
                </h4>
                <div class="chart-container">
                    <canvas id="membersChart"></canvas>
                </div>
            </div>
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-chart-line text-purple-600"></i>
                    Disbursed vs Collected (5 Days)
                </h4>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- BRANCH & OFFICER PERFORMANCE -->
    <div class="dashboard-container">
        <div class="section-title">
            <i class="fas fa-trophy text-amber-600"></i>
            Team Performance
        </div>

        <div class="kpi-grid grid-cols-responsive-2 gap-6">
            <!-- Top Branches -->
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-crown text-yellow-500"></i>
                    Top Branches (This Month)
                </h4>
                <div class="overflow-x-auto">
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Branch</th>
                                <th class="text-right">Collected (KSh)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($top_branches)): $r = 1; ?>
                                <?php foreach($top_branches as $tb): ?>
                                    <tr>
                                        <td class="font-medium"><?= $r++ ?></td>
                                        <td class="font-medium"><?= htmlspecialchars($tb['name']) ?></td>
                                        <td class="text-right font-semibold text-green-600">
                                            KSh <?= number_format($tb['collected'] ?? 0,2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-gray-500">
                                        No branch data available
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Officers by Collections -->
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-medal text-blue-500"></i>
                    Top Officers - Collections
                </h4>
                <div class="overflow-x-auto">
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Officer</th>
                                <th class="text-right">Collected (KSh)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($top_officers_collections)): $r=1; ?>
                                <?php foreach($top_officers_collections as $to): ?>
                                    <tr>
                                        <td class="font-medium"><?= $r++ ?></td>
                                        <td class="font-medium"><?= htmlspecialchars($to['name']) ?></td>
                                        <td class="text-right font-semibold text-blue-600">
                                            KSh <?= number_format($to['collected'] ?? 0,2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-gray-500">
                                        No officer data available
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Best Collectors Today -->
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-bolt text-green-500"></i>
                    Today's Best Collectors
                </h4>
                <div class="overflow-x-auto">
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Officer</th>
                                <th class="text-right">Today (KSh)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($best_collectors_today)): $r=1; ?>
                                <?php foreach($best_collectors_today as $bc): ?>
                                    <tr>
                                        <td class="font-medium"><?= $r++ ?></td>
                                        <td class="font-medium"><?= htmlspecialchars($bc['name']) ?></td>
                                        <td class="text-right font-semibold text-green-600">
                                            KSh <?= number_format($bc['collected_today'] ?? 0,2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-gray-500">
                                        No collections today
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Officers by Disbursements -->
            <div class="kpi-card">
                <h4 class="font-semibold mb-4 text-gray-800 flex items-center gap-2">
                    <i class="fas fa-rocket text-purple-500"></i>
                    Top Officers - Disbursements
                </h4>
                <div class="overflow-x-auto">
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Officer</th>
                                <th class="text-right">Disbursed (KSh)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($top_officers_disbursements)): $r=1; ?>
                                <?php foreach($top_officers_disbursements as $td): ?>
                                    <tr>
                                        <td class="font-medium"><?= $r++ ?></td>
                                        <td class="font-medium"><?= htmlspecialchars($td['name']) ?></td>
                                        <td class="text-right font-semibold text-purple-600">
                                            KSh <?= number_format($td['disbursed'] ?? 0,2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-gray-500">
                                        No disbursement data
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
const loanStatusLabels = <?= json_encode($loan_status_labels) ?>;
const loanStatusData = <?= json_encode($loan_status_data) ?>;
const trendDates = <?= json_encode($dates) ?>;
const disbursedData = <?= json_encode($disbursed_data) ?>;
const collectedData = <?= json_encode($collected_data) ?>;
const membersLabels = ['Active','Inactive','Pending','New YTD'];
const membersData = [<?= (int)$active_members ?>, <?= (int)$inactive_members ?>, <?= (int)$pending_accounts ?>, <?= (int)$all_customers_ytd ?>];

// Loan Status Doughnut Chart
if (document.getElementById('loanStatusChart')) {
    new Chart(document.getElementById('loanStatusChart'), {
        type: 'doughnut',
        data: { 
            labels: loanStatusLabels, 
            datasets: [{ 
                data: loanStatusData, 
                backgroundColor: ['#10b981','#3b82f6','#f59e0b','#6b7280','#ef4444'],
                borderWidth: 2,
                borderColor: '#fff'
            }] 
        },
        options: { 
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        usePointStyle: true
                    }
                }
            }
        }
    });
}

// Members Bar Chart
if (document.getElementById('membersChart')) {
    new Chart(document.getElementById('membersChart'), {
        type: 'bar',
        data: { 
            labels: membersLabels, 
            datasets: [{ 
                label: 'Count', 
                data: membersData, 
                backgroundColor: ['#10b981','#6b7280','#f59e0b','#3b82f6'],
                borderRadius: 4
            }] 
        },
        options: { 
            responsive: true,
            maintainAspectRatio: false,
            scales: { 
                y: { 
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

// Trend Line Chart
if (document.getElementById('trendChart')) {
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: trendDates,
            datasets: [
                { 
                    label: 'Disbursed', 
                    data: disbursedData, 
                    borderColor: '#10b981', 
                    backgroundColor: '#10b98120', 
                    fill: true, 
                    tension: 0.4,
                    borderWidth: 2
                },
                { 
                    label: 'Collected', 
                    data: collectedData, 
                    borderColor: '#3b82f6', 
                    backgroundColor: '#3b82f620', 
                    fill: true, 
                    tension: 0.4,
                    borderWidth: 2
                }
            ]
        },
        options: { 
            responsive: true,
            maintainAspectRatio: false,
            scales: { 
                y: { 
                    beginAtZero: true, 
                    ticks: { 
                        callback: function(v) { 
                            return 'KSh ' + Number(v).toLocaleString(); 
                        } 
                    }
                }
            }
        }
    });
}

// Add loading states for better UX
document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.querySelector('form');
    if (filterForm) {
        filterForm.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                submitBtn.disabled = true;
                
                // Re-enable after 3 seconds in case of error
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 3000);
            }
        });
    }
});
</script>
</body>
</html>