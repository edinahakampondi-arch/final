<?php
// get_recent_communications.php
session_start();
require_once '../connect.php';
header('Content-Type: application/json');

if (!isset($_SESSION['department'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_department = mysqli_real_escape_string($conn, $_SESSION['department']);

// Debug: Check connection
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error());
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Prepare and execute query with error checking
$query = "SELECT id, from_department, to_department, message, timestamp, is_read, priority, status 
          FROM communications 
          WHERE to_department = ? OR from_department = ? 
          ORDER BY timestamp DESC LIMIT 10";
$stmt = mysqli_prepare($conn, $query);
if ($stmt === false) {
    error_log("Prepare failed: " . mysqli_error($conn));
    echo json_encode(['error' => 'Query preparation failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ss", $user_department, $user_department);
if (!mysqli_stmt_execute($stmt)) {
    error_log("Execute failed: " . mysqli_error($conn));
    echo json_encode(['error' => 'Query execution failed: ' . mysqli_error($conn)]);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $messages[] = $row;
}
echo json_encode($messages);
mysqli_stmt_close($stmt);
