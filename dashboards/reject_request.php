<?php
// reject_request.php
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

$query = "SELECT status, from_department FROM borrowing_requests WHERE request_id = $request_id";
$result = mysqli_query($conn, $query);
if (!$result || mysqli_num_rows($result) === 0) {
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

// Admin can reject any request, otherwise only the lending department can reject
if ($_SESSION['department'] !== 'Admin' && $_SESSION['department'] !== $request['from_department']) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the lending department or Admin can reject this request']);
    exit;
}

$rejected_time = date('Y-m-d H:i:s');
$query = "UPDATE borrowing_requests SET status = 'Rejected', approved_time = '$rejected_time' 
          WHERE request_id = $request_id";
if (mysqli_query($conn, $query)) {
    echo json_encode(['success' => 'Request rejected successfully']);
} else {
    error_log("Reject Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>