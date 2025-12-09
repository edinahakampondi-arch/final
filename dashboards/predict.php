<?php
$drug = isset($_GET['drug']) ? $_GET['drug'] : '';
$show_results = !empty($drug);

// If drug is submitted, fetch prediction results
$data = null;
$api_error = null;

if ($show_results) {
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
        $api_error = "Could not connect to prediction API. Please ensure the FastAPI server is running on port 8000.<br>Error: " . ($error['message'] ?? 'Unknown error');
    } else {
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $api_error = "Invalid JSON response from API. Response: " . htmlspecialchars(substr($response, 0, 200));
        }
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Run Prediction<?php echo $show_results ? ' - ' . htmlspecialchars($drug) : ''; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
    /* Autocomplete dropdown */
    #suggestions {
        position: absolute;
        background: white;
        border: 1px solid #ddd;
        border-radius: 6px;
        width: 100%;
        z-index: 1000;
    }

    #suggestions div {
        padding: 8px;
        cursor: pointer;
    }

    #suggestions div:hover {
        background: #f3f4f6;
    }
    </style>
</head>

<body class="bg-gray-100 p-6">

<?php if ($show_results && ($api_error || (isset($data['error'])))) { ?>
    <div class="max-w-4xl mx-auto bg-white shadow-lg rounded-lg p-6">
        <h2 class="text-2xl font-bold mb-4">Prediction for: <?php echo htmlspecialchars($drug); ?></h2>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <p class="font-bold">Error:</p>
            <p><?php echo htmlspecialchars($api_error ?? $data['error'] ?? 'Unknown error'); ?></p>
            <?php if(isset($data['suggestion'])) { ?>
                <p class="mt-2 text-sm"><?php echo htmlspecialchars($data['suggestion']); ?></p>
            <?php } ?>
        </div>
        <div class="mt-6">
            <a href="predict.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Run Another Prediction</a>
        </div>
    </div>
<?php } else if ($show_results && isset($data['predictions']) && !empty($data['predictions'])) { ?>
    <div class="max-w-4xl mx-auto bg-white shadow-lg rounded-lg p-6">
        <h2 class="text-2xl font-bold mb-4">Prediction for: <?php echo htmlspecialchars($drug); ?></h2>

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
        
        <div class="mt-6">
            <a href="predict.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Run Another Prediction</a>
        </div>
    </div>
<?php } else { ?>
    <div class="max-w-xl mx-auto bg-white shadow-lg rounded-lg p-6 relative">
        <h2 class="text-2xl font-bold mb-4">Run Demand Forecast</h2>

        <form action="predict.php" method="GET" autocomplete="off">
            <label class="block mb-1 font-medium">Type Drug Name</label>

            <input type="text" id="drugInput" name="drug" value="<?php echo htmlspecialchars($drug); ?>"
                class="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-purple-500"
                placeholder="Start typing...">

            <div id="suggestions" class="hidden"></div>

            <button type="submit" class="mt-4 w-full bg-purple-600 text-white py-2 rounded hover:bg-purple-700">
                Predict
            </button>
        </form>
    </div>
<?php } ?>

    <?php if (!$show_results) { ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const drugInput = document.getElementById("drugInput");
        if (drugInput) {
            drugInput.addEventListener("keyup", function() {
                const query = this.value.trim();
                const suggestionsDiv = document.getElementById("suggestions");

                if (query.length < 1) {
                    suggestionsDiv.innerHTML = "";
                    suggestionsDiv.classList.add("hidden");
                    return;
                }

                fetch(`search_drug.php?q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.length === 0) {
                            suggestionsDiv.innerHTML = "<div>No match found</div>";
                        } else {
                            suggestionsDiv.innerHTML = data
                                .map(name =>
                                    `<div onclick="selectDrug('${name.replace(/'/g, "\\'")}')">${name}</div>`)
                                .join('');
                        }
                        suggestionsDiv.classList.remove("hidden");
                    });
            });
        }
    });

    function selectDrug(name) {
        const drugInput = document.getElementById("drugInput");
        if (drugInput) {
            drugInput.value = name;
            const suggestionsDiv = document.getElementById("suggestions");
            if (suggestionsDiv) {
                suggestionsDiv.classList.add("hidden");
            }
        }
    }
    </script>
    <?php } ?>

</body>

</html>