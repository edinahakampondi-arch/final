<?php
// checkout_drug.php
session_start();
require_once '../connect.php';
header('Content-Type: application/json');
date_default_timezone_set('Africa/Kampala');

// Store name in session as backup
if (!isset($_SESSION['user_name'])) {
    $user_query = "SELECT name FROM users WHERE id = ? AND department = ?";
    $user_stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($user_stmt, "is", $_SESSION['user_id'], $_SESSION['department']);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user = mysqli_fetch_assoc($user_result);
    $_SESSION['user_name'] = $user ? $user['name'] : 'Unknown User';
    mysqli_stmt_close($user_stmt);
}
$name = $_SESSION['user_name'];
error_log("Session user name: '$name'");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['drug']) && isset($_POST['quantity']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $drug_name = mysqli_real_escape_string($conn, $_POST['drug']);
    $quantity = intval($_POST['quantity']);
    $department = mysqli_real_escape_string($conn, $_SESSION['department']);

    // Reject zero or negative quantity
    if ($quantity <= 0) {
        echo json_encode(['error' => 'Quantity must be greater than zero.']);
        exit;
    }

    // Ensure name is set
    if (empty($name)) {
        $name = 'Unknown User';
        error_log("Name was empty, set to default: '$name'");
    }
    error_log("Final name value before insert: '$name'");

    // Check current stock
    $check_query = "SELECT current_stock FROM drugs WHERE drug_name = ? AND department = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ss", $drug_name, $department);
    mysqli_stmt_execute($check_stmt);
    $result = mysqli_stmt_get_result($check_stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($check_stmt);

    if ($row && $row['current_stock'] >= $quantity) {
        $new_stock = $row['current_stock'] - $quantity;

        // Get drug_id for the log (needed if table uses drug_id column)
        $drug_id_query = "SELECT drug_id FROM drugs WHERE drug_name = ? AND department = ? LIMIT 1";
        $drug_id_stmt = mysqli_prepare($conn, $drug_id_query);
        $drug_id = null;
        if ($drug_id_stmt) {
            mysqli_stmt_bind_param($drug_id_stmt, "ss", $drug_name, $department);
            if (mysqli_stmt_execute($drug_id_stmt)) {
                $drug_id_result = mysqli_stmt_get_result($drug_id_stmt);
                $drug_id_row = mysqli_fetch_assoc($drug_id_result);
                if ($drug_id_row) {
                    $drug_id = $drug_id_row['drug_id'];
                }
            }
            mysqli_stmt_close($drug_id_stmt);
        }

        // Start transaction
        mysqli_begin_transaction($conn);

        // Update stock
        $update_query = "UPDATE drugs SET current_stock = ? WHERE drug_name = ? AND department = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "iss", $new_stock, $drug_name, $department);
        $update_success = mysqli_stmt_execute($update_stmt);
        if (!$update_success) {
            error_log("Stock update failed: " . mysqli_error($conn));
        } else {
            error_log("Stock updated: drug=$drug_name, new_stock=$new_stock, department=$department");
        }
        mysqli_stmt_close($update_stmt);

        // Log checkout - try multiple schema options
        $checkout_time = date('Y-m-d H:i:s');
        $log_success = false;
        
        // First try: drug_name and quantity (if table has these columns)
        if (!$log_success) {
            $log_query = "INSERT INTO drug_checkouts (drug_name, quantity, name, department, checkout_time) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            if ($log_stmt) {
                mysqli_stmt_bind_param($log_stmt, "sisss", $drug_name, $quantity, $name, $department, $checkout_time);
                $log_success = mysqli_stmt_execute($log_stmt);
                if ($log_success) {
                    $last_id = mysqli_insert_id($conn);
                    error_log("Checkout logged (drug_name): id=$last_id, drug=$drug_name, quantity=$quantity, name='$name', department=$department, time=$checkout_time");
                } else {
                    error_log("Failed to log checkout with drug_name: " . mysqli_error($conn) . " | " . mysqli_stmt_error($log_stmt));
                }
                mysqli_stmt_close($log_stmt);
            }
        }
        
        // Second try: drug_id and quantity_dispensed (if first failed and drug_id exists)
        if (!$log_success && $drug_id !== null) {
            $log_query = "INSERT INTO drug_checkouts (drug_id, quantity_dispensed, name, department, checkout_time) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            if ($log_stmt) {
                mysqli_stmt_bind_param($log_stmt, "sisss", $drug_id, $quantity, $name, $department, $checkout_time);
                $log_success = mysqli_stmt_execute($log_stmt);
                if ($log_success) {
                    $last_id = mysqli_insert_id($conn);
                    error_log("Checkout logged (drug_id): id=$last_id, drug_id=$drug_id, quantity=$quantity, name='$name', department=$department, time=$checkout_time");
                } else {
                    error_log("Failed to log checkout with drug_id: " . mysqli_error($conn) . " | " . mysqli_stmt_error($log_stmt));
                }
                mysqli_stmt_close($log_stmt);
            }
        }
        
        // Third try: drug_id and quantity_dispensed with drug_name (if table has all columns)
        if (!$log_success && $drug_id !== null) {
            $log_query = "INSERT INTO drug_checkouts (drug_id, drug_name, quantity_dispensed, name, department, checkout_time) VALUES (?, ?, ?, ?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            if ($log_stmt) {
                mysqli_stmt_bind_param($log_stmt, "ssisss", $drug_id, $drug_name, $quantity, $name, $department, $checkout_time);
                $log_success = mysqli_stmt_execute($log_stmt);
                if ($log_success) {
                    $last_id = mysqli_insert_id($conn);
                    error_log("Checkout logged (both): id=$last_id, drug_id=$drug_id, drug=$drug_name, quantity=$quantity, name='$name', department=$department, time=$checkout_time");
                } else {
                    error_log("Failed to log checkout with both: " . mysqli_error($conn) . " | " . mysqli_stmt_error($log_stmt));
                }
                mysqli_stmt_close($log_stmt);
            }
        }

        if (!$log_success) {
            error_log("All checkout log insert attempts failed. Check table structure.");
        }

        if ($update_success && $log_success) {
            mysqli_commit($conn);
            echo json_encode(['success' => 'Drug checked out successfully. New stock: ' . $new_stock]);
        } else {
            mysqli_rollback($conn);
            error_log("Transaction rolled back: update_success=$update_success, log_success=" . ($log_success ? 'true' : 'false'));
            $error_msg = 'Failed to process checkout: ';
            if (!$update_success) {
                $error_msg .= 'Stock update failed. ';
            }
            if (!$log_success) {
                $error_msg .= 'Log insert failed. ' . mysqli_error($conn);
            }
            echo json_encode(['error' => trim($error_msg)]);
        }
    } else {
        echo json_encode(['error' => 'Insufficient stock or drug not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid request']);
}
