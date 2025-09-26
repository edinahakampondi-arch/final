<?php
// get_department_status.php
session_start();
require_once '../connect.php';

header('Content-Type: application/json');

// Simulate logged-in departments (replace with actual session tracking if available)
$logged_in = [];
$valid_departments = ['Internal Medicine', 'Surgery', 'Paediatrics', 'Obstetrics', 'Gynaecology', 'Admin'];

// Check active sessions (simplified; adjust based on your session management)
foreach ($valid_departments as $dept) {
    if (isset($_SESSION['department']) && $_SESSION['department'] === $dept) {
        $logged_in[] = $dept;
    }
    // Additional check for other sessions (e.g., via database or file)
    $session_file = session_save_path() . "/sess_" . md5($dept);
    if (file_exists($session_file) && (time() - filemtime($session_file) < 1800)) { // 30-minute timeout
        $logged_in[] = $dept;
    }
}

echo json_encode(['logged_in' => array_unique($logged_in)]);
