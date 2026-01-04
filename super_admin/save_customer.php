<?php
include 'includes/db.php';

function sanitize($data) {
  return htmlspecialchars(stripslashes(trim($data)));
}

function get_post($key, $default = null) {
  return isset($_POST[$key]) ? sanitize($_POST[$key]) : $default;
}

// Required fields
$national_id   = get_post('national_id');
$phone_number  = get_post('phone_number');
$first_name    = get_post('first_name');
$middle_name   = get_post('middle_name');
$surname       = get_post('surname');
$group_id      = get_post('group_id'); // NEW: Group assignment

if (!$first_name || !$surname || !$national_id || !$phone_number) {
  echo "<script>
    alert('Please fill in all required fields.');
    window.history.back();
  </script>";
  exit;
}

// Check for duplicates
$check = $conn->prepare("SELECT id FROM customers WHERE national_id = ?");
$check->bind_param("s", $national_id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
  echo "<script>
    alert('Customer with this National ID already exists!');
    window.location.href = 'create_customer';
  </script>";
  exit;
}

// Generate customer code
$customer_code = strtoupper(substr(uniqid('FA'), -8));
$status = "pending";

// Prepare statement (added group_id)
$stmt = $conn->prepare("INSERT INTO customers (
  customer_code, first_name, middle_name, surname, gender, date_of_birth,
  national_id, kra_pin, marital_status, dependents,
  phone_number, mpesa_number, email,
  county, sub_county, ward, location, sub_location, village, address,
  next_of_kin_name, next_of_kin_relationship, next_of_kin_phone,
  guarantor_first_name, guarantor_middle_name, guarantor_surname,
  guarantor_id_number, guarantor_phone, guarantor_relationship,
  pre_qualified_amount, status, group_id
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

// Assigning all values
$gender           = get_post('gender');
$dob              = get_post('date_of_birth');
$kra_pin          = get_post('kra_pin');
$marital_status   = get_post('marital_status');
$dependents       = is_numeric($_POST['dependents']) ? intval($_POST['dependents']) : 0;
$mpesa_number     = get_post('mpesa_number');
$email            = filter_var(get_post('email'), FILTER_VALIDATE_EMAIL) ?: null;
$county           = get_post('county');
$sub_county       = get_post('sub_county');
$ward             = get_post('ward');
$location         = get_post('location');
$sub_location     = get_post('sub_location');
$village          = get_post('village');
$address          = get_post('address');

$nok_name         = get_post('next_of_kin_name');
$nok_relationship = get_post('next_of_kin_relationship');
$nok_phone        = get_post('next_of_kin_phone');

$g_fname          = get_post('guarantor_first_name');
$g_mname          = get_post('guarantor_middle_name');
$g_sname          = get_post('guarantor_surname');
$g_id_number      = get_post('guarantor_id_number');
$g_phone          = get_post('guarantor_phone');
$g_relation       = get_post('guarantor_relationship');

$pre_qualified    = is_numeric($_POST['pre_qualified_amount']) ? floatval($_POST['pre_qualified_amount']) : 0.00;

// Bind values (added group_id at the end, "i" for integer)
$stmt->bind_param("sssssssissssssssssssssssssssdsi",
  $customer_code,
  $first_name, $middle_name, $surname,
  $gender, $dob,
  $national_id, $kra_pin, $marital_status, $dependents,
  $phone_number, $mpesa_number, $email,
  $county, $sub_county, $ward, $location,
  $sub_location, $village, $address,
  $nok_name, $nok_relationship, $nok_phone,
  $g_fname, $g_mname, $g_sname,
  $g_id_number, $g_phone, $g_relation,
  $pre_qualified,
  $status,
  $group_id
);

// Execute and respond
if ($stmt->execute()) {
  echo "<script>
    alert('Customer registered successfully!');
    window.location.href = 'dashboard'; // Or change to confirmation page
  </script>";
} else {
  echo "<script>
    alert('Error saving customer. Please try again.');
    window.history.back();
  </script>";
}

$stmt->close();
$conn->close();
?>
