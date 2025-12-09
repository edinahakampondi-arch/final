<?php 
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Include database connection
require_once '../connect.php';

// Function to clean drug name by removing "Suppository"
function cleanDrugName($drug_name) {
    return trim(str_ireplace('Suppository', '', $drug_name));
}

// Start session and generate CSRF token
session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] == 1 && hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'] ?? '')) {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Fetch total drugs count
$result = mysqli_query($conn, "SELECT COUNT(*) AS total_drugs FROM drugs");
$total_drugs = $result ? mysqli_fetch_assoc($result)['total_drugs'] : 'Error';
if (!$result) {
    error_log("Database Error (total drugs): " . mysqli_error($conn));
    echo "<p class='text-red-600 text-sm'>Database Error: " . mysqli_error($conn) . "</p>";
}

// ==============================
// Fetch all "At-Risk" drugs (Admin Dashboard)
// ==============================

// At-Risk: drugs already expired OR expiring within 2 months from now
$query_at_risk = "
    SELECT COUNT(*) AS at_risk_stock
    FROM drugs
    WHERE expiry_date < CURDATE()
       OR expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 MONTH)
";

$result_at_risk = mysqli_query($conn, $query_at_risk);

if ($result_at_risk) {
    $row = mysqli_fetch_assoc($result_at_risk);
    $at_risk_stock = $row['at_risk_stock'];
} else {
    $at_risk_stock = 'Error';
    error_log('Database Error (at-risk stock - admin): ' . mysqli_error($conn));
    echo "<p class='text-red-600 text-sm'>Database Error: " . mysqli_error($conn) . "</p>";
}


// Fetch number of pending requests
$result_pending = mysqli_query($conn, "SELECT COUNT(*) AS pending_orders FROM borrowing_requests WHERE status = 'Pending'");
$pending_orders = $result_pending ? mysqli_fetch_assoc($result_pending)['pending_orders'] : 'Error';
if (!$result_pending) {
    error_log("Database Error (pending orders): " . mysqli_error($conn));
    echo "<p class='text-red-600 text-sm'>Database Error: " . mysqli_error($conn) . "</p>";
}

// AJAX: Get total drugs count
if (isset($_GET['action']) && $_GET['action'] === 'get_total_drugs') {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total_drugs FROM drugs");
    echo $result ? mysqli_fetch_assoc($result)['total_drugs'] : 'Error';
    exit;
}

// AJAX: Get critical stock count
if (isset($_GET['action']) && $_GET['action'] === 'get_critical_stock') {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS critical_stock FROM drugs WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
    echo $result ? mysqli_fetch_assoc($result)['critical_stock'] : 'Error';
    exit;
}

// AJAX: Search drugs
if (isset($_GET['action']) && $_GET['action'] === 'search_drugs' && isset($_GET['query'])) {
    $query = mysqli_real_escape_string($conn, $_GET['query']);
    $sql = "SELECT * FROM drugs WHERE drug_name LIKE '$query%' OR category LIKE '$query%' OR department LIKE '$query%'";
    $result = mysqli_query($conn, $sql);
    $drugs = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $drugs[] = $row;
        }
    }
    echo json_encode($drugs);
    exit;
}

