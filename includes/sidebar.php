<?php
// Common navigation sidebar for department dashboards
$current_page = basename($_SERVER['PHP_SELF']);
$user_department = $_SESSION['department'] ?? 'Unknown';
?>

<div class="bg-white shadow-lg rounded-lg p-4 mb-6">
    <div class="flex items-center gap-2 mb-4">
        <i data-lucide="building" class="h-5 w-5 text-blue-600"></i>
        <h3 class="text-lg font-semibold"><?php echo htmlspecialchars($user_department); ?> Department</h3>
    </div>

    <nav class="space-y-2">
        <a href="#dashboard"
            class="nav-link flex items-center gap-2 px-3 py-2 rounded-md hover:bg-blue-50 <?php echo ($current_page == 'dashboard' || strpos($current_page, 'dashboard') !== false) ? 'bg-blue-100 text-blue-700' : 'text-gray-700'; ?>">
            <i data-lucide="bar-chart-3" class="h-4 w-4"></i>
            Inventory Dashboard
        </a>

        <a href="#communication"
            class="nav-link flex items-center gap-2 px-3 py-2 rounded-md hover:bg-blue-50 <?php echo strpos($current_page, 'communication') !== false ? 'bg-blue-100 text-blue-700' : 'text-gray-700'; ?>">
            <i data-lucide="message-square" class="h-4 w-4"></i>
            Communication
        </a>

        <a href="#forecasting"
            class="nav-link flex items-center gap-2 px-3 py-2 rounded-md hover:bg-blue-50 <?php echo strpos($current_page, 'forecast') !== false ? 'bg-blue-100 text-blue-700' : 'text-gray-700'; ?>">
            <i data-lucide="trending-up" class="h-4 w-4"></i>
            Forecasting
        </a>

        <a href="#borrowing"
            class="nav-link flex items-center gap-2 px-3 py-2 rounded-md hover:bg-blue-50 <?php echo strpos($current_page, 'borrow') !== false ? 'bg-blue-100 text-blue-700' : 'text-gray-700'; ?>">
            <i data-lucide="arrow-up-down" class="h-4 w-4"></i>
            Drug Borrowing
        </a>

        <a href="#checkout"
            class="nav-link flex items-center gap-2 px-3 py-2 rounded-md hover:bg-blue-50 <?php echo strpos($current_page, 'checkout') !== false ? 'bg-blue-100 text-blue-700' : 'text-gray-700'; ?>">
            <i data-lucide="shopping-cart" class="h-4 w-4"></i>
            Check Out Drugs
        </a>
    </nav>
</div>