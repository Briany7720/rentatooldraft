<?php
//$joinDate = new DateTime('2020-04-11');
//$joinDate = new DateTime('2024-06-14');
$joinDate = new DateTime('2023-11-27'); // Example join date
$today = new DateTime('2025-05-06');
$tenureDays = $joinDate->diff($today)->days;

// Reviewer data
$reviewerReputationScore = 3.2; // Reputation score of reviewer
$reviewerReviewsGiven = 6; // Total reviews given by reviewer
$reviewerAvgRatingGiven = 2.8667; // Average rating given by reviewer
$ratingGiven = 5; // Rating given in this review
$behaviorFactor = 1;

// Reviewee data
$revieweeCurrentReputationScore = 2.5016; // Current reputation score of reviewee
$revieweeReviewsReceived = 18; // Total reviews received by reviewee

// Calculate reputation bias multiplier for reviewer
if ($ratingGiven < $reviewerAvgRatingGiven) {
    $rawMultiplier = 1 + abs($reviewerAvgRatingGiven - $ratingGiven);
    $reputationBiasMultiplier = min($rawMultiplier, 3);
} else {
    $reputationBiasMultiplier = 1;
}

// Calculate reviewer weight
$reviewerWeight = log10($tenureDays + 1) * ($reviewerReputationScore / 5) * log10($reviewerReviewsGiven + 1) * $reputationBiasMultiplier * $behaviorFactor;

// Calculate new reputation score for reviewee as weighted average
$newReputationScore = ((
    ($revieweeCurrentReputationScore * $revieweeReviewsReceived) + 
    ($ratingGiven * $reviewerWeight)
) / ($revieweeReviewsReceived + $reviewerWeight)) ;

// Clamp new reputation score between 0 and 5
$newReputationScore = max(0, min(5, $newReputationScore));

echo "Reviewer tenureDays: $tenureDays\n";
echo "Reviewer calculated weight: $reviewerWeight\n";
echo "Reviewee current reputation score: $revieweeCurrentReputationScore\n";
echo "Reviewee reviews received: $revieweeReviewsReceived\n";
echo "the numerator: ($revieweeCurrentReputationScore * $revieweeReviewsReceived) + ($ratingGiven * $reviewerWeight)\n";
echo "the denominator: ($revieweeReviewsReceived + $reviewerWeight)\n";
echo "New calculated reputation score for reviewee: $newReputationScore\n";
?>
