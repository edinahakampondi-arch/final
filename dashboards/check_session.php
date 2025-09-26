<?php
// Start session
session_start();

// Set headers to prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header('Content-Type: application/json');

// Check if user is logged in
$logged_in = isset($_SESSION['department']);
if (!$logged_in) {
    error_log("check_session.php: Session invalid, department not set");
}

// Return JSON response
echo json_encode(['logged_in' => $logged_in]);
?>