<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/user_weight.php'; // Use refactored file
require_once '../../includes/header.php';

requireLogin();

$rentalId = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;

// Fetch rental details for this rental and renter
$stmt = $pdo->prepare("
    SELECT r.*, t.Name as ToolName, t.ToolID,
           u.FirstName as OwnerFirstName, u.LastName as OwnerLastName,
           u.UserID as OwnerID
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    JOIN User u ON t.OwnerID = u.UserID
    WHERE r.RentalID = :rentalId
    AND r.RenterID = :renterId
    AND r.Status = 'Completed'
");
$stmt->execute([
    'rentalId' => $rentalId,
    'renterId' => $_SESSION['user_id']
]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    $_SESSION['error_message'] = "Invalid rental or not eligible for review.";
    header('Location: index.php');
    exit();
}

// Check if a review exists for this rental and reviewer (renter)
$existingReviewId = null;
$stmt = $pdo->prepare("SELECT ReviewID FROM Review WHERE RentalID = :rentalId AND ReviewerID = :renterId AND EntityType = 'User' LIMIT 1");
$stmt->execute(['rentalId' => $rentalId, 'renterId' => $_SESSION['user_id']]);
$existingReviewId = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rating = (int)$_POST['rating'];
        $comment = sanitizeInput($_POST['comment']);

        if ($rating < 1 || $rating > 5) {
            throw new Exception("Invalid rating value");
        }

        $pdo->beginTransaction();

        if ($existingReviewId) {
            // Update existing review
            $stmt = $pdo->prepare("
                UPDATE Review
                SET Rating = :rating, Comment = :comment, ReviewDate = NOW()
                WHERE ReviewID = :reviewId
            ");
            if (!$stmt->execute([
                'rating' => $rating,
                'comment' => $comment,
                'reviewId' => $existingReviewId
            ])) {
                throw new Exception("Failed to update review");
            }
        } else {
            // Insert new review
            $stmt = $pdo->prepare("
                INSERT INTO Review (
                    ReviewerID, ReviewedEntityID, EntityType,
                    Rating, Comment, ReviewDate, RentalID
                ) VALUES (
                    :reviewerId, :ownerId, 'User',
                    :rating, :comment, NOW(), :rentalId
                )
            ");
            if (!$stmt->execute([
                'reviewerId' => $_SESSION['user_id'],
                'ownerId' => $rental['OwnerID'],
                'rating' => $rating,
                'comment' => $comment,
                'rentalId' => $rentalId
            ])) {
                throw new Exception("Failed to insert review");
            }
        }

        // Update User ReviewCount only (ReputationScore updated in calculateUserWeight)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Review WHERE ReviewedEntityID = :ownerId AND EntityType = 'User'");
        $stmt->execute(['ownerId' => $rental['OwnerID']]);
        $reviewCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE User
            SET ReviewCount = :review_count
            WHERE UserID = :ownerId
        ");
        if (!$stmt->execute([
            'review_count' => $reviewCount,
            'ownerId' => $rental['OwnerID']
        ])) {
            throw new Exception("Failed to update user review count");
        }

        // Use PHP function to calculate user weight and update reputation score for owner (reviewed user)
        $weight = calculateUserWeight($pdo, $_SESSION['user_id'], $rental['OwnerID'], true, $rentalId, 'reviewed user reputation update');
        if ($weight === null) {
            throw new Exception("Failed to update owner reputation score");
        }

        // Add call to update renter weight as reviewer with reviewedUserId = ownerId and isOwnerReview = false
        $weightReviewer = calculateUserWeight($pdo, $_SESSION['user_id'], $rental['OwnerID'], false, $rentalId, 'reviewer weight update');
        if ($weightReviewer === null) {
            throw new Exception("Failed to update renter weight");
        }

        // Recalculate ReviewCount for reviewer (renter)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Review WHERE ReviewerID = :reviewerId");
        $stmt->execute(['reviewerId' => $_SESSION['user_id']]);
        $reviewCountGiven = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE User
            SET ReviewCount = :review_count
            WHERE UserID = :reviewerId
        ");
        if (!$stmt->execute([
            'review_count' => $reviewCountGiven,
            'reviewerId' => $_SESSION['user_id']
        ])) {
            throw new Exception("Failed to update reviewer review count");
        }

        // Update ReviewsReceivedCount for renter (reviewee) using the value directly from the database after all calculations and reputation update
        $stmt = $pdo->prepare("SELECT ReviewsReceivedCount FROM User WHERE UserID = :revieweeId");
        $stmt->execute(['revieweeId' => $rental['RenterID']]);
        $reviewsReceivedCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE User
            SET ReviewsReceivedCount = :reviews_received_count
            WHERE UserID = :revieweeId
        ");
        if (!$stmt->execute([
            'reviews_received_count' => $reviewsReceivedCount,
            'revieweeId' => $rental['RenterID']
        ])) {
            throw new Exception("Failed to update renter reviews received count");
        }

        // Recalculate AvgRatingGiven for reviewer (renter)
        $stmt = $pdo->prepare("SELECT AVG(Rating) FROM Review WHERE ReviewerID = :reviewerId AND EntityType = 'User'");
        $stmt->execute(['reviewerId' => $_SESSION['user_id']]);
        $avgRatingGiven = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            UPDATE User
            SET AvgRatingGiven = :avg_rating_given
            WHERE UserID = :reviewerId
        ");
        if (!$stmt->execute([
            'avg_rating_given' => $avgRatingGiven,
            'reviewerId' => $_SESSION['user_id']
        ])) {
            throw new Exception("Failed to update reviewer average rating given");
        }

        $pdo->commit();
        $_SESSION['success_message'] = "Review submitted successfully!";
        header('Location: index.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Review submission error: " . $e->getMessage());
        $error = "Error submitting review: " . htmlspecialchars($e->getMessage());
    }
}

// Fetch existing review data to pre-fill form if exists
$existingRating = null;
$existingComment = '';
if ($existingReviewId) {
    $stmt = $pdo->prepare("SELECT Rating, Comment FROM Review WHERE ReviewID = :reviewId");
    $stmt->execute(['reviewId' => $existingReviewId]);
    $reviewData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reviewData) {
        $existingRating = (int)$reviewData['Rating'];
        $existingComment = $reviewData['Comment'];
    }
}
?>

<div class="container mx-auto my-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold">Review Owner</h2>
            <a href="index.php" class="text-blue-600 hover:underline">Back to Dashboard</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

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

<?php if ($existingReviewId): ?>
    <div class="p-4 bg-green-100 border border-green-400 text-green-700 rounded mb-4 max-w-2xl mx-auto">
        You have already completed this review.
    </div>
<?php else: ?>
<form method="POST" action="" class="space-y-6 max-w-2xl mx-auto">
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
        <div class="flex space-x-4">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <label class="flex items-center">
                    <input type="radio" name="rating" value="<?php echo $i; ?>" required
                           class="form-radio h-4 w-4 text-blue-600"
                           <?php echo ($existingRating === $i) ? 'checked' : ''; ?>>
                    <span class="ml-2"><?php echo $i; ?> ★</span>
                </label>
            <?php endfor; ?>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Review</label>
        <textarea name="comment" required
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            rows="4"
            placeholder="How was your experience with this owner?"><?php echo htmlspecialchars($existingComment); ?></textarea>
    </div>

    <button type="submit" 
        class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
        Submit Review
    </button>
</form>
<?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
