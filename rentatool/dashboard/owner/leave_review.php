<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/user_weight.php'; // Use refactored file
require_once '../../includes/header.php';

requireLogin();

$rentalId = isset($_GET['rental_id']) ? (int)$_GET['rental_id'] : 0;

// Fetch rental details for this rental and owner
$stmt = $pdo->prepare("
    SELECT r.*, t.Name as ToolName, t.ToolID,
           u.FirstName as RenterFirstName, u.LastName as RenterLastName,
           u.UserID as RenterID
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    JOIN User u ON r.RenterID = u.UserID
    WHERE r.RentalID = :rentalId
    AND t.OwnerID = :ownerId
    AND r.Status = 'Completed'
");
$stmt->execute([
    'rentalId' => $rentalId,
    'ownerId' => $_SESSION['user_id']
]);
$rental = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    $_SESSION['error_message'] = "Invalid rental or not eligible for review.";
    header('Location: index.php');
    exit();
}

// Check if a review exists for this rental and reviewer (owner)
$existingReviewId = null;
$stmt = $pdo->prepare("SELECT ReviewID FROM Review WHERE RentalID = :rentalId AND ReviewerID = :ownerId AND EntityType = 'User' LIMIT 1");
$stmt->execute(['rentalId' => $rentalId, 'ownerId' => $_SESSION['user_id']]);
$existingReviewId = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rating = (int)$_POST['rating'];
        $comment = sanitizeInput($_POST['comment']);
        $damageReported = isset($_POST['damage_reported']) ? 1 : 0;

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
            // Update ReviewHistory table for this review
            $stmtHist = $pdo->prepare("
                UPDATE ReviewHistory
                SET Rating = :rating, ReviewDate = NOW()
                WHERE ReviewID = :reviewId
            ");
            $stmtHist->execute([
                'rating' => $rating,
                'reviewId' => $existingReviewId
            ]);
        } else {
            // Insert new review
            $stmt = $pdo->prepare("
                INSERT INTO Review (
                    ReviewerID, ReviewedEntityID, EntityType,
                    Rating, Comment, ReviewDate, RentalID
                ) VALUES (
                    :reviewerId, :renterId, 'User',
                    :rating, :comment, NOW(), :rentalId
                )
            ");
            if (!$stmt->execute([
                'reviewerId' => $_SESSION['user_id'],
                'renterId' => $rental['RenterID'],
                'rating' => $rating,
                'comment' => $comment,
                'rentalId' => $rentalId
            ])) {
                throw new Exception("Failed to insert review");
            }
            $newReviewId = $pdo->lastInsertId();
            // Insert into ReviewHistory table
            $stmtHist = $pdo->prepare("
                INSERT INTO ReviewHistory (ReviewID, ReviewerID, RevieweeID, Rating, ReviewDate)
                VALUES (:reviewId, :reviewerId, :revieweeId, :rating, NOW())
            ");
            $stmtHist->execute([
                'reviewId' => $newReviewId,
                'reviewerId' => $_SESSION['user_id'],
                'revieweeId' => $rental['RenterID'],
                'rating' => $rating
            ]);
        }

        // Update DamageReported field in Rental table
        $stmtDamage = $pdo->prepare("UPDATE Rental SET DamageReported = :damageReported WHERE RentalID = :rentalId");
        if (!$stmtDamage->execute(['damageReported' => $damageReported, 'rentalId' => $rentalId])) {
            error_log("Failed to update damage report status for rentalId=$rentalId with damageReported=$damageReported");
            throw new Exception("Failed to update damage report status");
        } else {
            error_log("Damage report status updated successfully for rentalId=$rentalId with damageReported=$damageReported");
        }

        // Calculate user weight for reviewer (the one doing the review)
        error_log("leave_review.php: Calling calculateUserWeight for reviewer weight update - START");
        $weight = calculateUserWeight($pdo, $_SESSION['user_id'], $rental['RenterID'], false, $rentalId, 'reviewer weight update');
        if ($weight === null) {
            error_log("leave_review.php: calculateUserWeight failed for reviewer weight update");
            throw new Exception("Failed to update reviewer weight");
        }
        error_log("leave_review.php: Calling calculateUserWeight for reviewer weight update - END");

        // Update the reviewer weight in Reviewweight table
        $stmtUpdateWeight = $pdo->prepare("UPDATE Reviewweight SET Weight = :weight WHERE UserID = :userId");
        if (!$stmtUpdateWeight->execute(['weight' => $weight, 'userId' => $_SESSION['user_id']])) {
            error_log("Failed to update reviewer weight in Reviewweight table for userId=" . $_SESSION['user_id']);
            throw new Exception("Failed to update reviewer weight in Reviewweight table");
        }

        // Recalculate ReviewCount for reviewer (number of reviews given)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Review WHERE ReviewerID = :reviewerId");
        $stmt->execute(['reviewerId' => $_SESSION['user_id']]);
        $reviewCountGiven = (int)$stmt->fetchColumn();

        // Update ReviewCount for reviewer
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

        // Update ReviewsReceivedCount for reviewee (number of reviews received)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Review WHERE ReviewedEntityID = :revieweeId AND EntityType = 'User'");
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
            throw new Exception("Failed to update reviewee reviews received count");
        }

        // Recalculate AvgRatingGiven for reviewer
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

        // Calculate reputation score for reviewed user (renter) using refactored functions
        error_log("leave_review.php: Calling calculateNewReputation for reviewed user reputation update - START");
        if (!calculateNewReputation($pdo, $rental['RenterID'], $rentalId, $weight, $rating, true, 'reviewed user reputation update')) {
            error_log("leave_review.php: calculateNewReputation failed for reviewed user reputation update");
            throw new Exception("Failed to update reviewed user reputation score");
        }
        error_log("leave_review.php: Calling calculateNewReputation for reviewed user reputation update - END");

        $pdo->commit();
        $_SESSION['success_message'] = "Review submitted successfully!";
        header('Location: index.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error submitting review: " . $e->getMessage());
        $error = "Error submitting review: " . htmlspecialchars($e->getMessage());
    }
}

