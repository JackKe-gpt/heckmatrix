<?php
session_start();
require_once 'includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

$action = $_POST['action'] ?? '';
if (!$action) exit('No action specified');

switch ($action) {

  // Remove member
  case 'remove':
    $cid = intval($_POST['customer_id'] ?? 0);
    if ($cid > 0) {
      if ($conn->query("UPDATE customers SET group_id=NULL WHERE id=$cid")) exit('Removed');
      else exit('DB Error: ' . $conn->error);
    }
    exit('Missing customer_id');

  // Add member
  case 'add':
    $group_id = intval($_POST['group_id'] ?? 0);
    $customer_id = intval($_POST['customer_id'] ?? 0);
    if ($group_id && $customer_id) {
      if ($conn->query("UPDATE customers SET group_id=$group_id WHERE id=$customer_id")) exit('Added');
      else exit('DB Error: ' . $conn->error);
    }
    exit('Invalid group or customer');

  default:
    exit('Invalid Action');
}
?>
