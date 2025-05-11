<?php

// Handle AJAX requests for photo deletion, setting primary photo, and deleting tool
if (isset($_GET['ajax_action']) && isset($_GET['tool_id'])) {
    require_once '../../includes/config.php';
    require_once '../../includes/db_connection.php';
    require_once '../../includes/photo_utils.php';
    session_start();

    $toolId = (int)$_GET['tool_id'];

    try {
        // Verify tool ownership
        $stmt = $pdo->prepare("SELECT ToolID FROM Tool WHERE ToolID = :toolId AND OwnerID = :ownerId");
        $stmt->execute(['toolId' => $toolId, 'ownerId' => $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            jsonResponse(false, 'Unauthorized or invalid tool');
        }

        switch ($_GET['ajax_action']) {
            case 'delete_photo':
                if (!isset($_GET['photo_id'])) {
                    throw new Exception('Photo ID missing');
                }
                $photoId = (int)$_GET['photo_id'];
                deleteToolPhoto($toolId, $photoId);
                jsonResponse(true, 'Photo deleted');
                break;

            case 'set_primary':
                if (!isset($_GET['photo_id'])) {
                    throw new Exception('Photo ID missing');
                }
                $photoId = (int)$_GET['photo_id'];
                setPrimaryPhoto($toolId, $photoId);
                jsonResponse(true, 'Primary photo set');
                break;

            case 'delete_tool':
                // Delete tool photos first
                $photos = getToolPhotos($toolId);
                foreach ($photos as $photo) {
                    deleteToolPhoto($toolId, $photo['PhotoID']);
                }
                // Delete rentals associated with the tool (optional: or handle via foreign keys)
                $stmtDelRentals = $pdo->prepare("DELETE FROM Rental WHERE ToolID = :toolId");
                $stmtDelRentals->execute(['toolId' => $toolId]);
                // Delete the tool itself
                $stmtDelTool = $pdo->prepare("DELETE FROM Tool WHERE ToolID = :toolId AND OwnerID = :ownerId");
                $stmtDelTool->execute(['toolId' => $toolId, 'ownerId' => $_SESSION['user_id']]);
                jsonResponse(true, 'Tool deleted');
                break;

            default:
                jsonResponse(false, 'Unknown action');
        }
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit();
}

require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/header.php';
require_once '../../includes/photo_utils.php';

requireLogin();

ob_start();
ob_end_flush();

// Fetch owner's tools with rental info and availability dates
$stmt = $pdo->prepare("
    SELECT t.*, 
           COUNT(DISTINCT r.RentalID) as rental_count,
           AVG(rev.Rating) as avg_rating
    FROM Tool t
    LEFT JOIN Rental r ON t.ToolID = r.ToolID AND r.Status NOT IN ('Rejected', 'Cancelled')
    LEFT JOIN Review rev ON t.ToolID = rev.ReviewedEntityID AND rev.EntityType = 'Tool'
    WHERE t.OwnerID = :ownerID
    GROUP BY t.ToolID
    ORDER BY t.DateAdded DESC
");
$stmt->execute(['ownerID' => $_SESSION['user_id']]);
$tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch rentals for each tool to show rented periods and renters
$toolRentals = [];
foreach ($tools as $tool) {
    $stmtRentals = $pdo->prepare("
        SELECT r.RentalID, r.RentalStartDate, r.RentalEndDate, r.Status, u.UserID, u.FirstName, u.LastName
        FROM Rental r
        JOIN User u ON r.RenterID = u.UserID
        WHERE r.ToolID = :toolId AND r.Status NOT IN ('Rejected', 'Cancelled')
        ORDER BY r.RentalStartDate
    ");
    $stmtRentals->execute(['toolId' => $tool['ToolID']]);
    $rentals = $stmtRentals->fetchAll(PDO::FETCH_ASSOC);
    $toolRentals[$tool['ToolID']] = $rentals;
}

// Fetch booked rental date ranges for availability display
$toolBookings = [];
foreach ($tools as $tool) {
    $stmtBookings = $pdo->prepare("
        SELECT RentalStartDate, RentalEndDate
        FROM Rental
        WHERE ToolID = :toolId AND Status NOT IN ('Rejected', 'Cancelled') AND ReturnDate IS NULL
        ORDER BY RentalStartDate
    ");
    $stmtBookings->execute(['toolId' => $tool['ToolID']]);
    $bookings = $stmtBookings->fetchAll(PDO::FETCH_ASSOC);
    $toolBookings[$tool['ToolID']] = $bookings;
}

// Handle tool addition/editing if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO Tool (OwnerID, Name, Description, PricePerDay, Category, Location, DeliveryOption, DeliveryPrice)
                    VALUES (:ownerID, :name, :description, :pricePerDay, :category, :location, :deliveryOption, :deliveryPrice)
                ");
                
                $stmt->execute([
                    'ownerID' => $_SESSION['user_id'],
                    'name' => sanitizeInput($_POST['name']),
                    'description' => sanitizeInput($_POST['description']),
                    'pricePerDay' => floatval($_POST['pricePerDay']),
                    'category' => sanitizeInput($_POST['category']),
                    'location' => sanitizeInput($_POST['location']),
                    'deliveryOption' => sanitizeInput($_POST['deliveryOption']),
                    'deliveryPrice' => floatval($_POST['deliveryPrice'])
                ]);

                // Get the last inserted ToolID
                $toolId = $pdo->lastInsertId();

                // Handle photo upload with validation for multiple files
                if (isset($_FILES['photos'])) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    $maxSize = 5 * 1024 * 1024; // 5MB

                    foreach ($_FILES['photos']['error'] as $key => $errorCode) {
                        if ($errorCode === UPLOAD_ERR_OK) {
                            $fileType = $_FILES['photos']['type'][$key];
                            $fileSize = $_FILES['photos']['size'][$key];
                            $fileTmpName = $_FILES['photos']['tmp_name'][$key];
                            $fileName = $_FILES['photos']['name'][$key];

                            if (in_array($fileType, $allowedTypes) && $fileSize <= $maxSize) {
                                $fileArray = [
                                    'name' => $fileName,
                                    'type' => $fileType,
                                    'tmp_name' => $fileTmpName,
                                    'error' => $errorCode,
                                    'size' => $fileSize
                                ];
                                uploadToolPhoto($toolId, $fileArray);
                            } else {
                                $error = "Invalid photo - must be JPEG, PNG or GIF under 5MB";
                                break;
                            }
                        }
                    }
                }

                $success = "Tool added successfully!";
                header("Location: tools.php");
                exit();
            } else if ($_POST['action'] === 'edit' && isset($_POST['toolID'])) {
                $toolId = (int)$_POST['toolID'];
                $stmt = $pdo->prepare("
                    UPDATE Tool 
                    SET Name = :name,
                        Description = :description,
                        PricePerDay = :pricePerDay,
                        Category = :category,
                        AvailabilityStatus = :status,
                        Location = :location,
                        DeliveryOption = :deliveryOption,
                        DeliveryPrice = :deliveryPrice
                    WHERE ToolID = :toolID AND OwnerID = :ownerID
                ");

                $stmt->execute([
                    'name' => sanitizeInput($_POST['name']),
                    'description' => sanitizeInput($_POST['description']),
                    'pricePerDay' => floatval($_POST['pricePerDay']),
                    'category' => sanitizeInput($_POST['category']),
                    'status' => sanitizeInput($_POST['status']),
                    'location' => sanitizeInput($_POST['location']),
                    'deliveryOption' => sanitizeInput($_POST['deliveryOption']),
                    'deliveryPrice' => floatval($_POST['deliveryPrice']),
                    'toolID' => $toolId,
                    'ownerID' => $_SESSION['user_id']
                ]);

                // Handle new photo uploads in edit
                if (isset($_FILES['photos'])) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    $maxSize = 5 * 1024 * 1024; // 5MB

                    foreach ($_FILES['photos']['error'] as $key => $errorCode) {
                        if ($errorCode === UPLOAD_ERR_OK) {
                            $fileType = $_FILES['photos']['type'][$key];
                            $fileSize = $_FILES['photos']['size'][$key];
                            $fileTmpName = $_FILES['photos']['tmp_name'][$key];
                            $fileName = $_FILES['photos']['name'][$key];

                            if (in_array($fileType, $allowedTypes) && $fileSize <= $maxSize) {
                                $fileArray = [
                                    'name' => $fileName,
                                    'type' => $fileType,
                                    'tmp_name' => $fileTmpName,
                                    'error' => $errorCode,
                                    'size' => $fileSize
                                ];
                                uploadToolPhoto($toolId, $fileArray);
                            } else {
                                $error = "Invalid photo - must be JPEG, PNG or GIF under 5MB";
                                break;
                            }
                        }
                    }
                }

                $success = "Tool updated successfully!";
                header("Location: tools.php");
                exit();
            }
        }
    } catch (Exception $e) {
        $error = "Error processing request: " . $e->getMessage();
    }
}

