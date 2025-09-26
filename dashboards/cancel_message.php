<?php
// cancel_message.php
session_start();
require_once '../connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_id']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $message_id = mysqli_real_escape_string($conn, $_POST['message_id']);
    $query = "DELETE FROM communications WHERE id = ? AND from_department = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $message_id, $_SESSION['department']);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to cancel or message too old']);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['error' => 'Invalid request']);
}
