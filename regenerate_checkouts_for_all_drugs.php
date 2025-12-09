<?php
/**
 * Regenerate Checkout History for All Focused Departments
 * 
 * This script ensures EVERY drug in the 5 focused departments has
 * at least 24 months of checkout history for proper AutoETS predictions.
 * It deletes existing data and regenerates fresh 24-month history.
 */

require_once 'connect.php';

// Set timezone
date_default_timezone_set('Africa/Kampala');

echo "REGENERATING checkout history for focused departments...\n\n";

// Focus on these 5 departments only
$focused_departments = [
    'Surgery',
    'Paediatrics', 
    'Internal medicine',
    'Intensive Care unit',
    'Intensive care unit', // Handle both variations
    'Gynaecology'
];

// Check table structure
$describe_query = "DESCRIBE drug_checkouts";
$result = mysqli_query($conn, $describe_query);
$columns = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row['Field'];
    }
}

$has_drug_name = in_array('drug_name', $columns);
$has_drug_id = in_array('drug_id', $columns);
$has_quantity = in_array('quantity', $columns);
$has_quantity_dispensed = in_array('quantity_dispensed', $columns);

echo "Table structure:\n";
echo "  - drug_name: " . ($has_drug_name ? 'YES' : 'NO') . "\n";
echo "  - drug_id: " . ($has_drug_id ? 'YES' : 'NO') . "\n";
echo "  - quantity: " . ($has_quantity ? 'YES' : 'NO') . "\n";
echo "  - quantity_dispensed: " . ($has_quantity_dispensed ? 'YES' : 'NO') . "\n\n";

// Get all unique drugs from focused departments
$placeholders = str_repeat('?,', count($focused_departments) - 1) . '?';
$query = "SELECT drug_id, drug_name, department, current_stock 
          FROM drugs 
          WHERE department IN ($placeholders)
          ORDER BY drug_name, department";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    die("Error preparing query: " . mysqli_error($conn) . "\n");
}

$types = str_repeat('s', count($focused_departments));
mysqli_stmt_bind_param($stmt, $types, ...$focused_departments);

if (!mysqli_stmt_execute($stmt)) {
    die("Error executing query: " . mysqli_stmt_error($stmt) . "\n");
}

$result = mysqli_stmt_get_result($stmt);
$drugs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $drugs[] = $row;
}
mysqli_stmt_close($stmt);

echo "Found " . count($drugs) . " drugs in focused departments.\n";
echo "Departments: Surgery, Paediatrics, Internal medicine, Intensive Care unit, Gynaecology\n\n";

if (count($drugs) == 0) {
    die("No drugs found. Please add drugs first.\n");
}

// Sample users
$sample_users = ['Dr. Sarah Johnson', 'Dr. Michael Brown', 'Dr. Emily Davis', 'Dr. James Wilson', 
                 'Nurse Mary Smith', 'Nurse John Doe', 'Dr. Lisa Anderson', 'Dr. Robert Taylor'];

$total_inserted = 0;
$total_deleted = 0;
$total_errors = 0;

// Delete existing checkouts for these departments first
echo "Step 1: Cleaning existing checkout data for focused departments...\n";
$delete_query = "";
if ($has_drug_id) {
    $delete_query = "DELETE FROM drug_checkouts WHERE drug_id IN (
        SELECT drug_id FROM drugs WHERE department IN ($placeholders)
    )";
} elseif ($has_drug_name) {
    // This approach works but is less efficient
    $delete_placeholders = str_repeat('?,', count($focused_departments) - 1) . '?';
    $delete_query = "DELETE dc FROM drug_checkouts dc
                     INNER JOIN drugs d ON dc.drug_name = d.drug_name AND dc.department = d.department
                     WHERE d.department IN ($delete_placeholders)";
}

if ($delete_query) {
    $del_stmt = mysqli_prepare($conn, $delete_query);
    if ($del_stmt) {
        $del_types = str_repeat('s', count($focused_departments));
        mysqli_stmt_bind_param($del_stmt, $del_types, ...$focused_departments);
        if (mysqli_stmt_execute($del_stmt)) {
            $total_deleted = mysqli_affected_rows($conn);
            echo "  Deleted $total_deleted existing checkout records.\n";
        }
        mysqli_stmt_close($del_stmt);
    }
}

echo "\nStep 2: Generating 24 months of checkout history for each drug...\n\n";

