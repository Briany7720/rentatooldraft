<?php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Sanitize inputs
        $firstName = sanitizeInput($_POST['firstName']);
        $lastName = sanitizeInput($_POST['lastName']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirmPassword'];
        $phoneNumber = sanitizeInput($_POST['phoneNumber']);
        $userType = sanitizeInput($_POST['userType']);

        // Validate passwords match
        if ($password !== $confirmPassword) {
            throw new Exception("Passwords do not match.");
        }

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT UserID FROM User WHERE Email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            throw new Exception("Email already registered.");
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user with default ReputationScore = 3
        $stmt = $pdo->prepare("
            INSERT INTO User (FirstName, LastName, PhoneNumber, Email, Password, UserType, ReputationScore)
            VALUES (:firstName, :lastName, :phoneNumber, :email, :password, :userType, 3)
        ");

        $stmt->execute([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phoneNumber' => $phoneNumber,
            'email' => $email,
            'password' => $hashedPassword,
            'userType' => $userType
        ]);

        // Redirect to login with success message
        $_SESSION['success_message'] = "Registration successful! Please login.";
        header('Location: login.php');
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Rent-a-Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto my-8 max-w-md">
        <div class="bg-white p-8 rounded-lg shadow-md">
            <h2 class="text-2xl font-bold mb-6 text-center">Create Account</h2>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-500 text-white p-3 rounded mb-4"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="" onsubmit="return validateForm(this)">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="firstName" class="block text-sm font-medium text-gray-700">First Name</label>
                        <input type="text" name="firstName" id="firstName" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                    </div>
                    <div>
                        <label for="lastName" class="block text-sm font-medium text-gray-700">Last Name</label>
                        <input type="text" name="lastName" id="lastName" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" name="email" id="email" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-4">
                    <label for="phoneNumber" class="block text-sm font-medium text-gray-700">Phone Number</label>
                    <input type="tel" name="phoneNumber" id="phoneNumber" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" name="password" id="password" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-4">
                    <label for="confirmPassword" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                    <input type="password" name="confirmPassword" id="confirmPassword" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-6">
                    <label for="userType" class="block text-sm font-medium text-gray-700">Account Type</label>
                    <select name="userType" id="userType" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                        <option value="">Select account type</option>
                        <option value="Owner">Tool Owner</option>
                        <option value="Renter">Tool Renter</option>
                    </select>
                </div>

                <button type="submit" 
                    class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Register
                </button>
            </form>

            <p class="mt-4 text-center text-sm text-gray-600">
                Already have an account? 
                <a href="login.php" class="text-blue-600 hover:text-blue-800">Login here</a>
            </p>
        </div>
    </div>

    <script>
        function validateForm(form) {
            const password = form.password.value;
            const confirmPassword = form.confirmPassword.value;

            if (password !== confirmPassword) {
                showAlert('Passwords do not match!', 'error');
                return false;
            }

            return true;
        }
    </script>
</body>
</html>
