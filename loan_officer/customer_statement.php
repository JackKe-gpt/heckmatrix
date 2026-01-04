<?php
include '../includes/db.php';

$query = trim($_GET['query'] ?? '');

if (strlen($query) < 2) {
  echo json_encode([]);
  exit;
}

$sql = "
  SELECT id, national_id, CONCAT(first_name, ' ', middle_name, ' ', surname) AS full_name 
  FROM customers 
  WHERE national_id LIKE ? OR CONCAT(first_name, ' ', middle_name, ' ', surname) LIKE ?
  LIMIT 10
";

$stmt = $conn->prepare($sql);
$like = "%$query%";
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode($data);
