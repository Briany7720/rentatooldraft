<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/header.php';

requireLogin();

if (!isset($_GET['user_id'])) {
    echo "<p>User ID not specified.</p>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/footer.php';
    exit();
}

$userId = (int)$_GET['user_id'];
$loggedInUserId = $_SESSION['user_id'];

// Fetch user details
$stmt = $pdo->prepare("
    SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.RegistrationDate,
           u.ReputationScore, u.ReviewCount
    FROM User u
    WHERE u.UserID = :userId
");
$stmt->execute(['userId' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "<p>User not found.</p>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/footer.php';
    exit();
}

// Fetch reviews for this user
$stmt = $pdo->prepare("
    SELECT r.Rating, r.Comment, r.ReviewDate, rev.FirstName as ReviewerFirstName, rev.LastName as ReviewerLastName
    FROM Review r
    JOIN User rev ON r.ReviewerID = rev.UserID
    WHERE r.ReviewedEntityID = :userId AND r.EntityType = 'User'
    ORDER BY r.ReviewDate DESC
");
$stmt->execute(['userId' => $userId]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if profile user owns any tools
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Tool WHERE OwnerID = :userId");
$stmt->execute(['userId' => $userId]);
$profileUserOwnsTools = $stmt->fetchColumn() > 0;

// Check if logged-in user owns any tools (to determine if renter or owner)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Tool WHERE OwnerID = :loggedInUserId");
$stmt->execute(['loggedInUserId' => $loggedInUserId]);
$loggedInUserOwnsTools = $stmt->fetchColumn() > 0;

// Determine if tools should be shown:
// Show tools only if profile user owns tools AND logged-in user does NOT own tools (i.e., logged-in user is renter)
$showTools = $profileUserOwnsTools && !$loggedInUserOwnsTools;

$tools = [];
$toolBookings = [];

if ($showTools) {
    // Fetch tools owned by profile user that are available
    $stmt = $pdo->prepare("
        SELECT t.ToolID, t.Name, t.Category, t.PricePerDay, t.AvailabilityStatus
        FROM Tool t
        WHERE t.OwnerID = :userId AND t.AvailabilityStatus = 'Available'
    ");
    $stmt->execute(['userId' => $userId]);
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each tool, fetch booked rental date ranges
    foreach ($tools as $tool) {
        $stmtBookings = $pdo->prepare("
            SELECT RentalStartDate, COALESCE(ReturnDate, RentalEndDate) AS RentalEndDate
            FROM Rental
            WHERE ToolID = :toolId AND Status NOT IN ('Rejected', 'Cancelled')
            ORDER BY RentalStartDate
        ");
        $stmtBookings->execute(['toolId' => $tool['ToolID']]);
        $bookings = $stmtBookings->fetchAll(PDO::FETCH_ASSOC);
        $toolBookings[$tool['ToolID']] = $bookings;
    }
}
?>

<div class="container mx-auto my-8">
    <h2 class="text-2xl font-bold mb-4">User Profile: <?php echo htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']); ?></h2>
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['Email']); ?></p>
        <p><strong>Member since:</strong> <?php echo date('F j, Y', strtotime($user['RegistrationDate'])); ?></p>
        <p><strong>Reputation Score:</strong> <?php echo number_format($user['ReputationScore'], 2); ?> (<?php echo $user['ReviewCount']; ?> reviews)</p>
    </div>

    <?php if ($showTools): ?>
        <h3 class="text-xl font-semibold mb-4">Tools Available for Rental</h3>
        <?php if (empty($tools)): ?>
            <p>This user has no tools currently available for rental.</p>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 mb-6">
                <?php foreach ($tools as $tool): ?>
                    <div class="bg-white rounded-lg shadow p-4">
                        <h4 class="text-lg font-semibold mb-2"><?php echo htmlspecialchars($tool['Name']); ?></h4>
                        <p class="text-sm text-gray-600 mb-1">Category: <?php echo htmlspecialchars($tool['Category']); ?></p>
                        <p class="text-sm font-medium mb-2">$<?php echo number_format($tool['PricePerDay'], 2); ?> per day</p>
                        <p class="text-sm font-semibold mb-1">Booked Dates:</p>
                        <?php if (empty($toolBookings[$tool['ToolID']])): ?>
                            <p class="text-sm text-green-600">No current bookings. Available anytime.</p>
                        <?php else: ?>
                            <ul class="text-sm text-gray-700 list-disc list-inside">
                                <?php foreach ($toolBookings[$tool['ToolID']] as $booking): ?>
                                    <li><?php echo date('M d, Y', strtotime($booking['RentalStartDate'])) . ' - ' . date('M d, Y', strtotime($booking['RentalEndDate'])); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>dashboard/renter/rent_tool.php?tool_id=<?php echo $tool['ToolID']; ?>" class="mt-2 inline-block bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">Rent this tool</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <h3 class="text-xl font-semibold mb-4">Reviews</h3>
    <?php if (empty($reviews)): ?>
        <p>No reviews found for this user.</p>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($reviews as $review): ?>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center mb-2">
                        <?php 
                        $rating = (int)$review['Rating'];
                        for ($i = 1; $i <= 5; $i++): 
                        ?>
                            <svg class="w-5 h-5 <?php echo $i <= $rating ? 'text-yellow-400' : 'text-gray-300'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                        <span class="ml-2 text-gray-700 text-sm">by <?php echo htmlspecialchars($review['ReviewerFirstName'] . ' ' . $review['ReviewerLastName']); ?> on <?php echo date('M d, Y', strtotime($review['ReviewDate'])); ?></span>
                    </div>
                    <p><?php echo nl2br(htmlspecialchars($review['Comment'])); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/footer.php'; ?>
