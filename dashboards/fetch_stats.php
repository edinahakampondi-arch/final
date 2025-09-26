<?php
require_once '../connect.php';
header('Content-Type: application/json');

// Fetch total drugs count
$result = mysqli_query($conn, "SELECT COUNT(*) AS total_drugs FROM drugs");
$total_drugs = 'Error';
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_drugs = $row['total_drugs'];
} else {
    error_log("Database Error (total drugs): " . mysqli_error($conn));
}

// Fetch critical stock count (including expired and soon-to-expire within 30 days)
$result_critical = mysqli_query($conn, "SELECT COUNT(*) AS critical_stock FROM drugs WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$critical_stock = 'Error';
if ($result_critical) {
    $row_critical = mysqli_fetch_assoc($result_critical);
    $critical_stock = $row_critical['critical_stock'];
} else {
    error_log("Database Error (critical stock): " . mysqli_error($conn));
}

echo json_encode([
    'total_drugs' => $total_drugs,
    'critical_stock' => $critical_stock
]);
