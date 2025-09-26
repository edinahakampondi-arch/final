<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Include database connection
require_once '../connect.php';

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

// Fetch critical stock (expiring within 30 days)
$result_critical = mysqli_query($conn, "SELECT COUNT(*) AS critical_stock FROM drugs WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
$critical_stock = $result_critical ? mysqli_fetch_assoc($result_critical)['critical_stock'] : 'Error';
if (!$result_critical) {
    error_log("Database Error (critical stock): " . mysqli_error($conn));
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
    $min_stock = (int)$_POST['min_stock'];
    $max_stock = (int)$_POST['max_stock'];
    $expiry_date = mysqli_real_escape_string($conn, $_POST['expiry_date']);
    $stock_level = ($max_stock > 0) ? ($current_stock / $max_stock) * 100 : 0;

    $sql = "INSERT INTO drugs (drug_name, category, department, current_stock, min_stock, max_stock, expiry_date, stock_level) 
            VALUES ('$drug_name', '$category', '$department', $current_stock, $min_stock, $max_stock, '$expiry_date', $stock_level)";
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
                'min_stock' => $min_stock,
                'max_stock' => $max_stock,
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
                    <div id="total-drugs" class="text-2xl font-bold text-blue-600"><?php echo htmlspecialchars($total_drugs); ?></div>
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
            <div class="bg-white shadow-lg hover:shadow-xl transition-shadow rounded-lg">
                <div class="flex flex-row items-center justify-between p-4">
                    <h3 class="text-sm font-medium">Pending Orders</h3>
                    <i data-lucide="trending-up" class="h-4 w-4 text-orange-600"></i>
                </div>
                <div class="p-4 pt-0">
                    <div class="text-2xl font-bold text-orange-600"><?php echo htmlspecialchars($pending_orders); ?></div>
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
                        <div class="bg-purple-600 h-2.5 rounded-full" style="width: 57%"></div>
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
                        <span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-0.5 rounded">WARNING</span>
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
                <button class="tab-button flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium rounded-md" data-tab="admin">
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
                        <div id="successAlert" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 hidden" role="alert">
                            <span id="successMessage" class="block sm:inline"></span>
                            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                                <svg class="fill-current h-6 w-6 text-green-500" role="button" onclick="document.getElementById('successAlert').classList.add('hidden')" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path d="M10 8.586L2.929 1.515 1.515 2.929 8.586 10l-7.071 7.071 1.414 1.414L10 11.414l7.071 7.071 1.414-1.414L11.414 10l7.071-7.071-1.414-1.414L10 8.586z" />
                                </svg>
                            </span>
                        </div>

                        <!-- Header Card -->
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <div class="flex items-center gap-2 mb-2">
                                <i data-lucide="package" class="h-5 w-5 text-blue-600"></i>
                                <h2 class="text-xl font-bold">Real-Time Inventory Monitoring</h2>
                            </div>
                            <p class="text-sm text-gray-600">Track and monitor drug inventory across all hospital departments</p>
                        </div>

                        <!-- Add Drug Form -->
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <h3 class="text-lg font-semibold mb-4">Add New Drug</h3>
                            <form id="add-drug-form" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm text-gray-600">Drug Name</label>
                                    <input type="text" name="drug_name" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Category</label>
                                    <input type="text" name="category" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Department</label>
                                    <div>
                                        <select name="department" class="w-full p-2 border border-gray-300 rounded-md" required>
                                            <option value="">-- Select Department --</option>
                                            <option value="Internal medicine">Internal medicine</option>
                                            <option value="Surgery">Surgery</option>
                                            <option value="Paediatrics">Paediatrics</option>
                                            <option value="Obstetrics">Obstetrics</option>
                                            <option value="Gynaecology">Gynaecology</option>
                                            <option value="Intensive Care unit">Intensive Care unit</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Current Stock</label>
                                    <input type="number" name="current_stock" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Min Stock</label>
                                    <input type="number" name="min_stock" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Max Stock</label>
                                    <input type="number" name="max_stock" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div>
                                    <label class="text-sm text-gray-600">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                </div>
                                <div class="md:col-span-2">
                                    <button type="submit" name="add_drug" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Add Drug</button>
                                </div>
                            </form>
                        </div>

                        <!-- Live Search Section -->
                        <div class="bg-white rounded-lg shadow p-6 mb-6">
                            <h3 class="text-lg font-semibold mb-4">Search Drugs</h3>
                            <div class="relative">
                                <i data-lucide="search" class="absolute left-3 top-3 h-4 w-4 text-gray-400"></i>
                                <input type="text" id="search-input" placeholder="Type to search drugs..." class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" onkeyup="liveSearchDrugs()">
                            </div>
                            <div id="search-results" class="mt-2 max-h-40 overflow-y-auto bg-white border border-gray-200 rounded-md shadow-lg hidden"></div>
                        </div>

                        <!-- Inventory Cards -->
                        <div id="inventory-cards" class="space-y-4 ">
                            <?php
                            $sql = "SELECT * FROM drugs";
                            $result = $conn->query($sql);
                            if ($result) {
                                while ($row = $result->fetch_assoc()) {
                                    $status = $row['current_stock'] < $row['min_stock'] ? 'LOW' : 'NORMAL';
                                    $status_color = $row['current_stock'] < $row['min_stock'] ? 'bg-red-100 text-red-800 border-red-200' : 'bg-green-100 text-green-800 border-green-200';
                                    $trend_icon = $row['current_stock'] < $row['min_stock'] ? 'trending-down' : 'trending-up';
                                    $trend_color = $row['current_stock'] < $row['min_stock'] ? 'text-red-600' : 'text-green-600';
                            ?>
                                    <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6 ">
                                        <div class="flex justify-between items-start mb-4">
                                            <div>
                                                <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($row['drug_name']); ?></h3>
                                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($row['category'] . ' • ' . $row['department']); ?></p>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="<?php echo $status_color; ?> text-xs px-2 py-1 rounded"><?php echo $status; ?></span>
                                                <i data-lucide="<?php echo $trend_icon; ?>" class="h-4 w-4 <?php echo $trend_color; ?>"></i>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                            <div>
                                                <p class="text-sm text-gray-600">Current Stock</p>
                                                <p class="text-2xl font-bold"><?php echo $row['current_stock']; ?></p>
                                            </div>
                                            <div>
                                                <p class="text-sm text-gray-600">Stock Range</p>
                                                <p class="text-sm"><?php echo $row['min_stock'] . ' - ' . $row['max_stock'] . ' units'; ?></p>
                                            </div>
                                            <div>
                                                <p class="text-sm text-gray-600">Expiry Date</p>
                                                <p class="text-sm"><?php echo $row['expiry_date']; ?></p>
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            <div class="flex justify-between text-sm">
                                                <span>Stock Level</span>
                                                <span><?php echo round($row['stock_level']); ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                                                <div class="bg-green-500 h-2" style="width: <?php echo $row['stock_level']; ?>%;"></div>
                                            </div>
                                        </div>
                                        <div class="mt-4 flex gap-2">
                                            <button onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['drug_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['department'], ENT_QUOTES); ?>', <?php echo $row['current_stock']; ?>, <?php echo $row['min_stock']; ?>, <?php echo $row['max_stock']; ?>, '<?php echo $row['expiry_date']; ?>')" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600">Edit</button>
                                            <a href="?delete_id=<?php echo $row['id']; ?>" class="delete-drug bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600" onclick="return false;">Delete</a>
                                        </div>
                                    </div>
                            <?php
                                }
                            } else {
                                error_log("Fetch Drugs Error: " . mysqli_error($conn));
                                echo "<p class='text-red-600 text-sm'>Error fetching drugs: " . mysqli_error($conn) . "</p>";
                            }
                            ?>
                        </div>

                        <!-- Edit Drug Modal -->
                        <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center">
                            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                                <h3 class="text-lg font-semibold mb-4">Edit Drug</h3>
                                <form action="" method="POST">
                                    <input type="hidden" name="id" id="edit_id">
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Drug Name</label>
                                        <input type="text" name="drug_name" id="edit_drug_name" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Category</label>
                                        <input type="text" name="category" id="edit_category" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Department</label>
                                        <input type="text" name="department" id="edit_department" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Current Stock</label>
                                        <input type="number" name="current_stock" id="edit_current_stock" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Min Stock</label>
                                        <input type="number" name="min_stock" id="edit_min_stock" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Max Stock</label>
                                        <input type="number" name="max_stock" id="edit_max_stock" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Expiry Date</label>
                                        <input type="date" name="expiry_date" id="edit_expiry_date" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" name="update_drug" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Update</button>
                                        <button type="button" onclick="closeEditModal()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
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
                                    <p class="text-sm text-gray-500">Communicate with other departments about drug needs</p>
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
                                        <textarea id="message-input" rows="4" class="w-full border rounded px-3 py-2" placeholder="Type your message here..."></textarea>
                                    </div>
                                    <div class="flex gap-2">
                                        <button onclick="sendMessage()" class="bg-blue-600 text-white px-4 py-2 rounded flex items-center gap-2 flex-1">
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
                                    <p class="text-sm text-gray-500">Current status of all connected departments</p>
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

            <!-- Tab Content: Forecasting -->
            <section id="forecasting" class="tab-content hidden">
                <div class="bg-white shadow-lg rounded-lg p-4">
                    <h2 class="text-xl font-semibold">Demand Forecasting</h2>
                    <p class="text-gray-600">Soon coming</p>
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
                                <div>
                                    <label class="text-sm font-medium block mb-1">Drug</label>
                                    <select name="drug" class="w-full border rounded px-3 py-2" required>
                                        <option value="">Select drug</option>
                                        <?php foreach ($drugs as $s): ?>
                                            <option><?php echo htmlspecialchars($s['drug_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-sm font-medium block mb-1">Quantity</label>
                                    <input type="number" name="quantity" class="w-full border rounded px-3 py-2" required />
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
                                <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded w-full">Submit</button>
                            </form>
                        </div>
                        <!-- Available Supplies -->
                        <div class="bg-white border rounded-xl p-6 shadow max-h-80 overflow-y-auto">
                            <h2 class="text-lg font-bold text-green-600 mb-4">📦 Available Supplies</h2>
                            <div class="space-y-3">
                                <?php foreach ($drugs as $s): ?>
                                    <div class="border rounded p-4 shadow-sm">
                                        <div class="flex justify-between items-center">
                                            <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($s['drug_name']); ?></h3>
                                            <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($s['department']); ?></h3>
                                            <p class="text-sm text-gray-600"><?php echo intval($s['current_stock']); ?> units</p>
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
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0-1.657 1.343-3 3-3s3 1.343 3 3-1.343 3-3 3-3-1.343-3-3z M17 16H7v-2a4 4 0 014-4h2a4 4 0 014 4v2z" />
                                </svg>
                                Admin Dashboard
                            </div>
                            <p class="text-sm text-gray-500">System administration and user management</p>
                        </div>

                        <!-- Admin Tabs -->
                        <div class="space-y-4">
                            <!-- Tab Buttons -->
                            <div class="admin-tabs grid grid-cols-4 gap-2 bg-white rounded-xl overflow-hidden shadow">
                                <button data-tab="users" class="admin-tab-btn px-4 py-2 flex items-center gap-2 text-sm font-medium w-full hover:bg-gray-100">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20h6M3 20h5v-2a4 4 0 00-4-4H3M16 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    User Management
                                </button>
                                <button data-tab="add-user" class="admin-tab-btn px-4 py-2 flex items-center gap-2 text-sm font-medium w-full hover:bg-gray-100">
                                    <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
                                                    <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Name</th>
                                                    <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Phone Number</th>
                                                    <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Department</th>
                                                    <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($user = $user_result->fetch_assoc()): ?>
                                                    <tr class="border-t hover:bg-gray-50" data-user-id="<?php echo $user['id']; ?>">
                                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($user['name']); ?></td>
                                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($user['number']); ?></td>
                                                        <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($user['department']); ?></td>
                                                        <td class="px-6 py-4 text-sm text-gray-600">
                                                            <button onclick="openEditUserModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['number'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['department'], ENT_QUOTES); ?>')" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 mr-2">Edit</button>
                                                            <a href="?action=delete_user&user_id=<?php echo $user['id']; ?>" class="delete-user bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600" onclick="return false;">Delete</a>
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
                                <h2 class="text-xl font-semibold text-center text-gray-700 mb-2">Add New User</h2>
                                <p class="text-sm text-gray-500 text-center mb-4">Register a new system user</p>
                                <form id="userForm" class="space-y-4">
                                    <input type="text" name="name" placeholder="Full Name" required class="w-full p-2 border rounded" />
                                    <input type="text" name="username" placeholder="Username" required class="w-full p-2 border rounded" />
                                    <input type="text" name="number" placeholder="Phone Number" required class="w-full p-2 border rounded" />
                                    <input type="email" name="email" placeholder="Email" required class="w-full p-2 border rounded" />
                                    <input type="password" name="password" placeholder="Password" required class="w-full p-2 border rounded" />
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
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Register</button>
                                </form>
                                <div id="responseMsg" class="mt-4 text-center text-sm"></div>
                            </div>
                            <div class="admin-tab-content database hidden bg-white rounded-xl p-6 shadow">
                                <h2 class="text-lg font-semibold text-gray-700 mb-2">Database Management</h2>
                                <p class="text-gray-500">Database statistics and maintenance tools coming soon...</p>
                            </div>
                            <div class="admin-tab-content settings hidden bg-white rounded-xl p-6 shadow">
                                <h2 class="text-lg font-semibold text-gray-700 mb-2">System Settings</h2>
                                <p class="text-gray-500">System settings coming soon...</p>
                            </div>
                        </div>

                        <!-- Edit User Modal -->
                        <div id="editUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center">
                            <div class="bg-white rounded-lg p-6 w-full max-w-md">
                                <h3 class="text-lg font-semibold mb-4">Edit User</h3>
                                <form id="editUserForm" method="POST">
                                    <input type="hidden" name="user_id" id="edit_user_id">
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Name</label>
                                        <input type="text" name="name" id="edit_user_name" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Phone Number</label>
                                        <input type="text" name="number" id="edit_user_number" class="w-full p-2 border border-gray-300 rounded-md" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="text-sm text-gray-600">Department</label>
                                        <select name="department" id="edit_user_department" class="w-full p-2 border border-gray-300 rounded-md" required>
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
                                        <button type="submit" name="update_user" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Update</button>
                                        <button type="button" onclick="closeEditUserModal()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
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
                })
                .then(response => response.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    if (data.length > 0) {
                        resultsDiv.classList.remove('hidden');
                        data.forEach(drug => {
                            const div = document.createElement('div');
                            div.className = 'p-2 hover:bg-gray-100 cursor-pointer border-b';
                            div.textContent = `${drug.drug_name} (${drug.category}, ${drug.department})`;
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
                .catch(() => resultsDiv.innerHTML = '<div class="p-2 text-red-600">Error searching drugs.</div>');
        }

        // Display drug details when selected
        function displayDrugDetails(drug) {
            const inventoryCards = document.getElementById('inventory-cards');
            inventoryCards.innerHTML = `
                <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="font-semibold text-lg">${drug.drug_name}</h3>
                            <p class="text-sm text-gray-600">${drug.category} • ${drug.department}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="${drug.current_stock < drug.min_stock ? 'bg-red-100 text-red-800 border-red-200' : 'bg-green-100 text-green-800 border-green-200'} text-xs px-2 py-1 rounded">${drug.current_stock < drug.min_stock ? 'LOW' : 'NORMAL'}</span>
                            <i data-lucide="${drug.current_stock < drug.min_stock ? 'trending-down' : 'trending-up'}" class="h-4 w-4 ${drug.current_stock < drug.min_stock ? 'text-red-600' : 'text-green-600'}"></i>
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
                            <span>${Math.round(drug.stock_level)}%</span>
                        </div>
                        <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                            <div class="bg-green-500 h-2" style="width: ${drug.stock_level}%;"></div>
                        </div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <button onclick="openEditModal(${drug.id}, '${drug.drug_name.replace(/'/g, "\\'")}', '${drug.category.replace(/'/g, "\\'")}', '${drug.department.replace(/'/g, "\\'")}', ${drug.current_stock}, ${drug.min_stock}, ${drug.max_stock}, '${drug.expiry_date}')" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600">Edit</button>
                        <a href="?delete_id=${drug.id}" class="delete-drug bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600" onclick="return false;">Delete</a>
                    </div>
                </div>
            `;
            lucide.createIcons();
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
                        const status = drug.current_stock < drug.min_stock ? 'LOW' : 'NORMAL';
                        const statusColor = drug.current_stock < drug.min_stock ? 'bg-red-100 text-red-800 border-red-200' : 'bg-green-100 text-green-800 border-green-200';
                        const trendIcon = drug.current_stock < drug.min_stock ? 'trending-down' : 'trending-up';
                        const trendColor = drug.current_stock < drug.min_stock ? 'text-red-600' : 'text-green-600';
                        const card = document.createElement('div');
                        card.className = 'bg-white rounded-lg shadow hover:shadow-md transition-shadow p-6';
                        card.innerHTML = `
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
                                    <span>${Math.round(drug.stock_level)}%</span>
                                </div>
                                <div class="w-full bg-gray-200 h-2 rounded-full overflow-hidden">
                                    <div class="bg-green-500 h-2" style="width: ${drug.stock_level}%;"></div>
                                </div>
                            </div>
                            <div class="mt-4 flex gap-2">
                                <button onclick="openEditModal(${drug.id}, '${drug.drug_name.replace(/'/g, "\\'")}', '${drug.category.replace(/'/g, "\\'")}', '${drug.department.replace(/'/g, "\\'")}', ${drug.current_stock}, ${drug.min_stock}, ${drug.max_stock}, '${drug.expiry_date}')" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600">Edit</button>
                                <a href="?delete_id=${drug.id}" class="delete-drug bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600" onclick="return false;">Delete</a>
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
                    document.getElementById('successMessage').textContent = `Error adding drug: ${error.message}`;
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
                            document.getElementById('successMessage').textContent = `Error deleting drug: ${error.message}`;
                            alert.classList.remove('hidden');
                        });
                }
            }
        });

        // Edit Drug Modal Functions
        function openEditModal(id, drug_name, category, department, current_stock, min_stock, max_stock, expiry_date) {
            document.getElementById('editModal').classList.remove('hidden');
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_drug_name').value = drug_name;
            document.getElementById('edit_category').value = category;
            document.getElementById('edit_department').value = department;
            document.getElementById('edit_current_stock').value = current_stock;
            document.getElementById('edit_min_stock').value = min_stock;
            document.getElementById('edit_max_stock').value = max_stock;
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
                                document.getElementById('successMessage').textContent = data.message;
                                alert.classList.remove('hidden');
                            } else {
                                const alert = document.getElementById('successAlert');
                                document.getElementById('successMessage').textContent = data.message;
                                alert.classList.remove('hidden');
                            }
                        })
                        .catch(error => {
                            console.error('Error deleting user:', error);
                            const alert = document.getElementById('successAlert');
                            document.getElementById('successMessage').textContent = `Error deleting user: ${error.message}`;
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
                                    <button onclick="openEditUserModal(${user.id}, '${user.name.replace(/'/g, "\\'")}', '${user.number.replace(/'/g, "\\'")}', '${user.department.replace(/'/g, "\\'")}')" class="bg-yellow-500 text-white px-3 py-1 rounded-md hover:bg-yellow-600 mr-2">Edit</button>
                                    <a href="?action=delete_user&user_id=${user.id}" class="delete-user bg-red-500 text-white px-3 py-1 rounded-md hover:bg-red-600" onclick="return false;">Delete</a>
                                </td>
                            `;
                        }
                        closeEditUserModal();
                    }
                })
                .catch(error => {
                    console.error('Error updating user:', error);
                    const alert = document.getElementById('successAlert');
                    document.getElementById('successMessage').textContent = `Error updating user: ${error.message}`;
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
                document.querySelector(`.admin-tab-btn[data-tab="${name}"]`).classList.add("bg-gray-200");
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
            list.innerHTML = departments.map(dept => `<div class="flex justify-between"><span>${dept}</span><span class="text-green-600">Online</span></div>`).join("");
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
                    <span class="text-xs ${c.status === 'pending' ? 'text-yellow-600' : 'text-green-600'}">${c.status.toUpperCase()}</span>
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

        // Borrowing Tab
        document.getElementById("drug-form").addEventListener("submit", function(e) {
            e.preventDefault();
            const drug = this.querySelector("[name=drug]").value;
            const quantity = this.querySelector("[name=quantity]").value;
            const fromDept = this.querySelector("[name=from_department]").value;
            if (drug && quantity && fromDept) {
                const request = {
                    id: Date.now(),
                    drug: drug,
                    quantity: quantity,
                    fromDept: fromDept,
                    toDept: "Current Department", // Replace with actual user data
                    status: "Pending",
                    timestamp: new Date().toISOString().slice(0, 16).replace("T", " ")
                };
                const container = document.getElementById("requests-container");
                container.innerHTML += `
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <div class="flex justify-between text-sm">
                            <span>${request.drug} (${request.quantity} units)</span>
                            <span>${request.timestamp}</span>
                        </div>
                        <p class="mt-1 text-gray-700">From: ${request.fromDept} → To: ${request.toDept}</p>
                        <span class="text-xs text-yellow-600">${request.status}</span>
                    </div>
                `;
                this.reset();
                alert("Request submitted successfully!");
            } else {
                alert("Please fill in all fields.");
            }
        });
    </script>
</body>

</html>