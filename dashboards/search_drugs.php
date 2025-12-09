<?php
require_once '../connect.php';
header('Content-Type: application/json');

$department = $_GET['department'] ?? '';
$query = trim($_GET['query'] ?? '');

if (!$department) {
    echo json_encode([]);
    exit;
}

// If query is empty, return all drugs for the department
if (empty($query)) {
    $sql = "SELECT * FROM drugs WHERE department = ? ORDER BY drug_name ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $department);
} else {
    // If query has value, search by drug name or category
    $sql = "SELECT * FROM drugs WHERE department = ? AND (LOWER(drug_name) LIKE ? OR LOWER(category) LIKE ?) ORDER BY drug_name ASC";
    $stmt = mysqli_prepare($conn, $sql);
    $searchTerm = '%' . strtolower($query) . '%';
    mysqli_stmt_bind_param($stmt, "sss", $department, $searchTerm, $searchTerm);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$drugs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $drugs[] = $row;
}
mysqli_stmt_close($stmt);
echo json_encode($drugs);
