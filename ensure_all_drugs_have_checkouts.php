<?php
/**
 * Ensure All Drugs Have Checkout History
 * 
 * This script ensures every drug in the database has at least 12-24 months
 * of checkout history for predictions to work properly.
 */

require_once 'connect.php';

// Set timezone
date_default_timezone_set('Africa/Kampala');

echo "Ensuring all drugs have checkout history...\n\n";

// First, check what columns exist in drug_checkouts table
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

echo "Table structure detected:\n";
echo "  - drug_name: " . ($has_drug_name ? 'YES' : 'NO') . "\n";
echo "  - drug_id: " . ($has_drug_id ? 'YES' : 'NO') . "\n";
echo "  - quantity: " . ($has_quantity ? 'YES' : 'NO') . "\n";
echo "  - quantity_dispensed: " . ($has_quantity_dispensed ? 'YES' : 'NO') . "\n\n";

// Focus on these 5 departments only
$focused_departments = [
    'Surgery',
    'Paediatrics', 
    'Internal medicine',
    'Intensive Care unit',
    'Intensive care unit', // Handle both variations
    'Gynaecology'
];

// Create placeholders for prepared statement
$placeholders = str_repeat('?,', count($focused_departments) - 1) . '?';

// Get all unique drugs from the database - ONLY from focused departments
$query = "SELECT drug_id, drug_name, department, current_stock 
          FROM drugs 
          WHERE department IN ($placeholders)
          ORDER BY drug_name, department";
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    die("Error preparing query: " . mysqli_error($conn) . "\n");
}

// Bind department parameters
$types = str_repeat('s', count($focused_departments));
mysqli_stmt_bind_param($stmt, $types, ...$focused_departments);

if (!mysqli_stmt_execute($stmt)) {
    die("Error executing query: " . mysqli_stmt_error($stmt) . "\n");
}

$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    die("Error fetching drugs: " . mysqli_error($conn) . "\n");
}

$drugs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $drugs[] = $row;
}

echo "Found " . count($drugs) . " unique drug-department combinations in focused departments.\n";
echo "Focused departments: Surgery, Paediatrics, Internal medicine, Intensive Care unit, Gynaecology\n\n";

if (count($drugs) == 0) {
    die("No drugs found in database. Please add drugs first.\n");
}

// Sample user names
$sample_users = ['Dr. Sarah Johnson', 'Dr. Michael Brown', 'Dr. Emily Davis', 'Dr. James Wilson', 
                 'Nurse Mary Smith', 'Nurse John Doe', 'Dr. Lisa Anderson', 'Dr. Robert Taylor'];

// Counters
$total_processed = 0;
$total_inserted = 0;
$total_skipped = 0;
$total_errors = 0;

