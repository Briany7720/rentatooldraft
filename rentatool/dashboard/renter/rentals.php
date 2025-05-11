<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/header.php';

requireLogin(); // Ensure the user is logged in
if (getUserType() !== 'Renter') {
    header('Location: ' . BASE_URL . 'dashboard/owner/index.php');
    exit();
}

// Fetch user details
$userStmt = $pdo->prepare("
    SELECT u.*, u.ReputationScore, u.ReviewCount,
           (SELECT AVG(Rating) FROM Review WHERE ReviewedEntityID = u.UserID AND EntityType = 'User') as AvgRating
    FROM User u
    WHERE u.UserID = :userID
");
$userStmt->execute(['userID' => $_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// Fetch rentals for the logged-in renter
$stmt = $pdo->prepare("
    SELECT r.*, t.Name as ToolName, t.OwnerID, 
           o.FirstName as OwnerFirstName, o.LastName as OwnerLastName,
           o.ReputationScore as OwnerRating,
           o.ReviewCount as OwnerReviewCount
    FROM Rental r
    JOIN Tool t ON r.ToolID = t.ToolID
    JOIN User o ON t.OwnerID = o.UserID
    WHERE r.RenterID = :renterID
    ORDER BY r.RentalStartDate DESC
");
$stmt->execute(['renterID' => $_SESSION['user_id']]);
$rentalRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto my-8">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">My Rentals</h2>
        <div class="text-sm text-gray-500">
Member since: <?php echo date('M d, Y', strtotime($user['RegistrationDate'])); ?>
            <?php if ($user['AvgRating']): ?>
            <div class="flex items-center mt-1">
                Your rating: 
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <svg class="w-4 h-4 <?php echo $i <= round($user['AvgRating']) ? 'text-yellow-400' : 'text-gray-300'; ?>" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                    </svg>
                <?php endfor; ?>
                <span class="ml-1">(<?php echo number_format($user['AvgRating'], 1); ?> from <?php echo $user['ReviewCount']; ?> reviews)</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (empty($rentalRequests)): ?>
        <p class="text-gray-500">No rentals found.</p>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tool</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Owner</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($rentalRequests as $request): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['ToolName']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
<div class="text-sm text-gray-900">
    <a href="<?php echo BASE_URL; ?>dashboard/user_profile.php?user_id=<?php echo $request['OwnerID']; ?>" class="text-blue-600 hover:underline">
        <?php echo htmlspecialchars($request['OwnerFirstName'] . ' ' . $request['OwnerLastName']); ?>
    </a>
</div>
                                <?php if ($request['OwnerRating']): ?>
                                <div class="flex items-center mt-1">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <svg class="w-3 h-3 <?php echo $i <= round($request['OwnerRating']) ? 'text-yellow-400' : 'text-gray-300'; ?>" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    <?php endfor; ?>
                                    <span class="ml-1 text-xs text-gray-500">(<?php echo number_format($request['OwnerRating'], 1); ?> from <?php echo $request['OwnerReviewCount']; ?> reviews)</span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($request['RentalStartDate'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($request['RentalEndDate'])); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php echo $request['Status'] === 'Completed' ? 'bg-green-100 text-green-800' : 
                                        ($request['Status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                                        'bg-gray-100 text-gray-800'); ?>">
                                    <?php echo $request['Status']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php if ($request['Status'] === 'Approved'): ?>
                                    <div class="bg-blue-50 p-3 rounded-lg">
                                        <p class="text-sm text-gray-700 mb-2">Finished with this tool?</p>
                                        <a href="<?php echo BASE_URL; ?>dashboard/renter/return_tool.php?id=<?php echo $request['RentalID']; ?>" 
                                           class="inline-flex items-center px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            Mark as Returned
                                        </a>
                                    </div>
                                <?php elseif ($request['Status'] === 'Returned'): ?>
                                    <p class="text-sm text-gray-500">Waiting for owner confirmation</p>
                                <?php elseif ($request['Status'] === 'Completed'): ?>
                                    <p class="text-sm text-green-600">Return confirmed</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/footer.php'; ?>
