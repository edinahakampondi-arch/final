<?php
$drug = isset($_GET['drug']) ? $_GET['drug'] : '';
if (empty($drug)) {
    die("<p class='text-red-600'>Error: Drug name is required</p>");
}

$api = "http://127.0.0.1:8000/predict/" . urlencode($drug);
$context = stream_context_create([
    'http' => [
        'timeout' => 30,
        'method' => 'GET',
        'header' => 'Accept: application/json'
    ]
]);

$response = @file_get_contents($api, false, $context);
if ($response === false) {
    $error = error_get_last();
    die("<p class='text-red-600'>Error: Could not connect to prediction API. Please ensure the FastAPI server is running on port 8000.<br>Error: " . ($error['message'] ?? 'Unknown error') . "</p>");
}

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("<p class='text-red-600'>Error: Invalid JSON response from API. Response: " . htmlspecialchars(substr($response, 0, 200)) . "</p>");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Prediction Results - <?php echo htmlspecialchars($drug); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="bg-gray-100 p-6">

<div class="max-w-4xl mx-auto bg-white shadow-lg rounded-lg p-6">
    <h2 class="text-2xl font-bold mb-4">Prediction for: <?php echo htmlspecialchars($drug); ?></h2>

    <?php if(isset($data['error'])) { ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <p class="font-bold">Error:</p>
            <p><?php echo htmlspecialchars($data['error']); ?></p>
            <?php if(isset($data['suggestion'])) { ?>
                <p class="mt-2 text-sm"><?php echo htmlspecialchars($data['suggestion']); ?></p>
            <?php } ?>
        </div>
    <?php } else if(!isset($data['predictions']) || empty($data['predictions'])) { ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
            <p>Warning: No predictions returned from API.</p>
        </div>
    <?php } else { ?>

        <canvas id="forecastChart" height="80"></canvas>

        <script>
            const months = <?php echo json_encode($data['months'] ?? []); ?>;
            const preds  = <?php echo json_encode($data['predictions'] ?? []); ?>;

            if (months.length > 0 && preds.length > 0) {
                const ctx = document.getElementById("forecastChart").getContext("2d");
                new Chart(ctx, {
                    type: "line",
                    data: {
                        labels: months,
                        datasets: [{
                            label: "Predicted Demand",
                            data: preds,
                            borderColor: "rgb(99, 102, 241)",
                            backgroundColor: "rgba(99, 102, 241, 0.1)",
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: "3-Month Demand Forecast"
                            }
                        }
                    }
                });
            }
        </script>

        <h3 class="font-semibold mt-6 mb-3">Prediction Table</h3>

        <table class="w-full border-collapse border border-gray-300 mt-3">
            <thead>
                <tr class="bg-gray-200">
                    <th class="border border-gray-300 px-4 py-2">Month</th>
                    <th class="border border-gray-300 px-4 py-2">Predicted Demand (units)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (isset($data['months']) && isset($data['predictions'])) {
                    for($i=0; $i < count($data['months']) && $i < count($data['predictions']); $i++){
                        $month = htmlspecialchars($data['months'][$i]);
                        $pred = round($data['predictions'][$i]);
                        echo "<tr>";
                        echo "<td class='border border-gray-300 px-4 py-2'>{$month}</td>";
                        echo "<td class='border border-gray-300 px-4 py-2'><strong>{$pred}</strong> units</td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>

        <?php if(isset($data['method'])) { ?>
            <p class="text-sm text-gray-600 mt-4">Prediction Method: <strong><?php echo htmlspecialchars($data['method']); ?></strong></p>
        <?php } ?>
        
        <?php if(isset($data['historical_mean'])) { ?>
            <p class="text-sm text-gray-600">Historical Average: <strong><?php echo round($data['historical_mean']); ?></strong> units</p>
        <?php } ?>
        
        <?php if(isset($data['note'])) { ?>
            <p class="text-xs text-yellow-600 mt-2">Note: <?php echo htmlspecialchars($data['note']); ?></p>
        <?php } ?>

    <?php } ?>
    
    <div class="mt-6">
        <a href="predict.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Run Another Prediction</a>
    </div>
</div>

</body>
</html>
