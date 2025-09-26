<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
// Time
date_default_timezone_set('Africa/Kampala');

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private");
header("Pragma: no-cache");
header("Expires: -1");
header("X-Accel-Expires: 0");

// Start session and generate CSRF token
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['department'])) {
    error_log("Session check failed: user_id or department not set. Session: " . print_r($_SESSION, true));
    header("Location: ../index.php");
    exit;
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] == 1) {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Include database connection
require_once '../connect.php';

// Get logged-in user's details
$user_id = $_SESSION['user_id'];
$user_department = mysqli_real_escape_string($conn, $_SESSION['department']);

// Fetch name from users table
$user_query = "SELECT name FROM users WHERE id = ? AND department = ?";
$user_stmt = mysqli_prepare($conn, $user_query);
if ($user_stmt === false) {
    error_log("Prepare failed: " . mysqli_error($conn));
    $name = 'Prepare Error';
} else {
    mysqli_stmt_bind_param($user_stmt, "is", $user_id, $user_department);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    if ($user_result === false) {
        error_log("Query failed: " . mysqli_error($conn));
        $name = 'Query Error';
    } else {
        $user = mysqli_fetch_assoc($user_result);
        $name = $user ? $user['name'] : 'Unknown User';
        error_log("User query result: id=$user_id, department=$user_department, found=" . ($user ? 'yes' : 'no') . ", name=$name");
    }
    mysqli_stmt_close($user_stmt);
}
echo "<!-- Debug: id=$user_id, department=$user_department, name=$name -->";

// Fetch total drugs count across all departments
$result = mysqli_query($conn, "SELECT COUNT(*) AS total_drugs FROM drugs");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_drugs = $row['total_drugs'];
} else {
    $total_drugs = 'Error';
    error_log("Database Error (total drugs): " . mysqli_error($conn));
}

