<?php
// cancel_request.php
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

$query = "SELECT status, to_department FROM borrowing_requests WHERE request_id = $request_id";
$result = mysqli_query($conn, $query);
if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

$request = mysqli_fetch_assoc($result);
if ($request['status'] !== 'Pending') {
    http_response_code(400);
    echo json_encode(['error' => 'Only pending requests can be canceled']);
    exit;
}

if ($_SESSION['department'] !== $request['to_department']) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the requesting department can cancel this request']);
    exit;
}

$query = "DELETE FROM borrowing_requests WHERE request_id = $request_id";
if (mysqli_query($conn, $query)) {
    echo json_encode(['success' => 'Request canceled successfully']);
} else {
    error_log("Cancel Request Error: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>