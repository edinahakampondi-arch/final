<?php
/**
 * Create Sample Drugs for Empty Departments
 * 
 * This script identifies departments with zero drugs and creates sample drug data
 * including some with critical stock (expiring soon) to generate alerts.
 */

require_once 'connect.php';

echo "Creating sample drugs for empty departments...\n\n";

// Common drug names and their typical stock ranges
$sample_drugs = [
    ['name' => 'Paracetamol 500 mg Tablets', 'category' => 'Analgesic', 'stock_range' => [50, 500]],
    ['name' => 'Amoxicillin 500 mg Capsules', 'category' => 'Antibiotic', 'stock_range' => [30, 300]],
    ['name' => 'Ibuprofen 400 mg Tablets', 'category' => 'Analgesic', 'stock_range' => [40, 400]],
    ['name' => 'Metronidazole 200 mg Tablets', 'category' => 'Antibiotic', 'stock_range' => [25, 250]],
    ['name' => 'Diclofenac 50 mg Tablets', 'category' => 'Anti-inflammatory', 'stock_range' => [35, 350]],
    ['name' => 'Omeprazole 20 mg Capsules', 'category' => 'Antacid', 'stock_range' => [30, 300]],
    ['name' => 'Ceftriaxone 1 g Injection', 'category' => 'Antibiotic', 'stock_range' => [20, 200]],
    ['name' => 'Azithromycin 500 mg Tablets', 'category' => 'Antibiotic', 'stock_range' => [25, 250]],
    ['name' => 'Salbutamol Inhaler', 'category' => 'Bronchodilator', 'stock_range' => [15, 150]],
    ['name' => 'Insulin Glargine 100 IU/ml', 'category' => 'Hormone', 'stock_range' => [10, 100]],
    ['name' => 'Morphine 10 mg Injection', 'category' => 'Analgesic', 'stock_range' => [5, 50]],
    ['name' => 'Furosemide 40 mg Tablets', 'category' => 'Diuretic', 'stock_range' => [30, 300]],
    ['name' => 'Atenolol 50 mg Tablets', 'category' => 'Beta-blocker', 'stock_range' => [25, 250]],
    ['name' => 'Amlodipine 5 mg Tablets', 'category' => 'Antihypertensive', 'stock_range' => [30, 300]],
    ['name' => 'Metformin 500 mg Tablets', 'category' => 'Antidiabetic', 'stock_range' => [40, 400]],
];

// Get all departments
$dept_query = "SELECT DISTINCT department FROM drugs WHERE department IS NOT NULL AND department != ''";
$result = mysqli_query($conn, $dept_query);
$existing_departments = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $existing_departments[] = $row['department'];
    }
}

echo "Existing departments with drugs: " . implode(', ', $existing_departments) . "\n\n";

// List of all possible departments
$all_departments = [
    'Internal Medicine',
    'Surgery',
    'Paediatrics',
    'Gynaecology',
    'Intensive Care unit',
    'Anesthesia',
    'Emergency',
    'Orthopedics',
    'Cardiology',
    'Neurology',
    'Oncology',
    'Dermatology',
    'Psychiatry',
    'Radiology',
    'Laboratory'
];

// Find departments with no drugs
$empty_departments = [];
foreach ($all_departments as $dept) {
    if (!in_array($dept, $existing_departments)) {
        $empty_departments[] = $dept;
    } else {
        // Check if department has any drugs
        $check_query = "SELECT COUNT(*) as count FROM drugs WHERE department = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "s", $dept);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            $check_row = mysqli_fetch_assoc($check_result);
            mysqli_stmt_close($check_stmt);
            
            if ($check_row && intval($check_row['count']) == 0) {
                $empty_departments[] = $dept;
            }
        }
    }
}

if (empty($empty_departments)) {
    echo "All departments already have drugs. No action needed.\n";
    mysqli_close($conn);
    exit;
}

echo "Departments with zero drugs: " . implode(', ', $empty_departments) . "\n\n";

$total_inserted = 0;
$critical_inserted = 0;

// Create sample drugs for each empty department
foreach ($empty_departments as $department) {
    echo "Processing department: $department...\n";
    
    // Create 8-12 drugs per department
    $num_drugs = rand(8, 12);
    $selected_drugs = array_rand($sample_drugs, min($num_drugs, count($sample_drugs)));
    
    if (!is_array($selected_drugs)) {
        $selected_drugs = [$selected_drugs];
    }
    
    $dept_inserted = 0;
    $dept_critical = 0;
    
    foreach ($selected_drugs as $drug_idx) {
        $drug_template = $sample_drugs[$drug_idx];
        $drug_name = $drug_template['name'];
        $category = $drug_template['category'];
        
        // Determine stock level
        $stock_range = $drug_template['stock_range'];
        $current_stock = rand($stock_range[0], $stock_range[1]);
        
        // Determine expiry date
        // 30% chance of critical stock (expiring within 30 days or expired)
        // 20% chance of expired
        // 10% chance of expiring within 7 days
        $expiry_rand = rand(1, 100);
        
        if ($expiry_rand <= 10) {
            // Expired (past date)
            $days_offset = rand(-30, -1);
            $expiry_date = date('Y-m-d', strtotime("$days_offset days"));
            $is_critical = true;
        } elseif ($expiry_rand <= 30) {
            // Expiring within 30 days
            $days_offset = rand(1, 30);
            $expiry_date = date('Y-m-d', strtotime("+$days_offset days"));
            $is_critical = true;
        } else {
            // Safe stock (expiring after 30 days)
            $days_offset = rand(60, 365);
            $expiry_date = date('Y-m-d', strtotime("+$days_offset days"));
            $is_critical = false;
        }
        
        // Insert drug (using actual table structure: drug_id is auto-increment, so we don't include it)
        // Based on table structure: drug_id, drug_name, department, quantity_dispensed, current_stock, expiry_date, transaction_date
        $insert_query = "INSERT INTO drugs (drug_name, department, current_stock, expiry_date, quantity_dispensed) 
                        VALUES (?, ?, ?, ?, 0)";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        
        if ($insert_stmt) {
            mysqli_stmt_bind_param($insert_stmt, "ssis", $drug_name, $department, $current_stock, $expiry_date);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $dept_inserted++;
                $total_inserted++;
                if ($is_critical) {
                    $dept_critical++;
                    $critical_inserted++;
                }
            } else {
                echo "  Error inserting drug '$drug_name': " . mysqli_stmt_error($insert_stmt) . "\n";
            }
            mysqli_stmt_close($insert_stmt);
        } else {
            echo "  Error preparing insert for '$drug_name': " . mysqli_error($conn) . "\n";
        }
    }
    
    echo "  Inserted $dept_inserted drugs ($dept_critical with critical expiry dates).\n";
}

echo "\n";
echo "========================================\n";
echo "Summary:\n";
echo "  Departments processed: " . count($empty_departments) . "\n";
echo "  Total drugs inserted: $total_inserted\n";
echo "  Drugs with critical expiry: $critical_inserted\n";
echo "========================================\n";
echo "\nDone! Sample drugs have been created for empty departments.\n";

mysqli_close($conn);
?>

