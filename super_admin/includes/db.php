<?php
$host = "localhost"; // or your MySQL host
$user = "root";
$pass = ""; // or your MySQL password
$dbname = "faida"; // change if your DB name is different

$conn = new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
