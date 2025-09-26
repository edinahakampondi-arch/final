<?php
// Database connection
$servername = "localhost";
$dbUsername = "root";
$dbPassword = ""; // your DB password
$database = "system"; // your DB name

$conn = new mysqli($servername, $dbUsername, $dbPassword, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
