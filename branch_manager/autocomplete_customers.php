<?php
include '../includes/db.php';

$term = $_GET['term'] ?? '';
$branch_id = (int)($_GET['branch_id'] ?? 0);

$results = [];
if ($term && $branch_id) {
    $stmt = $conn->prepare("
        SELECT id, CONCAT(first_name, ' ', surname, ' (', customer_code, ') - ID:', national_id) AS label
        FROM customers
        WHERE branch_id=? AND (first_name LIKE ? OR surname LIKE ? OR customer_code LIKE ? OR national_id LIKE ?)
        LIMIT 10
    ");
    $like = "%$term%";
    $stmt->bind_param("issss", $branch_id, $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = ["value" => $row['id'], "label" => $row['label']];
    }
}

echo json_encode($results);
