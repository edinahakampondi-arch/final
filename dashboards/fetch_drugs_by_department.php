<?php
require_once '../connect.php';
header('Content-Type: application/json');

$department = $_GET['department'] ?? '';

if (!$department) {
    echo json_encode([]);
    exit;
}

// Get drugs with stock > 0 from the specified department
$query = "SELECT drug_name, current_stock, expiry_date FROM drugs 
          WHERE department = ? AND current_stock > 0 
          ORDER BY drug_name ASC";
$stmt = mysqli_prepare($conn, $query);

if (!$stmt) {
    echo json_encode(['error' => 'Query preparation failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $department);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$drugs = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $drugs[] = [
            'drug_name' => $row['drug_name'],
            'current_stock' => intval($row['current_stock']),
            'expiry_date' => $row['expiry_date']
        ];
    }
}

mysqli_stmt_close($stmt);
echo json_encode($drugs);
?>