// Fetch photos for all tools to pass to JS for edit modal
$toolPhotosMap = [];
foreach ($tools as $tool) {
    $toolPhotosMap[$tool['ToolID']] = getToolPhotos($tool['ToolID']);
}
?>

<div class="container mx-auto my-8">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">Manage Tools</h2>
        <button onclick="showAddToolModal()" 
                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            Add New Tool
        </button>
    </div>

    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Tools List -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($tools)): ?>
            <p class="p-6 text-gray-600">No tools listed yet. Add your first tool!</p>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Price/Day</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rating</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($tools as $tool): ?>
                        <tr>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($tool['Name']); ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php echo htmlspecialchars($tool['Description'] ?? ''); ?>
                            </div>
                            <div class="text-sm text-gray-400 mt-1">
                                <?php echo htmlspecialchars($tool['Location'] ?? ''); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <?php echo htmlspecialchars($tool['Category']); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            $<?php echo number_format($tool['PricePerDay'], 2); ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-sm rounded-full 
                                <?php echo $tool['AvailabilityStatus'] === 'Available' ? 
                                    'bg-green-100 text-green-800' : 
                                    ($tool['AvailabilityStatus'] === 'Rented' ? 
                                        'bg-blue-100 text-blue-800' : 
                                        'bg-red-100 text-red-800'); ?>">
                                <?php echo $tool['AvailabilityStatus']; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">
                            <?php 
                                echo $tool['avg_rating'] ? 
                                    number_format($tool['avg_rating'], 1) . ' ★' : 
                                    'No ratings';
                            ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700 max-w-xs">
                            <?php
                            $bookings = $toolBookings[$tool['ToolID']] ?? [];
                            if (empty($bookings)) {
                                echo 'No bookings';
                            } else {
                                echo '<ul class="list-disc list-inside">';
                                foreach ($bookings as $booking) {
                                    echo '<li>' . date('M d, Y', strtotime($booking['RentalStartDate'])) . ' - ' . date('M d, Y', strtotime($booking['RentalEndDate'])) . '</li>';
                                }
                                echo '</ul>';
                            }
                            ?>
                        </td>
                            <td class="px-6 py-4 text-sm">
                                <button onclick="showRentalDetailsModal(<?php echo htmlspecialchars(json_encode($tool)); ?>)"
                                        class="text-blue-600 hover:text-blue-900">
                                    View Rentals
                                </button>
