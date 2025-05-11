<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';

requireLogin(); // Ensure the user is logged in
if (getUserType() !== 'Owner') {
    header('Location: ' . BASE_URL . 'dashboard/renter/index.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . 'dashboard/owner/rentals.php');
    exit();
}

$rentalID = $_GET['id'];

// Verify the rental belongs to the owner's tool and is in Returned or Approved status
$stmt = $pdo->prepare("
    SELECT r.* FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    WHERE r.RentalID = :rentalID 
    AND t.OwnerID = :ownerID
    AND r.Status IN ('Returned', 'Approved')
");
$stmt->execute(['rentalID' => $rentalID, 'ownerID' => $_SESSION['user_id']]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    header('Location: ' . BASE_URL . 'dashboard/owner/rentals.php');
    exit();
}

$currentDate = date('Y-m-d H:i:s');
// Update rental ReturnDate and status to Completed
$stmt = $pdo->prepare("UPDATE Rental SET ReturnDate = :returnDate, Status = 'Completed' WHERE RentalID = :rentalID");
$stmt->execute(['returnDate' => $currentDate, 'rentalID' => $rentalID]);

header('Location: ' . BASE_URL . 'dashboard/owner/rentals.php');
exit();
?>
