<?php
// submit_request.php
require_once '../connect.php';
session_start();

if (!isset($_SESSION['department'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$drug = isset($_POST['drug']) ? mysqli_real_escape_string($conn, $_POST['drug']) : '';
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
$from_department = isset($_POST['from_department']) ? mysqli_real_escape_string($conn, $_POST['from_department']) : '';
$to_department = mysqli_real_escape_string($conn, $_SESSION['department']);

if (empty($drug) || $quantity <= 0 || empty($from_department)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

if ($from_department === $to_department) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot borrow from the same department']);
    exit;
}

// Fetch drug details
$query = "SELECT current_stock, expiry_date, min_stock, max_stock FROM drugs 
          WHERE drug_name = '$drug' AND department = '$from_department'";
$result = mysqli_query($conn, $query);
if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Drug not found in the selected department']);
    exit;
}

$row = mysqli_fetch_assoc($result);
if ($row['current_stock'] < $quantity) {
    http_response_code(400);
    echo json_encode(['error' => 'Insufficient stock in the lending department']);
    exit;
}

$expiry_date = $row['expiry_date'] ? "'".mysqli_real_escape_string($conn, $row['expiry_date'])."'" : 'NULL';
$min_stock = (int)$row['min_stock'];
$max_stock = (int)$row['max_stock'];

$request_time = date('Y-m-d H:i:s');
$query = "INSERT INTO borrowing_requests (drug_name, quantity, from_department, to_department, status, request_time, expiry_date, min_stock, max_stock)
          VALUES ('$drug', $quantity, '$from_department', '$to_department', 'Pending', '$request_time', $expiry_date, $min_stock, $max_stock)";
if (mysqli_query($conn, $query)) {
    echo json_encode(['success' => 'Request submitted successfully']);
} else {
    error_log("Submit Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>