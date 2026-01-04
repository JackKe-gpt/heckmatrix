<?php
// auth.php — secure reusable session checker

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Check if admin is logged in
function require_login() {
  if (
    empty($_SESSION['admin']) ||
    !is_array($_SESSION['admin']) ||
    !isset($_SESSION['admin']['email']) ||
    !filter_var($_SESSION['admin']['email'], FILTER_VALIDATE_EMAIL)
  ) {
    header("Location: index");
    exit;
  }
}

// Optionally: check for specific roles
function require_role($role) {
  require_login();
  if ($_SESSION['admin']['role'] !== $role) {
    echo "<div style='padding:20px; color:red;'>Access Denied: Requires $role role.</div>";
    exit;
  }
}
