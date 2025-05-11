<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/header.php';

requireLogin();

if (!isset($_GET['rental_id'])) {
    header('Location: view_rentals.php');
    exit();
}

$rentalId = (int)$_GET['rental_id'];

// Fetch rental details with payment info
$stmt = $pdo->prepare("
    SELECT r.*, t.Name as ToolName, t.PricePerDay, 
           u.FirstName as OwnerFirstName, u.LastName as OwnerLastName,
           p.PaymentAmount, p.PaymentStatus
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    JOIN User u ON t.OwnerID = u.UserID
    LEFT JOIN Payment p ON r.RentalID = p.RentalID
    WHERE r.RentalID = :rentalId
      AND r.RenterID = :renterId
      AND r.Status = 'Approved'
");
$stmt->execute([
    'rentalId' => $rentalId,
    'renterId' => $_SESSION['user_id']
]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    $_SESSION['error_message'] = "Rental not found or not approved.";
    header('Location: view_rentals.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Simulate payment processing
    try {
        $pdo->beginTransaction();

        // Update or insert payment record
        $stmt = $pdo->prepare("SELECT PaymentID FROM Payment WHERE RentalID = :rentalId");
        $stmt->execute(['rentalId' => $rentalId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($payment) {
            $stmt = $pdo->prepare("
                UPDATE Payment 
                SET PaymentStatus = 'Completed', PaymentDate = NOW()
                WHERE PaymentID = :paymentId
            ");
            $stmt->execute(['paymentId' => $payment['PaymentID']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO Payment (RentalID, PaymentAmount, PaymentStatus, PaymentDate)
                VALUES (:rentalId, :amount, 'Completed', NOW())
            ");
            $stmt->execute([
                'rentalId' => $rentalId,
                'amount' => $rental['TotalPrice']
            ]);
        }

        // Optionally update Rental status to 'Paid' or keep as 'Approved'
        // For now, keep as 'Approved' to allow further processing

        // Insert notifications for renter and owner
        $renterId = $_SESSION['user_id'];
        $ownerIdStmt = $pdo->prepare("SELECT OwnerID FROM Tool WHERE ToolID = :toolId");
        $ownerIdStmt->execute(['toolId' => $rental['ToolID']]);
        $ownerId = $ownerIdStmt->fetchColumn();

        $notificationStmt = $pdo->prepare("
            INSERT INTO Notification (UserID, Message) VALUES (:userId, :message)
        ");

        $notificationStmt->execute([
            'userId' => $renterId,
            'message' => "Payment completed for rental of tool '{$rental['ToolName']}'."
        ]);

        $notificationStmt->execute([
            'userId' => $ownerId,
            'message' => "Rental payment received for your tool '{$rental['ToolName']}'."
        ]);

        $pdo->commit();

        $_SESSION['success_message'] = "Payment completed successfully!";
        header('Location: view_rentals.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Payment failed: " . $e->getMessage();
    }
}
?>

<div class="container mx-auto my-8">
    <div class="max-w-lg mx-auto bg-white p-6 rounded shadow">
        <h2 class="text-2xl font-bold mb-4">Pay for Rental</h2>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="mb-4">
            <p><strong>Tool:</strong> <?php echo htmlspecialchars($rental['ToolName']); ?></p>
            <p><strong>Owner:</strong> <?php echo htmlspecialchars($rental['OwnerFirstName'] . ' ' . $rental['OwnerLastName']); ?></p>
            <p><strong>Rental Dates:</strong> <?php echo date('M d, Y', strtotime($rental['RentalStartDate'])) . ' - ' . date('M d, Y', strtotime($rental['RentalEndDate'])); ?></p>
            <p><strong>Total Price:</strong> $<?php echo number_format($rental['TotalPrice'], 2); ?></p>
            <p><strong>Payment Status:</strong> <?php echo htmlspecialchars($rental['PaymentStatus'] ?? 'Pending'); ?></p>
        </div>

        <form method="POST" action="">
            <p class="mb-4">Please confirm your payment details and submit to complete the payment.</p>

            <!-- Simulated payment form fields -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Card Number</label>
                <input type="text" name="card_number" required maxlength="19" placeholder="1234 5678 9012 3456"
                       class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
                    <input type="text" name="expiry_date" required placeholder="MM/YY"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CVV</label>
                    <input type="text" name="cvv" required maxlength="4" placeholder="123"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700">
                Submit Payment
            </button>
        </form>

        <div class="mt-4">
            <a href="view_rentals.php" class="text-blue-600 hover:underline">Back to My Rentals</a>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
