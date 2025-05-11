<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/header.php';

requireLogin();

$rentalId = 0;
if (isset($_POST['rental_id'])) {
    $rentalId = (int)$_POST['rental_id'];
} elseif (isset($_GET['rental_id'])) {
    $rentalId = (int)$_GET['rental_id'];
}

// Check if cancellation is requested
if (isset($_POST['cancel_confirm'])) {
    // Update rental status to 'Canceled' and set ReturnDate to current date
    $stmt = $pdo->prepare("UPDATE Rental SET Status = 'Canceled', ReturnDate = CURDATE() WHERE RentalID = :rentalId AND RenterID = :renterId");
    $stmt->execute([
        'rentalId' => $rentalId,
        'renterId' => $_SESSION['user_id']
    ]);
    $_SESSION['success_message'] = "Rental request has been cancelled successfully.";
    header('Location: view_rentals.php');
    exit();
}

// Fetch rental details
$stmt = $pdo->prepare("
    SELECT r.*, t.Name as ToolName, t.ToolID,
           u.FirstName as OwnerFirstName, u.LastName as OwnerLastName
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    JOIN User u ON t.OwnerID = u.UserID
    WHERE r.RentalID = :rentalId 
    AND r.RenterID = :renterId
    AND r.Status NOT IN ('cancel', 'Canceled')
");
$stmt->execute([
    'rentalId' => $rentalId,
    'renterId' => $_SESSION['user_id']
]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    header('Location: view_rentals.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['cancel_confirm'])) {
    // Removed review submission code as per user request
}
?>

<div class="container mx-auto my-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold">Cancel Rental Request</h2>
            <a href="view_rentals.php" class="text-blue-600 hover:underline">Back to Rentals</a>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="mb-6">
                <h3 class="font-semibold mb-2">Rental Details</h3>
                <p>Tool: <?php echo htmlspecialchars($rental['ToolName']); ?></p>
                <p>Owner: <?php echo htmlspecialchars($rental['OwnerFirstName'] . ' ' . $rental['OwnerLastName']); ?></p>
                <p>Dates: <?php 
                    echo date('M d, Y', strtotime($rental['RentalStartDate'])) . ' - ' . 
                    date('M d, Y', strtotime($rental['RentalEndDate'])); 
                ?></p>
            </div>

            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="rental_id" value="<?php echo $rentalId; ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Are you sure you want to cancel this rental request?</label>
                </div>
                <button type="submit" name="cancel_confirm" value="1"
                    class="w-full bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    Confirm Cancel
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
