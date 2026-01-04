<?php
require_once 'auth.php';
require_login();

include 'includes/db.php';

// Approve a single loan → Pending Disbursement
if (isset($_GET['approve_id'])) {
  $id = intval($_GET['approve_id']);
  mysqli_query($conn, "UPDATE loans SET status = 'Pending Disbursement' WHERE id = $id");
  echo "<script>alert('Loan approved successfully and is now pending disbursement!'); window.location='approve_loan';</script>";
  exit;
}

// Reject a single loan
if (isset($_GET['decline_id'])) {
  $id = intval($_GET['decline_id']);
  mysqli_query($conn, "UPDATE loans SET status = 'Rejected' WHERE id = $id");
  echo "<script>alert('Loan rejected successfully!'); window.location='approve_loan';</script>";
  exit;
}

// Reduce loan limit (update amount without approving/rejecting yet)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reduce_id'], $_POST['new_amount'])) {
  $id = intval($_POST['reduce_id']);
  $new_amount = floatval($_POST['new_amount']);
  mysqli_query($conn, "UPDATE loans SET principal_amount = $new_amount WHERE id = $id AND status = 'Pending'");
  echo "<script>alert('Loan amount reduced successfully!'); window.location='approve_loan';</script>";
  exit;
}

// Approve all pending loans → Pending Disbursement
if (isset($_GET['approve_all'])) {
  mysqli_query($conn, "UPDATE loans SET status = 'Pending Disbursement' WHERE status = 'Pending'");
  echo "<script>alert('All pending loans moved to pending disbursement!'); window.location='approve_loan';</script>";
  exit;
}

// Fetch all pending loans
$result = mysqli_query($conn, "
  SELECT loans.*, 
    CONCAT(c.first_name, ' ', c.middle_name, ' ', c.surname) AS customer_name,
    p.product_name 
  FROM loans 
  JOIN customers c ON loans.customer_id = c.id 
  JOIN loan_products p ON loans.product_id = p.id 
  WHERE loans.status = 'Pending' 
  ORDER BY loans.created_at DESC
");

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Approve Loans – Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .brand { color: #15a362; }
    .brand-bg { background-color: #15a362; }
    .brand-hover:hover { background-color: #128d51; }
  </style>
</head>
<body class="bg-gray-100 font-sans p-6">
  <div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-xl font-bold brand">Pending Loan Approvals</h1>
      <a href="?approve_all=1" onclick="return confirm('Are you sure you want to approve ALL pending loans?')" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">Approve All</a>
    </div>

    <?php if (mysqli_num_rows($result) === 0): ?>
      <div class="text-center text-gray-500 py-10 text-lg">No pending loans at the moment.</div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm text-left border border-gray-200">
        <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
          <tr>
            <th class="px-4 py-3 border">#</th>
            <th class="px-4 py-3 border">Customer</th>
            <th class="px-4 py-3 border">Product</th>
            <th class="px-4 py-3 border">Amount</th>
            <th class="px-4 py-3 border">Weeks</th>
            <th class="px-4 py-3 border">Status</th>
            <th class="px-4 py-3 border">Requested On</th>
            <th class="px-4 py-3 border text-center">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php $i = 1; while($row = mysqli_fetch_assoc($result)): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 border"><?= $i++ ?></td>
              <td class="px-4 py-2 border"><?= htmlspecialchars($row['customer_name']) ?></td>
              <td class="px-4 py-2 border"><?= htmlspecialchars($row['product_name']) ?></td>
              <td class="px-4 py-2 border text-green-600 font-semibold">KES <?= number_format($row['principal_amount'], 2) ?></td>
              <td class="px-4 py-2 border"><?= $row['duration_weeks'] ?> weeks</td>
              <td class="px-4 py-2 border text-yellow-600 font-semibold"><?= $row['status'] ?></td>
              <td class="px-4 py-2 border text-gray-500"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
              <td class="px-4 py-2 border text-center space-x-2">
                <a href="?approve_id=<?= $row['id'] ?>" onclick="return confirm('Approve this loan?')" class="text-white bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded text-xs">Approve</a>
                <a href="?decline_id=<?= $row['id'] ?>" onclick="return confirm('Reject this loan?')" class="text-white bg-red-600 hover:bg-red-700 px-3 py-1 rounded text-xs">Reject</a>
                
                <!-- Reduce Limit (opens inline form) -->
                <button onclick="document.getElementById('reduceForm<?= $row['id'] ?>').classList.toggle('hidden')" 
                  class="text-white bg-yellow-500 hover:bg-yellow-600 px-3 py-1 rounded text-xs">Reduce Limit</button>

                <form method="post" id="reduceForm<?= $row['id'] ?>" class="hidden mt-2">
                  <input type="hidden" name="reduce_id" value="<?= $row['id'] ?>">
                  <input type="number" step="0.01" name="new_amount" placeholder="New Amount" required
                    class="border px-2 py-1 text-xs rounded w-28 mb-1">
                  <button type="submit" onclick="return confirm('Reduce loan limit?')" 
                    class="bg-yellow-600 hover:bg-yellow-700 text-white px-2 py-1 rounded text-xs">Save</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
