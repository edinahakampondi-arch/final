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

// Debug logging
error_log("DEBUG: submit_request.php called with: drug=$drug, quantity=$quantity, from_department=$from_department, to_department=$to_department");

if (empty($drug) || $quantity <= 0 || empty($from_department)) {
    error_log("DEBUG: Invalid input validation failed: drug='$drug', quantity=$quantity, from_department='$from_department'");
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
$query = "SELECT current_stock, expiry_date FROM drugs 
          WHERE drug_name = ? AND department = ?";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query error']);
    exit;
}

mysqli_stmt_bind_param($stmt, "ss", $drug, $from_department);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Drug not found in the selected department']);
    mysqli_stmt_close($stmt);
    exit;
}

$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($row['current_stock'] < $quantity) {
    http_response_code(400);
    echo json_encode(['error' => 'Insufficient stock in the lending department']);
    exit;
}

$expiry_date = $row['expiry_date'] ? $row['expiry_date'] : null;

$request_time = date('Y-m-d H:i:s');
$query = "INSERT INTO borrowing_requests (drug_name, quantity, from_department, to_department, status, request_time, expiry_date)
          VALUES (?, ?, ?, ?, 'Pending', ?, ?)";
$insert_stmt = mysqli_prepare($conn, $query);
if (!$insert_stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database insert error']);
    exit;
}

mysqli_stmt_bind_param($insert_stmt, "sissss", $drug, $quantity, $from_department, $to_department, $request_time, $expiry_date);
$success = mysqli_stmt_execute($insert_stmt);
mysqli_stmt_close($insert_stmt);

if ($success) {
    echo json_encode(['success' => 'Request submitted successfully']);
} else {
    error_log("Submit Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
}
?>