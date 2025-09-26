<?php
session_start();
include 'connect.php';

// Login logic
$loginError = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $username = trim($_POST["username"]);
  $password = trim($_POST["password"]);

  if (empty($username) || empty($password)) {
    $loginError = "Please enter both username and password.";
  } else {
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);

    if ($stmt->execute()) {
      $result = $stmt->get_result();
      if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Secure password verification
        if (password_verify($password, $user['password'])) {
          $_SESSION["user_id"] = $user["id"];
          $_SESSION["username"] = $user["username"];
          $_SESSION["department"] = $user["department"];

          switch ($user["department"]) {
            case "Admin":
              header("Location: dashboards/admin_dashboard.php");
              break;
            case "Surgery":
              header("Location: dashboards/surgery.php");
              break;
            case "Paediatrics":
              header("Location: dashboards/Paediatrics.php");
              break;
            case "Gynaecology":
              header("Location: dashboards/gynaecology.php");
              break;
            case "Internal medicine":
              header("Location: dashboards/Internal medicine.php");
              break;
            case "Intensive Care unit":
              header("Location: dashboards/Intensive Care unit.php");
              break;
            default:
              header("Location: general_dashboard.php");
          }
          exit();
        } else {
          $loginError = "Invalid password.";
        }
      } else {
        $loginError = "User not found.";
      }
    } else {
      $loginError = "Something went wrong. Try again.";
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - Drug Distribution System</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
  <div class="flex w-full max-w-5xl bg-white rounded-lg shadow-lg overflow-hidden">

    <!-- Image Section -->
    <div class="hidden md:block md:w-1/2 bg-cover bg-center" style="background-image: url('pics/doctor.png')"></div>

    <!-- Form Section -->
    <div class="w-full md:w-1/2 p-8">
      <div class="text-center mb-6">
        <i data-lucide="shield" class="h-12 w-12 text-blue-600 mx-auto"></i>
        <h2 class="text-2xl font-bold mt-2">Drug Distribution <br> System</h2>
        <br>
        <p class="text-gray-600 text-sm">Sign in to access the hospital inventory system</p>
        <br>
      </div>

      <?php if ($loginError): ?>
        <div class="mb-4 p-4 border-l-4 border-red-500 bg-red-50 rounded-r-lg">
          <p class="text-sm text-red-800"><?= htmlspecialchars($loginError) ?></p>
        </div>
      <?php endif; ?>

      <form method="POST" action="" class="space-y-4">
        <!-- Username -->
        <div>
          <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
          <div class="relative">
            <i data-lucide="user" class="absolute left-3 top-3 h-4 w-4 text-gray-400"></i>
            <input
              id="username"
              name="username"
              type="text"
              placeholder="Enter your username"
              class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              required />
          </div>
        </div>

        <!-- Password -->
        <div>
          <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
          <div class="relative">
            <i data-lucide="lock" class="absolute left-3 top-3 h-4 w-4 text-gray-400"></i>
            <input
              id="password"
              name="password"
              type="password"
              placeholder="Enter your password"
              class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              required />
          </div>
        </div>

        <div class="flex justify-end">
          <a href="#" class="text-sm text-blue-600 hover:underline">Forgot password?</a>
        </div>

        <!-- Submit -->
        <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 transition">
          Sign In
        </button>
        <br>
        <br>


      </form>
    </div>
  </div>

  <script>
    lucide.createIcons();
  </script>
</body>

</html>