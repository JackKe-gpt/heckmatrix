<?php
// search_customer.php
include '../includes/db.php';

$query = $_POST['query'];
$escaped = mysqli_real_escape_string($conn, $query);

$results = mysqli_query($conn, "
  SELECT id, national_id, CONCAT(first_name, ' ', middle_name, ' ', surname) AS full_name, status
  FROM customers 
  WHERE national_id LIKE '%$escaped%' 
     OR CONCAT(first_name, ' ', middle_name, ' ', surname) LIKE '%$escaped%'
  LIMIT 10
");

$data = [];
while ($row = mysqli_fetch_assoc($results)) {
  $data[] = [
    'national_id' => $row['national_id'],
    'full_name' => $row['full_name'],
    'status' => $row['status']
  ];
}
header('Content-Type: application/json');
echo json_encode($data);


// search_customer.php
include '../includes/db.php';

$query = $_POST['query'];
$escaped = mysqli_real_escape_string($conn, $query);

$results = mysqli_query($conn, "
  SELECT id, national_id, CONCAT(first_name, ' ', middle_name, ' ', surname) AS full_name, status
  FROM customers 
  WHERE national_id LIKE '%$escaped%' 
     OR CONCAT(first_name, ' ', middle_name, ' ', surname) LIKE '%$escaped%'
  LIMIT 10
");

$data = [];
while ($row = mysqli_fetch_assoc($results)) {
  $data[] = [
    'national_id' => $row['national_id'],
    'full_name' => $row['full_name'],
    'status' => $row['status']
  ];
}
header('Content-Type: application/json');
echo json_encode($data);
?>