// Fetch existing review data to pre-fill form if exists
$existingRating = null;
$existingComment = '';
$existingDamageReported = 0;
if ($existingReviewId) {
    $stmt = $pdo->prepare("SELECT Rating, Comment FROM Review WHERE ReviewID = :reviewId");
    $stmt->execute(['reviewId' => $existingReviewId]);
    $reviewData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reviewData) {
        $existingRating = (int)$reviewData['Rating'];
        $existingComment = $reviewData['Comment'];
    }
    // Fetch existing damage reported status from Rental
    $stmtDamage = $pdo->prepare("SELECT DamageReported FROM Rental WHERE RentalID = :rentalId");
    $stmtDamage->execute(['rentalId' => $rentalId]);
    $existingDamageReported = (int)$stmtDamage->fetchColumn();
}
?>

<div class="container mx-auto my-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold">Review Renter</h2>
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
                <p>Renter: <?php echo htmlspecialchars($rental['RenterFirstName'] . ' ' . $rental['RenterLastName']); ?></p>
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
            placeholder="How was your experience with this renter? Did they return the tool in good condition and on time?"><?php echo htmlspecialchars($existingComment); ?></textarea>
    </div>

    <div class="flex items-center mb-4">
        <input type="checkbox" id="damage_reported" name="damage_reported" value="1" <?php echo $existingDamageReported ? 'checked' : ''; ?> class="form-checkbox h-4 w-4 text-red-600">
        <label for="damage_reported" class="ml-2 block text-sm text-gray-700">Tool was returned damaged</label>
    </div>
    <?php
    $currentDate = new DateTime();
    $rentalEndDate = new DateTime($rental['RentalEndDate']);
    if ($currentDate > $rentalEndDate):
    ?>
    <div class="flex items-center mb-4">
        <input type="checkbox" id="not_returned" name="not_returned" value="1" <?php echo isset($existingNotReturned) && $existingNotReturned ? 'checked' : ''; ?> class="form-checkbox h-4 w-4 text-red-600">
        <label for="not_returned" class="ml-2 block text-sm text-gray-700">Tool was not returned</label>
    </div>
    <?php endif; ?>

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
