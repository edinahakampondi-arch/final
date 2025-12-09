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

$query = "SELECT drug_name, quantity, from_department, to_department, status, expiry_date 
          FROM borrowing_requests WHERE request_id = ?";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    error_log("Fetch Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: Failed to prepare query']);
    exit;
}
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if (!$result) {
    mysqli_stmt_close($stmt);
    error_log("Fetch Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: Failed to fetch request']);
    exit;
}
if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    http_response_code(400);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Debug logging for quantity tracking
error_log("DEBUG: approve_request.php - Retrieved request: id={$request['request_id']}, quantity={$request['quantity']}, status={$request['status']}");

if ($request['status'] !== 'Pending') {
    http_response_code(400);
    echo json_encode(['error' => 'Request already processed']);
    exit;
}

// Admin can approve any request, otherwise only the lending department can approve
if ($_SESSION['department'] !== 'Admin' && $_SESSION['department'] !== $request['from_department']) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the lending department or Admin can approve this request']);
    exit;
}

$query = "SELECT current_stock FROM drugs 
          WHERE drug_name = ? AND department = ?";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    error_log("Fetch Stock Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: Failed to prepare stock query']);
    exit;
}
mysqli_stmt_bind_param($stmt, "ss", $request['drug_name'], $request['from_department']);
if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    error_log("Fetch Stock Error: " . mysqli_stmt_error($stmt));
    http_response_code(500);
    echo json_encode(['error' => 'Database error: Failed to fetch stock']);
    exit;
}
$result = mysqli_stmt_get_result($stmt);
if (!$result || mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    http_response_code(400);
    echo json_encode(['error' => 'Drug not found in the lending department']);
    exit;
}

$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if ($row['current_stock'] < $request['quantity']) {
    http_response_code(400);
    echo json_encode(['error' => 'Insufficient stock in the lending department']);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // Log lending stock before update
    $lending_stock_before = $row['current_stock'];
    error_log("DEBUG: approve_request.php - Lending department '{$request['from_department']}' stock before update: {$lending_stock_before}");

    // Update lending department stock
    $query = "UPDATE drugs SET current_stock = current_stock - ?
              WHERE drug_name = ? AND department = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Failed to prepare lending department update: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "iss", $request['quantity'], $request['drug_name'], $request['from_department']);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception('Failed to update lending department stock: ' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);

    error_log("DEBUG: approve_request.php - Deducted {$request['quantity']} from lending department '{$request['from_department']}'");

    // Check if drug exists in borrowing department (use drug_id instead of id)
    $query = "SELECT drug_id FROM drugs 
              WHERE drug_name = ? AND department = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Failed to prepare borrowing department check: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "ss", $request['drug_name'], $request['to_department']);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception('Failed to check borrowing department stock: ' . mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    $drug_exists = mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    if ($drug_exists) {
        error_log("DEBUG: approve_request.php - Drug exists in borrowing department '{$request['to_department']}', adding {$request['quantity']} to existing stock");

        // Update existing drug
        $query = "UPDATE drugs SET current_stock = current_stock + ?
                  WHERE drug_name = ? AND department = ?";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            throw new Exception('Failed to prepare borrowing department update: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "iss", $request['quantity'], $request['drug_name'], $request['to_department']);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception('Failed to update borrowing department stock: ' . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        error_log("DEBUG: approve_request.php - Added {$request['quantity']} to borrowing department '{$request['to_department']}' existing stock");
    } else {
        error_log("DEBUG: approve_request.php - Drug does not exist in borrowing department '{$request['to_department']}', inserting new drug with quantity {$request['quantity']}");

        // Insert new drug (without category and stock_level columns that were removed)
        $query = "INSERT INTO drugs (drug_name, department, current_stock, expiry_date)
                  VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            throw new Exception('Failed to prepare borrowing department insert: ' . mysqli_error($conn));
        }
        $expiry_date = $request['expiry_date'] ?: null;
        mysqli_stmt_bind_param($stmt, "ssis", $request['drug_name'], $request['to_department'], $request['quantity'], $expiry_date);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception('Failed to insert borrowing department drug: ' . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        error_log("DEBUG: approve_request.php - Inserted new drug in borrowing department '{$request['to_department']}' with quantity {$request['quantity']}");
    }

    // Update request status
    $approved_time = date('Y-m-d H:i:s');
    $query = "UPDATE borrowing_requests SET status = 'Approved', approved_time = ? 
              WHERE request_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Failed to prepare status update: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "si", $approved_time, $request_id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception('Failed to update request status: ' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);

    mysqli_commit($conn);
    echo json_encode(['success' => 'Request approved and stock updated']);
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Approve Request Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>