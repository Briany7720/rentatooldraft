<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';

requireLogin(); // Ensure the user is logged in
if (getUserType() !== 'Renter') {
    header('Location: ' . BASE_URL . 'dashboard/owner/index.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . 'dashboard/renter/rentals.php');
    exit();
}

$rentalID = $_GET['id'];

// Verify the rental belongs to the renter
$stmt = $pdo->prepare("
    SELECT r.* FROM Rental r
    WHERE r.RentalID = :rentalID AND r.RenterID = :renterID
    AND r.Status = 'Approved'
");
$stmt->execute(['rentalID' => $rentalID, 'renterID' => $_SESSION['user_id']]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    header('Location: ' . BASE_URL . 'dashboard/renter/rentals.php');
    exit();
}

// Update rental status to Returned
$stmt = $pdo->prepare("UPDATE Rental SET Status = 'Returned' WHERE RentalID = :rentalID");
$stmt->execute(['rentalID' => $rentalID]);

header('Location: ' . BASE_URL . 'dashboard/renter/rentals.php');
exit();
?>
