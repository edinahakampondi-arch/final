<?php
require_once '../connect.php';
header('Content-Type: application/json');

$query = "SELECT drug_name, department, current_stock, expiry_date, min_stock, max_stock FROM drugs WHERE current_stock > 0";
$result = mysqli_query($conn, $query);
$supplies = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $supplies[] = $row;
    }
}
echo json_encode($supplies);
