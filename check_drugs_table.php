<?php
require_once 'connect.php';

echo "Checking drugs table structure...\n\n";

// Check table structure
echo "Table structure:\n";
$describe = "DESCRIBE drugs";
$result = mysqli_query($conn, $describe);

if ($result) {
    echo str_pad("Field", 25) . str_pad("Type", 30) . "Null\tKey\n";
    echo str_repeat("-", 75) . "\n";
    while ($row = mysqli_fetch_assoc($result)) {
        echo str_pad($row['Field'], 25) . str_pad($row['Type'], 30) . $row['Null'] . "\t" . $row['Key'] . "\n";
    }
} else {
    echo "Error describing table: " . mysqli_error($conn) . "\n";
}

// Get one sample row to see what columns exist
echo "\n\nSample row from drugs table:\n";
$sample = "SELECT * FROM drugs LIMIT 1";
$result = mysqli_query($conn, $sample);
if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    foreach ($row as $key => $value) {
        echo "$key: $value\n";
    }
}

mysqli_close($conn);
?>

