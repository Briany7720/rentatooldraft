<?php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/header.php';
require_once '../../includes/photo_utils.php';

requireLogin();

$toolId = isset($_GET['tool_id']) ? (int)$_GET['tool_id'] : 0;

// Verify tool ownership
$stmt = $pdo->prepare("
    SELECT Name
    FROM Tool
    WHERE ToolID = :toolId AND OwnerID = :ownerId
");
$stmt->execute([
    'toolId' => $toolId,
    'ownerId' => $_SESSION['user_id']
]);
$tool = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tool) {
    $_SESSION['error_message'] = "Invalid tool or unauthorized access.";
    header('Location: tools.php');
    exit();
}

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'upload':
                    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                        throw new Exception("No file uploaded or upload error occurred.");
                    }
                    uploadToolPhoto($toolId, $_FILES['photo']);
                    $_SESSION['success_message'] = "Photo uploaded successfully.";
                    break;

                case 'set_primary':
                    $photoId = (int)$_POST['photo_id'];
                    setPrimaryPhoto($toolId, $photoId);
                    $_SESSION['success_message'] = "Primary photo updated.";
                    break;

                case 'delete':
                    $photoId = (int)$_POST['photo_id'];
                    deleteToolPhoto($toolId, $photoId);
                    $_SESSION['success_message'] = "Photo deleted successfully.";
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
    
    // Redirect to clear POST data
    header("Location: manage_photos.php?tool_id=" . $toolId);
    exit();
}

// Get all photos for this tool
$photos = getToolPhotos($toolId);
?>

<div class="container mx-auto my-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold">Manage Photos: <?php echo htmlspecialchars($tool['Name']); ?></h2>
            <a href="tools.php" class="text-blue-600 hover:underline">Back to Tools</a>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php 
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php 
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4">Upload New Photo</h3>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="upload">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Photo</label>
                    <input type="file" name="photo" accept="image/*" required
                           class="block w-full text-sm text-gray-500
                                  file:mr-4 file:py-2 file:px-4
                                  file:rounded-full file:border-0
                                  file:text-sm file:font-semibold
                                  file:bg-blue-50 file:text-blue-700
                                  hover:file:bg-blue-100">
                    <p class="mt-1 text-sm text-gray-500">Maximum file size: 5MB. Supported formats: JPG, PNG, GIF</p>
                </div>
                <button type="submit" 
                    class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Upload Photo
                </button>
            </form>
        </div>

        <!-- Photos Grid -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold mb-4">Current Photos</h3>
            <?php if (empty($photos)): ?>
                <p class="text-gray-500">No photos uploaded yet.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach ($photos as $photo): ?>
                        <div class="relative group">
                            <img src="../../<?php echo htmlspecialchars($photo['PhotoPath']); ?>" 
                                 alt="Tool photo"
                                 class="w-full h-48 object-cover rounded-lg">
                            
                            <div class="absolute inset-0 bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center space-x-2">
                                <?php if (!$photo['IsPrimary']): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="set_primary">
                                        <input type="hidden" name="photo_id" value="<?php echo $photo['PhotoID']; ?>">
                                        <button type="submit" 
                                            class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">
                                            Set as Primary
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="POST" class="inline" 
                                      onsubmit="return confirm('Are you sure you want to delete this photo?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="photo_id" value="<?php echo $photo['PhotoID']; ?>">
                                    <button type="submit" 
                                        class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">
                                        Delete
                                    </button>
                                </form>
                            </div>
                            
                            <?php if ($photo['IsPrimary']): ?>
                                <div class="absolute top-2 left-2 bg-blue-500 text-white px-2 py-1 rounded text-sm">
                                    Primary Photo
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