<button onclick='showEditToolModal(<?php echo htmlspecialchars(json_encode($tool), ENT_QUOTES, "UTF-8"); ?>)'
        class="ml-2 text-green-600 hover:text-green-900">
    Edit Tool
</button>
                                <button onclick="deleteTool(<?php echo $tool['ToolID']; ?>)"
                                        class="ml-2 text-red-600 hover:text-red-900">
                                    Delete Tool
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Add Tool Modal -->
<div id="addToolModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden">
    <div class="flex items-center justify-center min-h-screen">
        <div class="bg-white w-full max-w-md p-6 rounded-lg shadow-xl">
            <h3 class="text-xl font-bold mb-4">Add New Tool</h3>
            <form method="POST" action="" enctype="multipart/form-data" onsubmit="return validateToolForm(this)">
                <input type="hidden" name="action" value="add">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Tool Name</label>
                    <input type="text" name="name" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

+                <div class="mb-4">
+                    <label class="block text-sm font-medium text-gray-700">Description</label>
+                    <textarea name="description" required
+                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"></textarea>
+                </div>
+
+                <div class="mb-4">
+                    <label class="block text-sm font-medium text-gray-700">Location</label>
+                    <input type="text" name="location" required
+                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
+                           placeholder="Enter tool location">
+                </div>
+
+                <div class="mb-4">
+                    <label class="block text-sm font-medium text-gray-700">Delivery Option</label>
+                    <select name="deliveryOption" id="deliveryOption" required
+                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
+                            onchange="toggleDeliveryPriceInput()">
+                        <option value="Pickup Only">Pickup Only</option>
+                        <option value="Delivery Available">Delivery Available</option>
+                    </select>
+                </div>
+
+                <div class="mb-4" id="deliveryPriceContainer" style="display:none;">
+                    <label class="block text-sm font-medium text-gray-700">Delivery Price</label>
+                    <input type="number" name="deliveryPrice" step="0.01" min="0" value="0"
+                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
+                           placeholder="Enter delivery price (optional)">
+                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Price per Day</label>
                    <input type="number" name="pricePerDay" step="0.01" min="0" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Category</label>
                    <select name="category" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                        <option value="">Select category</option>
                        <option value="Hand Tools">Hand Tools</option>
                        <option value="Power Tools">Power Tools</option>
                        <option value="Garden Tools">Garden Tools</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Photos</label>
                    <input type="file" name="photos[]" multiple accept="image/*"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="flex justify-end gap-4">
                    <button type="button" onclick="hideAddToolModal()"
                            class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-500">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                        Add Tool
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Tool Modal -->
<div id="editToolModal" style="z-index: 1000;" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-auto">
    <div class="flex items-start justify-center min-h-screen pt-10 px-4">
        <div class="bg-white w-full max-w-3xl p-6 rounded-lg shadow-xl relative">
            <h3 class="text-xl font-bold mb-4">Edit Tool</h3>
            <form method="POST" action="" enctype="multipart/form-data" onsubmit="return validateToolForm(this)">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="toolID" id="editToolID">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Tool Name</label>
                    <input type="text" name="name" id="editToolName" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="editToolDescription" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"></textarea>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Price per Day</label>
                    <input type="number" name="pricePerDay" id="editToolPrice" step="0.01" min="0" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Category</label>
                    <select name="category" id="editToolCategory" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                        <option value="">Select category</option>
                        <option value="Hand Tools">Hand Tools</option>
                        <option value="Power Tools">Power Tools</option>
                        <option value="Garden Tools">Garden Tools</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Availability Status</label>
                    <select name="status" id="editToolStatus" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                        <option value="Available">Available</option>
                        <option value="Unavailable">Unavailable</option>
                    </select>
                </div>

                <!-- New fields for Location, Delivery Option, Delivery Price -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Location</label>
                    <input type="text" name="location" id="editToolLocation" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                           placeholder="Enter tool location">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Delivery Option</label>
                    <select name="deliveryOption" id="editDeliveryOption" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                            onchange="toggleEditDeliveryPriceInput()">
                        <option value="Pickup Only">Pickup Only</option>
                        <option value="Delivery Available">Delivery Available</option>
                    </select>
                </div>

                <div class="mb-4" id="editDeliveryPriceContainer" style="display:none;">
                    <label class="block text-sm font-medium text-gray-700">Delivery Price</label>
                    <input type="number" name="deliveryPrice" id="editDeliveryPrice" step="0.01" min="0" value="0"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                           placeholder="Enter delivery price (optional)">
                </div>

                <!-- Existing Photos Section -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Existing Photos</label>
                    <div id="existingPhotos" class="grid grid-cols-3 gap-4">
                        <!-- Photos will be dynamically inserted here -->
                    </div>
                </div>

                <!-- Upload New Photos -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700">Add Photos</label>
                    <input type="file" name="photos[]" multiple accept="image/*"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                </div>

                <div class="flex justify-end gap-4">
                    <button type="button" onclick="hideEditToolModal()"
                            class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-500">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                        Save Changes
                    </button>
                </div>
            </form>
            <button type="button" onclick="hideEditToolModal()" 
                    class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 text-2xl font-bold">&times;</button>
        </div>
    </div>
