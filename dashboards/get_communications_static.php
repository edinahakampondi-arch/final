<?php
// get_communications_static.php
require_once '../connect.php';
session_start();

if (!isset($_SESSION['department'])) {
    http_response_code(403);
    echo '<p class="text-red-600">Unauthorized</p>';
    exit;
}

$user_department = mysqli_real_escape_string($conn, $_SESSION['department']);
$query = "SELECT * FROM communications 
          WHERE from_department = '$user_department' OR to_department = '$user_department' 
          ORDER BY timestamp DESC LIMIT 5";
$result = mysqli_query($conn, $query);

if (!$result) {
    error_log("Fetch Communications Error: " . mysqli_error($conn));
    echo '<p class="text-red-600">Error fetching communications</p>';
    exit;
}

if (mysqli_num_rows($result) === 0) {
    echo '<p class="text-gray-600">No communications found.</p>';
    exit;
}

while ($row = mysqli_fetch_assoc($result)) {
    $priority_class = $row['priority'] === 'high' ? 'bg-red-100 text-red-800' : ($row['priority'] === 'medium' ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800');
    $status_class = $row['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : ($row['status'] === 'delivered' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800');
?>
    <div class="p-4 border rounded-lg bg-white shadow hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start mb-3">
            <div>
                <p class="font-medium text-sm">
                    From: <span class="text-blue-600"><?php echo htmlspecialchars($row['from_department']); ?></span> →
                    To: <span class="text-green-600"><?php echo htmlspecialchars($row['to_department']); ?></span>
                </p>
                <p class="text-xs text-gray-500 flex items-center gap-1 mt-1">
                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path d="M12 8v4l3 3" />
                    </svg>
                    <?php echo htmlspecialchars($row['timestamp']); ?>
                </p>
            </div>
            <div class="flex gap-2">
                <span class="px-2 py-1 text-xs rounded <?php echo $priority_class; ?>">
                    <?php echo ucfirst($row['priority']); ?>
                </span>
                <span class="px-2 py-1 text-xs rounded <?php echo $status_class; ?>">
                    <?php echo ucfirst($row['status']); ?>
                </span>
            </div>
        </div>
        <p class="text-sm text-gray-700 bg-gray-50 p-3 rounded-lg">
            <?php echo htmlspecialchars($row['message']); ?>
        </p>
    </div>
<?php
}
?>