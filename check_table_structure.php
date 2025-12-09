<?php
require_once 'connect.php';

echo "Checking drug_checkouts table structure...\n\n";

// Check if table exists
$check_table = "SHOW TABLES LIKE 'drug_checkouts'";
$result = mysqli_query($conn, $check_table);

if (!$result || mysqli_num_rows($result) == 0) {
    echo "Table 'drug_checkouts' does not exist. Creating it...\n";
    
    $create_table = "
    CREATE TABLE IF NOT EXISTS drug_checkouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        drug_name VARCHAR(255) NOT NULL,
        quantity INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        department VARCHAR(255) NOT NULL,
        checkout_time DATETIME NOT NULL,
        INDEX idx_drug_name (drug_name),
        INDEX idx_department (department),
        INDEX idx_checkout_time (checkout_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    
    if (mysqli_query($conn, $create_table)) {
        echo "Table 'drug_checkouts' created successfully.\n\n";
    } else {
        die("Error creating table: " . mysqli_error($conn) . "\n");
    }
} else {
    echo "Table 'drug_checkouts' exists.\n\n";
}

// Check table structure
echo "Table structure:\n";
$describe = "DESCRIBE drug_checkouts";
$result = mysqli_query($conn, $describe);

if ($result) {
    echo str_pad("Field", 20) . str_pad("Type", 30) . "Null\tKey\n";
    echo str_repeat("-", 70) . "\n";
    while ($row = mysqli_fetch_assoc($result)) {
        echo str_pad($row['Field'], 20) . str_pad($row['Type'], 30) . $row['Null'] . "\t" . $row['Key'] . "\n";
    }
} else {
    echo "Error describing table: " . mysqli_error($conn) . "\n";
}

mysqli_close($conn);
?>

