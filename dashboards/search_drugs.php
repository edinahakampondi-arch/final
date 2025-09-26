<?php
require_once '../connect.php';
header('Content-Type: application/json');

$department = $_GET['department'] ?? '';
$query = $_GET['query'] ?? '';

if (!$department) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT * FROM drugs WHERE department = ? AND (LOWER(drug_name) LIKE ? OR LOWER(category) LIKE ?)";
$stmt = mysqli_prepare($conn, $sql);
$searchTerm = '%' . strtolower($query) . '%';
mysqli_stmt_bind_param($stmt, "sss", $department, $searchTerm, $searchTerm);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$drugs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $drugs[] = $row;
}
mysqli_stmt_close($stmt);
echo json_encode($drugs);
