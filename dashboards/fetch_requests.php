<?php
// fetch_requests.php
require_once '../connect.php';
session_start();

if (!isset($_SESSION['department'])) {
    http_response_code(403);
    echo '<p class="text-red-600">Unauthorized</p>';
    exit;
}

$user_department = mysqli_real_escape_string($conn, $_SESSION['department']);
$query = "SELECT * FROM borrowing_requests 
          WHERE from_department = '$user_department' OR to_department = '$user_department' 
          ORDER BY request_time DESC";
$result = mysqli_query($conn, $query);

if (!$result) {
    error_log("Fetch Requests Error: " . mysqli_error($conn));
    echo '<p class="text-red-600">Error fetching requests</p>';
    exit;
}

if (mysqli_num_rows($result) === 0) {
    echo '<p class="text-gray-600">No borrowing requests found.</p>';
    exit;
}

echo '<div class="space-y-4 max-h-[500px] overflow-y-auto pr-2">'; // Scrollable container starts here

while ($row = mysqli_fetch_assoc($result)) {
    $status_color = $row['status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : ($row['status'] === 'Approved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
?>
    <div class="border rounded p-4 shadow-sm">
        <div class="flex justify-between items-center">
            <div>
                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($row['drug_name']); ?> (<?php echo $row['quantity']; ?> units)</p>
                <p class="text-sm text-gray-600">From: <?php echo htmlspecialchars($row['from_department']); ?> → To: <?php echo htmlspecialchars($row['to_department']); ?></p>
                <p class="text-sm text-gray-600">Expiry: <?php echo htmlspecialchars($row['expiry_date'] ?: 'N/A'); ?></p>
                <p class="text-sm text-gray-600">Stock Range: <?php echo htmlspecialchars($row['min_stock'] . ' - ' . $row['max_stock']); ?> units</p>
                <p class="text-sm text-gray-500"><?php echo $row['request_time']; ?></p>
                <?php if ($row['status'] === 'Approved' && $row['approved_time']) { ?>
                    <p class="text-sm text-gray-500">Approved: <?php echo $row['approved_time']; ?></p>
                <?php } elseif ($row['status'] === 'Rejected' && $row['approved_time']) { ?>
                    <p class="text-sm text-gray-500">Rejected: <?php echo $row['approved_time']; ?></p>
                <?php } ?>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-1 text-xs rounded <?php echo $status_color; ?>"><?php echo $row['status']; ?></span>
                <?php if ($row['status'] === 'Pending' && $row['from_department'] === $user_department) { ?>
                    <button onclick="approveRequest(<?php echo $row['id']; ?>)" class="bg-green-600 text-white px-3 py-1 text-sm rounded hover:bg-green-700">Approve</button>
                    <button onclick="rejectRequest(<?php echo $row['id']; ?>)" class="bg-red-600 text-white px-3 py-1 text-sm rounded hover:bg-red-700">Reject</button>
                <?php } ?>
                <?php if ($row['status'] === 'Pending' && $row['to_department'] === $user_department) { ?>
                    <button onclick="cancelRequest(<?php echo $row['id']; ?>)" class="bg-gray-600 text-white px-3 py-1 text-sm rounded hover:bg-gray-700">Cancel</button>
                <?php } ?>
            </div>
        </div>
    </div>
<?php
}

echo '</div>'; // Scrollable container ends here
?>