<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/header.php';

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

// Fetch rental request details with owner verification
$stmt = $pdo->prepare("
    SELECT r.*, t.Name as ToolName, t.Description as ToolDescription, 
           u.FirstName, u.LastName, u.Email, u.PhoneNumber as Phone, u.ReputationScore
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    JOIN User u ON r.RenterID = u.UserID
    WHERE r.RentalID = :rentalID AND t.OwnerID = :ownerID
");
$stmt->execute(['rentalID' => $rentalID, 'ownerID' => $_SESSION['user_id']]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    header('Location: ' . BASE_URL . 'dashboard/owner/rentals.php');
    exit();
}
?>

<div class="container mx-auto my-8">
    <h2 class="text-2xl font-bold mb-4">Rental Request Details</h2>
    
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Tool Information -->
            <div>
                <h3 class="text-lg font-semibold mb-2">Tool Information</h3>
                <p class="text-gray-700"><span class="font-medium">Tool:</span> <?php echo htmlspecialchars($rental['ToolName']); ?></p>
                <p class="text-gray-700"><span class="font-medium">Description:</span> <?php echo htmlspecialchars($rental['ToolDescription']); ?></p>
            </div>

            <!-- Renter Information -->
            <div>
                <h3 class="text-lg font-semibold mb-2">Renter Information</h3>
<p class="text-gray-700"><span class="font-medium">Name:</span> 
    <a href="<?php echo BASE_URL; ?>dashboard/user_profile.php?user_id=<?php echo $rental['RenterID']; ?>" class="text-blue-600 hover:underline">
        <?php echo htmlspecialchars($rental['FirstName'] . ' ' . $rental['LastName']); ?>
    </a>
</p>
                <p class="text-gray-700"><span class="font-medium">Email:</span> <?php echo htmlspecialchars($rental['Email']); ?></p>
                <p class="text-gray-700"><span class="font-medium">Phone:</span> <?php echo htmlspecialchars($rental['Phone']); ?></p>
            </div>

            <!-- Rental Details -->
            <div class="md:col-span-2">
                <h3 class="text-lg font-semibold mb-2">Rental Details</h3>
                <p class="text-gray-700"><span class="font-medium">Dates:</span> 
                    <?php echo date('M d, Y', strtotime($rental['RentalStartDate'])); ?> - 
                    <?php echo date('M d, Y', strtotime($rental['RentalEndDate'])); ?>
                </p>
                <p class="text-gray-700"><span class="font-medium">Status:</span> 
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                        <?php echo $rental['Status'] === 'Approved' ? 'bg-green-100 text-green-800' : 
                            ($rental['Status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                            'bg-gray-100 text-gray-800'); ?>">
                        <?php echo $rental['Status']; ?>
                    </span>
                </p>
                <p class="text-gray-700"><span class="font-medium">Request Date:</span> 
                    <?php echo isset($rental['RequestDate']) ? date('M d, Y H:i', strtotime($rental['RequestDate'])) : 'Not available'; ?>
                </p>
                <?php if ($rental['Status'] === 'Completed'): ?>
                <p class="text-gray-700"><span class="font-medium">Renter Rating:</span> 
                    <?php 
                    $avgRating = $rental['ReputationScore'];
                    if ($avgRating > 0): 
                    ?>
                        <div class="flex items-center">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg class="w-4 h-4 <?php echo $i <= round($avgRating) ? 'text-yellow-400' : 'text-gray-300'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            <?php endfor; ?>
                            <span class="ml-1 text-sm text-gray-500">(<?php echo number_format($avgRating, 1); ?>/5)</span>
                        </div>
                        <div class="mt-2 p-3 bg-gray-100 rounded">
                            <strong>Review Comment:</strong><br>
                            <?php
                            // Fetch the latest review comment for this rental and owner reviewer
                            $stmt = $pdo->prepare("SELECT Comment FROM Review WHERE RentalID = :rentalID AND ReviewerID = :ownerID AND EntityType = 'User' LIMIT 1");
                            $stmt->execute(['rentalID' => $rentalID, 'ownerID' => $_SESSION['user_id']]);
                            $reviewComment = $stmt->fetchColumn();
                            if ($reviewComment !== null && trim($reviewComment) !== '') {
                                echo nl2br(htmlspecialchars($reviewComment));
                            } else {
                                echo '<em>No review comment provided yet.</em><br><a href="' . BASE_URL . 'dashboard/owner/leave_review.php?rental_id=' . $rentalID . '" class="text-blue-500 hover:underline">Leave a review</a>';
                            }
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="text-sm text-gray-500">
                            No ratings yet - this renter hasn't been reviewed
                            <?php if ($rental['Status'] === 'Completed'): ?>
<br><a href="<?php echo BASE_URL; ?>dashboard/owner/leave_review.php?rental_id=<?php echo $rentalID; ?>" class="text-blue-500 hover:underline">Leave a review</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <?php if ($rental['Status'] === 'Pending'): ?>
            <div class="mt-6 flex space-x-4">
                <a href="<?php echo BASE_URL; ?>dashboard/owner/approve_rental.php?id=<?php echo $rentalID; ?>" 
                   class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    Approve Request
                </a>
                <a href="<?php echo BASE_URL; ?>dashboard/owner/reject_rental.php?id=<?php echo $rentalID; ?>" 
                   class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">
                    Reject Request
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/footer.php'; ?>