// Process each drug
foreach ($drugs as $drug) {
    $drug_id = $drug['drug_id'];
    $drug_name = $drug['drug_name'];
    $department = $drug['department'];
    $current_stock = intval($drug['current_stock']);
    
    $total_processed++;
    
    // Check if drug already has sufficient checkout history
    $check_query = "";
    if ($has_drug_name) {
        $check_query = "SELECT COUNT(*) as count FROM drug_checkouts 
                        WHERE drug_name = ? AND department = ? 
                        AND checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)";
    } elseif ($has_drug_id) {
        $check_query = "SELECT COUNT(*) as count FROM drug_checkouts 
                        WHERE drug_id = ? AND department = ? 
                        AND checkout_time >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)";
    } else {
        echo "  ERROR: drug_checkouts table has neither drug_name nor drug_id column!\n";
        $total_errors++;
        continue;
    }
    
    $check_stmt = mysqli_prepare($conn, $check_query);
    if (!$check_stmt) {
        echo "  ERROR preparing check query for $drug_name: " . mysqli_error($conn) . "\n";
        $total_errors++;
        continue;
    }
    
    $check_param = $has_drug_name ? $drug_name : $drug_id;
    mysqli_stmt_bind_param($check_stmt, "ss", $check_param, $department);
    
    if (!mysqli_stmt_execute($check_stmt)) {
        echo "  ERROR executing check query for $drug_name: " . mysqli_stmt_error($check_stmt) . "\n";
        mysqli_stmt_close($check_stmt);
        $total_errors++;
        continue;
    }
    
    $check_result = mysqli_stmt_get_result($check_stmt);
    $check_row = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($check_stmt);
    
    $existing_count = intval($check_row['count'] ?? 0);
    
    // If drug already has 18+ months of data, skip (we want at least 18 for AutoETS)
    if ($existing_count >= 18) {
        $total_skipped++;
        if ($total_processed % 100 == 0) {
            echo "Progress: $total_processed/" . count($drugs) . " processed, $total_inserted inserted, $total_skipped skipped...\n";
        }
        continue;
    }
    
    // Calculate average monthly demand based on current stock
    if ($current_stock == 0) {
        $avg_monthly_demand = 80 + rand(20, 120); // 80-200 units
    } else {
        // Estimate monthly demand as 15-35% of current stock
        $demand_percentage = 0.20 + (rand(0, 20) / 100); // 20-40%
        $avg_monthly_demand = max(intval($current_stock * $demand_percentage), 30);
    }
    
    // Generate 18-24 months of historical data (ensure minimum 18 months for AutoETS)
    // If drug has some data but less than 18 months, generate enough to reach 24 months total
    $months_to_generate = max(18, 24 - $existing_count);
    $start_date = date('Y-m-d', strtotime("-$months_to_generate months"));
    
    // Generate checkouts for each month
    $monthly_inserts = 0;
    for ($month_offset = 0; $month_offset < $months_to_generate; $month_offset++) {
        // Calculate month start date
        $month_date = date('Y-m-01', strtotime("$start_date +$month_offset months"));
        $month_start = date('Y-m-d', strtotime($month_date));
        $month_end = date('Y-m-t', strtotime($month_date)); // Last day of month
        
        // Generate 2-6 checkouts per month with realistic variation
        $checkouts_per_month = 2 + rand(0, 4);
        
        for ($i = 0; $i < $checkouts_per_month; $i++) {
            // Random date within the month
            $day = rand(1, min(28, date('t', strtotime($month_date))));
            $checkout_date = date('Y-m-d', strtotime("$month_date +$day days"));
            $checkout_time = $checkout_date . ' ' . sprintf('%02d:00:00', rand(8, 17)); // Between 8 AM and 5 PM
            
            // Random quantity per checkout (10-40% of monthly average)
            $base_quantity = max(1, intval($avg_monthly_demand / $checkouts_per_month));
            $variation = intval($base_quantity * 0.3);
            $quantity = max(5, $base_quantity + rand(-$variation, $variation));
            $quantity = min($quantity, 200); // Cap at 200 units
            
            // Add some natural variation month-to-month (seasonality simulation)
            // Earlier months might have slightly different patterns
            if ($month_offset < 6) {
                $quantity = intval($quantity * (0.9 + rand(0, 20) / 100)); // 90-110% of base
            } elseif ($month_offset < 12) {
                $quantity = intval($quantity * (0.95 + rand(0, 10) / 100)); // 95-105% of base
            }
            // Later months (12+) keep normal variation
            
            // Random user
            $name = $sample_users[array_rand($sample_users)];
            
            // Insert checkout - try multiple schema options
            $insert_success = false;
            
            // Try 1: drug_name and quantity
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
            
            // Try 2: drug_id and quantity_dispensed
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
            
            // Try 3: Both drug_id and drug_name if both exist
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
                echo "  ERROR inserting checkout for $drug_name on $checkout_time: " . mysqli_error($conn) . "\n";
                $total_errors++;
            }
        }
    }
    
    if ($monthly_inserts > 0) {
        echo "  ✓ Added $monthly_inserts checkouts for $drug_name ($department) [Stock: $current_stock]\n";
    }
    
    // Progress update every 50 drugs
    if ($total_processed % 50 == 0) {
        echo "\nProgress: $total_processed/" . count($drugs) . " processed, $total_inserted inserted, $total_skipped skipped, $total_errors errors\n\n";
    }
}

echo "\n";
echo "========================================\n";
echo "COMPLETED!\n";
echo "========================================\n";
echo "Total drugs processed: $total_processed\n";
echo "Total checkouts inserted: $total_inserted\n";
echo "Total drugs skipped (already had data): $total_skipped\n";
echo "Total errors: $total_errors\n";
echo "\nAll drugs now have checkout history for predictions!\n";

mysqli_close($conn);
?>

