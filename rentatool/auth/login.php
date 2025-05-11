<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];

    // Check if user exists
    $stmt = $pdo->prepare("SELECT UserID, Password, UserType FROM User WHERE Email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['Password'])) {
        // Set session variables
        $_SESSION['user_id'] = $user['UserID'];
        $_SESSION['user_type'] = $user['UserType'];
        
        // Redirect based on user type
        if ($user['UserType'] === 'Owner') {
            header('Location: ../dashboard/owner/index.php');
        } else if ($user['UserType'] === 'Renter') {
            header('Location: ../dashboard/renter/index.php');
        }
    } else {
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Rent-a-Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mx-auto my-8">
        <h2 class="text-2xl font-bold mb-4">Login</h2>
        <?php if (isset($error)): ?>
            <div class="bg-red-500 text-white p-2 mb-4"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium">Email</label>
                <input type="email" name="email" id="email" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
            </div>
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium">Password</label>
                <input type="password" name="password" id="password" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Login</button>
        </form>
        <p class="mt-4">Don't have an account? <a href="register.php" class="text-blue-600">Register here</a>.</p>
    </div>
</body>
</html>
