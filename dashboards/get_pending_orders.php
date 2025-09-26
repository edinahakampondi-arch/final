<?php
// get_pending_orders.php
require_once '../connect.php';

header('Content-Type: application/json');

$query = "SELECT COUNT(*) AS pending_orders FROM borrowing_requests WHERE status = 'Pending'";
$result = mysqli_query($conn, $query);

if ($result) {
    $row = mysqli_fetch_assoc($result);
    echo json_encode(['pending_orders' => $row['pending_orders']]);
} else {
    error_log("Database Error (pending orders): " . mysqli_error($conn));
    echo json_encode(['pending_orders' => 'Error']);
}
?>