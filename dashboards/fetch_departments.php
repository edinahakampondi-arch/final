<?php
require_once '../connect.php';
header('Content-Type: application/json');

$query = "SELECT DISTINCT department FROM drugs WHERE current_stock > 0";
$result = mysqli_query($conn, $query);
$departments = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $departments[] = $row['department'];
    }
}
echo json_encode($departments);
