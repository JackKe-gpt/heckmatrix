<?php
require_once 'auth.php';
require_login();
include 'includes/db.php';

// ===========================
// ADD NEW PRODUCT
// ===========================
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $stmt = $conn->prepare("INSERT INTO loan_products 
        (product_name, interest_rate, duration_weeks, min_amount, max_amount, description) 
        VALUES (?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "sdddds",
        $_POST['product_name'],
        $_POST['interest_rate'],
        $_POST['duration_weeks'],
        $_POST['min_amount'],
        $_POST['max_amount'],
        $_POST['description']
    );
    
    $stmt->execute();
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===========================
// EDIT PRODUCT
// ===========================
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $stmt = $conn->prepare("UPDATE loan_products SET 
        product_name=?, interest_rate=?, duration_weeks=?, min_amount=?, max_amount=?, description=?
        WHERE id=?");
    
    $stmt->bind_param(
        "sddddsi",
        $_POST['product_name'],
        $_POST['interest_rate'],
        $_POST['duration_weeks'],
        $_POST['min_amount'],
        $_POST['max_amount'],
        $_POST['description'],
        $_POST['id']
    );
    
    $stmt->execute();
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===========================
// DELETE PRODUCT
// ===========================
if (isset($_GET['delete'])) {
    $stmt = $conn->prepare("DELETE FROM loan_products WHERE id=?");
    $stmt->bind_param("i", $_GET['delete']);
    $stmt->execute();
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Loan Products - Faida SACCO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>

<!-- Modal Script -->
<script>
function openEditModal(product) {
    document.getElementById('edit-id').value = product.id;
    document.getElementById('edit-product').value = product.product_name;
    document.getElementById('edit-rate').value = product.interest_rate;
    document.getElementById('edit-weeks').value = product.duration_weeks;
    document.getElementById('edit-min').value = product.min_amount;
    document.getElementById('edit-max').value = product.max_amount;
    document.getElementById('edit-desc').value = product.description;
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}
</script>
</head>

<body class="bg-gray-100 p-6 font-sans">

<div class="max-w-6xl mx-auto bg-white p-6 rounded-2xl shadow-lg">

    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-800">Loan Products</h1>
      <button onclick="document.getElementById('addForm').classList.toggle('hidden')" 
        class="bg-emerald-600 text-white px-4 py-2 rounded-lg shadow hover:bg-emerald-700">
        + Add Product
      </button>
    </div>

    <!-- Add Product Form -->
    <div id="addForm" class="hidden border border-gray-200 bg-gray-50 p-5 rounded-xl mb-6">
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-semibold text-gray-700">Product Name</label>
            <input type="text" name="product_name" required class="w-full px-3 py-2 border rounded-lg">
          </div>
          <div>
            <label class="text-sm font-semibold text-gray-700">Interest Rate (%)</label>
            <input type="number" step="0.01" name="interest_rate" required class="w-full px-3 py-2 border rounded-lg">
          </div>
          <div>
            <label class="text-sm font-semibold text-gray-700">Duration (Weeks)</label>
            <input type="number" name="duration_weeks" required class="w-full px-3 py-2 border rounded-lg">
          </div>
          <div>
            <label class="text-sm font-semibold text-gray-700">Min Amount</label>
            <input type="number" name="min_amount" required class="w-full px-3 py-2 border rounded-lg">
          </div>
          <div>
            <label class="text-sm font-semibold text-gray-700">Max Amount</label>
            <input type="number" name="max_amount" required class="w-full px-3 py-2 border rounded-lg">
          </div>
          <div class="md:col-span-2">
            <label class="text-sm font-semibold text-gray-700">Description</label>
            <textarea name="description" rows="3" class="w-full px-3 py-2 border rounded-lg"></textarea>
          </div>
        </div>
        <div class="mt-4 text-right">
          <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow hover:bg-blue-700">
            Save Product
          </button>
        </div>
      </form>
    </div>

    <!-- Product Table -->
    <div class="overflow-x-auto mt-6">
      <table class="min-w-full bg-white text-sm">
        <thead class="bg-gray-100 text-xs uppercase text-gray-600">
          <tr>
            <th class="px-4 py-3">#</th>
            <th class="px-4 py-3">Product</th>
            <th class="px-4 py-3">Interest</th>
            <th class="px-4 py-3">Duration</th>
            <th class="px-4 py-3">Loan Range</th>
            <th class="px-4 py-3">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php
            $res = mysqli_query($conn, "SELECT * FROM loan_products ORDER BY id DESC");
            $i = 1;
            while ($row = mysqli_fetch_assoc($res)) {
              $json = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
              echo "
              <tr class='hover:bg-gray-50'>
                <td class='px-4 py-3'>$i</td>
                <td class='px-4 py-3 font-semibold'>{$row['product_name']}</td>
                <td class='px-4 py-3'>{$row['interest_rate']}%</td>
                <td class='px-4 py-3'>{$row['duration_weeks']} weeks</td>
                <td class='px-4 py-3'>KES ".number_format($row['min_amount'])." - ".number_format($row['max_amount'])."</td>
                <td class='px-4 py-3 flex gap-3'>
                  <button onclick='openEditModal(JSON.parse(\"$json\"))' class='text-blue-600 hover:underline'>Edit</button>
                  <a href='?delete={$row['id']}' class='text-red-600 hover:underline' onclick=\"return confirm('Delete this product?')\">Delete</a>
                </td>
              </tr>
              ";
              $i++;
            }
          ?>
        </tbody>
      </table>
    </div>
</div>

<!-- EDIT MODAL -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4">
  <div class="bg-white w-full max-w-lg rounded-xl p-6 shadow-xl">
    <h2 class="text-xl font-bold mb-4">Edit Product</h2>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" id="edit-id" name="id">

      <label class="block mt-2 text-sm font-medium">Product Name</label>
      <input id="edit-product" name="product_name" class="w-full p-2 border rounded-lg">

      <label class="block mt-2 text-sm font-medium">Interest Rate</label>
      <input id="edit-rate" name="interest_rate" type="number" step="0.01" class="w-full p-2 border rounded-lg">

      <label class="block mt-2 text-sm font-medium">Duration Weeks</label>
      <input id="edit-weeks" name="duration_weeks" type="number" class="w-full p-2 border rounded-lg">

      <label class="block mt-2 text-sm font-medium">Min Amount</label>
      <input id="edit-min" name="min_amount" type="number" class="w-full p-2 border rounded-lg">

      <label class="block mt-2 text-sm font-medium">Max Amount</label>
      <input id="edit-max" name="max_amount" type="number" class="w-full p-2 border rounded-lg">

      <label class="block mt-2 text-sm font-medium">Description</label>
      <textarea id="edit-desc" name="description" class="w-full p-2 border rounded-lg"></textarea>

      <div class="flex justify-end gap-3 mt-4">
        <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded-lg">Cancel</button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg">Save Changes</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
