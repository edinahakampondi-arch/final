<?php
// Database connection
$servername = "localhost";
$dbUsername = "root";
$dbPassword = ""; // your DB password
$database = "system"; // your DB name

// Use procedural connection for consistency with existing code
$conn = mysqli_connect($servername, $dbUsername, $dbPassword, $database);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}