<?php
// get_forecast_drugs.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once '../connect.php';
session_start();

header('Content-Type: application/json');

// Set longer execution time for processing multiple API calls
set_time_limit(300); // 5 minutes

try {
    if (!isset($_SESSION['department'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $user_department = $_SESSION['department'];
    $is_admin = ($user_department === 'Admin');

    // Check database connection
    if (!$conn || mysqli_connect_errno()) {
        error_log("Database connection error: " . mysqli_connect_error());
        echo json_encode(['error' => 'Database connection error']);
        exit;
    }

    // Get drugs for the department (or all drugs for admin from 5 target departments)
    // Target departments: Surgery, Paediatrics, Internal medicine, Intensive Care unit, Gynaecology
    if ($is_admin) {
        $target_departments = ['Surgery', 'Paediatrics', 'Internal medicine', 'Intensive Care unit', 'Gynaecology'];
        $placeholders = implode(',', array_fill(0, count($target_departments), '?'));
        $query = "SELECT drug_name, department, MAX(current_stock) as current_stock 
                  FROM drugs 
                  WHERE department IN ($placeholders)
                  GROUP BY drug_name, department 
                  ORDER BY department, drug_name ASC";
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            $types = str_repeat('s', count($target_departments));
            mysqli_stmt_bind_param($stmt, $types, ...$target_departments);
        }
    } else {
        $query = "SELECT drug_name, department, MAX(current_stock) as current_stock 
                  FROM drugs 
                  WHERE department = ? 
                  GROUP BY drug_name 
                  ORDER BY drug_name ASC";
        $stmt = mysqli_prepare($conn, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $user_department);
        }
    }

    if (!$stmt) {
        $error_msg = mysqli_error($conn);
        error_log("Database prepare error for department '$user_department': " . $error_msg);
        echo json_encode(['error' => 'Database query error: ' . $error_msg]);
        exit;
    }

    if (!mysqli_stmt_execute($stmt)) {
        $error_msg = mysqli_stmt_error($stmt);
        error_log("Database execute error for department '$user_department': " . $error_msg);
        mysqli_stmt_close($stmt);
        echo json_encode(['error' => 'Database execute error: ' . $error_msg]);
        exit;
    }

    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result) {
        $error_msg = mysqli_error($conn);
        error_log("Database result error for department '$user_department': " . $error_msg);
        mysqli_stmt_close($stmt);
        echo json_encode(['error' => 'Database result error: ' . $error_msg]);
        exit;
    }

    $drugs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row && isset($row['drug_name'])) {
            $drugs[] = [
                'drug_name' => $row['drug_name'],
                'department' => $row['department'] ?? $user_department,
                'current_stock' => intval($row['current_stock'] ?? 0)
            ];
        }
    }
    mysqli_stmt_close($stmt);

    if (empty($drugs)) {
        error_log("No drugs found for department: '$user_department'");
        echo json_encode(['forecasts' => []]);
        exit;
    }

    error_log("Found " . count($drugs) . " drugs for department: '$user_department'");

    // Function to call prediction API
    function getPrediction($drug_name) {
        $api_url = "http://127.0.0.1:8000/predict/" . urlencode($drug_name);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($api_url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            error_log("API call failed for drug '$drug_name': " . ($error['message'] ?? 'Unknown error'));
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error for drug '$drug_name': " . json_last_error_msg());
            return null;
        }
        
        if (isset($data['error'])) {
            error_log("API returned error for drug '$drug_name': " . $data['error']);
            return null;
        }
        
        // Calculate total demand for next 3 months
        if (isset($data['predictions']) && is_array($data['predictions']) && count($data['predictions']) >= 3) {
            $total_demand = array_sum(array_slice($data['predictions'], 0, 3));
            return [
                'total_demand' => round($total_demand, 2),
                'month1' => isset($data['predictions'][0]) ? round($data['predictions'][0], 2) : 0,
                'month2' => isset($data['predictions'][1]) ? round($data['predictions'][1], 2) : 0,
                'month3' => isset($data['predictions'][2]) ? round($data['predictions'][2], 2) : 0,
                'method' => $data['method'] ?? 'Unknown',
                'historical_mean' => isset($data['historical_mean']) ? round($data['historical_mean'], 2) : null
            ];
        }
        
        error_log("Invalid predictions data for drug '$drug_name': predictions array missing or incomplete");
        return null;
    }

    // Deduplicate drugs before processing
    // Use an associative array to ensure unique drug names
    $unique_drugs = [];
    foreach ($drugs as $drug) {
        $drug_key = $is_admin ? $drug['drug_name'] . '|' . $drug['department'] : $drug['drug_name'];
        
        // If drug not seen before, or if this entry has higher stock, keep it
        if (!isset($unique_drugs[$drug_key])) {
            $unique_drugs[$drug_key] = $drug;
        } else {
            // If we've seen this drug before, keep the one with higher stock
            if ($drug['current_stock'] > $unique_drugs[$drug_key]['current_stock']) {
                $unique_drugs[$drug_key] = $drug;
            }
        }
    }

    // Convert back to indexed array
    $drugs = array_values($unique_drugs);
    
    error_log("Processing " . count($drugs) . " unique drugs for department: '$user_department'");

    // Get predictions for all unique drugs (optimize for admin - limit per department)
    $forecasts = [];
    
    if ($is_admin) {
        // For admin: Get top predictions per department (5 departments × 15 = 75 max)
        $drugs_by_dept = [];
        foreach ($drugs as $drug) {
            $dept = $drug['department'];
            if (!isset($drugs_by_dept[$dept])) {
                $drugs_by_dept[$dept] = [];
            }
            $drugs_by_dept[$dept][] = $drug;
        }
        
        // Process up to 20 drugs per department, then keep top 15
        $max_per_dept_initial = 20;
        $max_per_dept_final = 15;
        $processed = 0;
        $errors = 0;
        $dept_forecasts = [];
        
        foreach ($drugs_by_dept as $dept => $dept_drugs) {
            $dept_forecasts_list = [];
            $dept_processed = 0;
            
            foreach ($dept_drugs as $drug) {
                if ($dept_processed >= $max_per_dept_initial) {
                    break;
                }
                
                try {
                    $prediction = getPrediction($drug['drug_name']);
                    
                    if ($prediction !== null && isset($prediction['total_demand']) && $prediction['total_demand'] > 0) {
                        $dept_forecasts_list[] = [
                            'drug_name' => $drug['drug_name'],
                            'department' => $drug['department'],
                            'current_stock' => $drug['current_stock'],
                            'total_demand' => $prediction['total_demand'],
                            'month1' => $prediction['month1'],
                            'month2' => $prediction['month2'],
                            'month3' => $prediction['month3'],
                            'method' => $prediction['method'],
                            'historical_mean' => $prediction['historical_mean']
                        ];
                        
                        $processed++;
                        $dept_processed++;
                    } else {
                        $errors++;
                    }
                } catch (Exception $e) {
                    error_log("Exception processing drug '{$drug['drug_name']}' in department '$dept': " . $e->getMessage());
                    $errors++;
                }
                
                // Reduced delay for admin (25ms instead of 50ms)
                usleep(25000); // 25ms delay
            }
            
            // Sort department forecasts by total demand and keep top 15
            usort($dept_forecasts_list, function($a, $b) {
                return $b['total_demand'] <=> $a['total_demand'];
            });
            
            $dept_forecasts[$dept] = array_slice($dept_forecasts_list, 0, $max_per_dept_final);
        }
        
        // Combine all department forecasts
        foreach ($dept_forecasts as $dept => $dept_list) {
            $forecasts = array_merge($forecasts, $dept_list);
        }
        
        error_log("Admin processed $processed forecasts, $errors errors across all departments");
    } else {
        // For regular departments: Process up to 50 drugs
        $max_drugs = 50;
        $processed = 0;
        $errors = 0;

        foreach ($drugs as $drug) {
            if ($processed >= $max_drugs) {
                break;
            }
            
            try {
                $prediction = getPrediction($drug['drug_name']);
                
                if ($prediction !== null && isset($prediction['total_demand']) && $prediction['total_demand'] > 0) {
                    $forecasts[] = [
                        'drug_name' => $drug['drug_name'],
                        'department' => $drug['department'],
                        'current_stock' => $drug['current_stock'],
                        'total_demand' => $prediction['total_demand'],
                        'month1' => $prediction['month1'],
                        'month2' => $prediction['month2'],
                        'month3' => $prediction['month3'],
                        'method' => $prediction['method'],
                        'historical_mean' => $prediction['historical_mean']
                    ];
                    
                    $processed++;
                } else {
                    $errors++;
                }
            } catch (Exception $e) {
                error_log("Exception processing drug '{$drug['drug_name']}': " . $e->getMessage());
                $errors++;
            }
            
            // Small delay to prevent overwhelming the API
            usleep(50000); // 50ms delay
        }
        
        error_log("Processed $processed forecasts, $errors errors for department: '$user_department'");
    }

    // Sort by total demand (highest first)
    usort($forecasts, function($a, $b) {
        return $b['total_demand'] <=> $a['total_demand'];
    });

    // Return top predictions (75 for admin, 50 for departments)
    $max_results = $is_admin ? 75 : 50;
    $forecasts = array_slice($forecasts, 0, $max_results);

    echo json_encode(['forecasts' => $forecasts]);

} catch (Exception $e) {
    error_log("Fatal error in get_forecast_drugs.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>

