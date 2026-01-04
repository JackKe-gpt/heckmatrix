<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Faida Business Solutions</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#128d51',
            sidebar: '#f9fafb',
            textDark: '#1f2937',
            hoverBg: '#e6f5ef',
            dropdownBg: '#f1f5f9'
          }
        }
      }
    };
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-white text-textDark font-sans">

  <!-- Header -->
  <header class="fixed top-0 left-0 right-0 z-50 bg-white border-b border-gray-200 h-14 flex items-center justify-between px-4 shadow-sm">
    <div class="flex items-center gap-4">
      <button id="sidebarToggle" class="text-primary text-2xl">
        <i class="fas fa-bars"></i>
      </button>

      <!-- Logo Added -->
      <div class="flex items-center gap-2">
        <img src="../logo.jpeg" alt="Faida Logo" class="h-8 w-auto"> <!-- Replace with actual logo path -->
        <a href="dashboard" class="text-lg font-bold text-primary">HeckMatrix</a>
      </div>
    </div>

    <div class="flex items-center gap-4">
      <input type="text" placeholder="Search..." class="hidden md:block px-3 py-1 border border-gray-300 rounded text-sm w-64 focus:outline-none focus:ring focus:ring-primary" />
      <div class="flex items-center gap-1 text-sm">
        <i class="fas fa-wifi text-green-500"></i> <span id="networkStatus">Online</span>
      </div>
    </div>
  </header>

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed top-14 left-0 w-64 h-[calc(100%-3.5rem)] bg-sidebar border-r border-gray-200 transform -translate-x-full transition-transform z-40 shadow-lg overflow-y-auto">
    <nav class="p-4 space-y-2 text-sm">

      <a href="dashboard" class="flex items-center gap-3 px-4 py-2 rounded hover:bg-hoverBg font-medium transition">
        <i class="fas fa-tachometer-alt text-primary w-5"></i> Dashboard
      </a>

      <!-- Dropdowns -->
      <div>
        <button class="w-full flex items-center justify-between px-4 py-2 rounded hover:bg-hoverBg font-medium transition dropdown-toggle">
          <span class="flex items-center gap-3"><i class="fas fa-user-friends text-primary w-5"></i> Customers</span>
          <i class="fas fa-chevron-down transition-transform duration-200"></i>
        </button>
        <div class="dropdown-menu ml-8 mt-1 hidden flex-col border-l border-primary pl-3 space-y-1">
          <a href="customers" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">All Customers</a>
          <a href="create_customer" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Create Customer</a>
                <a href="inactive" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Inactive Customers</a>
                <a href="summary" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Customer Statement</a>
        </div>
      </div>

      <!-- Loans -->
      <div>
        <button class="w-full flex items-center justify-between px-4 py-2 rounded hover:bg-hoverBg font-medium transition dropdown-toggle">
          <span class="flex items-center gap-3"><i class="fas fa-hand-holding-usd text-primary w-5"></i> Loans</span>
          <i class="fas fa-chevron-down transition-transform duration-200"></i>
        </button>
        <div class="dropdown-menu ml-8 mt-1 hidden flex-col border-l border-primary pl-3 space-y-1">
          <a href="create_loan" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Create Loan</a>
          <a href="loans" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Loans</a>
          <a href="products" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Loan Products</a>
          <a href="approve_loan" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Loan Approval</a>
        </div>
      </div>

      <!-- Disbursement -->
      <div>
        <button class="w-full flex items-center justify-between px-4 py-2 rounded hover:bg-hoverBg font-medium transition dropdown-toggle">
          <span class="flex items-center gap-3"><i class="fas fa-paper-plane text-primary w-5"></i> Disbursement</span>
          <i class="fas fa-chevron-down transition-transform duration-200"></i>
        </button>
        <div class="dropdown-menu ml-8 mt-1 hidden flex-col border-l border-primary pl-3 space-y-1">
          <a href="disburse" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Disbursement</a>
          <a href="pending" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Pending Disbursement</a>
        </div>
      </div>

      <!-- Payments -->
      <a href="payments" class="flex items-center gap-3 px-4 py-2 rounded hover:bg-hoverBg font-medium transition">
        <i class="fas fa-credit-card text-primary w-5"></i> Payments
      </a>

      <!-- Accounting -->
      <div>
        <button class="w-full flex items-center justify-between px-4 py-2 rounded hover:bg-hoverBg font-medium transition dropdown-toggle">
          <span class="flex items-center gap-3"><i class="fas fa-wallet text-primary w-5"></i> Accounting</span>
          <i class="fas fa-chevron-down transition-transform duration-200"></i>
        </button>
        <div class="dropdown-menu ml-8 mt-1 hidden flex-col border-l border-primary pl-3 space-y-1">
          <a href="user_account" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">User Account</a>
        </div>
      </div>

      <!-- Reports -->
      <div>
        <button class="w-full flex items-center justify-between px-4 py-2 rounded hover:bg-hoverBg font-medium transition dropdown-toggle">
          <span class="flex items-center gap-3"><i class="fas fa-chart-bar text-primary w-5"></i> Reports</span>
          <i class="fas fa-chevron-down transition-transform duration-200"></i>
        </button>
        <div class="dropdown-menu ml-8 mt-1 hidden flex-col border-l border-primary pl-3 space-y-1">
          <a href="dues" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Dues</a>
          <a href="arrears" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Arrears</a>
          <a href="income" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Income</a>
          <a href="perfomance" class="block px-2 py-1 rounded hover:bg-primary hover:text-white">Overall perfomance</a>
        </div>
      </div>

      <!-- Settings -->
      <a href="settings" class="flex items-center gap-3 px-4 py-2 rounded hover:bg-hoverBg font-medium transition mt-4">
        <i class="fas fa-cogs text-primary w-5"></i> Settings
      </a>

      <hr class="my-2">

      <!-- Logout -->
      <a href="logout" class="flex items-center gap-3 px-4 py-2 rounded hover:bg-hoverBg font-medium transition">
        <i class="fas fa-sign-out-alt text-primary w-5"></i> Logout
      </a>
      
    </nav>
  </aside>

  <!-- Overlay -->
  <div id="overlay" class="fixed inset-0 bg-black bg-opacity-40 hidden z-30"></div>

  <!-- Padding for page content -->
  <div class="pt-14 mb-4"></div>

  <!-- JS Logic -->
  <script>
    const sidebar = document.getElementById("sidebar");
    const toggle = document.getElementById("sidebarToggle");
    const overlay = document.getElementById("overlay");

    function openSidebar() {
      sidebar.classList.remove("-translate-x-full");
      overlay.classList.remove("hidden");
    }

    function closeSidebar() {
      sidebar.classList.add("-translate-x-full");
      overlay.classList.add("hidden");
    }

    toggle.addEventListener("click", () => {
      sidebar.classList.contains("-translate-x-full") ? openSidebar() : closeSidebar();
    });

    overlay.addEventListener("click", closeSidebar);

    // Dropdown logic
    document.querySelectorAll('.dropdown-toggle').forEach(button => {
      button.addEventListener('click', () => {
        const menu = button.nextElementSibling;
        const icon = button.querySelector('i.fa-chevron-down');
        menu.classList.toggle('hidden');
        icon.classList.toggle('rotate-180');
      });
    });

    // Connection Status
    function updateConnectionStatus() {
      const status = navigator.onLine ? 'Online' : 'Offline';
      document.getElementById('networkStatus').textContent = status;
      document.getElementById('networkStatus').classList.toggle('text-red-500', !navigator.onLine);
      document.getElementById('networkStatus').classList.toggle('text-green-500', navigator.onLine);
    }
    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);
    updateConnectionStatus();
  </script>
</body>
</html>
