<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
//time
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

// Fetch name from users table with enhanced debugging
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
    echo "<p class='text-red-600 text-sm'>Database Error: " . mysqli_error($conn) . "</p>";
}

// Fetch critical stock across all departments (expiring within 30 days)
$result_critical = mysqli_query($conn, "SELECT COUNT(*) AS critical_stock FROM drugs WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
if ($result_critical) {
    $row_critical = mysqli_fetch_assoc($result_critical);
    $critical_stock = $row_critical['critical_stock'];
} else {
    $critical_stock = 'Error';
    error_log("Database Error (critical stock): " . mysqli_error($conn));
    echo "<p class='text-red-600 text-sm'>Database Error: " . mysqli_error($conn) . "</p>";
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
} else {
    $drugs = [];
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
    <title>Holistic Drug Distribution System - Paediatrics</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100">
    <div class="container mx-auto p-6">
        <!-- Header -->
        <header class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-900 mb-2">Holistic Drug Distribution System</h1>
                <p class="text-lg text-gray-600">Paediatrics Department Dashboard</p>
            </div>
            <div>
                <a href="?logout=1" onclick="return confirm('Are you sure you want to log out?');"
                    class="flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
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
            <div class="p-4 pt-0 space-y-3">
                <div class="border-l-4 border-red-500 bg-gray-50 p-4 rounded-r-lg">
                    <div class="flex justify-between items-center">
                        <span><strong>Paracetamol 500mg</strong> in Emergency - Stock: 5 units</span>
                        <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded">CRITICAL</span>
                    </div>
                </div>
                <div class="border-l-4 border-orange-500 bg-gray-50 p-4 rounded-r-lg">
                    <div class="flex justify-between items-center">
                        <span><strong>Amoxicillin</strong> in Pediatrics - Stock: 12 units</span>
                        <span
                            class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-0.5 rounded">WARNING</span>
                    </div>
                </div>
                <div class="border-l-4 border-blue-500 bg-gray-50 p-4 rounded-r-lg">
                    <div class="flex justify-between items-center">
                        <span><strong>Insulin</strong> in Endocrinology - Stock: 25 units</span>
                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">INFO</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Main Navigation Tabs -->
        <div class="space-y-5">
            <div class="grid grid-cols-5 gap-1 bg-white shadow-lg rounded-lg p-1">
                <button
                    class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md bg-gray-100 text-gray-900"
                    data-tab="dashboard">
                    <i data-lucide="bar-chart-3" class="h-4 w-4"></i>
                    Inventory
                </button>
                <button
                    class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md"
                    data-tab="communication">
                    <i data-lucide="message-square" class="h-4 w-4"></i>
                    Communication
                </button>
                <button
                    class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md"
                    data-tab="forecasting">
                    <i data-lucide="trending-up" class="h-4 w-4"></i>
                    Forecasting
                </button>
                <button
                    class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md"
                    data-tab="borrowing">
                    <i data-lucide="arrow-up-down" class="h-4 w-4"></i>
                    Drug Borrowing
                </button>
                <button
                    class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md"
                    data-tab="checkout">
                    <i data-lucide="shopping-cart" class="h-4 w-4"></i>
                    Check Out Drugs
                </button>
            </div>

            <!-- Tab Content: Inventory Dashboard -->
            <section id="dashboard" class="tab-content">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Inventory Dashboard</h2>
                    <div class="space-y-4 mt-4 max-h-120 overflow-y-auto" id="checkout-log">
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <div class="flex items-center gap-2 mb-2">
                                <i data-lucide="package" class="h-5 w-5 text-blue-600"></i>
                                <h2 class="text-xl font-bold">Department Inventory</h2>
                            </div>
                            <p class="text-sm text-gray-600">View drug inventory for your department:
                                <?php echo htmlspecialchars($user_department); ?></p>
                            <div class="flex gap-4 mt-6 mb-4">
                                <div class="relative flex-1">
                                    <i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-gray-400"></i>
                                    <input type="text" placeholder="Search drugs or categories..."
                                        class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <button
                                    class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Search</button>
                            </div>
                        </div>

                        <div id="inventory-cards" class="space-y-4">
                            <?php
                            $sql = "SELECT * FROM drugs WHERE department = '$user_department'";
                            $result = $conn->query($sql);
                            if (!$result) {
                                error_log("Fetch Drugs Error: " . mysqli_error($conn));
                                echo "<p class='text-red-600 text-sm'>Error fetching drugs: " . mysqli_error($conn) . "</p>";
                            } elseif ($result->num_rows === 0) {
                                echo "<p class='text-gray-600'>No drugs found for your department.</p>";
                            } else {
                                while ($row = $result->fetch_assoc()) {
                                    $status = $row['current_stock'] < $row['min_stock'] ? 'LOW' : 'NORMAL';
                                    $status_color = $row['current_stock'] < $row['min_stock'] ? 'bg-red-100 text-red-800 border-red-200' : 'bg-green-100 text-green-800 border-green-200';
                                    $trend_icon = $row['current_stock'] < $row['min_stock'] ? 'trending-down' : 'trending-up';
                                    $trend_color = $row['current_stock'] < $row['min_stock'] ? 'text-red-600' : 'text-green-600';
                                    // Calculate stock level if not set or invalid
                                    $stock_level = ($row['stock_level'] > 0 && $row['stock_level'] <= 100) ? $row['stock_level'] : ($row['max_stock'] > 0 ? round(($row['current_stock'] / $row['max_stock']) * 100) : 0);
                            ?>
                            <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h3 class="font-semibold text-lg">
                                            <?php echo htmlspecialchars($row['drug_name']); ?></h3>
                                        <p class="text-sm text-gray-600">
                                            <?php echo htmlspecialchars($row['category'] . ' • ' . $row['department']); ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="<?php echo $status_color; ?> text-xs px-2 py-1 rounded"><?php echo $status; ?></span>
                                        <i data-lucide="<?php echo $trend_icon; ?>"
                                            class="h-4 w-4 <?php echo $trend_color; ?>"></i>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                    <div>
                                        <p class="text-sm text-gray-600">Current Stock</p>
                                        <p class="text-2xl font-bold"><?php echo $row['current_stock']; ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Stock Range</p>
                                        <p class="text-sm">
                                            <?php echo $row['min_stock'] . ' - ' . $row['max_stock'] . ' units'; ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Expiry Date</p>
                                        <p class="text-sm"><?php echo $row['expiry_date']; ?></p>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span>Stock Level</span>
                                        <span><?php echo $stock_level; ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                                        <div class="bg-green-500 h-2" style="width: <?php echo $stock_level; ?>%;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
                                }
                            }
                            ?>
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
                                        <?php
                                        $valid_departments = ['Internal Medicine', 'Surgery', 'Paediatrics', 'Obstetrics', 'Gynaecology', 'Admin'];
                                        foreach ($valid_departments as $dept) {
                                            if ($dept !== $user_department) {
                                                echo '<option value="' . htmlspecialchars($dept) . '">' . htmlspecialchars($dept) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-medium mb-2 block">Message</label>
                                    <textarea id="message-input" rows="4" class="w-full border rounded px-3 py-2"
                                        placeholder="Type your message here..." required></textarea>
                                </div>
                                <div class="flex gap-2">
                                    <button onclick="sendMessage()"
                                        class="bg-blue-600 text-white px-4 py-2 rounded flex items-center gap-2 flex-1">
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
                    <div id="forecast-results" class="mt-4 p-4 bg-gray-50 border rounded">
                        <p class="text-gray-600">Loading forecast...</p>
                    </div>
                </div>
            </section>

            <!-- <script>
$(document).ready(function() {
    function loadForecast() {
        $.ajax({
            url: "http://127.0.0.1:5000/forecast", // Flask API
            method: "GET",
            success: function(data) {
                let html = "<h3 class='text-lg font-bold mb-2'>Drug Demand Forecast</h3>";
                html += "<table class='w-full text-sm border'>";
                html += "<tr class='bg-blue-100'><th class='border p-2'>Drug</th><th class='border p-2'>Predicted Demand</th></tr>";

                if (Array.isArray(data)) {
                    data.forEach(item => {
                        html += `<tr>
                                    <td class='border p-2'>${item.drug}</td>
                                    <td class='border p-2'>${item.forecast}</td>
                                </tr>`;
                    });
                } else {
                    html += `<tr><td colspan='2' class='border p-2 text-center'>No forecast available</td></tr>`;
                }

                html += "</table>";
                $("#forecast-results").html(html);
            },
            error: function(xhr, status, error) {
                console.error("Forecast load error:", error);
                $("#forecast-results").html("<p class='text-red-600'>Error loading forecast data.</p>");
            }
        });
    }

    // Load forecast automatically
    loadForecast();
});
</script> -->
            <script>
            function loadForecast() {
                const payload = {
                    drug_id: 1,
                    drug_name: "Paracetamol",
                    department: "Paediatrics"
                };

                $.ajax({
                    url: "http://127.0.0.1:5000/forecast",
                    method: "POST",
                    contentType: "application/json",
                    data: JSON.stringify(payload),
                    success: function(data) {
                        let html = "<h3 class='text-lg font-bold mb-2'>Drug Demand Forecast</h3>";
                        html += "<table class='w-full text-sm border'>";
                        html +=
                            "<tr class='bg-blue-100'><th class='border p-2'>Drug</th><th class='border p-2'>Predicted Demand</th></tr>";

                        if (data.forecasts && Array.isArray(data.forecasts)) {
                            data.forecasts.forEach(item => {
                                html += `<tr>
                         <td class='border p-2'>${item.DrugName}</td>
                            <td class='border p-2'>${item.PredictedDemand}</td>
                        </tr>`;
                            });
                        } else {
                            html +=
                                `<tr><td colspan='2' class='border p-2 text-center'>No forecast available</td></tr>`;
                        }

                        html += "</table>";
                        $("#forecast-results").html(html);
                    },
                    error: function(xhr, status, error) {
                        console.error("Forecast load error:", error);
                        $("#forecast-results").html(
                            "<p class='text-red-600'>Error loading forecast data.</p>");
                    }
                });
            }

            $(document).ready(function() {
                // Load forecast when page loads
                loadForecast();
            });
            </script>


            <!-- Tab Content: Drug Borrowing -->
            <section id="borrowing" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Drug Borrowing</h2>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Drug Transfer Form -->
                        <div class="bg-white border rounded-xl p-6 shadow">
                            <h2 class="text-lg font-bold text-blue-600 mb-4">↕ Request Drug Transfer</h2>
                            <form id="drug-form" class="space-y-4">
                                <input type="hidden" name="csrf_token"
                                    value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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
                                    <input type="number" name="quantity" class="w-full border rounded px-3 py-2"
                                        required min="1" />
                                </div>
                                <div>
                                    <label class="text-sm font-medium block mb-1">From Department</label>
                                    <select name="from_department" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select department</option>
                                        <?php
                                        $dept_query = "SELECT DISTINCT department FROM drugs WHERE current_stock > 0";
                                        $dept_result = mysqli_query($conn, $dept_query);
                                        if ($dept_result && mysqli_num_rows($dept_result) > 0) {
                                            while ($dept = mysqli_fetch_assoc($dept_result)) {
                                                if (in_array($dept['department'], ['Internal Medicine', 'Surgery', 'Paediatrics', 'Obstetrics', 'Gynaecology', 'Admin'])) {
                                                    echo '<option>' . htmlspecialchars($dept['department']) . '</option>';
                                                }
                                            }
                                        } else {
                                            echo '<option value="">No departments with stock available</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button type="submit"
                                    class="bg-blue-600 text-white py-2 px-4 rounded w-full hover:bg-blue-700">Submit</button>
                            </form>
                        </div>
                        <!-- Available Supplies -->
                        <div class="bg-white border rounded-xl p-6 shadow max-h-80 overflow-y-auto">
                            <h2 class="text-lg font-bold text-green-600 mb-4">📦 Available Supplies</h2>
                            <div class="space-y-3">
                                <?php
                                $query = "SELECT drug_name, department, current_stock, expiry_date, min_stock, max_stock FROM drugs WHERE current_stock > 0";
                                $result = mysqli_query($conn, $query);
                                if ($result && mysqli_num_rows($result) > 0) {
                                    while ($row = mysqli_fetch_assoc($result)) {
                                ?>
                                <div class="border rounded p-4 shadow-sm">
                                    <div class="flex justify-between items-center">
                                        <h3 class="font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($row['drug_name']); ?></h3>
                                        <h3 class="font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($row['department']); ?></h3>
                                        <p class="text-sm text-gray-600"><?php echo intval($row['current_stock']); ?>
                                            units</p>
                                    </div>
                                    <p class="text-sm text-gray-600">Expiry:
                                        <?php echo htmlspecialchars($row['expiry_date']); ?></p>
                                    <p class="text-sm text-gray-600">Stock Range:
                                        <?php echo htmlspecialchars($row['min_stock'] . ' - ' . $row['max_stock']); ?>
                                        units</p>
                                </div>
                                <?php
                                    }
                                } else {
                                    echo '<p class="text-gray-600">No drugs with available stock.</p>';
                                }
                                ?>
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
                                    <input type="hidden" name="csrf_token"
                                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <div>
                                        <label class="text-sm font-medium mb-2 block">Drug</label>
                                        <select name="drug" id="checkout-drug" class="w-full border rounded px-3 py-2"
                                            required>
                                            <option value="">Select drug</option>
                                            <?php foreach ($drugs as $drug): ?>
                                            <option value="<?php echo htmlspecialchars($drug['drug_name']); ?>"
                                                data-stock="<?php echo htmlspecialchars($drug['current_stock']); ?>">
                                                <?php echo htmlspecialchars($drug['drug_name']); ?> (Stock:
                                                <?php echo htmlspecialchars($drug['current_stock']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium mb-2 block">Quantity</label>
                                        <input type="number" name="quantity" id="checkout-quantity"
                                            class="w-full border rounded px-3 py-2" required min="1"
                                            oninput="validateQuantity()">
                                    </div>
                                    <button type="submit"
                                        class="bg-blue-600 text-white px-4 py-2 rounded flex items-center gap-2">
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
                                <?php
                                // Verify connection
                                if (!$conn) {
                                    error_log("Database connection failed: " . mysqli_connect_error());
                                    echo "<p class='text-red-600 text-sm'>Database connection error.</p>";
                                } else {
                                    $checkout_query = "SELECT drug_name, quantity, name, checkout_time FROM drug_checkouts WHERE department = ? ORDER BY checkout_time DESC LIMIT 100";
                                    $checkout_stmt = mysqli_prepare($conn, $checkout_query);
                                    if ($checkout_stmt === false) {
                                        error_log("Prepare failed for checkout query: " . mysqli_error($conn));
                                        echo "<p class='text-red-600 text-sm'>Query preparation error: " . mysqli_error($conn) . "</p>";
                                    } else {
                                        mysqli_stmt_bind_param($checkout_stmt, "s", $user_department);
                                        mysqli_stmt_execute($checkout_stmt);
                                        $result = mysqli_stmt_get_result($checkout_stmt);
                                        $checkouts = [];
                                        if ($result && mysqli_num_rows($result) > 0) {
                                            while ($row = mysqli_fetch_assoc($result)) {
                                                $checkouts[] = $row;
                                            }
                                        }
                                        mysqli_stmt_close($checkout_stmt);

                                        foreach ($checkouts as $checkout): ?>
                                <div class="p-4 border rounded-lg bg-white shadow hover:shadow-md transition-shadow">
                                    <div class="flex justify-between items-start mb-2">
                                        <p class="text-sm text-gray-700">
                                            <strong>Drug:</strong>
                                            <?php echo htmlspecialchars($checkout['drug_name']); ?>
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            <strong>Qty:</strong> <?php echo htmlspecialchars($checkout['quantity']); ?>
                                        </p>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <p class="text-xs text-gray-500">
                                            <strong>User:</strong> <?php echo htmlspecialchars($checkout['name']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <strong>Time:</strong>
                                            <?php echo htmlspecialchars($checkout['checkout_time']); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php endforeach;
                                        if (empty($checkouts)): ?>
                                <p class="text-gray-600">No recent checkouts.</p>
                                <?php endif;
                                    }
                                }
                                ?>
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

    // Utility function to capitalize first letter
    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1).toLowerCase();
    }

    // Send Message
    function sendMessage() {
        const dept = document.getElementById("department-select").value;
        const msg = document.getElementById("message-input").value.trim();
        const isUrgent = document.getElementById("mark-urgent").classList.contains("bg-blue-600");
        if (!dept || !msg) {
            alert("Please select a department and type a message.");
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
                    document.getElementById("department-select").value = "";
                    document.getElementById("message-input").value = "";
                    document.getElementById("mark-urgent").classList.remove("bg-blue-600", "text-white");
                    document.getElementById("mark-urgent").classList.add("border");
                    document.getElementById("mark-urgent").textContent = "Mark as Urgent";
                    loadCommunications();
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

    // Load Communications
    function loadCommunications() {
        $.ajax({
            url: 'get_recent_communications.php',
            type: 'GET',
            cache: false,
            success: function(data) {
                console.log('Communications loaded:', data);
                const communicationsList = $('#communications-list');
                communicationsList.html('');
                if (data && Array.isArray(data)) {
                    data.forEach(msg => {
                        const isSentByUser = msg.from_department ===
                            '<?php echo $user_department; ?>';
                        const timeDiff = (new Date() - new Date(msg.timestamp)) / 1000;
                        const canCancel = isSentByUser && timeDiff < 300; // 5-minute window
                        const priorityClass = msg.priority === 'high' ? 'bg-red-100 text-red-800' :
                            (msg.priority === 'medium' ? 'bg-orange-100 text-orange-800' :
                                'bg-green-100 text-green-800');
                        const statusText = isSentByUser ? (msg.is_read ? 'Read' : 'Sent') : (msg
                            .is_read ? 'Read' : 'Received');
                        const statusClass = msg.is_read ? 'bg-green-100 text-green-800' : (
                            isSentByUser ? 'bg-blue-100 text-blue-800' :
                            'bg-yellow-100 text-yellow-800');
                        const div = $(
                            '<div class="p-4 border rounded-lg bg-white shadow hover:shadow-md transition-shadow">'
                        );
                        div.html(`
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
                            `);
                        communicationsList.append(div);
                    });
                } else {
                    communicationsList.html('<p class="text-red-600">No communications available</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Communications load error:', status, error, xhr.responseText);
                $('#communications-list').html('<p class="text-red-600">Error loading communications</p>');
            }
        });
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
                    loadCommunications();
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
                    loadCommunications();
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
        const select = document.getElementById('checkout-drug');
        const quantityInput = document.getElementById('checkout-quantity');
        const selectedOption = select.options[select.selectedIndex];
        const maxStock = parseInt(selectedOption ? selectedOption.getAttribute('data-stock') : 0);
        const quantity = parseInt(quantityInput.value) || 0;

        if (quantity > maxStock) {
            quantityInput.value = maxStock;
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
                    // Force reload of checkout log
                    $.get(document.URL, function(data) {
                        const newLog = $(data).find('#checkout-log').html();
                        $('#checkout-log').html(newLog);
                    }).fail(function(xhr, status, error) {
                        console.error('Log reload failed:', status, error, xhr
                            .responseText);
                        alert('Failed to update checkout log.');
                    });
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

    // Document Ready
    $(document).ready(function() {
        // Tab functionality
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                tabButtons.forEach(btn => btn.classList.remove('bg-gray-100', 'text-gray-900'));
                tabContents.forEach(content => content.classList.add('hidden'));
                button.classList.add('bg-gray-100', 'text-gray-900');
                const tabId = button.getAttribute('data-tab');
                document.getElementById(tabId).classList.remove('hidden');
            });
        });

        // Communication Tab Setup
        $('#mark-urgent').on('click', function() {
            $(this).toggleClass('bg-blue-600 text-white border');
            $(this).text($(this).hasClass('bg-blue-600') ? 'Urgent (On)' : 'Mark as Urgent');
        });

        // Auto-update communications
        loadCommunications();
        setInterval(loadCommunications, 5000);

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
                    console.log('Submit response:', response);
                    if (response.success) {
                        alert(response.success);
                        $('#drug-form')[0].reset();
                        loadRequests();
                    } else {
                        alert(response.error || 'Failed to submit request.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Submit request error:', status, error, xhr.responseText);
                    alert('Failed to submit request: ' + (xhr.responseJSON?.error ||
                        'Server error'));
                },
                complete: function() {
                    submitButton.prop('disabled', false).text('Submit');
                }
            });
        });

        window.approveRequest = function(requestId) {
            console.log('Approving request ID:', requestId);
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
                    console.log('Approve response:', response);
                    if (response.success) {
                        alert(response.success);
                        loadRequests();
                    } else {
                        alert(response.error || 'Failed to approve request.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Approve request error:', status, error, xhr.responseText);
                    alert('Failed to approve request: ' + (xhr.responseJSON?.error ||
                        'Server error'));
                }
            });
        };

        window.rejectRequest = function(requestId) {
            console.log('Rejecting request ID:', requestId);
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
                    console.log('Reject response:', response);
                    if (response.success) {
                        alert(response.success);
                        loadRequests();
                    } else {
                        alert(response.error || 'Failed to reject request.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Reject request error:', status, error, xhr.responseText);
                    alert('Failed to reject request: ' + (xhr.responseJSON?.error ||
                        'Server error'));
                }
            });
        };

        window.cancelRequest = function(requestId) {
            console.log('Canceling request ID:', requestId);
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
                    console.log('Cancel response:', response);
                    if (response.success) {
                        alert(response.success);
                        loadRequests();
                    } else {
                        alert(response.error || 'Failed to cancel request.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Cancel request error:', status, error, xhr.responseText);
                    alert('Failed to cancel request: ' + (xhr.responseJSON?.error ||
                        'Server error'));
                }
            });
        };

        function loadRequests() {
            console.log('Loading requests...');
            $('#requests-container').html('<p class="text-gray-600">Loading...</p>');
            $.ajax({
                url: 'fetch_requests.php',
                type: 'GET',
                cache: false,
                success: function(data) {
                    console.log('Requests loaded:', data);
                    $('#requests-container').html(data);
                },
                error: function(xhr, status, error) {
                    console.error('Fetch requests error:', status, error, xhr.responseText);
                    $('#requests-container').html(
                        '<p class="text-red-600">Error loading requests: ' + (xhr.responseJSON
                            ?.error || 'Server error') + '</p>');
                }
            });
        }

        // Auto-update pending orders count
        function updatePendingOrders() {
            $.ajax({
                url: 'get_pending_orders.php',
                type: 'GET',
                cache: false,
                success: function(response) {
                    console.log('Pending orders updated:', response);
                    $('#pending-orders-count').text(response.pending_orders || '0');
                },
                error: function(xhr, status, error) {
                    console.error('Pending orders update error:', status, error, xhr.responseText);
                    $('#pending-orders-count').text('Error');
                }
            });
        }

        // Initial load and start auto-update
        updatePendingOrders();
        setInterval(updatePendingOrders, 5000);

        loadRequests();
    });
    </script>
</body>

</html>