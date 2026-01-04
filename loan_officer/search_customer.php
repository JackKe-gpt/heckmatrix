<?php
session_start();
include '../includes/db.php';

// Check branch manager
if (!isset($_SESSION['admin']) || $_SESSION['admin']['role'] != 'loan_officer') {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$branch_id = $_SESSION['admin']['branch_id'];
$q = $_GET['q'] ?? '';
$q = mysqli_real_escape_string($conn, $q);

if (!$q) {
    echo json_encode([]);
    exit;
}

// Fetch customers in this branch matching name or code
$sql = "SELECT id, customer_code AS code, CONCAT(first_name,' ',middle_name,' ',surname) AS name 
        FROM customers 
        WHERE branch_id='$branch_id' 
          AND (first_name LIKE '%$q%' OR middle_name LIKE '%$q%' OR surname LIKE '%$q%' OR customer_code LIKE '%$q%')
        LIMIT 10";

$result = mysqli_query($conn, $sql);
$customers = [];
while ($row = mysqli_fetch_assoc($result)) {
    $customers[] = $row;
}

header('Content-Type: application/json');
echo json_encode($customers);
