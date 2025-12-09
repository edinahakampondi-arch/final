<?php
require_once '../connect.php';
header('Content-Type: application/json');

// Focus on these 5 departments only
$focused_departments = [
    'Surgery',
    'Paediatrics', 
    'Internal medicine',
    'Intensive Care unit',
    'Intensive care unit', // Handle both variations
    'Gynaecology'
];

// Create placeholders for prepared statement
$placeholders = str_repeat('?,', count($focused_departments) - 1) . '?';

// Only show drugs from focused departments with stock > 0
$query = "SELECT drug_name, department, current_stock, expiry_date 
          FROM drugs 
          WHERE department IN ($placeholders) AND current_stock > 0
          ORDER BY department, drug_name ASC";
$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    error_log("Prepare failed in fetch_supplies.php: " . mysqli_error($conn));
    echo json_encode(['error' => 'Query preparation failed']);
    exit;
}

$types = str_repeat('s', count($focused_departments));
mysqli_stmt_bind_param($stmt, $types, ...$focused_departments);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Execute failed in fetch_supplies.php: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    echo json_encode(['error' => 'Query execution failed']);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$supplies = [];

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $supplies[] = $row;
    }
}

mysqli_stmt_close($stmt);
echo json_encode($supplies);
