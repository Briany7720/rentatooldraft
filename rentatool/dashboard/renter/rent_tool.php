<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/header.php';
require_once '../../includes/photo_utils.php';

requireLogin();

if (!isset($_GET['tool_id'])) {
    header('Location: search_tools.php');
    exit();
}

// Fetch tool details
$stmt = $pdo->prepare("
    SELECT t.*, u.FirstName, u.LastName, u.ReputationScore
    FROM Tool t
    JOIN User u ON t.OwnerID = u.UserID
    WHERE t.ToolID = :toolID AND t.AvailabilityStatus = 'Available'
");
$stmt->execute(['toolID' => $_GET['tool_id']]);
$tool = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tool) {
    header('Location: search_tools.php');
    exit();
}

// Fetch tool photos
$photos = getToolPhotos($tool['ToolID']);

// Handle rental request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Calculate rental duration and costs
        $startDate = new DateTime($_POST['start_date']);
        $endDate = new DateTime($_POST['end_date']);
        $duration = $startDate->diff($endDate)->days + 1;

        // Check for overlapping rentals for the same tool
        $overlapStmt = $pdo->prepare("
            SELECT COUNT(*) FROM Rental
            WHERE ToolID = :toolID
              AND Status NOT IN ('Rejected', 'Cancelled')
              AND (
                (RentalStartDate <= :endDate AND COALESCE(ReturnDate, RentalEndDate) >= :startDate)
              )
        ");
        $overlapStmt->execute([
            'toolID' => $tool['ToolID'],
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d')
        ]);
        $overlapCount = $overlapStmt->fetchColumn();

        if ($overlapCount > 0) {
            throw new Exception("The tool is already rented for the selected period. Please choose different dates.");
        }
        
        $basePrice = $tool['PricePerDay'] * $duration;
        $depositFee = $basePrice * 0.5; // 50% deposit
        $serviceFee = $basePrice * 0.1; // 10% service fee
        $totalPrice = $basePrice + $depositFee + $serviceFee;

        // Begin transaction
        $pdo->beginTransaction();

        // Create rental record
        $stmt = $pdo->prepare("
            INSERT INTO Rental (
                ToolID, RenterID, RentalStartDate, RentalEndDate,
                BaseRentalPrice, DepositFee, ServiceFee, TotalPrice, Status
            ) VALUES (
                :toolID, :renterID, :startDate, :endDate,
                :basePrice, :depositFee, :serviceFee, :totalPrice, 'Pending'
            )
        ");

        $stmt->execute([
            'toolID' => $tool['ToolID'],
            'renterID' => $_SESSION['user_id'],
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'basePrice' => $basePrice,
            'depositFee' => $depositFee,
            'serviceFee' => $serviceFee,
            'totalPrice' => $totalPrice
        ]);

        $rentalID = $pdo->lastInsertId();

        // Create pending payment record
        $stmt = $pdo->prepare("
            INSERT INTO Payment (RentalID, PaymentAmount, PaymentStatus)
            VALUES (:rentalID, :amount, 'Pending')
        ");

        $stmt->execute([
            'rentalID' => $rentalID,
            'amount' => $totalPrice
        ]);

        // Create notification for tool owner
        $stmt = $pdo->prepare("
            INSERT INTO Notification (UserID, Message)
            VALUES (:ownerID, :message)
        ");

        $stmt->execute([
            'ownerID' => $tool['OwnerID'],
            'message' => "New rental request for your tool: " . $tool['Name']
        ]);

        $pdo->commit();
        $success = "Rental request submitted successfully! <a href='" . BASE_URL . "dashboard/renter/index.php' class='text-blue-600 hover:underline'>Return to Dashboard</a>";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage() ?: "Error processing rental request. Please try again.";
    }
}
?>

<div class="container mx-auto my-8">
    <div class="max-w-2xl mx-auto">
        <h2 class="text-2xl font-bold mb-6">Rent Tool</h2>

        <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo $success; ?>
                <p class="mt-2">
                    <a href="<?php echo BASE_URL; ?>dashboard/renter/view_rentals.php" class="text-green-700 underline">
                        View your rentals
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="p-6">
                <h3 class="text-xl font-semibold mb-4"><?php echo htmlspecialchars($tool['Name']); ?></h3>

                <?php if (!empty($photos)): ?>
                    <div class="mb-6 flex space-x-4 overflow-x-auto">
                        <?php foreach ($photos as $photo): ?>
                            <img src="<?php echo htmlspecialchars(BASE_URL . $photo['PhotoPath']); ?>" alt="Tool Photo" class="h-32 rounded-md object-cover">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <p class="text-gray-600">Category</p>
                        <p class="font-medium"><?php echo htmlspecialchars($tool['Category']); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Price per Day</p>
                        <p class="font-medium">$<?php echo number_format($tool['PricePerDay'], 2); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-600">Owner</p>
                        <p class="font-medium">
                            <a href="<?php echo BASE_URL; ?>dashboard/user_profile.php?user_id=<?php echo $tool['OwnerID']; ?>" class="text-blue-600 hover:underline">
                                <?php echo htmlspecialchars($tool['FirstName'] . ' ' . $tool['LastName']); ?>
                            </a>
                            <span class="text-sm text-gray-500">
                                (Rating: <?php echo number_format($tool['ReputationScore'], 1); ?>★)
                            </span>
                        </p>
                    </div>
                </div>

                <div class="border-t pt-6">
                    <h4 class="font-semibold mb-4">Rental Details</h4>
                    <form method="POST" action="" onsubmit="return validateRentalForm(this)">
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Start Date</label>
                                <input type="date" name="start_date" required
                                    min="<?php echo date('Y-m-d'); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">End Date</label>
                                <input type="date" name="end_date" required
                                    min="<?php echo date('Y-m-d'); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                        </div>

                        <div class="bg-gray-50 p-4 rounded-md mb-6">
                            <h5 class="font-medium mb-2">Rental Terms</h5>
                            <ul class="text-sm text-gray-600 space-y-1">
                                <li>• 50% security deposit required</li>
                                <li>• 10% service fee applies</li>
                                <li>• Cancellation available up to 24 hours before rental</li>
                                <li>• Tool must be returned in original condition</li>
                            </ul>
                        </div>

                        <button type="submit"
                            class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Submit Rental Request
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function validateRentalForm(form) {
    const startDate = new Date(form.start_date.value);
    const endDate = new Date(form.end_date.value);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (startDate < today) {
        alert('Start date cannot be in the past.');
        return false;
    }

    if (endDate < startDate) {
        alert('End date must be after start date.');
        return false;
    }

    return true;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
