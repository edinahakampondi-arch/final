<?php
$conn = new mysqli("localhost", "root", "", "system");

if ($conn->connect_error) {
    die(json_encode([]));
}

$q = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : "";

$sql = "SELECT DISTINCT drug_name 
        FROM drugs 
        WHERE drug_name LIKE '%$q%' 
        ORDER BY drug_name 
        LIMIT 10";

$result = $conn->query($sql);

$drugs = [];
while ($row = $result->fetch_assoc()) {
    $drugs[] = $row['drug_name'];
}

echo json_encode($drugs);
?>
