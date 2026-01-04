<?php
session_start();
include '../includes/db.php';

// Check login and role
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'branch_manager') {
    header("Location: ../login.php");
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];

// Fetch loan products
$products = mysqli_query($conn, "SELECT * FROM loan_products ORDER BY product_name ASC");

// Handle loan submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = mysqli_real_escape_string($conn, $_POST['customer_id']);
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $principal_amount = floatval($_POST['principal_amount']);
    $interest_rate = floatval($_POST['interest_rate']);
    $duration_weeks = intval($_POST['duration_weeks']);
    $weekly_installment = floatval($_POST['weekly_installment']);
    $total_repayable = floatval($_POST['total_repayable']);

    // Fetch customer info
    $customer_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT branch_id, status FROM customers WHERE id='$customer_id'"));
    if (!$customer_row) {
        echo "<script>alert('Customer not found!'); window.history.back();</script>";
        exit;
    }
    $customer_branch_id = $customer_row['branch_id'] ?? $branch_id;
    $status = $customer_row['status'];

    // ✅ Check status rules
    if ($status === 'Active') {
        echo "<script>alert('This customer already has an active loan, cannot create another.'); window.history.back();</script>";
        exit;
    } elseif ($status === 'Pending') {
        echo "<script>alert('This customer is pending approval, loan cannot be created.'); window.history.back();</script>";
        exit;
    } elseif ($status !== 'Inactive') {
        echo "<script>alert('This customer is not eligible for a loan.'); window.history.back();</script>";
        exit;
    }

    // ✅ Check for outstanding loans
    $check_balance = mysqli_query($conn, "
        SELECT SUM(l.total_repayable - IFNULL(p.total_paid, 0)) AS balance
        FROM loans l
        LEFT JOIN (
            SELECT loan_id, SUM(principal_amount) AS total_paid
            FROM loan_payments GROUP BY loan_id
        ) p ON l.id = p.loan_id
        WHERE l.customer_id = '$customer_id' AND l.status = 'Disbursed'
    ");
    $balance_row = mysqli_fetch_assoc($check_balance);
    $current_balance = $balance_row['balance'] ?? 0;

    if ($current_balance > 0) {
        echo "<script>alert('This customer has an outstanding loan balance of KES " . number_format($current_balance, 2) . ". Loan creation denied.'); window.history.back();</script>";
        exit;
    }

    // ✅ Validate product & loan limits
    $product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loan_products WHERE id = '$product_id'"));
    if (!$product) {
        echo "<script>alert('Loan product not found!'); window.history.back();</script>";
        exit;
    }
    if ($principal_amount < $product['min_amount'] || $principal_amount > $product['max_amount']) {
        echo "<script>alert('Loan amount must be between KES {$product['min_amount']} and {$product['max_amount']}'); window.history.back();</script>";
        exit;
    }

    // ✅ Create loan
    $code = 'LN' . strtoupper(uniqid());
    $stmt = mysqli_prepare($conn, "INSERT INTO loans 
        (customer_id, product_id, principal_amount, interest_rate, duration_weeks, weekly_installment, total_repayable, loan_code, status, created_at, branch_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?)");
    mysqli_stmt_bind_param(
        $stmt, 
        'iiddiissi',
        $customer_id,
        $product_id,
        $principal_amount,
        $interest_rate,
        $duration_weeks,
        $weekly_installment,
        $total_repayable,
        $code,
        $customer_branch_id
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo "<script>alert('Loan created successfully!'); window.location.href='loans';</script>";
    exit;
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Loan – Faida SACCO</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
.brand-color { color: #15a362; }
.brand-bg { background-color: #15a362; }
.brand-border { border-color: #15a362; }
.brand-bg-hover:hover { background-color: #128d51; }
.suggest-box { max-height: 200px; overflow-y: auto; background: white; border: 1px solid #ddd; position: absolute; width: 100%; z-index: 50; }
.suggest-item { padding: 8px 12px; cursor: pointer; }
.suggest-item:hover { background-color: #f0f0f0; }
</style>
</head>
<body class="bg-gray-100 font-sans p-6">
<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
<h1 class="text-xl font-bold mb-6 brand-color">Create New Loan</h1>

<form method="POST" autocomplete="off">
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

<!-- Customer (autocomplete) -->
<div class="relative">
<label class="block text-sm font-medium mb-1 brand-color">Customer</label>
<input type="text" id="customerSearch" placeholder="Search by name or code" class="w-full px-3 py-2 border brand-border rounded" required />
<input type="hidden" name="customer_id" id="customerId" required />
<div id="customerSuggestions" class="suggest-box hidden"></div>
</div>

<!-- Product -->
<div>
<label class="block text-sm font-medium mb-1 brand-color">Loan Product</label>
<select name="product_id" id="productSelect" required class="w-full px-3 py-2 border brand-border rounded" onchange="fillProductDetails()">
<option value="">-- Select Loan Product --</option>
<?php
mysqli_data_seek($products, 0);
while($p = mysqli_fetch_assoc($products)):
  $p_json = htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8');
?>
<option value="<?= $p['id'] ?>" data-details="<?= $p_json ?>"><?= $p['product_name'] ?></option>
<?php endwhile; ?>
</select>
</div>

<!-- Loan Amount -->
<div>
<label class="block text-sm font-medium mb-1 brand-color">Loan Amount (KES)</label>
<input type="number" name="principal_amount" id="amountInput" required class="w-full px-3 py-2 border brand-border rounded" oninput="calculateLoan()" />
<small class="text-xs text-gray-500" id="amountRangeText"></small>
</div>

<!-- Interest -->
<div>
<label class="block text-sm font-medium mb-1 brand-color">Interest Rate (%)</label>
<input type="number" name="interest_rate" id="interestInput" readonly class="w-full px-3 py-2 border brand-border rounded bg-gray-100" />
</div>

<!-- Duration -->
<div>
<label class="block text-sm font-medium mb-1 brand-color">Duration (Weeks)</label>
<input type="number" name="duration_weeks" id="durationInput" readonly class="w-full px-3 py-2 border brand-border rounded bg-gray-100" />
</div>

<!-- Installment -->
<div>
<label class="block text-sm font-medium mb-1 brand-color">Weekly Installment (KES)</label>
<input type="text" name="weekly_installment" id="installmentInput" readonly class="w-full px-3 py-2 border brand-border rounded bg-gray-100" />
</div>

<!-- Total Repayable -->
<div>
<label class="block text-sm font-medium mb-1 brand-color">Total Repayable (KES)</label>
<input type="text" name="total_repayable" id="repayableInput" readonly class="w-full px-3 py-2 border brand-border rounded bg-gray-100" />
</div>

</div>
<div class="mt-6 text-right">
<button type="submit" class="brand-bg text-white px-4 py-2 rounded brand-bg-hover">Submit Loan</button>
</div>
</form>
</div>

<script>
function fillProductDetails() {
  const sel = document.querySelector('#productSelect option:checked');
  if (!sel.dataset.details) return;
  const data = JSON.parse(sel.dataset.details);
  document.getElementById('interestInput').value = data.interest_rate;
  document.getElementById('durationInput').value = data.duration_weeks;
  document.getElementById('amountInput').min = data.min_amount;
  document.getElementById('amountInput').max = data.max_amount;
  document.getElementById('amountRangeText').innerText =
    `Allowed: KES ${Number(data.min_amount).toLocaleString()} – ${Number(data.max_amount).toLocaleString()}`;
  calculateLoan();
}

function calculateLoan() {
  const amount = parseFloat(document.getElementById('amountInput').value);
  const interest = parseFloat(document.getElementById('interestInput').value);
  const weeks = parseInt(document.getElementById('durationInput').value);
  if (!amount || !interest || !weeks) return;
  const periods = weeks / 4;
  const totalInterest = amount * (interest / 100) * periods;
  const totalRepayable = amount + totalInterest;
  const weeklyInstallment = totalRepayable / weeks;
  document.getElementById('repayableInput').value = totalRepayable.toFixed(2);
  document.getElementById('installmentInput').value = weeklyInstallment.toFixed(2);
}

// Customer search autocomplete
document.getElementById('customerSearch').addEventListener('input', function() {
  const query = this.value;
  const suggestionBox = document.getElementById('customerSuggestions');
  if (query.length < 2) {
    suggestionBox.classList.add('hidden');
    return;
  }
  fetch('search_customer.php?q=' + encodeURIComponent(query))
    .then(res => res.json())
    .then(data => {
      suggestionBox.innerHTML = '';
      if (data.length === 0) {
        suggestionBox.classList.add('hidden');
        return;
      }
      data.forEach(item => {
        const div = document.createElement('div');
        div.className = 'suggest-item';
        div.textContent = `${item.name} (${item.code})`;
        div.onclick = () => {
          document.getElementById('customerSearch').value = `${item.name} (${item.code})`;
          document.getElementById('customerId').value = item.id;
          suggestionBox.classList.add('hidden');
        };
        suggestionBox.appendChild(div);
      });
      suggestionBox.classList.remove('hidden');
    });
});
</script>
</body>
</html>
