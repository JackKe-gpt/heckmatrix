<?php
require_once 'auth.php';
require_login();
include 'includes/db.php';
include 'header.php';

$success = '';
$error = '';

// Constants
define('REG_FEE', 300);
define('PROCESSING_FEE', 500);

// Approve customer → set status to Inactive + insert registration fee
if (isset($_GET['approve_id'])) {
    $id = intval($_GET['approve_id']);
    
    // Fetch customer first
    $customer = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM customers WHERE id = $id LIMIT 1"));
    
    if ($customer && $customer['status'] === 'Pending') {
        // Approve customer
        $update = mysqli_query($conn, "UPDATE customers SET status = 'Inactive' WHERE id = $id AND status = 'Pending'");
        
        if ($update) {
            // Insert registration fee record
            $payment_date = date('Y-m-d');
            mysqli_query($conn, "INSERT INTO payments (customer_id, amount, purpose, payment_date) 
                                 VALUES ($id, " . REG_FEE . ", 'registration', '$payment_date')");
            
            $success = "Customer approved successfully. Registration fee (" . REG_FEE . ") has been recorded. Customer is now Inactive.";
        } else {
            $error = "Error approving customer: " . mysqli_error($conn);
        }
    } else {
        $error = "Customer not found or already approved.";
    }
}

// Fetch all Pending customers with branch name
$result = mysqli_query($conn, "
    SELECT c.id,
           CONCAT(c.first_name,' ',c.middle_name,' ',c.surname) AS full_name,
           c.national_id,
           c.phone_number,
           b.name AS branch_name,
           c.status,
           c.created_at
    FROM customers c
    LEFT JOIN branches b ON c.branch_id = b.id
    WHERE c.status = 'Pending' 
    ORDER BY c.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Approve Customers – Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans p-6">
<div class="w-full px-4 sm:px-6 lg:px-8 py-6 bg-white rounded shadow-md">
    <h2 class="text-xl font-bold text-emerald-600 mb-4">Pending Customers</h2>

    <?php if ($success): ?>
      <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4"><?= $success ?></div>
    <?php elseif ($error): ?>
      <div class="bg-red-100 text-red-800 px-4 py-2 rounded mb-4"><?= $error ?></div>
    <?php endif; ?>

    <?php if (mysqli_num_rows($result) === 0): ?>
        <div class="text-center text-gray-500 py-6">No pending customers found.</div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm border border-gray-200">
        <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
          <tr>
            <th class="px-4 py-3 border">#</th>
            <th class="px-4 py-3 border">Full Name</th>
            <th class="px-4 py-3 border">National ID</th>
            <th class="px-4 py-3 border">Phone No</th>
            <th class="px-4 py-3 border">Branch</th>
            <th class="px-4 py-3 border">Status</th>
            <th class="px-4 py-3 border">Registered On</th>
            <th class="px-4 py-3 border text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; while ($row = mysqli_fetch_assoc($result)): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 border"><?= $i++ ?></td>
              <td class="px-4 py-2 border"><?= htmlspecialchars($row['full_name']) ?></td>
              <td class="px-4 py-2 border"><?= htmlspecialchars($row['national_id']) ?></td>
              <td class="px-4 py-2 border"><?= htmlspecialchars($row['phone_number']) ?></td>
              <td class="px-4 py-2 border"><?= htmlspecialchars($row['branch_name']) ?></td>
              <td class="px-4 py-2 border text-yellow-600 font-semibold"><?= $row['status'] ?></td>
              <td class="px-4 py-2 border text-gray-500"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
              <td class="px-4 py-2 border text-center">
                <a href="?approve_id=<?= $row['id'] ?>" 
                   onclick="return confirm('Approve this customer and record registration fee?')" 
                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs">
                   Approve
                </a>
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
