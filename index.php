<?php
session_start();
include 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin'] = [
            'id' => $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => $admin['role'],
            'branch_id' => $admin['branch_id'],
            'created_at' => $admin['created_at']
        ];

        $role_folder = strtolower($admin['role']);
        $dashboard_path = $role_folder . '/dashboard.php';

        if (file_exists($dashboard_path)) {
            header("Location: $dashboard_path");
        } else {
            header("Location: error.php?msg=No dashboard for role");
        }
        exit;
    } else {
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Login – HECK Matrix Solutions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#128d51',
            secondary: '#0e6c3d'
          },
          fontFamily: {
            sans: ['Inter', 'sans-serif']
          }
        }
      }
    }
  </script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">

  <div class="w-full max-w-6xl bg-white shadow-2xl rounded-2xl overflow-hidden flex flex-col md:flex-row">

    <!-- Left image -->
    <div class="hidden md:block md:w-1/2 bg-cover bg-center" 
         style="background-image: url('faida.jpg');">
      <div class="h-full w-full bg-black/30 flex items-center justify-center">
        <h1 class="text-white text-3xl font-bold drop-shadow-md">
          HECK Matrix Solutions
        </h1>
      </div>
    </div>

    <!-- Login Card -->
    <div class="w-full md:w-1/2 flex items-center justify-center p-6 md:p-10">
      <div class="w-full max-w-sm sm:max-w-md">

<!-- Logo -->
<div class="flex justify-center mb-6">
  <img src="logo.jpeg" 
       alt="HECK Matrix Solutions Logo" 
       class="w-28 h-28 object-cover rounded-full border-1 border-primary shadow-lg">
</div>


        <!-- Heading -->
        <div class="text-center mb-6">
          <h2 class="text-2xl font-bold text-gray-800">Welcome Back</h2>
          <p class="text-gray-500 text-sm">Sign in to continue to your dashboard</p>
        </div>

        <!-- Error Message -->
        <?php if (isset($error)): ?>
          <div class="bg-red-100 border border-red-300 text-red-600 text-sm px-4 py-2 rounded-lg mb-4 text-center">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input name="email" type="email" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input name="password" type="password" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary transition" />
          </div>

          <div class="flex items-center justify-between text-xs">
            <a href="forgot_password.php" class="text-primary hover:underline">Forgot password?</a>
          </div>

          <button type="submit"
                  class="w-full py-2.5 bg-primary text-white font-medium rounded-lg shadow hover:bg-secondary transition">
            Sign In
          </button>
        </form>

        <!-- Footer -->
        <p class="text-xs text-gray-400 text-center mt-6">
          &copy; <?= date('Y') ?> HECK Matrix Solutions. All rights reserved.
        </p>
      </div>
    </div>
  </div>

</body>
</html>
