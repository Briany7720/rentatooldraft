<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/header.php';

requireLogin(); // Ensure the user is logged in

    // Fetch user information including registration date
    $stmt = $pdo->prepare("
        SELECT 
            u.FirstName, 
            u.LastName,
            u.RegistrationDate,
            u.ReputationScore,
            u.ReviewsReceivedCount,
            COUNT(DISTINCT t.ToolID) as total_tools,
            COUNT(DISTINCT CASE WHEN rent.Status = 'Pending' THEN rent.RentalID END) as pending_requests
        FROM User u
        LEFT JOIN Tool t ON u.UserID = t.OwnerID
        LEFT JOIN Rental rent ON t.ToolID = rent.ToolID
        WHERE u.UserID = ?
        GROUP BY u.UserID, u.FirstName, u.LastName, u.RegistrationDate, u.ReputationScore, u.ReviewsReceivedCount
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch total earnings separately with adjusted calculation
    $earningsStmt = $pdo->prepare("
        SELECT 
            SUM(
                (DATEDIFF(r.RentalEndDate, r.RentalStartDate) + 1) * t.PricePerDay
            ) + SUM(
                CASE 
                    WHEN r.ReturnDate IS NOT NULL AND r.ReturnDate > r.RentalEndDate THEN
                        LEAST(DATEDIFF(r.ReturnDate, r.RentalEndDate) * t.PricePerDay, r.DepositFee)
                    WHEN r.ReturnDate IS NULL AND CURDATE() > r.RentalEndDate THEN
                        r.DepositFee
                    ELSE 0
                END
            ) AS total_earnings,
            SUM(
                (DATEDIFF(r.RentalEndDate, r.RentalStartDate) + 1) * t.PricePerDay
            ) AS base_earnings,
            SUM(
                CASE 
                    WHEN r.ReturnDate IS NOT NULL AND r.ReturnDate > r.RentalEndDate THEN
                        LEAST(DATEDIFF(r.ReturnDate, r.RentalEndDate) * t.PricePerDay, r.DepositFee)
                    WHEN r.ReturnDate IS NOT NULL AND r.ReturnDate <= r.RentalEndDate THEN
                        0
                    WHEN r.ReturnDate IS NULL AND CURDATE() > r.RentalEndDate THEN
                        r.DepositFee
                    ELSE 0
                END
            ) AS deposit_earnings
        FROM Rental r
        JOIN Tool t ON r.ToolID = t.ToolID
        JOIN Payment p ON r.RentalID = p.RentalID
        WHERE t.OwnerID = ? AND r.Status IN ('Approved', 'Completed', 'Returned') AND p.PaymentStatus = 'Completed'
    ");
    $earningsStmt->execute([$_SESSION['user_id']]);
    $earningsResult = $earningsStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch unread notifications for the owner
    $notifStmt = $pdo->prepare("
        SELECT NotificationID, Message, NotificationTimestamp 
        FROM Notification 
        WHERE UserID = ? AND IsRead = 0
        ORDER BY NotificationTimestamp DESC
        LIMIT 5
    ");
    $notifStmt->execute([$_SESSION['user_id']]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $joinDate = $userInfo['RegistrationDate'] ? date('F j, Y', strtotime($userInfo['RegistrationDate'])) : 'Not available';

    $fullName = htmlspecialchars($userInfo['FirstName'] . ' ' . $userInfo['LastName']);
    $rating = number_format($userInfo['ReputationScore'], 1);
    $ReviewsReceivedCount = $userInfo['ReviewsReceivedCount'];
    $totalTools = $userInfo['total_tools'];
    $pendingRequests = $userInfo['pending_requests'];
    $totalEarnings = $earningsResult['total_earnings'];

    // Debug output for earnings components
    error_log("Owner Earnings Debug - Total: " . $earningsResult['total_earnings'] . 
              ", Base: " . $earningsResult['base_earnings'] . 
              ", Deposit: " . $earningsResult['deposit_earnings']);

    // Fetch recent rentals
    $stmt = $pdo->prepare("
        SELECT r.*, t.Name as ToolName, u.FirstName, u.LastName
        FROM Rental r
        JOIN Tool t ON r.ToolID = t.ToolID
        JOIN User u ON r.RenterID = u.UserID
        WHERE t.OwnerID = ?
        ORDER BY r.RentalStartDate DESC
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recentRentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto my-8">
    <!-- Enhanced User Profile Section with Notification Icon -->
    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
        <div class="flex justify-between items-center">
            <!-- Notification Icon -->
            <div class="relative inline-block text-left mr-4">
                <button id="notificationButton" type="button" class="relative inline-flex items-center p-2 text-gray-700 hover:text-gray-900 focus:outline-none" aria-expanded="false" aria-haspopup="true">
                    <!-- Bell Icon -->
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 01-3.46 0"></path>
                    </svg>
                    <?php if (count($notifications) > 0): ?>
                        <span id="notificationBadge" class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">
                            <?= count($notifications) ?>
                        </span>
                    <?php endif; ?>
                </button>

                <!-- Dropdown panel, hidden by default -->
                <div id="notificationDropdown" class="origin-top-right absolute left-0 mt-2 w-80 max-w-xs rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 hidden z-50">
                    <div class="py-2 max-h-64 overflow-y-auto">
                        <?php if (empty($notifications)): ?>
                            <p class="px-4 py-2 text-gray-600">No new notifications.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($notifications as $notification): ?>
                                    <li class="px-4 py-2 border-b border-gray-200 hover:bg-gray-100 cursor-pointer" data-notification-id="<?= $notification['NotificationID'] ?>">
                                        <p class="text-gray-800"><?= htmlspecialchars($notification['Message']) ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?= date('M d, Y H:i', strtotime($notification['NotificationTimestamp'])) ?>
                                        </p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div>
                <h1 class="text-2xl font-bold mb-2">Welcome, <?= $fullName ?></h1>
                <div class="flex items-center mb-2">
                    <div class="text-yellow-400 text-xl">
                    <?php
                    $filledStars = max(0, floor($rating));
                    $emptyStars = max(0, 5 - $filledStars);
                    echo str_repeat('★', $filledStars) . str_repeat('☆', $emptyStars);
                    ?>
                    </div>
                    <span class="ml-2 text-gray-600">(<?= $rating ?> from <?= $ReviewsReceivedCount ?> reviews)</span>
                </div>
                <p class="text-gray-600">Member since: <?= $joinDate ?></p>
            </div>
            <!-- Status Badge -->
            <span class="bg-blue-100 text-blue-800 text-sm font-medium px-2.5 py-0.5 rounded">
                <?= $totalTools >= 20 ? 'Expert Owner' : ($totalTools >= 10 ? 'Regular Owner' : 'New Owner') ?>
            </span>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const button = document.getElementById('notificationButton');
            const dropdown = document.getElementById('notificationDropdown');

            button.addEventListener('click', function (event) {
                console.log('Notification button clicked');
                event.stopPropagation();
                dropdown.classList.toggle('hidden');
                console.log('Dropdown hidden class toggled:', dropdown.classList.contains('hidden'));
            });

            // Add click event to notification items
            const notificationItems = dropdown.querySelectorAll('li');
            notificationItems.forEach(item => {
                item.addEventListener('click', function () {
                    console.log('Notification item clicked');
                    const notificationId = this.getAttribute('data-notification-id');
                    if (notificationId) {
                        fetch('mark_notification_read.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ notificationId })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                console.log('Notification marked as read');
                                // Optionally remove the notification item or update UI
                                this.remove();
                                // Update badge count
                                const badge = document.getElementById('notificationBadge');
                                if (badge) {
                                    let count = parseInt(badge.textContent);
                                    count = count > 1 ? count - 1 : 0;
                                    if (count === 0) {
                                        badge.remove();
                                    } else {
                                        badge.textContent = count;
                                    }
                                }
                            } else {
                                console.error('Failed to mark notification as read');
                            }
                        })
                        .catch(error => {
                            console.error('Error marking notification as read:', error);
                        });
                    }
                    dropdown.classList.add('hidden');
                    console.log('Dropdown hidden class added');
                });
            });

            document.addEventListener('click', function () {
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                    console.log('Document click: dropdown hidden');
                }
            });
        });
    </script>

    <!-- Stats Section -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <!-- Total Tools -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-lg font-semibold mb-2">Total Tools</h3>
            <p class="text-3xl font-bold text-blue-600"><?= $totalTools ?></p>
            <p class="text-sm text-gray-500">Tools listed</p>
        </div>
        
        <!-- Pending Requests -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-lg font-semibold mb-2">Pending Requests</h3>
            <p class="text-3xl font-bold text-yellow-600"><?= $pendingRequests ?></p>
            <p class="text-sm text-gray-500">Awaiting approval</p>
        </div>
        
        <!-- Total Earnings -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-lg font-semibold mb-2">Total Earnings</h3>
        <p class="text-3xl font-bold text-green-600">$<?= number_format($totalEarnings ?? 0, 2) ?></p>
        <p class="text-sm text-gray-500">Lifetime earnings</p>
    </div>

        <!-- Owner Status -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-lg font-semibold mb-2">Owner Status</h3>
            <p class="text-3xl font-bold text-purple-600">
                <?= $totalTools >= 20 ? 'Expert' : ($totalTools >= 10 ? 'Regular' : 'New') ?>
            </p>
            <p class="text-sm text-gray-500">
                <?= $totalTools >= 20 ? '20+ tools' : ($totalTools >= 10 ? '10+ tools' : 'Getting started') ?>
            </p>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-xl font-semibold mb-4">Quick Actions</h3>
            <div class="space-y-4">
                <a href="<?= BASE_URL ?>dashboard/owner/tools.php" class="block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-center">
                    Manage Tools
                </a>
                <a href="<?= BASE_URL ?>dashboard/owner/rentals.php" class="block bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 text-center">
                    View Rental Requests
                </a>
                <a href="<?= BASE_URL ?>dashboard/owner/earnings.php" class="block bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-center">
                    View Earnings Report
                </a>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h3 class="text-xl font-semibold mb-4">Recent Activity</h3>
            <?php if (empty($recentRentals)): ?>
                <p class="text-gray-500">No recent activity</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($recentRentals as $rental): ?>
                        <div class="border-b pb-2">
                            <p class="font-medium">
                                <?= htmlspecialchars($rental['FirstName'] . ' ' . $rental['LastName']) ?>
                                rented <?= htmlspecialchars($rental['ToolName']) ?>
                            </p>
                            <p class="text-sm text-gray-500">
                                <?= date('M d, Y', strtotime($rental['RentalStartDate'])) ?> - 
                                <?= date('M d, Y', strtotime($rental['RentalEndDate'])) ?>
                            </p>
                            <span class="inline-block px-2 py-1 text-sm rounded-full 
                                <?= $rental['Status'] === 'Completed' ? 'bg-green-100 text-green-800' : 
                                   ($rental['Status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                                   'bg-blue-100 text-blue-800') ?>">
                                <?= $rental['Status'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/footer.php'; ?>