</div>

<!-- Rentals Modal -->
<div id="rentalDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-auto">
    <div class="flex items-start justify-center min-h-screen pt-10 px-4">
        <div class="bg-white w-full max-w-3xl p-6 rounded-lg shadow-xl relative">
            <h3 class="text-xl font-bold mb-4">Rentals for <span id="rentalToolNameTitle"></span></h3>
            <button type="button" onclick="hideRentalDetailsModal()" 
                    class="absolute top-3 right-3 text-gray-500 hover:text-gray-700 text-2xl font-bold">&times;</button>
            <div id="rentalDetailsContainer" class="max-h-96 overflow-y-auto">
                <!-- Rental details will be populated here -->
            </div>
        </div>
    </div>
</div>

<script>
const toolPhotosMap = <?php echo json_encode($toolPhotosMap); ?>;
const toolRentals = <?php echo json_encode($toolRentals); ?>;

function showAddToolModal() {
    document.getElementById('addToolModal').classList.remove('hidden');
}

function hideAddToolModal() {
    document.getElementById('addToolModal').classList.add('hidden');
}

function showEditToolModal(tool) {
    if (typeof tool === 'string') {
        try {
            tool = JSON.parse(tool);
        } catch (e) {
            console.error('Failed to parse tool JSON:', e);
            return;
        }
    }
    document.getElementById('editToolID').value = tool.ToolID;
    document.getElementById('editToolName').value = tool.Name;
    document.getElementById('editToolDescription').value = tool.Description;
    document.getElementById('editToolPrice').value = tool.PricePerDay;
    document.getElementById('editToolCategory').value = tool.Category;
    document.getElementById('editToolStatus').value = tool.AvailabilityStatus;

    // Set location and delivery option values
    document.getElementById('editToolLocation').value = tool.Location || '';
    document.getElementById('editDeliveryOption').value = tool.DeliveryOption || 'Pickup Only';
    toggleEditDeliveryPriceInput();

    // Set delivery price value
    document.getElementById('editDeliveryPrice').value = tool.DeliveryPrice || 0;

    // Populate existing photos
    const photosContainer = document.getElementById('existingPhotos');
    photosContainer.innerHTML = '';
    const photos = toolPhotosMap[tool.ToolID] || [];
    photos.forEach(photo => {
        const photoDiv = document.createElement('div');
        photoDiv.className = 'relative group rounded-lg overflow-hidden border border-gray-300';

        const img = document.createElement('img');
        img.src = '../../' + photo.PhotoPath;
        img.alt = 'Tool photo';
        img.className = 'w-full h-32 object-cover';

        const overlay = document.createElement('div');
        overlay.className = 'absolute inset-0 bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center space-x-2';

        // Set as primary button if not primary
        if (!photo.IsPrimary) {
            const setPrimaryBtn = document.createElement('button');
            setPrimaryBtn.textContent = 'Set as Primary';
            setPrimaryBtn.className = 'bg-green-500 text-white px-2 py-1 rounded hover:bg-green-600 text-xs';
            setPrimaryBtn.onclick = () => setPrimaryPhoto(tool.ToolID, photo.PhotoID);
            overlay.appendChild(setPrimaryBtn);
        } else {
            const primaryLabel = document.createElement('div');
            primaryLabel.textContent = 'Primary Photo';
            primaryLabel.className = 'absolute top-1 left-1 bg-blue-500 text-white px-2 py-0.5 rounded text-xs';
            photoDiv.appendChild(primaryLabel);
        }

        // Delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.textContent = 'Delete';
        deleteBtn.className = 'bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600 text-xs';
        deleteBtn.onclick = () => deletePhoto(tool.ToolID, photo.PhotoID);
        overlay.appendChild(deleteBtn);

        photoDiv.appendChild(img);
        photoDiv.appendChild(overlay);
        photosContainer.appendChild(photoDiv);
    });

    document.getElementById('editToolModal').classList.remove('hidden');
}

