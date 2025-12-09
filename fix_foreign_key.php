<?php
require_once 'connect.php';

echo "Fixing foreign key constraint in drug_checkouts table...\n\n";

// Drop the old foreign key constraint
echo "Dropping old foreign key constraint...\n";
$drop_fk = "ALTER TABLE drug_checkouts DROP FOREIGN KEY drug_checkouts_ibfk_1";
if (mysqli_query($conn, $drop_fk)) {
    echo "Old foreign key dropped successfully.\n";
} else {
    echo "Error dropping foreign key (may not exist): " . mysqli_error($conn) . "\n";
}

// Add new foreign key constraint to drugs table
echo "\nAdding new foreign key constraint to drugs table...\n";
$add_fk = "ALTER TABLE drug_checkouts 
           ADD CONSTRAINT drug_checkouts_ibfk_1 
           FOREIGN KEY (drug_id) REFERENCES drugs(drug_id) 
           ON DELETE CASCADE ON UPDATE CASCADE";
if (mysqli_query($conn, $add_fk)) {
    echo "New foreign key constraint added successfully.\n";
} else {
    echo "Error adding foreign key: " . mysqli_error($conn) . "\n";
}

mysqli_close($conn);
echo "\nDone!\n";
?>

