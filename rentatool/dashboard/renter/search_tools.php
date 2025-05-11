<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/header.php';

$tools = [];

// Handle search functionality
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $search = sanitizeInput($_GET['search']);
    $category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
    $location = isset($_GET['location']) ? sanitizeInput($_GET['location']) : '';
    $delivery = isset($_GET['delivery']) ? sanitizeInput($_GET['delivery']) : '';

    $params = ['search' => '%' . $search . '%'];
    $query = "SELECT DISTINCT t.ToolID, t.Name, t.Category, t.PricePerDay, t.OwnerID, t.Location, t.DeliveryOption, u.FirstName, u.LastName
              FROM Tool t
              JOIN User u ON t.OwnerID = u.UserID
              WHERE (t.Name LIKE :search OR u.FirstName LIKE :search OR u.LastName LIKE :search)";
    
    if ($category) {
        $query .= " AND t.Category = :category";
        $params['category'] = $category;
    }
    if ($location) {
        $query .= " AND REPLACE(LOWER(t.Location), ' ', '') LIKE REPLACE(LOWER(:location), ' ', '')";
        $params['location'] = '%' . str_replace(' ', '', strtolower($location)) . '%';
    }
    if ($delivery) {
        if ($delivery === 'Both') {
            $query .= " AND t.DeliveryOption IN ('Pickup Only', 'Delivery Available')";
        } else {
            $query .= " AND t.DeliveryOption = :delivery";
            $params['delivery'] = $delivery;
        }
    }
    
    $query .= " AND t.AvailabilityStatus = 'Available'";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicate tools by ToolID
    $uniqueTools = [];
    $seenToolIDs = [];
    foreach ($tools as $tool) {
        if (!in_array($tool['ToolID'], $seenToolIDs)) {
            $seenToolIDs[] = $tool['ToolID'];
            $uniqueTools[] = $tool;
        }
    }
    $tools = $uniqueTools;

    // Fetch one photo per tool
    foreach ($tools as &$tool) {
        $stmtPhoto = $pdo->prepare("SELECT PhotoPath FROM ToolPhoto WHERE ToolID = :toolID LIMIT 1");
        $stmtPhoto->execute(['toolID' => $tool['ToolID']]);
        $photo = $stmtPhoto->fetch(PDO::FETCH_ASSOC);
        $tool['PhotoPath'] = $photo ? $photo['PhotoPath'] : null;
    }
}
?>

<div class="container mx-auto my-8">
    <h2 class="text-2xl font-bold mb-4">Search Tools</h2>
    <form method="GET" action="" class="mb-6 flex flex-wrap items-center gap-3">
        <input type="text" name="search" placeholder="Search for tools or owners..." required
            value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
            class="border rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-48">
        <select name="category" class="border rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-40">
            <option value="" <?php echo empty($_GET['category']) ? 'selected' : ''; ?>>All Categories</option>
            <option value="Hand Tools" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Hand Tools') ? 'selected' : ''; ?>>Hand Tools</option>
            <option value="Power Tools" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Power Tools') ? 'selected' : ''; ?>>Power Tools</option>
            <option value="Garden Tools" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Garden Tools') ? 'selected' : ''; ?>>Garden Tools</option>
            <option value="Electronics" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Electronics') ? 'selected' : ''; ?>>Electronics</option>
            <option value="Other" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Other') ? 'selected' : ''; ?>>Other</option>
        </select>
        <input type="text" name="location" placeholder="Filter by location"
            value="<?php echo isset($_GET['location']) ? htmlspecialchars($_GET['location']) : ''; ?>"
            class="border rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-40">
        <select name="delivery" class="border rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-40">
            <option value="" <?php echo empty($_GET['delivery']) ? 'selected' : ''; ?>>All Delivery Options</option>
            <option value="Pickup Only" <?php echo (isset($_GET['delivery']) && $_GET['delivery'] === 'Pickup Only') ? 'selected' : ''; ?>>Pickup Only</option>
            <option value="Delivery Available" <?php echo (isset($_GET['delivery']) && $_GET['delivery'] === 'Delivery Available') ? 'selected' : ''; ?>>Delivery Available</option>
            <option value="Both" <?php echo (isset($_GET['delivery']) && $_GET['delivery'] === 'Both') ? 'selected' : ''; ?>>Both</option>
        </select>
        <button type="submit" class="bg-blue-600 text-white px-4 py-1 rounded hover:bg-blue-700 h-8">
            Search
        </button>
    </form>

    <?php if (empty($tools)): ?>
        <p class="text-gray-600">No tools found matching your search criteria.</p>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
            <?php foreach ($tools as $tool): ?>
                <div class="bg-white rounded-lg shadow p-4 flex flex-col">
                    <?php if ($tool['PhotoPath']): ?>
                        <img src="<?php echo htmlspecialchars(BASE_URL . $tool['PhotoPath']); ?>" alt="Tool Image" class="h-48 w-full object-cover rounded mb-4">
                    <?php else: ?>
                        <div class="h-48 w-full bg-gray-200 rounded mb-4 flex items-center justify-center text-gray-500">
                            No Image
                        </div>
                    <?php endif; ?>
                    <h3 class="text-lg font-semibold mb-1"><?php echo htmlspecialchars($tool['Name']); ?></h3>
                    <p class="text-sm text-gray-600 mb-1">Owner: <a href="<?php echo BASE_URL; ?>dashboard/user_profile.php?user_id=<?php echo $tool['OwnerID']; ?>" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($tool['FirstName'] . ' ' . $tool['LastName']); ?></a></p>
                    <p class="text-sm text-gray-600 mb-1">Category: <?php echo htmlspecialchars($tool['Category']); ?></p>
                    <p class="text-sm text-gray-600 mb-1">Location: <?php echo htmlspecialchars($tool['Location'] ?? ''); ?></p>
                    <p class="text-sm text-gray-600 mb-1">Delivery Option: <?php echo htmlspecialchars($tool['DeliveryOption'] ?? ''); ?></p>
                    <p class="text-sm font-medium mb-4">$<?php echo number_format($tool['PricePerDay'], 2); ?> per day</p>
                    <a href="rent_tool.php?tool_id=<?php echo $tool['ToolID']; ?>" class="mt-auto bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-center">
                        Rent
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