function hideEditToolModal() {
    document.getElementById('editToolModal').classList.add('hidden');
}

function showRentalDetailsModal(tool) {
    document.getElementById('rentalToolNameTitle').textContent = tool.Name;
    const rentalDetailsContainer = document.getElementById('rentalDetailsContainer');
    rentalDetailsContainer.innerHTML = '';

    const rentals = toolRentals[tool.ToolID] || [];
    if (rentals.length === 0) {
        rentalDetailsContainer.innerHTML = '<p class="text-gray-600">No rentals for this tool.</p>';
    } else {
        const list = document.createElement('ul');
        list.className = 'divide-y divide-gray-200';

        rentals.forEach(rental => {
            const item = document.createElement('li');
            item.className = 'py-2';

            const renterName = document.createElement('p');
            renterName.className = 'font-semibold';
            renterName.textContent = `Rented by: ${rental.FirstName} ${rental.LastName}`;

            const rentalPeriod = document.createElement('p');
            rentalPeriod.className = 'text-sm text-gray-600';
            rentalPeriod.textContent = `Period: ${new Date(rental.RentalStartDate).toLocaleDateString()} - ${new Date(rental.RentalEndDate).toLocaleDateString()}`;

            const status = document.createElement('p');
            status.className = 'text-sm text-gray-600';
            status.textContent = `Status: ${rental.Status}`;

            item.appendChild(renterName);
            item.appendChild(rentalPeriod);
            item.appendChild(status);

            list.appendChild(item);
        });

        rentalDetailsContainer.appendChild(list);
    }

    document.getElementById('rentalDetailsModal').classList.remove('hidden');
}

