<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_connection.php';

// This script checks for rentals past their RentalEndDate with no ReturnDate
// and creates notifications for renters about late returns.

// Get current date (date only)
$today = date('Y-m-d');

// Find overdue rentals
$stmt = $pdo->prepare("
    SELECT r.RentalID, r.RenterID, t.Name as ToolName, r.RentalEndDate
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    WHERE r.ReturnDate IS NULL AND r.RentalEndDate < :today AND r.Status IN ('Approved', 'Pending')
");
$stmt->execute(['today' => $today]);
$overdueRentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($overdueRentals as $rental) {
    $renterId = $rental['RenterID'];
    $rentalId = $rental['RentalID'];
    $toolName = $rental['ToolName'];
    $dueDate = $rental['RentalEndDate'];

    // Check if notification already exists for this rental and renter to avoid duplicates
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM Notification
        WHERE UserID = :userId AND Message LIKE :messagePattern
    ");
    $messagePattern = "%overdue for tool ID $rentalId%";
    $checkStmt->execute(['userId' => $renterId, 'messagePattern' => $messagePattern]);
    $exists = $checkStmt->fetchColumn();

    if (!$exists) {
        $message = "Your rental for tool '$toolName' (Rental ID: $rentalId) is overdue since $dueDate. Please return it as soon as possible to avoid losing your deposit.";
        $insertStmt = $pdo->prepare("
            INSERT INTO Notification (UserID, Message, NotificationTimestamp, IsRead)
            VALUES (:userId, :message, NOW(), 0)
        ");
        $insertStmt->execute(['userId' => $renterId, 'message' => $message]);
    }
}
?>
