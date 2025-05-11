<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/header.php';

requireLogin(); // Ensure the user is logged in
if (getUserType() !== 'Owner') {
    header('Location: ' . BASE_URL . 'dashboard/renter/index.php');
    exit();
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// First check if owner has any tools
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Tool WHERE OwnerID = :ownerID");
$stmt->execute(['ownerID' => $_SESSION['user_id']]);
$toolCount = $stmt->fetchColumn();

if ($toolCount == 0) {
    $rentalRequests = [];
} else {
    // Fetch rental requests for owner's tools
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, t.Name as ToolName, u.FirstName, u.LastName, u.Email
            FROM Rental r
            JOIN Tool t ON r.ToolID = t.ToolID
            JOIN User u ON r.RenterID = u.UserID
            WHERE t.OwnerID = :ownerID AND r.Status != 'Rejected'
            ORDER BY r.RentalStartDate DESC
        ");
        $stmt->execute(['ownerID' => $_SESSION['user_id']]);
        $rentalRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $rentalRequests = [];
    }
}
?>

<div class="container mx-auto my-8">
    <h2 class="text-2xl font-bold mb-4">Rental Requests</h2>
    
    <?php if (empty($rentalRequests)): ?>
        <p class="text-gray-500">No rental requests found.</p>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tool</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Renter</th>
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
                                    <a href="<?php echo BASE_URL; ?>dashboard/owner/rental_detail.php?id=<?php echo $request['RentalID']; ?>" class="hover:underline">
                                        <?php echo htmlspecialchars($request['FirstName'] . ' ' . $request['LastName']); ?>
                                    </a>
                                </div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($request['Email']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($request['RentalStartDate'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($request['RentalEndDate'])); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php echo $request['Status'] === 'Approved' ? 'bg-green-100 text-green-800' : 
                                        ($request['Status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                                        'bg-gray-100 text-gray-800'); ?>">
                                    <?php echo $request['Status']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php if ($request['Status'] === 'Pending'): ?>
                                    <a href="<?php echo BASE_URL; ?>dashboard/owner/approve_rental.php?id=<?php echo $request['RentalID']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">Approve</a>
                                    <a href="<?php echo BASE_URL; ?>dashboard/owner/reject_rental.php?id=<?php echo $request['RentalID']; ?>" class="text-red-600 hover:text-red-900">Reject</a>
                                <?php elseif ($request['Status'] === 'Returned'): ?>
                                    <a href="<?php echo BASE_URL; ?>dashboard/owner/confirm_return.php?id=<?php echo $request['RentalID']; ?>" class="text-green-600 hover:text-green-900">Confirm Return</a>
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
