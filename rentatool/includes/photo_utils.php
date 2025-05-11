<?php
function uploadToolPhoto($toolId, $photoFile) {
    global $pdo;
    
    // Validate file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($photoFile['type'], $allowedTypes)) {
        throw new Exception("Invalid file type. Only JPG, PNG and GIF are allowed.");
    }
    
    if ($photoFile['size'] > $maxSize) {
        throw new Exception("File is too large. Maximum size is 5MB.");
    }
    
    // Create unique filename
    $extension = pathinfo($photoFile['name'], PATHINFO_EXTENSION);
    $filename = uniqid("tool_{$toolId}_") . '.' . $extension;
    $uploadPath = __DIR__ . '/../uploads/tools/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($photoFile['tmp_name'], $uploadPath)) {
        throw new Exception("Failed to upload file.");
    }
    
    // Insert into database
    $stmt = $pdo->prepare("
        INSERT INTO ToolPhoto (ToolID, PhotoPath) 
        VALUES (:toolId, :photoPath)
    ");
    
    $stmt->execute([
        'toolId' => $toolId,
        'photoPath' => 'uploads/tools/' . $filename
    ]);
    
    $photoId = $pdo->lastInsertId();
    
    // If this is the first photo, make it primary
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as photo_count 
        FROM ToolPhoto 
        WHERE ToolID = :toolId
    ");
    $stmt->execute(['toolId' => $toolId]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['photo_count'];
    
    if ($count == 1) {
        $stmt = $pdo->prepare("CALL SetPrimaryPhoto(:toolId, :photoId)");
        $stmt->execute([
            'toolId' => $toolId,
            'photoId' => $photoId
        ]);
    }
    
    return $photoId;
}

function getToolPhotos($toolId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT PhotoID, PhotoPath, IsPrimary, UploadedAt
        FROM ToolPhoto
        WHERE ToolID = :toolId
        ORDER BY IsPrimary DESC, UploadedAt DESC
    ");
    
    $stmt->execute(['toolId' => $toolId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setPrimaryPhoto($toolId, $photoId) {
    global $pdo;
    
    // Verify the photo belongs to the tool
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as photo_exists
        FROM ToolPhoto
        WHERE PhotoID = :photoId AND ToolID = :toolId
    ");
    $stmt->execute([
        'photoId' => $photoId,
        'toolId' => $toolId
    ]);
    
    if ($stmt->fetch(PDO::FETCH_ASSOC)['photo_exists'] == 0) {
        throw new Exception("Invalid photo ID.");
    }
    
    $stmt = $pdo->prepare("CALL SetPrimaryPhoto(:toolId, :photoId)");
    $stmt->execute([
        'toolId' => $toolId,
        'photoId' => $photoId
    ]);
}

function deleteToolPhoto($toolId, $photoId) {
    global $pdo;
    
    // Get photo path before deleting
    $stmt = $pdo->prepare("
        SELECT PhotoPath, IsPrimary
        FROM ToolPhoto
        WHERE PhotoID = :photoId AND ToolID = :toolId
    ");
    $stmt->execute([
        'photoId' => $photoId,
        'toolId' => $toolId
    ]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$photo) {
        throw new Exception("Invalid photo ID.");
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Delete from database
        $stmt = $pdo->prepare("
            DELETE FROM ToolPhoto
            WHERE PhotoID = :photoId AND ToolID = :toolId
        ");
        $stmt->execute([
            'photoId' => $photoId,
            'toolId' => $toolId
        ]);
        
        // If this was the primary photo, set another photo as primary
        if ($photo['IsPrimary']) {
            $stmt = $pdo->prepare("
                SELECT PhotoID
                FROM ToolPhoto
                WHERE ToolID = :toolId
                ORDER BY UploadedAt DESC
                LIMIT 1
            ");
            $stmt->execute(['toolId' => $toolId]);
            $newPrimary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($newPrimary) {
                $stmt = $pdo->prepare("CALL SetPrimaryPhoto(:toolId, :photoId)");
                $stmt->execute([
                    'toolId' => $toolId,
                    'photoId' => $newPrimary['PhotoID']
                ]);
            }
        }
        
        $pdo->commit();
        
        // Delete file from filesystem
        $filePath = __DIR__ . '/../' . $photo['PhotoPath'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function getToolPrimaryPhoto($toolId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT PhotoPath
        FROM ToolPhoto
        WHERE ToolID = :toolId AND IsPrimary = TRUE
        LIMIT 1
    ");
    
    $stmt->execute(['toolId' => $toolId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $photo ? $photo['PhotoPath'] : null;
}
?>
