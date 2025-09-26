<?php
// send_message.php
session_start();
require_once '../connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['to_department']) && isset($_POST['message']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $from_department = mysqli_real_escape_string($conn, $_SESSION['department']);
    $to_department = mysqli_real_escape_string($conn, $_POST['to_department']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    $priority = mysqli_real_escape_string($conn, $_POST['priority']);
    $status = 'sent';

    $query = "INSERT INTO communications (from_department, to_department, message, timestamp, is_read, priority, status) 
              VALUES (?, ?, ?, NOW(), 0, ?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sssss", $from_department, $to_department, $message, $priority, $status);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => 'Message sent successfully']);
    } else {
        echo json_encode(['error' => 'Failed to send message']);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['error' => 'Invalid request']);
}
