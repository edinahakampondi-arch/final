<?php
// mark_as_read.php
session_start();
require_once '../connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_id']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $message_id = mysqli_real_escape_string($conn, $_POST['message_id']);
    $query = "UPDATE communications SET is_read = 1 WHERE id = ? AND to_department = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $message_id, $_SESSION['department']);
    if (mysqli_stmt_execute($stmt)) {
        // Update sender's status to 'Read' if this is the receiver marking it
        $check_query = "SELECT from_department FROM communications WHERE id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $message_id);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        $sender = mysqli_fetch_assoc($result)['from_department'];
        mysqli_stmt_close($check_stmt);

        if ($sender) {
            $update_sender_query = "UPDATE communications SET status = 'read' WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_sender_query);
            mysqli_stmt_bind_param($update_stmt, "i", $message_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Failed to mark as read']);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['error' => 'Invalid request']);
}
