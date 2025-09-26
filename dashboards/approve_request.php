<?php
// approve_request.php
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

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
if ($request_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request ID']);
    exit;
}

$query = "SELECT drug_name, quantity, from_department, to_department, status, expiry_date, min_stock, max_stock 
          FROM borrowing_requests WHERE id = $request_id";
$result = mysqli_query($conn, $query);
if (!$result) {
    error_log("Fetch Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: Failed to fetch request']);
    exit;
}
if (mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

$request = mysqli_fetch_assoc($result);
if ($request['status'] !== 'Pending') {
    http_response_code(400);
    echo json_encode(['error' => 'Request already processed']);
    exit;
}

if ($_SESSION['department'] !== $request['from_department']) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the lending department can approve this request']);
    exit;
}

$query = "SELECT current_stock FROM drugs 
          WHERE drug_name = '" . mysqli_real_escape_string($conn, $request['drug_name']) . "' 
          AND department = '" . mysqli_real_escape_string($conn, $request['from_department']) . "'";
$result = mysqli_query($conn, $query);
if (!$result) {
    error_log("Fetch Stock Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: Failed to fetch stock']);
    exit;
}
if (mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Drug not found in the lending department']);
    exit;
}

$row = mysqli_fetch_assoc($result);
if ($row['current_stock'] < $request['quantity']) {
    http_response_code(400);
    echo json_encode(['error' => 'Insufficient stock in the lending department']);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // Update lending department stock
    $query = "UPDATE drugs SET current_stock = current_stock - {$request['quantity']} 
              WHERE drug_name = '" . mysqli_real_escape_string($conn, $request['drug_name']) . "' 
              AND department = '" . mysqli_real_escape_string($conn, $request['from_department']) . "'";
    if (!mysqli_query($conn, $query)) {
        throw new Exception('Failed to update lending department stock: ' . mysqli_error($conn));
    }

    // Check if drug exists in borrowing department
    $query = "SELECT id FROM drugs 
              WHERE drug_name = '" . mysqli_real_escape_string($conn, $request['drug_name']) . "' 
              AND department = '" . mysqli_real_escape_string($conn, $request['to_department']) . "'";
    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception('Failed to check borrowing department stock: ' . mysqli_error($conn));
    }

    $expiry_date = $request['expiry_date'] ? "'".mysqli_real_escape_string($conn, $request['expiry_date'])."'" : 'NULL';
    if (mysqli_num_rows($result) > 0) {
        $query = "UPDATE drugs SET current_stock = current_stock + {$request['quantity']} 
                  WHERE drug_name = '" . mysqli_real_escape_string($conn, $request['drug_name']) . "' 
                  AND department = '" . mysqli_real_escape_string($conn, $request['to_department']) . "'";
    } else {
        $query = "INSERT INTO drugs (drug_name, department, current_stock, min_stock, max_stock, expiry_date, category, stock_level) 
                  VALUES ('" . mysqli_real_escape_string($conn, $request['drug_name']) . "', 
                          '" . mysqli_real_escape_string($conn, $request['to_department']) . "', 
                          {$request['quantity']}, 
                          {$request['min_stock']}, 
                          {$request['max_stock']}, 
                          $expiry_date, 
                          'Unknown', 
                          0)";
    }
    if (!mysqli_query($conn, $query)) {
        throw new Exception('Failed to update borrowing department stock: ' . mysqli_error($conn));
    }

    // Update request status
    $approved_time = date('Y-m-d H:i:s');
    $query = "UPDATE borrowing_requests SET status = 'Approved', approved_time = '$approved_time' 
              WHERE id = $request_id";
    if (!mysqli_query($conn, $query)) {
        throw new Exception('Failed to update request status: ' . mysqli_error($conn));
    }

    mysqli_commit($conn);
    echo json_encode(['success' => 'Request approved and stock updated']);
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Approve Request Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>