// AJAX: Add drug
if (isset($_POST['add_drug']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    $drug_name = mysqli_real_escape_string($conn, $_POST['drug_name']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
    $current_stock = (int)$_POST['current_stock'];
    $expiry_date = mysqli_real_escape_string($conn, $_POST['expiry_date']);
    $stock_level = 100; // Set to 100% as default since we don't use max_stock anymore

    $sql = "INSERT INTO drugs (drug_name, category, department, current_stock, expiry_date, stock_level)
            VALUES ('$drug_name', '$category', '$department', $current_stock, '$expiry_date', $stock_level)";
    $response = ['success' => false, 'message' => 'Error adding drug'];
    if ($conn->query($sql) === TRUE) {
        $new_drug_id = $conn->insert_id;
        $response = [
            'success' => true,
            'message' => "Drug '$drug_name' successfully added!",
            'drug' => [
                'id' => $new_drug_id,
                'drug_name' => $drug_name,
                'category' => $category,
                'department' => $department,
                'current_stock' => $current_stock,
                'expiry_date' => $expiry_date,
                'stock_level' => $stock_level
            ]
        ];
    } else {
        error_log("Add Drug Error: " . $conn->error);
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// AJAX: Update drug
if (isset($_POST['update_drug']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    $id = (int)$_POST['id'];
    $drug_name = mysqli_real_escape_string($conn, $_POST['drug_name']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
    $current_stock = (int)$_POST['current_stock'];
    $expiry_date = mysqli_real_escape_string($conn, $_POST['expiry_date']);
    $stock_level = 100; // Set to 100% as default since we don't use max_stock anymore

    $sql = "UPDATE drugs SET drug_name='$drug_name', category='$category', department='$department', current_stock=$current_stock, expiry_date='$expiry_date', stock_level=$stock_level WHERE id=$id";
    $response = ['success' => false, 'message' => 'Error updating drug'];
    if ($conn->query($sql) === TRUE) {
        $response = [
            'success' => true,
            'message' => "Drug '$drug_name' successfully updated!",
            'drug' => [
                'id' => $id,
                'drug_name' => $drug_name,
                'category' => $category,
                'department' => $department,
                'current_stock' => $current_stock,
                'expiry_date' => $expiry_date,
                'stock_level' => $stock_level
            ]
        ];
    } else {
        error_log("Update Drug Error: " . $conn->error);
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// AJAX: Delete user
if (isset($_GET['action']) && $_GET['action'] === 'delete_user' && isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $response = ['success' => false, 'message' => 'Error deleting user'];
    if ($stmt->execute()) {
        $response = ['success' => true, 'message' => 'User deleted successfully'];
    } else {
        error_log("Delete User Error: " . $stmt->error);
    }
    echo json_encode($response);
    $stmt->close();
    exit;
}

// AJAX: Update user
if (isset($_POST['update_user']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    $user_id = (int)$_POST['user_id'];
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $number = mysqli_real_escape_string($conn, $_POST['number']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);

    $stmt = $conn->prepare("UPDATE users SET name = ?, number = ?, department = ? WHERE id = ?");
    $stmt->bind_param("sssi", $name, $number, $department, $user_id);
    $response = ['success' => false, 'message' => 'Error updating user'];
    if ($stmt->execute()) {
        $response = [
            'success' => true,
            'message' => "User '$name' updated successfully!",
            'user' => ['id' => $user_id, 'name' => $name, 'number' => $number, 'department' => $department]
        ];
    } else {
        error_log("Update User Error: " . $stmt->error);
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    $stmt->close();
    exit;
}

// Fetch drugs with available stock
$query = "SELECT drug_name, department, current_stock FROM drugs WHERE current_stock > 0";
$result = mysqli_query($conn, $query);
$drugs = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $drugs[] = $row;
    }
}

// Handle user registration (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $department = trim($_POST['department'] ?? '');

    if (!$name || !$username || !$number || !$email || !$password || !$department) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all fields.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
        exit;
    }
    $check = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->bind_result($count);
    $check->fetch();
    $check->close();
    if ($count > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username or email already exists.']);
        exit;
    }
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (name, username, password, number, email, department) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $name, $username, $hashedPassword, $number, $email, $department);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => '✅ User registered successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => '❌ Registration failed: ' . $stmt->error]);
    }
    $stmt->close();
    $conn->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Holistic Drug Distribution System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>


<body class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100">
    <div class="container mx-auto p-6">
        <!-- Header -->
        <header class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-900 mb-2">Holistic Drug Distribution System</h1>
                <p class="text-lg text-gray-600">Real-time pharmaceutical inventory management</p>
            </div>
            <div>
                <a href="?logout=1&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                    onclick="return confirm('Are you sure you want to log out?');"
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
                    <div id="total-drugs" class="text-2xl font-bold text-blue-600">
                        <?php echo htmlspecialchars($total_drugs); ?></div>
                    <p class="text-xs text-gray-500">Across all departments</p>
                </div>
            </div>
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Critical Stock</h3>
                    <i data-lucide="alert-triangle" class="h-4 w-4 text-red-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-red-600"><?php echo htmlspecialchars($at_risk_stock); ?></div>
                    <p class="text-xs text-gray-500">Require immediate attention</p>
                </div>
            </div>
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Pending Orders</h3>
                    <i data-lucide="trending-up" class="h-4 w-4 text-orange-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-orange-600"><?php echo htmlspecialchars($pending_orders); ?>
                    </div>
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

            <!-- Demand Forecast Card -->
            <div id="forecastCard" onclick="window.location.href='predict.php';"
                class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg cursor-pointer">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Demand Forecast</h3>
                    <i data-lucide="trending-up" class="h-4 w-4 text-purple-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-purple-600">AI</div>
                    <p class="text-xs text-gray-500">Click to run predictions</p>
                </div>
            </div>

            <!-- FORECAST MODAL (Required) -->
            <div id="forecastModal"
                class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center">
                <div class="bg-white p-6 rounded-lg w-full max-w-2xl">
                    <h2 class="text-xl font-semibold mb-4">Demand Forecast</h2>

                    <div id="forecastOutput" class="hidden">
                        <canvas id="forecastChart" height="120"></canvas>

                        <div id="forecastResults" class="mt-4 text-sm text-gray-700"></div>
                    </div>

                    <div id="forecastLoading" class="text-center hidden">
                        <p class="text-purple-600">⏳ Loading forecast...</p>
                    </div>

                    <button id="closeForecast" class="mt-4 bg-gray-300 px-4 py-2 rounded">Close</button>
                </div>
            </div>




        </section>

        <!-- Recent Alerts -->
        <!-- Admin Dashboard: All Expiry Alerts -->
        <section class="bg-white shadow-lg rounded-lg mb-8">
            <div class="p-4">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <i data-lucide="alert-triangle" class="h-5 w-5 text-orange-600"></i>
                    All Department Expiry Alerts
                </h2>
            </div>
            <div class="p-4 pt-0 space-y-3">
                <?php
        include('connect.php');

        // Query to fetch all drugs nearing expiry or already expired (across departments)
        $sql = "
            SELECT drug_name, department, current_stock, expiry_date
            FROM drugs
            WHERE expiry_date <= CURDATE()
            OR expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 MONTH)
            ORDER BY expiry_date ASC
        ";

        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $drug = htmlspecialchars($row['drug_name']);
                $dept = htmlspecialchars($row['department']);
                $stock = htmlspecialchars($row['current_stock']);
                $expiry = $row['expiry_date'];
                $today = date('Y-m-d');

                // Calculate days to expiry
                $daysRemaining = (strtotime($expiry) - strtotime($today)) / (60 * 60 * 24);

                // Determine alert level
                if ($expiry <= $today) {
                    $border = "border-red-500";
                    $bg = "bg-red-100 text-red-800";
                    $label = "CRITICAL";
                    $note = "Expired";
                } else {
                    $border = "border-orange-500";
                    $bg = "bg-orange-100 text-orange-800";
                    $label = "WARNING";
                    $note = "Expires in " . round($daysRemaining) . " days";
                }

                // Display alert card
                echo "
                <div class='border-l-4 $border bg-gray-50 p-4 rounded-r-lg'>
                    <div class='flex justify-between items-center'>
                        <div>
                            <strong>{$drug}</strong> - {$dept} Department
                            <br>
                            <span class='text-sm text-gray-600'>Stock: {$stock} units | {$note}</span>
                        </div>
                        <span class='$bg text-xs font-medium px-2.5 py-0.5 rounded'>{$label}</span>
                    </div>
                </div>
                ";
            }
        } else {
            echo "<p class='text-gray-500 italic'>No expiry alerts found across departments.</p>";
        }

        
        ?>
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
                    data-tab="admin">
                    <i data-lucide="shield" class="h-4 w-4"></i>
                    Admin
                </button>
            </div>

            <!-- Tab Content: Inventory Dashboard -->
            <section id="dashboard" class="tab-content">
                <div class="bg-white shadow-lg rounded-lg p-4 ">
                    <h2 class="text-xl font-semibold">Inventory Dashboard</h2>
                    <div class="container mx-auto p-6 ">
                        <!-- Success Alert -->
                        <div id="successAlert"
                            class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 hidden"
                            role="alert">
                            <span id="successMessage" class="block sm:inline"></span>
                            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                                <svg class="fill-current h-6 w-6 text-green-500" role="button"
                                    onclick="document.getElementById('successAlert').classList.add('hidden')"
                                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path
                                        d="M10 8.586L2.929 1.515 1.515 2.929 8.586 10l-7.071 7.071 1.414 1.414L10 11.414l7.071 7.071 1.414-1.414L11.414 10l7.071-7.071-1.414-1.414L10 8.586z" />
                                </svg>
                            </span>
                        </div>

                        <!-- Header Card -->
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <div class="flex items-center gap-2 mb-2">
                                <i data-lucide="package" class="h-5 w-5 text-blue-600"></i>
                                <h2 class="text-xl font-bold">Real-Time Inventory Monitoring</h2>
                            </div>
                            <p class="text-sm text-gray-600">Track and monitor drug inventory across all hospital
                                departments</p>
                        </div>

                        <?php
// Include database connection
include '../connect.php'; // adjust path if needed

// Fetch distinct drug names
$drug_names = [];
$result_names = mysqli_query($conn, "SELECT DISTINCT drug_name FROM drugs ORDER BY drug_name ASC");
if ($result_names) {
    while ($row = mysqli_fetch_assoc($result_names)) {
        $drug_names[] = $row['drug_name'];
    }
}

// Fetch distinct categories
$categories = [];
$result_categories = mysqli_query($conn, "SELECT DISTINCT category FROM drugs WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
if ($result_categories) {
    while ($row = mysqli_fetch_assoc($result_categories)) {
        if (isset($row['category']) && !empty($row['category'])) {
        $categories[] = $row['category'];
        }
    }
}
?>
                        <!-- Add Drug Form -->
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <h3 class="text-lg font-semibold mb-4">Add New Drug</h3>
                            <form id="add-drug-form" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">

                                <!-- Drug Name -->
                                <div>
                                    <label class="text-sm text-gray-600">Drug Name</label>
                                    <input list="drug_name_list" name="drug_name"
                                        class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"
                                        placeholder="Select or type drug name" required>
                                    <datalist id="drug_name_list">
                                        <?php foreach ($drug_names as $name): ?>
                                        <option value="<?php echo htmlspecialchars($name); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>

                                <!-- Category -->
                                <div>
                                    <label class="text-sm text-gray-600">Category</label>
                                    <input list="category_list" name="category"
                                        class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"
                                        placeholder="Select or type category" required>
                                    <datalist id="category_list">
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>

                                <!-- Continue your form fields here... -->

                                <div>
                                    <label class="text-sm text-gray-600">Department</label>
                                    <div>
                                        <select name="department" class="w-full p-2 border border-gray-300 rounded-md"
                                            required>
                                            <option value="">-- Select Department --</option>
                                            <option value="Internal medicine">Internal medicine</option>
                                            <option value="Surgery">Surgery</option>
                                            <option value="Paediatrics">Paediatrics</option>
                                            <option value="Obstetrics">Obstetrics</option>
                                            <option value="Gynaecology">Gynaecology</option>
                                            <option value="Intensive Care unit">Intensive Care unit</option>
                                            <option value="Central Store">Central Store</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Current Stock</label>
                                    <input type="number" name="current_stock"
                                        class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Expiry Date</label>
                                    <input type="date" name="expiry_date"
                                        class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div class="md:col-span-2">
                                    <button type="submit" name="add_drug"
                                        class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Add
                                        Drug</button>
                                </div>
                            </form>
                        </div>

                        <!-- Live Search Section -->
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <h3 class="text-lg font-semibold mb-4">Search Drugs</h3>
                            <div class="relative">
                                <i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-gray-400"></i>
                                <input type="text" id="search-input" placeholder="Type to search drugs..."
                                    class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onkeyup="liveSearchDrugs()">
                            </div>
                            <div id="search-results"
                                class="mt-2 max-h-40 overflow-y-auto bg-white border border-gray-200 rounded-md shadow-lg hidden">
                            </div>
                        </div>



                        <!-- Edit Drug Modal -->
                        <div id="editModal"
                            class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center">
                            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                                <h3 class="text-lg font-semibold mb-4">Edit Drug</h3>
                                <form id="edit-drug-form" action="" method="POST">
                                    <input type="hidden" name="id" id="edit_id">
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Drug Name</label>
                                        <input type="text" name="drug_name" id="edit_drug_name"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Category</label>
                                        <input type="text" name="category" id="edit_category"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Department</label>
                                        <input type="text" name="department" id="edit_department"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Current Stock</label>
                                        <input type="number" name="current_stock" id="edit_current_stock"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Expiry Date</label>
                                        <input type="date" name="expiry_date" id="edit_expiry_date"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" name="update_drug"
                                            class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Update</button>
                                        <button type="button" onclick="closeEditModal()"
                                            class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>






            <!-- Tab Content: Communication -->
            <section id="communication" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Department Communication</h2>
                    <div class="space-y-6 max-w-7xl mx-auto">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div class="bg-white p-6 rounded-lg shadow">
                                <div>
                                    <h2 class="flex items-center gap-2 text-lg font-semibold text-blue-600">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M22 2L11 13" />
                                            <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                                        </svg>
                                        Send Message
                                    </h2>
                                    <p class="text-sm text-gray-500">Communicate with other departments
                                        about drug needs
                                    </p>
                                </div>
                                <div class="space-y-4 mt-4">
                                    <div>
                                        <label class="text-sm font-medium mb-2 block">To Department</label>
                                        <select id="department-select" class="w-full border rounded px-3 py-2">
                                            <option value="">Select department</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium mb-2 block">Message</label>
                                        <textarea id="message-input" rows="4" class="w-full border rounded px-3 py-2"
                                            placeholder="Type your message here..."></textarea>
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
                                        <button class="border px-4 py-2 rounded">Mark as Urgent</button>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white p-6 rounded-lg shadow">
                                <div>
                                    <h2 class="flex items-center gap-2 text-lg font-semibold text-green-600">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                                            <circle cx="9" cy="7" r="4" />
                                            <path d="M23 21v-2a4 4 0 00-3-3.87" />
                                        </svg>
                                        Department Status
                                    </h2>
                                    <p class="text-sm text-gray-500">Current status of all connected
                                        departments</p>
                                </div>
                                <div class="space-y-3 mt-4" id="status-list"></div>
                            </div>
                        </div>
                        <div class="bg-white p-6 rounded-lg shadow">
                            <div>
                                <h2 class="flex items-center gap-2 text-lg font-semibold text-purple-600">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                                    </svg>
                                    Recent Communications
                                </h2>
                                <p class="text-sm text-gray-500">Latest messages between departments</p>
                            </div>
                            <div class="space-y-4 mt-4" id="communications-list"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Forecasting Section -->
            <section id="forecasting" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold mb-4">Demand Forecasting - Top Drugs (Next 3 Months)</h2>
                    <p class="text-sm text-gray-600 mb-4">Showing drugs with highest predicted demand across all departments</p>
                    <div id="forecast-loading" class="text-center py-8">
                        <p class="text-blue-600">⏳ Loading forecast predictions...</p>
                    </div>
                    <div id="forecast-results" class="hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-gray-300">
                                <thead>
                                    <tr class="bg-blue-100">
                                        <th class="border border-gray-300 px-4 py-2 text-left">Rank</th>
                                        <th class="border border-gray-300 px-4 py-2 text-left">Drug Name</th>
                                        <th class="border border-gray-300 px-4 py-2 text-left">Department</th>
                                        <th class="border border-gray-300 px-4 py-2 text-right">Current Stock</th>
                                        <th class="border border-gray-300 px-4 py-2 text-right">Month 1</th>
                                        <th class="border border-gray-300 px-4 py-2 text-right">Month 2</th>
                                        <th class="border border-gray-300 px-4 py-2 text-right">Month 3</th>
                                        <th class="border border-gray-300 px-4 py-2 text-right font-bold">Total (3 Months)</th>
                                        <th class="border border-gray-300 px-4 py-2 text-center">Method</th>
                                    </tr>
                                </thead>
                                <tbody id="forecast-table-body">
                                </tbody>
                            </table>
                        </div>
                        <p id="forecast-empty" class="text-gray-600 text-center py-4 hidden">No forecast data available.</p>
                    </div>
                </div>
            </section>

            <script>
            document.addEventListener("DOMContentLoaded", () => {
                const API_BASE = "http://127.0.0.1:8000";
                const btn = document.getElementById("runForecastBtn");

                btn.addEventListener("click", async () => {
                    const drugName = prompt(
                        "Enter the exact drug name (e.g. Albendazole 400 mg Tablets):"
                    );
                    if (!drugName) return;

                    const output = document.getElementById("forecastOutput");
                    const resultDiv = document.getElementById("forecastResults");
                    const chartCanvas = document.getElementById("forecastChart");
                    output.classList.remove("hidden");
                    resultDiv.innerHTML =
                        `<p class='text-gray-500'>⏳ Running prediction for <b>${drugName}</b>...</p>`;

                    try {
                        const res = await fetch(
                            `${API_BASE}/predict/${encodeURIComponent(drugName)}`);
                        const data = await res.json();

                        if (data.error) {
                            resultDiv.innerHTML =
                                `<p class='text-red-600'>⚠️ ${data.error}</p>`;
                            return;
                        }

                        // Clear chart
                        const ctx = chartCanvas.getContext("2d");
                        if (window.forecastChart) window.forecastChart.destroy();

                        // Use correct field names from API response
                        const months = data.months || [];
                        const predictions = data.predictions || [];
                        
                        if (!predictions || predictions.length === 0) {
                            resultDiv.innerHTML = `<p class='text-red-600'>⚠️ No predictions returned from API</p>`;
                            return;
                        }

                        // Draw chart
                        window.forecastChart = new Chart(ctx, {
                            type: "line",
                            data: {
                                labels: months,
                                datasets: [{
                                    label: `${data.drug || drugName} Forecast`,
                                    data: predictions,
                                    borderColor: "rgb(139, 92, 246)",
                                    borderWidth: 2,
                                    fill: false,
                                    tension: 0.3
                                }]
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    y: {
                                        beginAtZero: true
                                    }
                                },
                                plugins: {
                                    title: {
                                        display: true,
                                        text: `3-Month Forecast for ${data.drug || drugName} (${data.method || 'AutoETS'})`
                                    },
                                    legend: {
                                        display: false
                                    }
                                }
                            }
                        });

                        // Show prediction results
                        let resultsHtml = `
                            <h3 class="font-semibold text-gray-800 mt-6">Prediction Results</h3>
        <table class="mt-2 w-full border text-sm">
                                <tr class="bg-gray-100">
                                    <th class="border px-3 py-2">Month</th>
                                    <th class="border px-3 py-2">Predicted Demand</th>
                                </tr>`;
                        
                        for (let i = 0; i < months.length && i < predictions.length; i++) {
                            resultsHtml += `
                                <tr>
                                    <td class="border px-3 py-2">${months[i]}</td>
                                    <td class="border px-3 py-2">${Math.round(predictions[i])} units</td>
                                </tr>`;
                        }
                        
                        resultsHtml += `</table>`;
                        
                        if (data.historical_mean) {
                            resultsHtml += `<p class="text-sm text-gray-600 mt-4">Historical average: ${Math.round(data.historical_mean)} units</p>`;
                        }
                        
                        if (data.method) {
                            resultsHtml += `<p class="text-xs text-gray-500 mt-2">Method: ${data.method}</p>`;
                        }
                        
                        if (data.note) {
                            resultsHtml += `<p class="text-xs text-yellow-600 mt-1">Note: ${data.note}</p>`;
                        }
                        
                        resultDiv.innerHTML = resultsHtml;
                    } catch (err) {
                        console.error(err);
                        resultDiv.innerHTML =
                            `<p class='text-red-600'>⚠️ Could not connect to FastAPI backend. Ensure it's running at port 8000.</p>`;
                    }
                });
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
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div>
                                    <label class="text-sm font-medium block mb-1">Drug</label>
                                    <select name="drug" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select drug</option>
                                        <?php foreach ($drugs as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s['drug_name']); ?>"><?php echo htmlspecialchars(cleanDrugName($s['drug_name'])); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-medium block mb-1">Quantity</label>
                                    <input type="number" name="quantity" class="w-full border rounded px-3 py-2"
                                        required />
                                </div>
                                <div>
                                    <label class="text-sm font-medium block mb-1">From Department</label>
                                    <select name="from_department" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select department</option>
                                        <option>Internal Medicine</option>
                                        <option>Pediatrics</option>
                                        <option>Obstetric</option>
                                        <option>Surgery</option>
                                        <option>Gynaecology</option>
                                    </select>
                                </div>
                                <button type="submit"
                                    class="bg-blue-600 text-white py-2 px-4 rounded w-full">Submit</button>
                            </form>
                        </div>
                        <!-- Available Supplies -->
                        <div class="bg-white border rounded-xl p-6 shadow max-h-80 overflow-y-auto">
                            <h2 class="text-lg font-bold text-green-600 mb-4">📦 Available Supplies</h2>
                            <div class="space-y-3">
                                <?php foreach ($drugs as $s): ?>
                                <div class="border rounded p-4 shadow-sm">
                                    <div class="flex justify-between items-center">
                                        <h3 class="font-semibold text-gray-800">
                                            <?php echo htmlspecialchars(cleanDrugName($s['drug_name'])); ?></h3>
                                        <h3 class="font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($s['department']); ?></h3>
                                        <p class="text-sm text-gray-600">
                                            <?php echo intval($s['current_stock']); ?>
                                            units</p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
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

            <!-- Tab Content: Admin Dashboard -->
            <section id="admin" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Admin Dashboard</h2>
                    <div class="admin-dashboard bg-gray-100 p-6">
                        <!-- Header -->
                        <div class="bg-white border rounded-xl p-6 shadow mb-6">
                            <div class="flex items-center gap-2 text-xl font-semibold text-gray-700">
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 11c0-1.657 1.343-3 3-3s3 1.343 3 3-1.343 3-3 3-3-1.343-3-3z M17 16H7v-2a4 4 0 014-4h2a4 4 0 014 4v2z" />
                                </svg>
                                Admin Dashboard
                            </div>
                            <p class="text-sm text-gray-500">System administration and user management</p>
                        </div>

                        <!-- Admin Tabs -->
                        <div class="space-y-4">
                            <!-- Tab Buttons -->
                            <div class="admin-tabs grid grid-cols-4 gap-2 bg-white rounded-xl overflow-hidden shadow">
                                <button data-tab="users"
                                    class="admin-tab-btn px-4 py-2 flex items-center gap-2 text-sm font-medium w-full hover:bg-gray-100">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20h6M3 20h5v-2a4 4 0 00-4-4H3M16 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    User Management
                                </button>
                                <button data-tab="add-user"
                                    class="admin-tab-btn px-4 py-2 flex items-center gap-2 text-sm font-medium w-full hover:bg-gray-100">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                    </svg>
                                    Add User
                                </button>


                            </div>

                            <!-- Tab Contents -->
                            <div class="admin-tab-content users bg-white rounded-xl p-6 shadow">
                                <h2 class="text-lg font-semibold text-gray-700 mb-4">User Management</h2>
                                <p class="text-gray-500 mb-4">Manage registered users here.</p>
                                <?php
                                $user_query = "SELECT id, name, number, department FROM users";
                                $user_result = $conn->query($user_query);
                                if ($user_result && $user_result->num_rows > 0) {
                                ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                                        <thead>
                                            <tr class="bg-gray-100">
                                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">
                                                    Name
                                                </th>
                                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">
                                                    Phone Number</th>
                                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">
                                                    Department</th>
                                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">
                                                    Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($user = $user_result->fetch_assoc()): ?>
                                            <tr class="border-t hover:bg-gray-50"
                                                data-user-id="<?php echo $user['id']; ?>">
                                                <td class="px-6 py-4 text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($user['name']); ?></td>
                                                <td class="px-6 py-4 text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($user['number']); ?></td>
                                                <td class="px-6 py-4 text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($user['department']); ?>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-600">
                                                    <button
                                                        onclick="openEditUserModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['department'], ENT_QUOTES); ?>')"
                                                        class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 mr-2">Edit</button>
                                                    <a href="?action=delete_user&user_id=<?php echo $user['id']; ?>"
                                                        class="delete-user bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600"
                                                        onclick="return false;">Delete</a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php } else {
                                    echo "<p class='text-gray-500'>No users found.</p>";
                                } ?>
                            </div>
                            <div class="admin-tab-content add-user hidden bg-white rounded-xl p-6 shadow">
                                <h2 class="text-xl font-semibold text-center text-gray-700 mb-2">Add New
                                    User</h2>
                                <p class="text-sm text-gray-500 text-center mb-4">Register a new system user
                                </p>
                                <form id="userForm" class="space-y-4">
                                    <input type="text" name="name" placeholder="Full Name" required
                                        class="w-full p-2 border rounded" />
                                    <input type="text" name="username" placeholder="Username" required
                                        class="w-full p-2 border rounded" />
                                    <input type="text" name="number" placeholder="Phone Number" required
                                        class="w-full p-2 border rounded" />
                                    <input type="email" name="email" placeholder="Email" required
                                        class="w-full p-2 border rounded" />
                                    <input type="password" name="password" placeholder="Password" required
                                        class="w-full p-2 border rounded" />
                                    <select name="department" required class="w-full p-2 border rounded text-gray-700">
                                        <option value="">Select Department</option>
                                        <option value="Internal medicine">Internal medicine</option>
                                        <option value="Surgery">Surgery</option>
                                        <option value="Paediatrics">Paediatrics</option>
                                        <option value="Obstetrics">Obstetrics</option>
                                        <option value="Gynaecology">Gynaecology</option>
                                        <option value="Admin">Admin</option>
                                        <option value="Intensive Care unit">Intensive Care unit</option>
                                    </select>
                                    <button type="submit"
                                        class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Register</button>
                                </form>
                                <div id="responseMsg" class="mt-4 text-center text-sm"></div>
                            </div>
                            <div class="admin-tab-content database hidden bg-white rounded-xl p-6 shadow">
                                <h2 class="text-lg font-semibold text-gray-700 mb-2">Database Management
                                </h2>
                                <p class="text-gray-500">Database statistics and maintenance tools coming
                                    soon...</p>
                            </div>
                            <div class="admin-tab-content settings hidden bg-white rounded-xl p-6 shadow">
                                <h2 class="text-lg font-semibold text-gray-700 mb-2">System Settings</h2>
                                <p class="text-gray-500">System settings coming soon...</p>
                            </div>
                        </div>

                        <!-- Edit User Modal -->
                        <div id="editUserModal"
                            class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center">
                            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                                <h3 class="text-lg font-semibold mb-4">Edit User</h3>
                                <form id="editUserForm" method="POST">
                                    <input type="hidden" name="user_id" id="edit_user_id">
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Name</label>
                                        <input type="text" name="name" id="edit_user_name"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Phone Number</label>
                                        <input type="text" name="number" id="edit_user_number"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Department</label>
                                        <select name="department" id="edit_user_department"
                                            class="w-full p-2 border border-gray-300 rounded-md" required>
                                            <option value="">Select Department</option>
                                            <option value="Internal medicine">Internal medicine</option>
                                            <option value="Surgery">Surgery</option>
                                            <option value="Paediatrics">Paediatrics</option>
                                            <option value="Obstetrics">Obstetrics</option>
                                            <option value="Gynaecology">Gynaecology</option>
                                            <option value="Admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" name="update_user"
                                            class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Update</button>
                                        <button type="button" onclick="closeEditUserModal()"
                                            class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
                                    </div>
                                </form>
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

    // Main tab functionality
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

    // Update total drugs count
    function updateTotalDrugs() {
        fetch('?action=get_total_drugs', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(data => document.getElementById('total-drugs').textContent = data)
            .catch(() => document.getElementById('total-drugs').textContent = 'Error');
    }

    // Live Search Drugs
    function liveSearchDrugs() {
        const query = document.getElementById('search-input').value.trim();
        const resultsDiv = document.getElementById('search-results');
        if (query.length < 1) {
            resultsDiv.classList.add('hidden');
            return;
        }
        fetch(`?action=search_drugs&query=${encodeURIComponent(query)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(response => response.json())
            .then(data => {
                resultsDiv.innerHTML = '';
                if (data.length > 0) {
                    resultsDiv.classList.remove('hidden');
                    data.forEach(drug => {
                        const div = document.createElement('div');
                        div.className = 'p-2 hover:bg-gray-100 cursor-pointer border-b';
                        const cat = drug.category ? `${drug.category}, ` : '';
                        const cleanName = cleanDrugName(drug.drug_name);
                        div.textContent = `${cleanName} (${cat}${drug.department})`;
                        div.onclick = () => {
                            document.getElementById('search-input').value = drug.drug_name;
                            resultsDiv.classList.add('hidden');
                            displayDrugDetails(drug);
                        };
                        resultsDiv.appendChild(div);
                    });
                } else {
                    resultsDiv.classList.add('hidden');
                }
            })
            .catch(() => resultsDiv.innerHTML =
                '<div class="p-2 text-red-600">Error searching drugs.</div>');
    }

    // Function to clean drug name by removing "Suppository"
    function cleanDrugName(drugName) {
        if (!drugName) return '';
        return drugName.replace(/Suppository/gi, '').trim();
    }

    // Display drug details when selected
    function displayDrugDetails(drug) {
        const inventoryCards = document.getElementById('inventory-cards');
        const status = drug.current_stock > 0 ? 'NORMAL' : 'OUT OF STOCK';
        const statusColor = drug.current_stock > 0 ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200';
        const trendIcon = drug.current_stock > 0 ? 'trending-up' : 'trending-down';
        const trendColor = drug.current_stock > 0 ? 'text-green-600' : 'text-red-600';
        const category = drug.category ? `${drug.category} • ` : '';
        const stockLevel = drug.stock_level || 100;
        const cleanDrugNameDisplay = cleanDrugName(drug.drug_name);
        inventoryCards.innerHTML = `
        <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h3 class="font-semibold text-lg">${cleanDrugNameDisplay}</h3>
                    <p class="text-sm text-gray-600">${category}${drug.department}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="${statusColor} text-xs px-2 py-1 rounded">${status}</span>
                    <i data-lucide="${trendIcon}" class="h-4 w-4 ${trendColor}"></i>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <p class="text-sm text-gray-600">Current Stock</p>
                    <p class="text-2xl font-bold">${drug.current_stock}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Expiry Date</p>
                    <p class="text-sm">${drug.expiry_date}</p>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between text-sm">
                    <span>Stock Level</span>
                    <span>${Math.round(stockLevel)}%</span>
                </div>
                <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                    <div class="bg-green-500 h-2" style="width: ${stockLevel}%;"></div>
                </div>
            </div>

            <div class="mt-4 flex gap-2">
  <button
    onclick='openEditModal(
      ${drug.id},
      ${JSON.stringify(cleanDrugNameDisplay)},
      ${JSON.stringify(drug.category || '')},
      ${JSON.stringify(drug.department)},
      ${drug.current_stock},
      ${JSON.stringify(drug.expiry_date)}
    )'
    class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600">
    Edit
  </button>
  <a href="?delete_id=${drug.id}"
     class="delete-drug bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600"
     onclick="return false;">Delete</a>
</div>

        </div>
        `;
        lucide.createIcons();
    }




    async function loadPredictions(drugName) {
        const chartCanvas = document.getElementById("forecastChart");
        const tableDiv = document.getElementById("forecastResults");

        // Show loading state
        tableDiv.innerHTML = "<p class='text-gray-500'>Loading predictions...</p>";

        try {
            // ✅ FIX 1: Include the drug name in the URL (and encode spaces)
            const response = await fetch(`http://127.0.0.1:8000/predict/${encodeURIComponent(drugName)}`);


            // Check for response errors
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }

            const data = await response.json();

            // ✅ FIX 2: Your FastAPI sends `predictions`, not `predicted_demand`
            const predictions = data.predictions;
            const months = data.months;

            // Destroy old chart if exists
            if (window.forecastChart) window.forecastChart.destroy();

            // Draw chart
            const ctx = chartCanvas.getContext("2d");
            window.forecastChart = new Chart(ctx, {
                type: "line",
                data: {
                    labels: months,
                    datasets: [{
                        label: `${data.drug} - Predicted Demand`,
                        data: predictions,
                        borderColor: "rgba(99, 102, 241, 1)", // Indigo-500
                        backgroundColor: "rgba(99, 102, 241, 0.2)",
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true
                        },
                        title: {
                            display: true,
                            text: "3-Month Demand Forecast"
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // ✅ FIX 3: Use `data.predictions` in table generation
            tableDiv.innerHTML = `
            <table class="w-full border mt-4 text-sm">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="px-4 py-2">Month</th>
                        <th class="px-4 py-2">Predicted Demand</th>
                    </tr>
                </thead>
                <tbody>
                    ${months.map((m, i) => `
                        <tr>
                            <td class='border px-4 py-2'>${m}</td>
                            <td class='border px-4 py-2'>${predictions[i].toFixed(2)}</td>
                        </tr>`).join("")}
                </tbody>
            </table>`;
        } catch (err) {
            console.error(err);
            tableDiv.innerHTML =
                `<p class='text-red-600'>⚠️ Could not connect to backend or fetch prediction. Make sure app.py is running.</p>`;
        }
    }






    // Handle Add Drug form submission
    document.getElementById('add-drug-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('add_drug', '1');

        fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                const alert = document.getElementById('successAlert');
                document.getElementById('successMessage').textContent = data.message;
                alert.classList.remove('hidden');
                if (data.success) {
                    updateTotalDrugs();
                    const inventoryCards = document.getElementById('inventory-cards');
                    const drug = data.drug;
                    const status = drug.current_stock > 0 ? 'NORMAL' : 'OUT OF STOCK';
                    const statusColor = drug.current_stock > 0 ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200';
                    const trendIcon = drug.current_stock > 0 ? 'trending-up' : 'trending-down';
                    const trendColor = drug.current_stock > 0 ? 'text-green-600' : 'text-red-600';
                    const category = drug.category ? `${drug.category} • ` : '';
                    const stockLevel = drug.stock_level || 100;
                    const categoryDisplay = drug.category || '';
                    const cleanDrugNameDisplay = cleanDrugName(drug.drug_name);
                    const card = document.createElement('div');
                    card.className = 'bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6';
                    card.innerHTML = ` <div
            class="flex justify-between items-start mb-4">
            <div>
                <h3 class="font-semibold text-lg">${cleanDrugNameDisplay}</h3>
                <p class="text-sm text-gray-600">${category}${drug.department}</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="${statusColor} text-xs px-2 py-1 rounded">${status}</span>
                <i data-lucide="${trendIcon}" class="h-4 w-4 ${trendColor}"></i>
            </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <p class="text-sm text-gray-600">Current Stock</p>
                    <p class="text-2xl font-bold">${drug.current_stock}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Expiry Date</p>
                    <p class="text-sm">${drug.expiry_date}</p>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex justify-between text-sm">
                    <span>Stock Level</span>
                    <span>${Math.round(stockLevel)}%</span>
                </div>
                <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                    <div class="bg-green-500 h-2" style="width: ${stockLevel}%;"></div>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
<button onclick='openEditModal(
                    ${drug.id},
                    ${JSON.stringify(cleanDrugNameDisplay)},
                    ${JSON.stringify(categoryDisplay)},
                    ${JSON.stringify(drug.department)},
                    ${drug.current_stock},
                    ${JSON.stringify(drug.expiry_date)}  )'
                                         class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600">
                                           Edit </button>
<a href="?delete_id=${drug.id}"
    class="delete-drug bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600"
    onclick="return false;">Delete</a>
            </div>
            `;
                    inventoryCards.prepend(card);
                    lucide.createIcons();
                    this.reset();
                }
            })
            .catch(error => {
                console.error('Error adding drug:', error);
                const alert = document.getElementById('successAlert');
                document.getElementById('successMessage').textContent =
                    `Error adding drug: ${error.message}`;
                alert.classList.remove('hidden');
            });

    });

    // Handle Edit Drug form submission
    document.getElementById('edit-drug-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('update_drug', '1');

        fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                const alert = document.getElementById('successAlert');
                document.getElementById('successMessage').textContent = data.message;
                alert.classList.remove('hidden');
                if (data.success) {
                    updateTotalDrugs();
                    const drug = data.drug;
                    const card = document.querySelector(`[data-id="${drug.id}"]`);
                    if (card) {
                        const status = drug.current_stock > 0 ? 'NORMAL' : 'OUT OF STOCK';
                        const statusColor = drug.current_stock > 0 ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200';
                        const trendIcon = drug.current_stock > 0 ? 'trending-up' : 'trending-down';
                        const trendColor = drug.current_stock > 0 ? 'text-green-600' : 'text-red-600';
                        const category = drug.category ? `${drug.category} • ` : '';
                        const stockLevel = drug.stock_level || 100;
                        const categoryDisplay = drug.category || '';
                        const cleanDrugNameDisplay = cleanDrugName(drug.drug_name);
                        card.innerHTML = `
<div class="flex justify-between items-start mb-4">
    <div>
        <h3 class="font-semibold text-lg">${cleanDrugNameDisplay}</h3>
        <p class="text-sm text-gray-600">${category}${drug.department}</p>
    </div>
    <div class="flex items-center gap-2">
        <span class="${statusColor} text-xs px-2 py-1 rounded">${status}</span>
        <i data-lucide="${trendIcon}" class="h-4 w-4 ${trendColor}"></i>
    </div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
    <div>
        <p class="text-sm text-gray-600">Current Stock</p>
        <p class="text-2xl font-bold">${drug.current_stock}</p>
    </div>
    <div>
        <p class="text-sm text-gray-600">Expiry Date</p>
        <p class="text-sm">${drug.expiry_date}</p>
    </div>
</div>
<div class="space-y-2">
    <div class="flex justify-between text-sm">
        <span>Stock Level</span>
        <span>${Math.round(stockLevel)}%</span>
    </div>
    <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
        <div class="bg-green-500 h-2" style="width: ${stockLevel}%;"></div>
    </div>
</div>
<div class="mt-4 flex gap-2">
    <button class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600" onclick='openEditModal(${drug.id}, ${JSON.stringify(cleanDrugNameDisplay)}, ${JSON.stringify(categoryDisplay)}, ${JSON.stringify(drug.department)}, ${drug.current_stock}, ${JSON.stringify(drug.expiry_date)})' >Edit</button>
    <a href="?delete_id=${drug.id}" class="delete-drug bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600" onclick="return false;">Delete</a>
</div>
                `;
                        lucide.createIcons();
                    }
                    closeEditModal();
                }
            })
            .catch(error => {
                console.error('Error updating drug:', error);
                const alert = document.getElementById('successAlert');
                document.getElementById('successMessage').textContent =
                    `Error updating drug: ${error.message}`;
                alert.classList.remove('hidden');
            });
    });

    // Handle Delete Drug
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-drug')) {
            e.preventDefault();
            const deleteUrl = e.target.getAttribute('href');
            if (confirm('Are you sure you want to delete this drug?')) {
                fetch(deleteUrl, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (response.ok) {
                            updateTotalDrugs();
                            e.target.closest('.bg-white.rounded-lg').remove();
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting drug:', error);
                        const alert = document.getElementById('successAlert');
                        document.getElementById('successMessage').textContent =
                            `Error deleting drug: ${error.message}`;
                        alert.classList.remove('hidden');
                    });
            }
        }
    });

    // Edit Drug Modal Functions
    function openEditModal(drug_id, drug_name, category, department, current_stock, expiry_date) {
        document.getElementById('editModal').classList.remove('hidden');
        document.getElementById('edit_id').value = drug_id;
        document.getElementById('edit_drug_name').value = drug_name;
        document.getElementById('edit_category').value = category;
        document.getElementById('edit_department').value = department;
        document.getElementById('edit_current_stock').value = current_stock;
        document.getElementById('edit_expiry_date').value = expiry_date;
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.add('hidden');
    }

    // Edit User Modal Functions
    function openEditUserModal(id, name, number, department) {
        document.getElementById('editUserModal').classList.remove('hidden');
        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_user_name').value = name;
        document.getElementById('edit_user_number').value = number;
        document.getElementById('edit_user_department').value = department;
    }

    function closeEditUserModal() {
        document.getElementById('editUserModal').classList.add('hidden');
    }

    // Handle Delete User
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-user')) {
            e.preventDefault();
            const deleteUrl = e.target.getAttribute('href');
            if (confirm('Are you sure you want to delete this user?')) {
                fetch(deleteUrl, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            e.target.closest('tr').remove();
                            const alert = document.getElementById('successAlert');
                            document.getElementById('successMessage').textContent = data
                                .message;
                            alert.classList.remove('hidden');
                        } else {
                            const alert = document.getElementById('successAlert');
                            document.getElementById('successMessage').textContent = data
                                .message;
                            alert.classList.remove('hidden');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting user:', error);
                        const alert = document.getElementById('successAlert');
                        document.getElementById('successMessage').textContent =
                            `Error deleting user: ${error.message}`;
                        alert.classList.remove('hidden');
                    });
            }
        }
    });

    // Handle Edit User Form Submission
    document.getElementById('editUserForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('update_user', '1');

        fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                const alert = document.getElementById('successAlert');
                document.getElementById('successMessage').textContent = data.message;
                alert.classList.remove('hidden');
                if (data.success) {
                    const user = data.user;
                    const row = document.querySelector(`tr[data-user-id="${user.id}"]`);
                    if (row) {
                        row.innerHTML = `
            <td class="px-6 py-4 text-sm text-gray-600">${user.name}</td>
            <td class="px-6 py-4 text-sm text-gray-600">${user.number}</td>
            <td class="px-6 py-4 text-sm text-gray-600">${user.department}</td>
            <td class="px-6 py-4 text-sm text-gray-600">
    <button 
        onclick="openEditUserModal(
            ${user.id}, 
            '${user.name.replace(/'/g, "\\'")}', 
            '${user.number.replace(/'/g, "\\'")}', 
            '${user.department.replace(/'/g, "\\'")}'
        )"
        class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 mr-2">
        Edit
    </button>

    <a href='?action=delete_user&user_id=${user.id}'
       class='delete-user bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600'
       onclick='return false;'>Delete</a>
</td>

            `;
                    }
                    closeEditUserModal();
                }
            })
            .catch(error => {
                console.error('Error updating user:', error);
                const alert = document.getElementById('successAlert');
                document.getElementById('successMessage').textContent =
                    `Error updating user: ${error.message}`;
                alert.classList.remove('hidden');
            });
    });

    // User Registration Form Submission
    document.getElementById("userForm").addEventListener("submit", function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('ajax', '1');

        fetch("", {
                method: "POST",
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const msgBox = document.getElementById("responseMsg");
                msgBox.textContent = data.message;
                msgBox.className = "mt-4 text-center text-sm " +
                    (data.status === "success" ? "text-green-600" : "text-red-600");
                if (data.status === "success") {
                    this.reset();
                }
            })
            .catch(() => {
                const msgBox = document.getElementById("responseMsg");
                msgBox.textContent = "❌ Something went wrong.";
                msgBox.className = "mt-4 text-center text-sm text-red-600";
            });
    });

    // Admin Tab Functionality
    document.addEventListener("DOMContentLoaded", () => {
        const buttons = document.querySelectorAll(".admin-tab-btn");
        const contents = document.querySelectorAll(".admin-tab-content");

        function activateTab(name) {
            contents.forEach(c => c.classList.add("hidden"));
            document.querySelector(`.admin-tab-content.${name}`).classList.remove("hidden");
            buttons.forEach(b => b.classList.remove("bg-gray-200"));
            document.querySelector(`.admin-tab-btn[data-tab="${name}"]`).classList.add(
                "bg-gray-200");
        }

        buttons.forEach(btn => {
            btn.addEventListener("click", () => activateTab(btn.getAttribute("data-tab")));
        });

        activateTab("users"); // Default tab
    });

    // Communication Tab
    const departments = [
        "Emergency Department", "ICU", "Pediatrics", "Cardiology",
        "Endocrinology", "Surgery", "General Ward", "Central Pharmacy"
    ];

    const communications = [{
            id: 1,
            from: "Emergency Department",
            to: "Central Pharmacy",
            message: "Urgent: Running low on Epinephrine. Need 20 vials ASAP.",
            timestamp: "2025-07-16 10:00",
            status: "pending",
            priority: "high"
        },
        {
            id: 2,
            from: "Central Pharmacy",
            to: "ICU",
            message: "Morphine shipment delayed. Expected delivery tomorrow morning.",
            timestamp: "2025-07-16 09:45",
            status: "delivered",
            priority: "medium"
        },
        {
            id: 3,
            from: "Pediatrics",
            to: "Surgery",
            message: "Requesting 10 units of Ibuprofen for pediatric use.",
            timestamp: "2025-07-15 14:30",
            status: "pending",
            priority: "low"
        }
    ];

    function populateDepartments() {
        const select = document.getElementById("department-select");
        departments.forEach(dept => {
            const option = document.createElement("option");
            option.value = dept;
            option.textContent = dept;
            select.appendChild(option);
        });
    }

    function updateStatusList() {
        const list = document.getElementById("status-list");
        list.innerHTML = departments.map(dept =>
            `<div class="flex justify-between"><span>${dept}</span><span class="text-green-600">Online</span></div>`
        ).join("");
    }

    function updateCommunicationsList() {
        const list = document.getElementById("communications-list");
        list.innerHTML = communications.map(c => `
            <div class="p-3 bg-gray-50 rounded-lg">
                <div class="flex justify-between text-sm">
                    <span>${c.from} → ${c.to}</span>
                    <span>${c.timestamp}</span>
                </div>
                <p class="mt-1 text-gray-700">${c.message}</p>
                <span
                    class="text-xs ${c.status === 'pending' ? 'text-yellow-600' : 'text-green-600'}">${c.status.toUpperCase()}</span>
            </div>
            `).join("");
    }

    function sendMessage() {
        const to = document.getElementById("department-select").value;
        const message = document.getElementById("message-input").value;
        if (to && message) {
            const newComm = {
                id: communications.length + 1,
                from: "Current User", // Replace with actual user data
                to: to,
                message: message,
                timestamp: new Date().toISOString().slice(0, 16).replace("T", " "),
                status: "pending",
                priority: "medium"
            };
            communications.push(newComm);
            updateCommunicationsList();
            document.getElementById("message-input").value = "";
            alert("Message sent successfully!");
        } else {
            alert("Please select a department and enter a message.");
        }
    }

    populateDepartments();
    updateStatusList();
    updateCommunicationsList();

    // Borrowing Tab - Load Requests
    function loadRequests() {
        $.ajax({
            url: 'fetch_requests.php',
            type: 'GET',
            dataType: 'html',
            success: function(data) {
                $('#requests-container').html(data);
            },
            error: function(xhr, status, error) {
                console.error('Load requests error:', status, error, xhr.responseText);
                $('#requests-container').html('<p class="text-red-600">Error loading requests</p>');
            }
        });
    }

    // Approve Request
    window.approveRequest = function(requestId) {
        if (!confirm('Are you sure you want to approve this request?')) {
            return;
        }

        $.ajax({
            url: 'approve_request.php',
            type: 'POST',
            data: {
                request_id: requestId,
                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.success);
                    loadRequests();
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

    // Reject Request
    window.rejectRequest = function(requestId) {
        if (!confirm('Are you sure you want to reject this request?')) {
            return;
        }

        $.ajax({
            url: 'reject_request.php',
            type: 'POST',
            data: {
                request_id: requestId,
                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.success);
                    loadRequests();
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

    // Cancel Request
    window.cancelRequest = function(requestId) {
        if (!confirm('Are you sure you want to cancel this request?')) {
            return;
        }

        $.ajax({
            url: 'cancel_request.php',
            type: 'POST',
            data: {
                request_id: requestId,
                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.success);
                    loadRequests();
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

    // Drug Borrowing Form Submit
    document.getElementById("drug-form").addEventListener("submit", function(e) {
        e.preventDefault();
        const drug = this.querySelector("[name=drug]").value;
        const quantity = this.querySelector("[name=quantity]").value;
        const fromDept = this.querySelector("[name=from_department]").value;
        
        if (!drug || !quantity || !fromDept) {
            alert("Please fill in all fields.");
            return;
        }

        const submitButton = this.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.textContent = 'Submitting...';

        $.ajax({
            url: 'submit_request.php',
            type: 'POST',
            data: {
                drug: drug,
                quantity: quantity,
                from_department: fromDept,
                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.success);
                    e.target.reset();
                    loadRequests();
        } else {
                    alert(response.error || 'Failed to submit request.');
                }
            },
            error: function(xhr, status, error) {
                console.error('Submit request error:', status, error, xhr.responseText);
                alert('Failed to submit request: ' + (xhr.responseJSON?.error || 'Server error'));
            },
            complete: function() {
                submitButton.disabled = false;
                submitButton.textContent = 'Submit';
            }
        });
    });

    // Load requests when borrowing tab is opened
    document.querySelector('[data-tab="borrowing"]').addEventListener('click', function() {
        setTimeout(loadRequests, 100);
    });

    // Load forecast when forecasting tab is opened
    document.querySelector('[data-tab="forecasting"]').addEventListener('click', function() {
        setTimeout(loadForecast, 100);
    });

    // Load Forecast Data (for Admin - shows all departments)
    function loadForecast() {
        $('#forecast-loading').removeClass('hidden');
        $('#forecast-results').addClass('hidden');
        $('#forecast-empty').addClass('hidden');
        
        $.ajax({
            url: 'get_forecast_drugs.php',
            type: 'GET',
            dataType: 'json',
            timeout: 300000, // 5 minutes for admin (processing all departments)
            success: function(response) {
                $('#forecast-loading').addClass('hidden');
                
                if (response.error) {
                    $('#forecast-results').removeClass('hidden');
                    $('#forecast-table-body').html(`<tr><td colspan="9" class="border border-gray-300 px-4 py-2 text-center text-red-600">${response.error}</td></tr>`);
                    return;
                }
                
                if (!response.forecasts || response.forecasts.length === 0) {
                    $('#forecast-results').removeClass('hidden');
                    $('#forecast-empty').removeClass('hidden');
                    return;
                }
                
                let html = '';
                response.forecasts.forEach((forecast, index) => {
                    const cleanName = forecast.drug_name ? forecast.drug_name.replace(/Suppository/gi, '').trim() : forecast.drug_name;
                    const stockClass = forecast.current_stock < forecast.total_demand ? 'text-red-600 font-bold' : 'text-gray-800';
                    
                    // Determine method badge based on method name
                    let methodBadge = 'bg-gray-100 text-gray-800';
                    const method = (forecast.method || '').toLowerCase();
                    if (method.includes('autoets')) {
                        methodBadge = 'bg-purple-100 text-purple-800';
                    } else if (method.includes('holt') || method.includes('winters') || method.includes('exponential')) {
                        methodBadge = 'bg-blue-100 text-blue-800';
                    } else if (method.includes('trend') || method.includes('projection') || method.includes('linear') || method.includes('growth')) {
                        methodBadge = 'bg-green-100 text-green-800';
                    } else if (method.includes('minimal') || method.includes('mean')) {
                        methodBadge = 'bg-yellow-100 text-yellow-800';
                    }
                    
                    // Format method name for display
                    const methodDisplay = forecast.method ? forecast.method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Unknown';
                    
                    html += `
                        <tr class="hover:bg-gray-50">
                            <td class="border border-gray-300 px-4 py-2 font-semibold">${index + 1}</td>
                            <td class="border border-gray-300 px-4 py-2">${escapeHtml(cleanName)}</td>
                            <td class="border border-gray-300 px-4 py-2">${escapeHtml(forecast.department)}</td>
                            <td class="border border-gray-300 px-4 py-2 text-right ${stockClass}">${forecast.current_stock}</td>
                            <td class="border border-gray-300 px-4 py-2 text-right">${forecast.month1}</td>
                            <td class="border border-gray-300 px-4 py-2 text-right">${forecast.month2}</td>
                            <td class="border border-gray-300 px-4 py-2 text-right">${forecast.month3}</td>
                            <td class="border border-gray-300 px-4 py-2 text-right font-bold text-blue-600">${forecast.total_demand}</td>
                            <td class="border border-gray-300 px-4 py-2 text-center"><span class="px-2 py-1 rounded text-xs ${methodBadge}">${methodDisplay}</span></td>
                        </tr>
                    `;
                });
                
                $('#forecast-table-body').html(html);
                $('#forecast-results').removeClass('hidden');
            },
            error: function(xhr, status, error) {
                $('#forecast-loading').addClass('hidden');
                $('#forecast-results').removeClass('hidden');
                $('#forecast-table-body').html(`<tr><td colspan="9" class="border border-gray-300 px-4 py-2 text-center text-red-600">Error loading forecast: ${error}. Please ensure the prediction API is running.</td></tr>`);
                console.error('Forecast load error:', status, error, xhr.responseText);
            }
        });
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Initial load if borrowing tab is active
    if (document.getElementById('borrowing') && !document.getElementById('borrowing').classList.contains('hidden')) {
        loadRequests();
    }
    </script>


    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
    document.getElementById("forecastCard").addEventListener("click", async () => {
        const drugName = prompt("Enter the exact drug name (e.g. Albendazole 400 mg Tablets):");
        if (!drugName) return;

        const outputDiv = document.getElementById("forecastOutput");
        const resultDiv = document.getElementById("forecastResults");
        const chartCanvas = document.getElementById("forecastChart");
        outputDiv.classList.remove("hidden");

        resultDiv.innerHTML =
            `<p class="text-gray-500 mt-4">⏳ Running prediction for <b>${drugName}</b>...</p>`;

        try {
            const response = await fetch(
                `http://127.0.0.1:8000/predict/${encodeURIComponent(drugName)}`);
            const data = await response.json();

            if (data.error) {
                resultDiv.innerHTML = `<p class="text-red-600 mt-4">⚠️ ${data.error}</p>`;
                return;
            }

            // Render chart
            const ctx = chartCanvas.getContext("2d");
            if (window.forecastChart) window.forecastChart.destroy();

            window.forecastChart = new Chart(ctx, {
                type: "line",
                data: {
                    labels: data.months,
                    datasets: [{
                        label: `${data.drug || drugName} Forecast`,
                        data: data.predictions,
                        borderColor: "rgb(139, 92, 246)",
                        borderWidth: 2,
                        fill: false,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: `3-Month Forecast for ${data.drug || drugName}`
                        },
                        legend: {
                            display: false
                        }
                    }
                }
            });

            resultDiv.innerHTML =
                `<p class="text-gray-700 mt-4">✔ Forecast generated using AutoETS model.</p>`;

        } catch (err) {
            console.error(err);
            resultDiv.innerHTML =
                `<p class="text-red-600 mt-4">⚠️ Could not connect to FastAPI backend. Ensure it's running at port 8000.</p>`;
        }
    });
    </script>

    < /body>

        < /html>