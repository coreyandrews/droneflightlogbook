<?php
// Define the path for the SQLite database file
$dbFile = '/var/www/html/data/drone_flights.sqlite';
$uploadDir = '/var/www/html/uploads/'; // Directory to store uploaded files

// Ensure directories exist and are writable
if (!is_dir(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0777, true);
}
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Connect to SQLite database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create documents table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        document_type TEXT NOT NULL, -- e.g., 'drone_registration', 'pilot_license'
        file_path TEXT NOT NULL UNIQUE,
        original_filename TEXT NOT NULL,
        upload_date TEXT NOT NULL
    )");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$messageType = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    $documentType = trim($_POST['document_type'] ?? '');

    if (empty($documentType) || !isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please select a document type and a file to upload.';
        $messageType = 'error';
    } else {
        $file = $_FILES['document_file'];
        $originalFilename = basename($file['name']);
        $fileExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $allowedExtensions = ['pdf'];

        if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
            $message = 'Only PDF files are allowed.';
            $messageType = 'error';
        } elseif ($file['size'] > 5 * 1024 * 1024) { // 5 MB limit
            $message = 'File size exceeds the 5MB limit.';
            $messageType = 'error';
        } else {
            // Generate a unique filename to prevent conflicts
            $uniqueFilename = uniqid('doc_', true) . '.' . $fileExtension;
            $destinationPath = $uploadDir . $uniqueFilename;

            if (move_uploaded_file($file['tmp_name'], $destinationPath)) {
                try {
                    // Before inserting, check if a document of this type already exists
                    // If it does, update it. Otherwise, insert.
                    $stmt = $pdo->prepare("SELECT id, file_path FROM documents WHERE document_type = :document_type");
                    $stmt->execute([':document_type' => $documentType]);
                    $existingDoc = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingDoc) {
                        // Update existing entry and delete old file
                        $stmt = $pdo->prepare("UPDATE documents SET file_path = :file_path, original_filename = :original_filename, upload_date = :upload_date WHERE id = :id");
                        $stmt->execute([
                            ':file_path' => 'uploads/' . $uniqueFilename, // Store relative path
                            ':original_filename' => $originalFilename,
                            ':upload_date' => date('Y-m-d H:i:s'),
                            ':id' => $existingDoc['id']
                        ]);
                        // Delete the old file if it exists
                        if (file_exists('/var/www/html/' . $existingDoc['file_path'])) {
                            unlink('/var/www/html/' . $existingDoc['file_path']);
                        }
                        $message = 'Document updated successfully.';
                    } else {
                        // Insert new entry
                        $stmt = $pdo->prepare("INSERT INTO documents (document_type, file_path, original_filename, upload_date) VALUES (:document_type, :file_path, :original_filename, :upload_date)");
                        $stmt->execute([
                            ':document_type' => $documentType,
                            ':file_path' => 'uploads/' . $uniqueFilename, // Store relative path
                            ':original_filename' => $originalFilename,
                            ':upload_date' => date('Y-m-d H:i:s')
                        ]);
                        $message = 'Document uploaded successfully.';
                    }
                    $messageType = 'success';
                } catch (PDOException $e) {
                    // If DB insertion fails, delete the uploaded file to prevent orphans
                    unlink($destinationPath);
                    $message = 'Error saving document details to database: ' . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = 'Failed to move uploaded file.';
                $messageType = 'error';
            }
        }
    }
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_document') {
    $docId = filter_var($_POST['doc_id'] ?? '', FILTER_VALIDATE_INT);

    if ($docId === false || $docId <= 0) {
        $message = 'Invalid document ID for deletion.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE id = :id");
            $stmt->execute([':id' => $docId]);
            $docToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($docToDelete) {
                $stmt = $pdo->prepare("DELETE FROM documents WHERE id = :id");
                $stmt->execute([':id' => $docId]);

                if ($stmt->rowCount() > 0) {
                    // Also delete the physical file
                    if (file_exists('/var/www/html/' . $docToDelete['file_path'])) {
                        unlink('/var/www/html/' . $docToDelete['file_path']);
                    }
                    $message = 'Document deleted successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Document not found or could not be deleted from database.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Document not found.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting document: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch all uploaded documents for display
$stmt = $pdo->query("SELECT * FROM documents ORDER BY upload_date DESC");
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Documents - Drone Flight Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
        }
        .container {
            max-width: 960px;
        }
        /* Modal styles (copied from detail.php for consistency) */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 90%;
            max-width: 400px;
        }
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="container mx-auto bg-white shadow-lg rounded-xl p-8 my-8">
        <h1 class="text-4xl font-bold text-center text-gray-800 mb-8">Upload Documents</h1>

        <div class="mb-6">
            <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                &larr; Back to Logbook
            </a>
        </div>

        <?php if ($message): ?>
            <div class="rounded-lg p-4 mb-6
                <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Upload New Document</h2>
            <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                <input type="hidden" name="action" value="upload_document">
                <div>
                    <label for="document_type" class="block text-sm font-medium text-gray-700">Document Type</label>
                    <select id="document_type" name="document_type" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border bg-white">
                        <option value="">Select Type</option>
                        <option value="pilot_license">Pilot License</option>
                        <option value="drone_registration">Drone Registration</option>
                        <option value="other">Other Document</option>
                    </select>
                </div>
                <div>
                    <label for="document_file" class="block text-sm font-medium text-gray-700">Select PDF File (Max 5MB)</label>
                    <input type="file" id="document_file" name="document_file" accept=".pdf" required
                           class="mt-1 block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-white focus:outline-none file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                </div>
                <div class="md:col-span-2">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out w-full">
                        Upload Document
                    </button>
                </div>
            </form>
        </div>

        <!-- Uploaded Documents List -->
        <div class="p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Uploaded Documents</h2>
            <?php if (empty($documents)): ?>
                <p class="text-gray-600">No documents uploaded yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">
                                    Type
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Original Filename
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Upload Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php
                                            $typeDisplay = str_replace('_', ' ', $doc['document_type']);
                                            echo htmlspecialchars(ucwords($typeDisplay));
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($doc['original_filename']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($doc['upload_date']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium flex space-x-2">
                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank"
                                           class="text-blue-600 hover:text-blue-900 transition duration-150 ease-in-out">
                                            View
                                        </a>
                                        <button type="button"
                                                onclick="showDeleteModal(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['original_filename']); ?>')"
                                                class="text-red-600 hover:text-red-900 transition duration-150 ease-in-out">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal hidden">
        <div class="modal-content">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Confirm Deletion</h3>
            <p class="text-gray-700 mb-6">Are you sure you want to delete <span id="entryToDeleteText" class="font-bold"></span>?</p>
            <div class="flex justify-end space-x-4">
                <button type="button" onclick="hideDeleteModal()"
                        class="py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Cancel
                </button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="doc_id" id="confirmDeleteEntryId">
                    <button type="submit"
                            class="py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        const deleteModal = document.getElementById('deleteModal');
        const confirmDeleteEntryId = document.getElementById('confirmDeleteEntryId');
        const entryToDeleteText = document.getElementById('entryToDeleteText');

        function showDeleteModal(id, entryDescription) {
            confirmDeleteEntryId.value = id;
            entryToDeleteText.textContent = entryDescription;
            deleteModal.classList.remove('hidden');
        }

        function hideDeleteModal() {
            deleteModal.classList.add('hidden');
        }

        // Close modal if user clicks outside of it
        window.onclick = function(event) {
            if (event.target == deleteModal) {
                hideDeleteModal();
            }
        }
    </script>
</body>
</html>
