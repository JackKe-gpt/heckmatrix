<?php
// create_group.php
// Independent page: connects DB, fetches data, inserts group

// ---------------- DB CONNECTION ----------------
$host = "localhost";
$user = "root";
$pass = "";
$db   = "faida";  // change to your db name

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

// ---------------- HANDLE FORM SUBMIT ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_name      = trim($_POST['group_name']);
    $group_code      = trim($_POST['group_code']);
    $branch_id       = intval($_POST['branch_id']);
    $loan_officer_id = !empty($_POST['loan_officer_id']) ? intval($_POST['loan_officer_id']) : NULL;

    if (empty($group_code)) {
        $group_code = "GRP-" . time(); // auto-generate if blank
    }

    $stmt = $conn->prepare("INSERT INTO groups (branch_id, loan_officer_id, group_code, group_name) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $branch_id, $loan_officer_id, $group_code, $group_name);

    if ($stmt->execute()) {
        $success = "Group <b>$group_name</b> created successfully!";
    } else {
        $error = "Error: " . $stmt->error;
    }
    $stmt->close();
}

// ---------------- FETCH BRANCHES ----------------
$branches = [];
$res = $conn->query("SELECT id, name FROM branches ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $branches[] = $row;
    }
}

// ---------------- FETCH LOAN OFFICERS ----------------
$loan_officers = [];
$res = $conn->query("SELECT id, name FROM admin_users WHERE role = 'loan_officer' ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $loan_officers[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create Group</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 font-sans">
  <div class="min-h-screen flex items-center justify-center p-6">
    <div class="bg-white shadow-xl rounded-2xl p-8 w-full max-w-2xl">

      <!-- Title -->
      <div class="text-center mb-6">
        <h1 class="text-3xl font-bold text-green-700">Create Group</h1>
        <p class="text-gray-500">Register a new borrowing group</p>
      </div>

      <!-- Alerts -->
      <?php if (!empty($success)): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg"><?php echo $success; ?></div>
      <?php elseif (!empty($error)): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg"><?php echo $error; ?></div>
      <?php endif; ?>

      <!-- Form -->
      <form method="POST" class="space-y-6">

        <!-- Group Name -->
        <div>
          <label class="block text-sm font-semibold text-gray-600">Group Name *</label>
          <input type="text" name="group_name" required
            class="w-full mt-1 p-3 border rounded-xl focus:ring-2 focus:ring-green-500 focus:outline-none">
        </div>

        <!-- Group Code -->
        <div>
          <label class="block text-sm font-semibold text-gray-600">Group Code</label>
          <input type="text" name="group_code" placeholder="Leave blank for auto"
            class="w-full mt-1 p-3 border rounded-xl focus:ring-2 focus:ring-green-500 focus:outline-none">
        </div>

        <!-- Branch -->
        <div>
          <label class="block text-sm font-semibold text-gray-600">Branch *</label>
          <select name="branch_id" required
            class="w-full mt-1 p-3 border rounded-xl focus:ring-2 focus:ring-green-500 focus:outline-none">
            <option value="">-- Select Branch --</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Loan Officer -->
        <div>
          <label class="block text-sm font-semibold text-gray-600">Loan Officer</label>
          <select name="loan_officer_id"
            class="w-full mt-1 p-3 border rounded-xl focus:ring-2 focus:ring-green-500 focus:outline-none">
            <option value="">-- Optional --</option>
            <?php foreach ($loan_officers as $o): ?>
  <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
<?php endforeach; ?>

          </select>
        </div>

        <!-- Submit -->
        <div class="text-center">
          <button type="submit"
            class="w-full bg-green-600 text-white py-3 rounded-xl text-lg font-semibold shadow-md hover:bg-green-700 transition">
            Create Group
          </button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
