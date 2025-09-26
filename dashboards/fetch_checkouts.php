<?php
require_once '../connect.php';
header('Content-Type: application/json');

$department = $_GET['department'] ?? '';
if (!$department) {
    echo json_encode([]);
    exit;
}

$query = "SELECT drug_name, quantity, name, checkout_time FROM drug_checkouts WHERE department = ? ORDER BY checkout_time DESC LIMIT 100";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $department);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$checkouts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $checkouts[] = $row;
}
mysqli_stmt_close($stmt);
echo json_encode($checkouts);
