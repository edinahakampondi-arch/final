<?php
/**
 * Generate Sample Checkout Data for All Drugs
 * 
 * This script generates realistic checkout history for each drug in the database
 * over the last 6 months. This ensures the prediction API has historical data to work with.
 */

require_once 'connect.php';

// Set timezone
date_default_timezone_set('Africa/Kampala');

echo "Starting sample checkout data generation...\n\n";

// Get all unique drugs from the database (with drug_id)
$query = "SELECT drug_id, drug_name, department, current_stock FROM drugs ORDER BY drug_name, department";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Error fetching drugs: " . mysqli_error($conn) . "\n");
}

$drugs = [];
while ($row = mysqli_fetch_assoc($result)) {
    $drugs[] = $row;
}

echo "Found " . count($drugs) . " unique drug-department combinations.\n";

// Limit processing to prevent long execution times
// Process first 2000 drugs, or all if less than 2000
$max_drugs = min(2000, count($drugs));
if (count($drugs) > $max_drugs) {
    echo "Note: Processing first $max_drugs drugs to prevent long execution time.\n";
    echo "Run the script again to process remaining drugs if needed.\n";
    $drugs = array_slice($drugs, 0, $max_drugs);
}

echo "\n";

if (count($drugs) == 0) {
    die("No drugs found in database. Please add drugs first.\n");
}

// Sample user names (will be assigned to checkouts)
$sample_users = ['Dr. Sarah Johnson', 'Dr. Michael Brown', 'Dr. Emily Davis', 'Dr. James Wilson', 
                 'Nurse Mary Smith', 'Nurse John Doe', 'Dr. Lisa Anderson', 'Dr. Robert Taylor'];

// Counters
$total_inserted = 0;
$total_skipped = 0;

// Process each drug
foreach ($drugs as $drug) {
    $drug_id = strval($drug['drug_id']); // Convert to string for drug_id column in drug_checkouts
    $drug_name = $drug['drug_name'];
    $department = $drug['department'];
    $current_stock = intval($drug['current_stock']);
    
    // Check if this drug already has checkout history
    $check_query = "SELECT COUNT(*) as count FROM drug_checkouts 
                    WHERE drug_id = ? AND department = ? 
                    AND checkout_time >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
    $check_stmt = mysqli_prepare($conn, $check_query);
    
    if (!$check_stmt) {
        echo "  Error preparing check query: " . mysqli_error($conn) . "\n";
        continue;
    }
    
    mysqli_stmt_bind_param($check_stmt, "ss", $drug_id, $department);
    
    if (!mysqli_stmt_execute($check_stmt)) {
        echo "  Error executing check query: " . mysqli_stmt_error($check_stmt) . "\n";
        mysqli_stmt_close($check_stmt);
        continue;
    }
    
    $check_result = mysqli_stmt_get_result($check_stmt);
    $check_row = mysqli_fetch_assoc($check_result);
    mysqli_stmt_close($check_stmt);
    
    if ($check_row && intval($check_row['count']) >= 12) {
        // Skip silently for large datasets
        $total_skipped++;
        continue;
    }
    
    // Only show progress for every 50th drug to reduce output
    if ($total_inserted % 50 == 0) {
        echo "Processing: $drug_name ($department) [Stock: $current_stock]...\n";
    }
    
    // Calculate average monthly demand based on current stock
    // If stock is 0, use a default. Otherwise, assume stock covers 3-6 months
    if ($current_stock == 0) {
        $avg_monthly_demand = 100; // Default for zero stock
    } else {
        // Estimate monthly demand as 20-30% of current stock
        // This assumes stock covers 3-5 months of demand
        $avg_monthly_demand = max($current_stock * 0.25, 20);
    }
    
    // Generate checkouts for the last 6 months
    $inserted = 0;
    for ($month_offset = 5; $month_offset >= 0; $month_offset--) {
        // Start of month
        $month_start = date('Y-m-01', strtotime("-$month_offset months"));
        $month_end = date('Y-m-t', strtotime("-$month_offset months"));
        
        // Generate 3-5 checkouts per month (realistic hospital usage)
        $checkouts_per_month = rand(3, 5);
        
        // Calculate total monthly quantity (with some variation ±20%)
        $monthly_quantity = round($avg_monthly_demand * (0.8 + (rand(0, 40) / 100)));
        
        // Distribute quantity across checkouts
        $quantities = [];
        $remaining = $monthly_quantity;
        
        for ($i = 0; $i < $checkouts_per_month - 1; $i++) {
            // Distribute quantities (smaller first, larger later in month)
            $max_per_checkout = floor($remaining / ($checkouts_per_month - $i));
            $qty = rand(5, min($max_per_checkout, $monthly_quantity * 0.4));
            $quantities[] = $qty;
            $remaining -= $qty;
        }
        $quantities[] = $remaining; // Last checkout gets remainder
        
        // Sort quantities to have larger checkouts later in month
        sort($quantities);
        
        // Generate checkout dates within the month
        $dates = [];
        $month_start_date = new DateTime($month_start);
        $month_end_date = new DateTime($month_end);
        
        for ($i = 0; $i < $checkouts_per_month; $i++) {
            // Distribute dates evenly across the month
            $day_offset = floor((28 * $i) / $checkouts_per_month) + 1;
            $checkout_date = clone $month_start_date;
            $checkout_date->modify("+$day_offset days");
            
            $hour = rand(8, 17); // Business hours
            $minute = rand(0, 59);
            $dates[] = $checkout_date->format('Y-m-d') . " " . sprintf("%02d:%02d:00", $hour, $minute);
        }
        
        sort($dates); // Sort chronologically
        
        // Insert checkouts
        for ($i = 0; $i < $checkouts_per_month; $i++) {
            $checkout_time = $dates[$i];
            $quantity = max(1, $quantities[$i]); // Ensure at least 1
            $user_name = $sample_users[array_rand($sample_users)];
            
            // Insert checkout (using actual table structure: drug_id, quantity_dispensed)
            $insert_query = "INSERT INTO drug_checkouts (drug_id, quantity_dispensed, name, department, checkout_time) 
                           VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            
            if (!$insert_stmt) {
                if ($total_inserted % 50 == 0) {
                    echo "  Error preparing insert query: " . mysqli_error($conn) . "\n";
                }
                continue;
            }
            
            mysqli_stmt_bind_param($insert_stmt, "sisss", $drug_id, $quantity, $user_name, $department, $checkout_time);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $inserted++;
            } else {
                // Only show errors occasionally to reduce output
                if ($total_inserted % 50 == 0) {
                    echo "  Warning: Failed to insert checkout: " . mysqli_stmt_error($insert_stmt) . "\n";
                }
            }
            mysqli_stmt_close($insert_stmt);
        }
    }
    
    // Only show progress for every 50th drug
    if ($total_inserted % 50 == 0 && $inserted > 0) {
        echo "  Inserted $inserted checkouts.\n";
    }
    $total_inserted += $inserted;
}

echo "\n";
echo "========================================\n";
echo "Summary:\n";
echo "  Total drugs processed: " . count($drugs) . "\n";
echo "  Drugs skipped (already have history): $total_skipped\n";
echo "  Checkouts inserted: $total_inserted\n";
echo "========================================\n";
echo "\nDone! Sample checkout data has been generated.\n";

mysqli_close($conn);
?>

