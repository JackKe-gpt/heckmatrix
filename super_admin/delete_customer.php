<?php
include 'includes/db.php';

$id = intval($_GET['id'] ?? 0);

// Delete the customer and all related records (via cascading)
mysqli_query($conn, "DELETE FROM customers WHERE id = $id");

header("Location: customers"); // Redirect to customer list
exit;
