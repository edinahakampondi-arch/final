<?php
require_once '../connect.php';
header('Content-Type: application/json');

$department = $_GET['department'] ?? '';
if (!$department) {
    echo json_encode([]);
    exit;
}

// Check database connection
if (!$conn) {
    error_log("Database connection failed in fetch_checkouts.php");
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Check table structure to determine which columns exist
$describe_query = "DESCRIBE drug_checkouts";
$desc_result = mysqli_query($conn, $describe_query);
$columns = [];
if ($desc_result) {
    while ($desc_row = mysqli_fetch_assoc($desc_result)) {
        $columns[] = $desc_row['Field'];
    }
}

$has_drug_name = in_array('drug_name', $columns);
$has_quantity = in_array('quantity', $columns);
$has_quantity_dispensed = in_array('quantity_dispensed', $columns);

// Build query based on available columns
if ($has_drug_name && $has_quantity) {
    // Schema 1: drug_name and quantity
    $query = "SELECT drug_name, quantity, name, checkout_time 
              FROM drug_checkouts 
              WHERE department = ? 
              ORDER BY checkout_time DESC 
              LIMIT 100";
} elseif ($has_drug_name && $has_quantity_dispensed) {
    // Schema 2: drug_name and quantity_dispensed
    $query = "SELECT drug_name, quantity_dispensed as quantity, name, checkout_time 
              FROM drug_checkouts 
              WHERE department = ? 
              ORDER BY checkout_time DESC 
              LIMIT 100";
} elseif (in_array('drug_id', $columns) && $has_quantity_dispensed) {
    // Schema 3: drug_id with JOIN to get drug_name
    $query = "SELECT d.drug_name, dc.quantity_dispensed as quantity, dc.name, dc.checkout_time 
              FROM drug_checkouts dc
              INNER JOIN drugs d ON dc.drug_id = d.drug_id
              WHERE dc.department = ? 
              ORDER BY dc.checkout_time DESC 
              LIMIT 100";
} else {
    error_log("fetch_checkouts.php: Unknown table structure");
    echo json_encode(['error' => 'Unknown table structure']);
    exit;
}

$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    error_log("Prepare failed in fetch_checkouts.php: " . mysqli_error($conn));
    echo json_encode(['error' => 'Query preparation failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $department);
$execute_success = mysqli_stmt_execute($stmt);

if (!$execute_success) {
    error_log("Execute failed in fetch_checkouts.php: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    echo json_encode(['error' => 'Query execution failed: ' . mysqli_stmt_error($stmt)]);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$checkouts = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $checkouts[] = $row;
    }
} else {
    error_log("Get result failed in fetch_checkouts.php: " . mysqli_error($conn));
}

mysqli_stmt_close($stmt);
echo json_encode($checkouts);