function hideRentalDetailsModal() {
    document.getElementById('rentalDetailsModal').classList.add('hidden');
}

function deleteTool(toolId) {
    if (!confirm('Are you sure you want to delete this tool? This action cannot be undone.')) return;
    fetch(`tools.php?ajax_action=delete_tool&tool_id=${toolId}`)
        .then(async response => {
            const text = await response.text();
            if (!response.ok) {
                alert('Failed to delete tool: Server returned status ' + response.status);
                console.error('Delete tool error response:', text);
                return;
            }
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Failed to delete tool: ' + data.message);
                    console.error('Delete tool failure:', data);
                }
            } catch (e) {
                alert('Failed to delete tool: Invalid server response');
                console.error('Delete tool invalid JSON:', text);
            }
        })
        .catch(error => {
            alert('Failed to delete tool due to network error.');
            console.error('Delete tool network error:', error);
        });
}


function validateToolForm(form) {
    const price = parseFloat(form.pricePerDay.value);
    if (isNaN(price) || price <= 0) {
        showAlert('Please enter a valid price per day.', 'error');
        return false;
    }
    return true;
}

function showAlert(message, type = 'success') {
    const alertDiv = document.createElement('div');
    alertDiv.textContent = message;
    alertDiv.className = type === 'success' ? 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4' : 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4';
    const container = document.querySelector('.container');
    container.insertBefore(alertDiv, container.firstChild);
    setTimeout(() => alertDiv.remove(), 4000);
}

function toggleDeliveryPriceInput() {
    const deliveryOption = document.getElementById('deliveryOption').value;
    const deliveryPriceContainer = document.getElementById('deliveryPriceContainer');
    if (deliveryOption === 'Delivery Available') {
        deliveryPriceContainer.style.display = 'block';
    } else {
        deliveryPriceContainer.style.display = 'none';
    }
}

function toggleEditDeliveryPriceInput() {
    const deliveryOption = document.getElementById('editDeliveryOption').value;
    const deliveryPriceContainer = document.getElementById('editDeliveryPriceContainer');
    if (deliveryOption === 'Delivery Available') {
        deliveryPriceContainer.style.display = 'block';
    } else {
        deliveryPriceContainer.style.display = 'none';
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    const addModal = document.getElementById('addToolModal');
    const editModal = document.getElementById('editToolModal');
    const rentalModal = document.getElementById('rentalDetailsModal');
    if (event.target === addModal) {
        hideAddToolModal();
    }
    if (event.target === editModal) {
        hideEditToolModal();
    }
    if (event.target === rentalModal) {
        hideRentalDetailsModal();
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
