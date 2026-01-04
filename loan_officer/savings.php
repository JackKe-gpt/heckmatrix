<?php
session_start();

include '../includes/db.php';
include 'header.php';

// Ensure admin session exists
if (!isset($_SESSION['admin'])) {
    header("Location: ../index");
    exit;
}

$branch_id = isset($_SESSION['admin']['branch_id']) ? (int)$_SESSION['admin']['branch_id'] : 0;
if ($branch_id === 0) {
    die("Branch ID not found. Please log in again.");
}

$success = $error = "";

// Get branch name
$branch_name = '';
$branch_res = $conn->query("SELECT name FROM branches WHERE id=$branch_id");
if ($row = $branch_res->fetch_assoc()) {
    $branch_name = $row['name'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? '';

    if ($customer_id && $amount > 0 && $method) {
        $conn->begin_transaction();
        try {
            // Insert transaction
            $stmt = $conn->prepare("INSERT INTO savings_transactions (customer_id, amount, method, transaction_date) VALUES (?, ?, ?, CURDATE())");
            $stmt->bind_param("ids", $customer_id, $amount, $method);
            $stmt->execute();
            $stmt->close();

            // Update balance
            $stmt2 = $conn->prepare("UPDATE customers SET savings_balance = savings_balance + ? WHERE id = ?");
            $stmt2->bind_param("di", $amount, $customer_id);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            $success = "Savings recorded successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error recording savings. Please try again.";
        }
    } else {
        $error = "Please enter valid customer, amount, and method.";
    }
}

// Fetch recent transactions
$transactions = $conn->query("
    SELECT s.id, c.customer_code, CONCAT(c.first_name, ' ', c.surname) AS name, 
           s.amount, s.method, s.transaction_date
    FROM savings_transactions s
    JOIN customers c ON s.customer_id = c.id
    WHERE c.branch_id = $branch_id
    ORDER BY s.transaction_date DESC
");

// Calculate total savings for this branch
$total_savings = $conn->query("
    SELECT IFNULL(SUM(s.amount),0) AS total 
    FROM savings_transactions s 
    JOIN customers c ON s.customer_id=c.id 
    WHERE c.branch_id=$branch_id
")->fetch_assoc()['total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Group Savings - <?= htmlspecialchars($branch_name) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
</head>
<body class="bg-gray-100 text-gray-800">

<div class="w-full max-w-6xl mx-auto px-6 py-8">

    <h1 class="text-3xl font-bold text-green-700 mb-6">Savings - <?= htmlspecialchars($branch_name) ?></h1>

    <?php if (!empty($success)): ?>
        <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= $success ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <!-- Savings Form -->
    <div class="bg-white p-6 rounded shadow-md mb-8">
        <form method="POST" id="savingsForm" class="grid md:grid-cols-3 gap-4">
            <div>
                <label class="block text-gray-700 mb-1">Customer</label>
                <input type="text" id="customer" placeholder="Type name, code or ID..." class="w-full border rounded px-3 py-2">
                <input type="hidden" name="customer_id" id="customer_id">
            </div>
            <div>
                <label class="block text-gray-700 mb-1">Amount</label>
                <input type="number" step="0.01" name="amount" min="1" class="w-full border rounded px-3 py-2" required>
            </div>
            <div>
                <label class="block text-gray-700 mb-1">Method</label>
                <select name="method" class="w-full border rounded px-3 py-2" required>
                    <option value="Cash">Cash</option>
                    <option value="M-Pesa">M-Pesa</option>
                    <option value="Bank">Bank</option>
                </select>
            </div>
            <div class="md:col-span-3 mt-2">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded">Record Savings</button>
            </div>
        </form>
    </div>

    <!-- Statistics Card -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-green-50 p-5 rounded shadow-md">
            <p class="text-xs text-gray-500">Total Savings (Branch)</p>
            <p class="text-lg font-semibold text-green-700">KES <?= number_format($total_savings,2) ?></p>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white p-6 rounded shadow-md">
        <h2 class="text-xl font-semibold mb-4">Recent Savings</h2>
        <div class="overflow-x-auto">
            <table id="transactions" class="display table-auto w-full text-sm">
                <thead class="bg-gray-200 text-gray-600">
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Code</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n=1; while($row=$transactions->fetch_assoc()): ?>
                    <tr>
                        <td class="px-3 py-2 border"><?= $n++ ?></td>
                        <td class="px-3 py-2 border"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="px-3 py-2 border"><?= htmlspecialchars($row['customer_code']) ?></td>
                        <td class="px-3 py-2 border"><?= number_format($row['amount'],2) ?></td>
                        <td class="px-3 py-2 border"><?= htmlspecialchars($row['method']) ?></td>
                        <td class="px-3 py-2 border"><?= $row['transaction_date'] ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
$(function(){
    // Autocomplete for customer search
    $("#customer").autocomplete({
        source: function(request, response) {
            $.ajax({
                url: "autocomplete_customers.php",
                dataType: "json",
                data: {
                    term: request.term,
                    branch_id: <?= $branch_id ?>
                },
                success: function(data) { response(data); }
            });
        },
        minLength: 2,
        select: function(event, ui) {
            $("#customer").val(ui.item.label);
            $("#customer_id").val(ui.item.value);
            return false;
        }
    });

    // Validate form before submit
    $("#savingsForm").on("submit", function(e) {
        if ($("#customer_id").val() === "") {
            alert("Please select a valid customer from the list.");
            e.preventDefault();
        }
    });

    // DataTable initialization
    $('#transactions').DataTable({
        order: [[5, 'desc']]
    });
});
</script>
</body>
</html>
