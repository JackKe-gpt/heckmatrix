<?php
// get_groups.php
header("Content-Type: application/json");

// DB connection
$host = "localhost";
$user = "root";
$pass = "";
$db   = "faida";   // change if your DB name is different

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// Fetch groups
$sql = "SELECT id, group_name FROM groups ORDER BY group_name ASC";
$result = $conn->query($sql);

$groups = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
}

echo json_encode($groups);

$conn->close();
