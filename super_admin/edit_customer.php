<?php
require_once 'auth.php';
require_login();

include 'includes/db.php';

$id = $_GET['id'];
$query = mysqli_query($conn, "SELECT * FROM customers WHERE id = '$id'");
$customer = mysqli_fetch_assoc($query);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $first_name = $_POST['first_name'];
  $middle_name = $_POST['middle_name'];
  $surname = $_POST['surname'];
  $phone = $_POST['phone_number'];
  $status = $_POST['status'];

  mysqli_query($conn, "UPDATE customers SET first_name='$first_name', middle_name='$middle_name', surname='$surname', phone_number='$phone', status='$status' WHERE id='$id'");
  header("Location: view_customer.php?id=$id");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Customer - Faida SACCO</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">

<div class="max-w-xl mx-auto mt-10 bg-white p-6 shadow rounded">
  <h2 class="text-xl font-bold mb-4">Edit Customer</h2>
  <form method="POST">
    <div class="grid gap-4">
      <input type="text" name="first_name" value="<?= $customer['first_name'] ?>" placeholder="First Name" class="border p-2 rounded" required>
      <input type="text" name="middle_name" value="<?= $customer['middle_name'] ?>" placeholder="Middle Name" class="border p-2 rounded">
      <input type="text" name="surname" value="<?= $customer['surname'] ?>" placeholder="Surname" class="border p-2 rounded" required>
      <input type="text" name="phone_number" value="<?= $customer['phone_number'] ?>" placeholder="Phone Number" class="border p-2 rounded" required>
      <select name="status" class="border p-2 rounded">
        <option value="Active" <?= $customer['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Inactive" <?= $customer['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        <option value="Blacklisted" <?= $customer['status'] === 'Blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
      </select>
    </div>
    <div class="mt-6 flex justify-end gap-3">
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Save Changes</button>
      <a href="view_customer.php?id=<?= $id ?>" class="text-gray-600 hover:underline">Cancel</a>
    </div>
  </form>
</div>

</body>
</html>