// Fetch critical stock across all departments
$result_critical = mysqli_query($conn, "SELECT COUNT(*) AS critical_stock FROM drugs WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
if ($result_critical) {
    $row_critical = mysqli_fetch_assoc($result_critical);
    $critical_stock = $row_critical['critical_stock'];
} else {
    $critical_stock = 'Error';
    error_log("Database Error (critical stock): " . mysqli_error($conn));
}

// Fetch drugs with available stock for Drug Borrowing and Check Out
$query = "SELECT drug_name, current_stock FROM drugs WHERE department = ? AND current_stock > 0";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $user_department);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$drugs = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $drugs[] = $row;
    }
}
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, private">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="-1">
    <title>Holistic Drug Distribution System - <?php echo htmlspecialchars($user_department); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100">
    <div class="container mx-auto p-6">
        <!-- Header -->
        <header class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-900 mb-2">Holistic Drug Distribution System</h1>
                <p class="text-lg text-gray-600"><?php echo htmlspecialchars($user_department); ?> Dashboard</p>
            </div>
            <div>
                <a href="?logout=1" onclick="return confirm('Are you sure you want to log out?');" class="flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                    <i data-lucide="log-out" class="h-4 w-4"></i>
                    Logout
                </a>
            </div>
        </header>

        <!-- Quick Stats Overview -->
        <section class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Total Drugs</h3>
                    <i data-lucide="package" class="h-4 w-4 text-blue-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-blue-600"><?php echo htmlspecialchars($total_drugs); ?></div>
                    <p class="text-xs text-gray-500">Across all departments</p>
                </div>
            </div>
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Critical Stock</h3>
                    <i data-lucide="alert-triangle" class="h-4 w-4 text-red-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-red-600"><?php echo htmlspecialchars($critical_stock); ?></div>
                    <p class="text-xs text-gray-500">Require immediate attention</p>
                </div>
            </div>
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg" id="pending-orders-card">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Pending Orders</h3>
                    <i data-lucide="trending-up" class="h-4 w-4 text-orange-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-orange-600" id="pending-orders-count">0</div>
                    <p class="text-xs text-gray-500">Awaiting fulfillment</p>
                </div>
            </div>
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Departments</h3>
                    <i data-lucide="users" class="h-4 w-4 text-green-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-green-600">6</div>
                    <p class="text-xs text-gray-500">Connected units</p>
                </div>
            </div>
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Efficiency</h3>
                    <i data-lucide="activity" class="h-4 w-4 text-purple-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-purple-600">77%</div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                        <div class="bg-purple-600 h-2.5 rounded-full" style="width: 77%"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Recent Alerts -->
        <section class="bg-white shadow-lg rounded-lg mb-8">
            <div class="p-4">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <i data-lucide="alert-triangle" class="h-5 w-5 text-orange-600"></i>
                    Recent Alerts
                </h2>
            </div>
            <div class="p-4 pt-0 space-y-3" id="alerts-container">
                <!-- Alerts will be dynamically loaded -->
            </div>
        </section>

        <!-- Main Navigation Tabs -->
        <div class="space-y-5">
            <div class="grid grid-cols-5 gap-1 bg-white shadow-lg rounded-lg p-1">
                <button class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md bg-gray-100 text-gray-900" data-tab="dashboard">
                    <i data-lucide="bar-chart-3" class="h-4 w-4"></i>
                    Inventory
                </button>
                <button class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md" data-tab="communication">
                    <i data-lucide="message-square" class="h-4 w-4"></i>
                    Communication
                </button>
                <button class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md" data-tab="forecasting">
                    <i data-lucide="trending-up" class="h-4 w-4"></i>
                    Forecasting
                </button>
                <button class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md" data-tab="borrowing">
                    <i data-lucide="arrow-up-down" class="h-4 w-4"></i>
                    Drug Borrowing
                </button>
                <button class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md" data-tab="checkout">
                    <i data-lucide="shopping-cart" class="h-4 w-4"></i>
                    Check Out Drugs
                </button>
            </div>

            <!-- Tab Content: Inventory Dashboard -->
            <section id="dashboard" class="tab-content">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Inventory Dashboard</h2>
                    <div class="space-y-4 mt-4 max-h-80 overflow-y-auto" id="inventory-content">
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <div class="flex items-center gap-2 mb-2">
                                <i data-lucide="package" class="h-5 w-5 text-blue-600"></i>
                                <h2 class="text-xl font-bold">Department Inventory</h2>
                            </div>
                            <p class="text-sm text-gray-600">View drug inventory for your department: <?php echo htmlspecialchars($user_department); ?></p>
                            <div class="flex gap-4 mt-6 mb-4">
                                <div class="relative flex-1">
                                    <i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-gray-400"></i>
                                    <input type="text" id="search-input" placeholder="Search drugs or categories..." class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <button onclick="searchDrugs()" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Search</button>
                            </div>
                        </div>
                        <div id="inventory-cards" class="space-y-4">
                            <!-- Inventory cards will be loaded dynamically -->
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tab Content: Communication -->
            <section id="communication" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Department Communication</h2>
                    <div class="space-y-6 max-w-7xl mx-auto">
                        <!-- Send Message Form -->
                        <div class="bg-white p-6 rounded-lg shadow">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-blue-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path d="M22 2L11 13" />
                                    <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                                </svg>
                                Send Message
                            </h2>
                            <p class="text-sm text-gray-500">Communicate with other departments about drug needs</p>
                            <div class="space-y-4 mt-4">
                                <div>
                                    <label class="text-sm font-medium mb-2 block">To Department</label>
                                    <select id="department-select" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select department</option>
                                        <!-- Departments will be loaded dynamically -->
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-medium mb-2 block">Message</label>
                                    <textarea id="message-input" rows="4" class="w-full border rounded px-3 py-2" placeholder="Type your message here..." required></textarea>
                                </div>
                                <div class="flex gap-2">
                                    <button onclick="sendMessage()" class="bg-blue-600 text-white px-4 py-2 rounded flex items-center gap-2 flex-1">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M22 2L11 13" />
                                            <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                                        </svg>
                                        Send Message
                                    </button>
                                    <button id="mark-urgent" class="border px-4 py-2 rounded">Mark as Urgent</button>
                                </div>
                            </div>
                        </div>
                        <!-- Recent Communications -->
                        <div class="bg-white p-6 rounded-lg shadow">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-purple-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                                </svg>
                                Recent Communications
                            </h2>
                            <p class="text-sm text-gray-500">Latest messages between departments</p>
                            <div class="space-y-4 mt-4" id="communications-list"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tab Content: Forecasting -->
            <section id="forecasting" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Demand Forecasting</h2>
                    <p class="text-gray-600">Placeholder for Demand Forecasting content</p>
                </div>
            </section>

            <!-- Tab Content: Drug Borrowing -->
            <section id="borrowing" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Drug Borrowing</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Drug Transfer Form -->
                        <div class="bg-white border rounded-xl p-6 shadow">
                            <h2 class="text-lg font-bold text-blue-600 mb-4">↕ Request Drug Transfer</h2>
                            <form id="drug-form" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div>
                                    <label class="text-sm font-medium block mb-1">Drug</label>
                                    <select name="drug" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select drug</option>
                                        <?php
                                        $query = "SELECT drug_name, current_stock FROM drugs WHERE current_stock > 0";
                                        $result = mysqli_query($conn, $query);
                                        if ($result && mysqli_num_rows($result) > 0) {
                                            while ($row = mysqli_fetch_assoc($result)) {
                                                echo '<option value="' . htmlspecialchars($row['drug_name']) . '">' . htmlspecialchars($row['drug_name']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-medium block mb-1">Quantity</label>
                                    <input type="number" name="quantity" class="w-full border rounded px-3 py-2" required min="1" />
                                </div>
                                <div>
                                    <label class="text-sm font-medium block mb-1">From Department</label>
                                    <select name="from_department" id="from-department-select" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select department</option>
                                        <!-- Departments will be loaded dynamically -->
                                    </select>
                                </div>
                                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded w-full hover:bg-blue-700">Submit</button>
                            </form>
                        </div>
                        <!-- Available Supplies -->
                        <div class="bg-white border rounded-xl p-6 shadow max-h-80 overflow-y-auto">
                            <h2 class="text-lg font-bold text-green-600 mb-4">📦 Available Supplies</h2>
                            <div class="space-y-3" id="available-supplies">
                                <!-- Supplies will be loaded dynamically -->
                            </div>
                        </div>
                        <!-- Borrowing Requests -->
                        <div class="mt-10 bg-white border rounded-xl p-6 shadow">
                            <h2 class="text-lg font-bold text-purple-700 mb-4">📄 Borrowing Requests</h2>
                            <div id="requests-container" class="space-y-4"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tab Content: Check Out Drugs -->
            <section id="checkout" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Check Out Drugs</h2>
                    <div class="space-y-6 max-w-7xl mx-auto">
                        <div class="bg-white p-6 rounded-lg shadow">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-blue-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path d="M5 13l4 4L19 7" />
                                </svg>
                                Check Out Drug
                            </h2>
                            <p class="text-sm text-gray-500">Remove drugs from your department's inventory</p>
                            <div class="space-y-4 mt-4">
                                <form id="checkout-form" class="space-y-4">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <div>
                                        <label class="text-sm font-medium mb-2 block">Drug</label>
                                        <select name="drug" id="checkout-drug" class="w-full border rounded px-3 py-2" required>
                                            <option value="">Select drug</option>
                                            <?php foreach ($drugs as $drug): ?>
                                                <option value="<?php echo htmlspecialchars($drug['drug_name']); ?>" data-stock="<?php echo htmlspecialchars($drug['current_stock']); ?>">
                                                    <?php echo htmlspecialchars($drug['drug_name']); ?> (Stock: <?php echo htmlspecialchars($drug['current_stock']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium mb-2 block">Quantity</label>
                                        <input type="number" name="quantity" id="checkout-quantity" class="w-full border rounded px-3 py-2" required min="1" oninput="validateQuantity()">
                                    </div>
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded flex items-center gap-2">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M5 13l4 4L19 7" />
                                        </svg>
                                        Check Out
                                    </button>
                                </form>
                            </div>
                        </div>
                        <!-- Recent Checkouts Log -->
                        <div class="bg-white p-6 rounded-lg shadow">
                            <h2 class="flex items-center gap-2 text-lg font-semibold text-purple-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 8v4l3 3" />
                                </svg>
                                Recent Checkouts
                            </h2>
                            <p class="text-sm text-gray-500">Last recent checkouts from your department</p>
                            <div class="space-y-4 mt-4 max-h-60 overflow-y-auto" id="checkout-log">
                                <!-- Checkouts will be loaded dynamically -->
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // WebSocket connection
        const ws = new WebSocket('ws://localhost:4000');
        ws.onopen = () => {
            console.log('WebSocket connected');
            ws.send(JSON.stringify({
                type: 'subscribe',
                department: '<?php echo $user_department; ?>'
            }));
        };
        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.type === 'communications') {
                updateCommunications(data.messages);
            } else if (data.type === 'pending_orders') {
                $('#pending-orders-count').text(data.count);
            } else if (data.type === 'alerts') {
                updateAlerts(data.alerts);
            }
        };
        ws.onclose = () => {
            console.log('WebSocket disconnected');
        };
        ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        // Utility function to capitalize first letter
        function capitalizeFirstLetter(string) {
            return string.charAt(0).toUpperCase() + string.slice(1).toLowerCase();
        }

        // Search Drugs
        function searchDrugs() {
            const query = $('#search-input').val().trim().toLowerCase();
            $.ajax({
                url: 'search_drugs.php',
                type: 'GET',
                data: {
                    query: query,
                    department: '<?php echo $user_department; ?>'
                },
                dataType: 'json',
                success: function(data) {
                    const container = $('#inventory-cards');
                    container.empty();
                    if (data.length > 0) {
                        data.forEach(drug => {
                            const status = drug.current_stock < drug.min_stock ? 'LOW' : 'NORMAL';
                            const statusColor = drug.current_stock < drug.min_stock ? 'bg-red-100 text-red-800 border-red-200' : 'bg-green-100 text-green-800 border-green-200';
                            const trendIcon = drug.current_stock < drug.min_stock ? 'trending-down' : 'trending-up';
                            const trendColor = drug.current_stock < drug.min_stock ? 'text-red-600' : 'text-green-600';
                            const stockLevel = drug.max_stock > 0 ? Math.round((drug.current_stock / drug.max_stock) * 100) : 0;
                            const card = `
                                <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h3 class="font-semibold text-lg">${drug.drug_name}</h3>
                                            <p class="text-sm text-gray-600">${drug.category} • ${drug.department}</p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="${statusColor} text-xs px-2 py-1 rounded">${status}</span>
                                            <i data-lucide="${trendIcon}" class="h-4 w-4 ${trendColor}"></i>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                        <div>
                                            <p class="text-sm text-gray-600">Current Stock</p>
                                            <p class="text-2xl font-bold">${drug.current_stock}</p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600">Stock Range</p>
                                            <p class="text-sm">${drug.min_stock} - ${drug.max_stock} units</p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600">Expiry Date</p>
                                            <p class="text-sm">${drug.expiry_date}</p>
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="flex justify-between text-sm">
                                            <span>Stock Level</span>
                                            <span>${stockLevel}%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                                            <div class="bg-green-500 h-2" style="width: ${stockLevel}%;"></div>
                                        </div>
                                    </div>
                                </div>
                            `;
                            container.append(card);
                        });
                        lucide.createIcons();
                    } else {
                        container.html('<p class="text-gray-600">No drugs found.</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Search error:', status, error);
                    $('#inventory-cards').html('<p class="text-red-600">Error loading drugs.</p>');
                }
            });
        }

        // Fetch Departments
        function fetchDepartments() {
            $.ajax({
                url: 'fetch_departments.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    const select = $('#department-select, #from-department-select');
                    select.each(function() {
                        $(this).empty().append('<option value="">Select department</option>');
                        data.forEach(dept => {
                            if (dept !== '<?php echo $user_department; ?>') {
                                $(this).append(`<option value="${dept}">${dept}</option>`);
                            }
                        });
                    });
                },
                error: function(xhr, status, error) {
                    console.error('Fetch departments error:', status, error);
                    $('#department-select, #from-department-select').html('<option value="">Error loading departments</option>');
                }
            });
        }

        // Fetch Available Supplies
        function fetchSupplies() {
            $.ajax({
                url: 'fetch_supplies.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    const container = $('#available-supplies');
                    container.empty();
                    if (data.length > 0) {
                        data.forEach(drug => {
                            container.append(`
                                <div class="border rounded p-4 shadow-sm">
                                    <div class="flex justify-between items-center">
                                        <h3 class="font-semibold text-gray-800">${drug.drug_name}</h3>
                                        <h3 class="font-semibold text-gray-800">${drug.department}</h3>
                                        <p class="text-sm text-gray-600">${drug.current_stock} units</p>
                                    </div>
                                    <p class="text-sm text-gray-600">Expiry: ${drug.expiry_date}</p>
                                    <p class="text-sm text-gray-600">Stock Range: ${drug.min_stock} - ${drug.max_stock} units</p>
                                </div>
                            `);
                        });
                    } else {
                        container.html('<p class="text-gray-600">No drugs with available stock.</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Fetch supplies error:', status, error);
                    container.html('<p class="text-red-600">Error loading supplies.</p>');
                }
            });
        }

        // Send Message
        function sendMessage() {
            const dept = $('#department-select').val();
            const msg = $('#message-input').val().trim();
            const isUrgent = $('#mark-urgent').hasClass('bg-blue-600');
            if (!dept || !msg) {
                alert('Please select a department and type a message.');
                return;
            }

            $.ajax({
                url: 'send_message.php',
                type: 'POST',
                data: {
                    to_department: dept,
                    message: msg,
                    priority: isUrgent ? 'high' : 'low',
                    csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert(response.success);
                        $('#department-select').val('');
                        $('#message-input').val('');
                        $('#mark-urgent').removeClass('bg-blue-600 text-white').addClass('border').text('Mark as Urgent');
                        ws.send(JSON.stringify({
                            type: 'new_message',
                            department: '<?php echo $user_department; ?>'
                        }));
                    } else {
                        alert(response.error || 'Failed to send message.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Send message error:', status, error, xhr.responseText);
                    alert('Failed to send message: ' + (xhr.responseJSON?.error || 'Server error'));
                }
            });
        }

        // Update Communications
        function updateCommunications(messages) {
            const communicationsList = $('#communications-list');
            communicationsList.empty();
            if (messages && Array.isArray(messages)) {
                messages.forEach(msg => {
                    const isSentByUser = msg.from_department === '<?php echo $user_department; ?>';
                    const timeDiff = (new Date() - new Date(msg.timestamp)) / 1000;
                    const canCancel = isSentByUser && timeDiff < 300;
                    const priorityClass = msg.priority === 'high' ? 'bg-red-100 text-red-800' : (msg.priority === 'medium' ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800');
                    const statusText = isSentByUser ? (msg.is_read ? 'Read' : 'Sent') : (msg.is_read ? 'Read' : 'Received');
                    const statusClass = msg.is_read ? 'bg-green-100 text-green-800' : (isSentByUser ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800');
                    const div = $(`
                        <div class="p-4 border rounded-lg bg-white shadow hover:shadow-md transition-shadow">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <p class="font-medium text-sm">
                                        From: <span class="text-blue-600">${msg.from_department}</span> →
                                        To: <span class="text-green-600">${msg.to_department}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 flex items-center gap-1 mt-1">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 8v4l3 3" />
                                        </svg>
                                        ${new Date(msg.timestamp).toLocaleString()}
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <span class="px-2 py-1 text-xs rounded ${priorityClass}">
                                        ${msg.priority ? capitalizeFirstLetter(msg.priority) : 'Normal'}
                                    </span>
                                    <span class="px-2 py-1 text-xs rounded ${statusClass}">
                                        ${statusText}
                                    </span>
                                </div>
                            </div>
                            <p class="text-sm text-gray-700 bg-gray-50 p-3 rounded-lg">
                                ${msg.message}
                            </p>
                            <div class="flex gap-2 mt-2">
                                <span class="${msg.is_read ? 'text-green-600' : 'text-gray-600'} text-sm">(${msg.is_read ? 'Read' : 'Unread'})</span>
                                ${isSentByUser ? (canCancel ? `<button onclick="cancelMessage('${msg.id}')" class="text-red-600 text-sm hover:underline">Cancel</button>` : '') : 
                                    (!msg.is_read ? `<button onclick="markAsRead('${msg.id}')" class="text-blue-600 text-sm hover:underline">Mark as Read</button>` : '')}
                            </div>
                        </div>
                    `);
                    communicationsList.append(div);
                });
            } else {
                communicationsList.html('<p class="text-red-600">No communications available</p>');
            }
        }

        // Update Alerts
        function updateAlerts(alerts) {
            const container = $('#alerts-container');
            container.empty();
            if (alerts && Array.isArray(alerts)) {
                alerts.forEach(alert => {
                    const statusColor = alert.current_stock <= 5 ? 'border-red-500 bg-red-50' :
                        alert.current_stock <= 12 ? 'border-orange-500 bg-orange-50' :
                        'border-blue-500 bg-blue-50';
                    const statusLabel = alert.current_stock <= 5 ? 'CRITICAL' :
                        alert.current_stock <= 12 ? 'WARNING' : 'INFO';
                    const statusClass = alert.current_stock <= 5 ? 'bg-red-100 text-red-800' :
                        alert.current_stock <= 12 ? 'bg-orange-100 text-orange-800' :
                        'bg-blue-100 text-blue-800';
                    container.append(`
                        <div class="border-l-4 ${statusColor} p-4 rounded-r-lg">
                            <div class="flex justify-between items-center">
                                <span><strong>${alert.drug_name}</strong> in ${alert.department} - Stock: ${alert.current_stock} units</span>
                                <span class="${statusClass} text-xs font-medium px-2.5 py-0.5 rounded">${statusLabel}</span>
                            </div>
                        </div>
                    `);
                });
            } else {
                container.html('<p class="text-gray-600">No alerts available.</p>');
            }
        }

        // Mark as Read
        function markAsRead(messageId) {
            $.ajax({
                url: 'mark_as_read.php',
                type: 'POST',
                data: {
                    message_id: messageId,
                    csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        ws.send(JSON.stringify({
                            type: 'new_message',
                            department: '<?php echo $user_department; ?>'
                        }));
                    } else {
                        alert(response.error || 'Failed to mark as read');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Mark as read error:', status, error, xhr.responseText);
                    alert('Failed to mark as read: ' + (xhr.responseJSON?.error || 'Server error'));
                }
            });
        }

        // Cancel Message
        function cancelMessage(messageId) {
            if (!confirm('Are you sure you want to cancel this message?')) return;
            $.ajax({
                url: 'cancel_message.php',
                type: 'POST',
                data: {
                    message_id: messageId,
                    csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        ws.send(JSON.stringify({
                            type: 'new_message',
                            department: '<?php echo $user_department; ?>'
                        }));
                    } else {
                        alert(response.error || 'Failed to cancel message');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Cancel message error:', status, error, xhr.responseText);
                    alert('Failed to cancel message: ' + (xhr.responseJSON?.error || 'Server error'));
                }
            });
        }

        // Check Out Drugs
        function validateQuantity() {
            const select = $('#checkout-drug');
            const quantityInput = $('#checkout-quantity');
            const selectedOption = select[0].options[select[0].selectedIndex];
            const maxStock = parseInt(selectedOption ? selectedOption.getAttribute('data-stock') : 0);
            const quantity = parseInt(quantityInput.val()) || 0;

            if (quantity > maxStock) {
                quantityInput.val(maxStock);
                alert(`Quantity cannot exceed available stock of ${maxStock}.`);
            }
        }

        $('#checkout-form').on('submit', function(e) {
            e.preventDefault();
            const drug = $('#checkout-drug').val();
            const quantity = parseInt($('#checkout-quantity').val());
            const maxStock = parseInt($('#checkout-drug option:selected').attr('data-stock'));

            if (!drug) {
                alert('Please select a drug.');
                return;
            }
            if (quantity <= 0 || quantity > maxStock) {
                alert(`Please enter a quantity between 1 and ${maxStock}.`);
                return;
            }

            $.ajax({
                url: 'checkout_drug.php',
                type: 'POST',
                data: $(this).serialize() + '&quantity=' + quantity,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert(response.success);
                        $('#checkout-form')[0].reset();
                        loadCheckouts();
                        ws.send(JSON.stringify({
                            type: 'new_checkout',
                            department: '<?php echo $user_department; ?>'
                        }));
                    } else {
                        alert(response.error || 'Failed to check out drug.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Checkout error:', status, error, xhr.responseText);
                    alert('Failed to check out drug: ' + (xhr.responseJSON?.error || 'Server error'));
                }
            });
        });

        // Load Checkouts
        function loadCheckouts() {
            $.ajax({
                url: 'fetch_checkouts.php',
                type: 'GET',
                data: {
                    department: '<?php echo $user_department; ?>'
                },
                dataType: 'json',
                success: function(data) {
                    const container = $('#checkout-log');
                    container.empty();
                    if (data.length > 0) {
                        data.forEach(checkout => {
                            container.append(`
                                <div class="p-4 border rounded-lg bg-white shadow hover:shadow-md transition-shadow">
                                    <div class="flex justify-between items-start mb-2">
                                        <p class="text-sm text-gray-700">
                                            <strong>Drug:</strong> ${checkout.drug_name}
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            <strong>Qty:</strong> ${checkout.quantity}
                                        </p>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <p class="text-xs text-gray-500">
                                            <strong>User:</strong> ${checkout.name}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <strong>Time:</strong> ${checkout.checkout_time}
                                        </p>
                                    </div>
                                </div>
                            `);
                        });
                    } else {
                        container.html('<p class="text-gray-600">No recent checkouts.</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Load checkouts error:', status, error);
                    $('#checkout-log').html('<p class="text-red-600">Error loading checkouts.</p>');
                }
            });
        }

        // Document Ready
        $(document).ready(function() {
            // Tab functionality
            $('.tab-button').on('click', function() {
                $('.tab-button').removeClass('bg-gray-100 text-gray-900');
                $('.tab-content').addClass('hidden');
                $(this).addClass('bg-gray-100 text-gray-900');
                const tabId = $(this).data('tab');
                $('#' + tabId).removeClass('hidden');
                if (tabId === 'dashboard') {
                    searchDrugs();
                } else if (tabId === 'borrowing') {
                    fetchSupplies();
                    loadRequests();
                } else if (tabId === 'checkout') {
                    loadCheckouts();
                }
            });

            // Communication Tab Setup
            $('#mark-urgent').on('click', function() {
                $(this).toggleClass('bg-blue-600 text-white border');
                $(this).text($(this).hasClass('bg-blue-600') ? 'Urgent (On)' : 'Mark as Urgent');
            });

            // Search on input
            $('#search-input').on('input', function() {
                if ($(this).val().length >= 2) {
                    searchDrugs();
                } else {
                    searchDrugs();
                }
            });

            // Initial loads
            searchDrugs();
            fetchDepartments();
            fetchSupplies();
            loadCheckouts();
            loadRequests();

            // Drug Borrowing AJAX
            $('#drug-form').on('submit', function(e) {
                e.preventDefault();
                const drug = $('select[name="drug"]').val();
                const quantity = parseInt($('input[name="quantity"]').val());
                const fromDepartment = $('select[name="from_department"]').val();

                if (!drug || !fromDepartment) {
                    alert('Please select a drug and department.');
                    return;
                }
                if (quantity <= 0) {
                    alert('Quantity must be greater than 0.');
                    return;
                }

                const submitButton = $(this).find('button[type="submit"]');
                submitButton.prop('disabled', true).text('Submitting...');

                $.ajax({
                    url: 'submit_request.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.success);
                            $('#drug-form')[0].reset();
                            loadRequests();
                            ws.send(JSON.stringify({
                                type: 'new_request',
                                department: fromDepartment
                            }));
                        } else {
                            alert(response.error || 'Failed to submit request.');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Submit request error:', status, error, xhr.responseText);
                        alert('Failed to submit request: ' + (xhr.responseJSON?.error || 'Server error'));
                    },
                    complete: function() {
                        submitButton.prop('disabled', false).text('Submit');
                    }
                });
            });

            window.approveRequest = function(requestId) {
                const csrfToken = $('input[name="csrf_token"]').val();
                if (!csrfToken) {
                    alert('CSRF token missing. Please refresh the page.');
                    return;
                }
                $.ajax({
                    url: 'approve_request.php',
                    type: 'POST',
                    data: {
                        request_id: requestId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.success);
                            loadRequests();
                            ws.send(JSON.stringify({
                                type: 'request_update',
                                department: '<?php echo $user_department; ?>'
                            }));
                        } else {
                            alert(response.error || 'Failed to approve request.');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Approve request error:', status, error, xhr.responseText);
                        alert('Failed to approve request: ' + (xhr.responseJSON?.error || 'Server error'));
                    }
                });
            };

            window.rejectRequest = function(requestId) {
                const csrfToken = $('input[name="csrf_token"]').val();
                if (!csrfToken) {
                    alert('CSRF token missing. Please refresh the page.');
                    return;
                }
                if (!confirm('Are you sure you want to reject this request?')) {
                    return;
                }
                $.ajax({
                    url: 'reject_request.php',
                    type: 'POST',
                    data: {
                        request_id: requestId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.success);
                            loadRequests();
                            ws.send(JSON.stringify({
                                type: 'request_update',
                                department: '<?php echo $user_department; ?>'
                            }));
                        } else {
                            alert(response.error || 'Failed to reject request.');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Reject request error:', status, error, xhr.responseText);
                        alert('Failed to reject request: ' + (xhr.responseJSON?.error || 'Server error'));
                    }
                });
            };

            window.cancelRequest = function(requestId) {
                const csrfToken = $('input[name="csrf_token"]').val();
                if (!csrfToken) {
                    alert('CSRF token missing. Please refresh the page.');
                    return;
                }
                if (!confirm('Are you sure you want to cancel this request?')) {
                    return;
                }
                $.ajax({
                    url: 'cancel_request.php',
                    type: 'POST',
                    data: {
                        request_id: requestId,
                        csrf_token: csrfToken
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.success);
                            loadRequests();
                            ws.send(JSON.stringify({
                                type: 'request_update',
                                department: '<?php echo $user_department; ?>'
                            }));
                        } else {
                            alert(response.error || 'Failed to cancel request.');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Cancel request error:', status, error, xhr.responseText);
                        alert('Failed to cancel request: ' + (xhr.responseJSON?.error || 'Server error'));
                    }
                });
            };

            function loadRequests() {
                $('#requests-container').html('<p class="text-gray-600">Loading...</p>');
                $.ajax({
                    url: 'fetch_requests.php',
                    type: 'GET',
                    cache: false,
                    success: function(data) {
                        $('#requests-container').html(data);
                    },
                    error: function(xhr, status, error) {
                        console.error('Fetch requests error:', status, error, xhr.responseText);
                        $('#requests-container').html('<p class="text-red-600">Error loading requests.</p>');
                    }
                });
            }
        });
    </script>
</body>

</html>