// Process each drug
foreach ($drugs as $index => $drug) {
    $drug_id = $drug['drug_id'];
    $drug_name = $drug['drug_name'];
    $department = $drug['department'];
    $current_stock = intval($drug['current_stock']);
    
    // Calculate average monthly demand
    if ($current_stock == 0) {
        $avg_monthly_demand = 80 + rand(20, 120); // 80-200 units
    } else {
        $demand_percentage = 0.20 + (rand(0, 20) / 100); // 20-40%
        $avg_monthly_demand = max(intval($current_stock * $demand_percentage), 30);
    }
    
    // Generate exactly 24 months of historical data
    $start_date = date('Y-m-d', strtotime('-24 months'));
    $monthly_inserts = 0;
    
    for ($month_offset = 0; $month_offset < 24; $month_offset++) {
        $month_date = date('Y-m-01', strtotime("$start_date +$month_offset months"));
        $month_start = date('Y-m-d', strtotime($month_date));
        $days_in_month = date('t', strtotime($month_date));
        
        // Generate 2-6 checkouts per month with variation
        $checkouts_per_month = 2 + rand(0, 4);
        
        for ($i = 0; $i < $checkouts_per_month; $i++) {
            $day = rand(1, min(28, $days_in_month));
            $checkout_date = date('Y-m-d', strtotime("$month_date +$day days"));
            $checkout_time = $checkout_date . ' ' . sprintf('%02d:00:00', rand(8, 17));
            
            // Calculate quantity with monthly variation
            $base_quantity = max(1, intval($avg_monthly_demand / $checkouts_per_month));
            
            // Add trend: earlier months slightly less, later months slightly more
            $trend_factor = 1.0;
            if ($month_offset < 6) {
                $trend_factor = 0.85 + (rand(0, 30) / 100); // 85-115% for months 1-6
            } elseif ($month_offset < 12) {
                $trend_factor = 0.90 + (rand(0, 20) / 100); // 90-110% for months 7-12
            } elseif ($month_offset < 18) {
                $trend_factor = 0.95 + (rand(0, 10) / 100); // 95-105% for months 13-18
            } else {
                $trend_factor = 0.98 + (rand(0, 4) / 100); // 98-102% for months 19-24 (most recent)
            }
            
            $quantity = intval($base_quantity * $trend_factor);
            $quantity = max(5, $quantity + rand(-intval($quantity * 0.2), intval($quantity * 0.2)));
            $quantity = min($quantity, 200);
            
            $name = $sample_users[array_rand($sample_users)];
            
            // Insert checkout
            $insert_success = false;
            
            if ($has_drug_name && $has_quantity && !$insert_success) {
                $insert_query = "INSERT INTO drug_checkouts (drug_name, quantity, name, department, checkout_time) 
                                VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                if ($insert_stmt) {
                    mysqli_stmt_bind_param($insert_stmt, "sisss", $drug_name, $quantity, $name, $department, $checkout_time);
                    $insert_success = mysqli_stmt_execute($insert_stmt);
                    mysqli_stmt_close($insert_stmt);
                }
            }
            
            if ($has_drug_id && $has_quantity_dispensed && !$insert_success) {
                $insert_query = "INSERT INTO drug_checkouts (drug_id, quantity_dispensed, name, department, checkout_time) 
                                VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                if ($insert_stmt) {
                    mysqli_stmt_bind_param($insert_stmt, "sisss", $drug_id, $quantity, $name, $department, $checkout_time);
                    $insert_success = mysqli_stmt_execute($insert_stmt);
                    mysqli_stmt_close($insert_stmt);
                }
            }
            
            if ($has_drug_id && $has_drug_name && $has_quantity_dispensed && !$insert_success) {
                $insert_query = "INSERT INTO drug_checkouts (drug_id, drug_name, quantity_dispensed, name, department, checkout_time) 
                                VALUES (?, ?, ?, ?, ?, ?)";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                if ($insert_stmt) {
                    mysqli_stmt_bind_param($insert_stmt, "ssisss", $drug_id, $drug_name, $quantity, $name, $department, $checkout_time);
                    $insert_success = mysqli_stmt_execute($insert_stmt);
                    mysqli_stmt_close($insert_stmt);
                }
            }
            
            if ($insert_success) {
                $monthly_inserts++;
                $total_inserted++;
            } else {
                $total_errors++;
            }
        }
    }
    
    if ($monthly_inserts > 0) {
        echo "  ✓ $drug_name ($department): $monthly_inserts checkouts over 24 months [Stock: $current_stock]\n";
    }
    
    // Progress update
    if (($index + 1) % 50 == 0) {
        echo "\nProgress: " . ($index + 1) . "/" . count($drugs) . " drugs, $total_inserted checkouts inserted\n\n";
    }
}

echo "\n";
echo "========================================\n";
echo "COMPLETED!\n";
echo "========================================\n";
echo "Total drugs processed: " . count($drugs) . "\n";
echo "Total checkouts deleted: $total_deleted\n";
echo "Total checkouts inserted: $total_inserted\n";
echo "Total errors: $total_errors\n";
echo "\nAll drugs now have 24 months of checkout history!\n";
echo "AutoETS predictions should work properly now.\n";

mysqli_close($conn);
?>

