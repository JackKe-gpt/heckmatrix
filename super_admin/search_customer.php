<?php
include 'includes/db.php';

$query = '';
if (isset($_POST['query'])) {
  $query = mysqli_real_escape_string($conn, $_POST['query']);
} elseif (isset($_GET['query'])) {
  $query = mysqli_real_escape_string($conn, $_GET['query']);
}

$results = [];
if ($query !== '') {
  $res = mysqli_query($conn, "
    SELECT national_id, CONCAT(first_name, ' ', middle_name, ' ', surname) AS full_name 
    FROM customers 
    WHERE national_id LIKE '%$query%' 
       OR CONCAT(first_name, ' ', middle_name, ' ', surname) LIKE '%$query%'
    LIMIT 10
  ");
  while ($row = mysqli_fetch_assoc($res)) {
    $results[] = [
      'national_id' => $row['national_id'],
      'full_name'   => $row['full_name']
    ];
  }
}

header('Content-Type: application/json');
echo json_encode($results);
