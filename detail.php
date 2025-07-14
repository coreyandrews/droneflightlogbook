<?php
// Define the path for the SQLite database file
$dbFile = '/var/www/html/data/drone_flights.sqlite';

// Ensure the data directory exists and is writable
if (!is_dir(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0777, true);
}

// Connect to SQLite database
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create flights table if it doesn't exist with all new fields
    $pdo->exec("CREATE TABLE IF NOT EXISTS flights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        operation_name TEXT NOT NULL,
        pilot TEXT NOT NULL,
        max_flight_height REAL NOT NULL,
        location TEXT NOT NULL,
        radius REAL NOT NULL,
        category_of_operation TEXT NOT NULL, -- 'Basic' or 'Advance'
        activity TEXT NOT NULL,
        flight_type TEXT NOT NULL,
        manufacturer TEXT NOT NULL,
        model TEXT NOT NULL,
        registration_number TEXT NOT NULL,
        take_off_time TEXT NOT NULL, -- Stored as YYYY-MM-DD HH:MM:SS
        landing_time TEXT NOT NULL,   -- Stored as YYYY-MM-DD HH:MM:SS
        UNIQUE(registration_number, take_off_time)
    )");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$messageType = ''; // 'success' or 'error'
$flight = null; // To store flight data if editing

// Get flight ID from URL if editing
$flightId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

if ($flightId) {
    // Fetch existing flight data for editing
    $stmt = $pdo->prepare("SELECT * FROM flights WHERE id = :id");
    $stmt->execute([':id' => $flightId]);
    $flight = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$flight) {
        $message = 'Flight entry not found.';
        $messageType = 'error';
        $flightId = null; // Clear ID if not found
    }
}

// Handle form submission for adding/updating flight
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $operationName = trim($_POST['operation_name'] ?? '');
    $pilot = trim($_POST['pilot'] ?? '');
    $maxFlightHeight = filter_var($_POST['max_flight_height'] ?? '', FILTER_VALIDATE_FLOAT);
    $location = trim($_POST['location'] ?? '');
    $radius = filter_var($_POST['radius'] ?? '', FILTER_VALIDATE_FLOAT);
    $categoryOfOperation = trim($_POST['category_of_operation'] ?? '');
    $activity = trim($_POST['activity'] ?? '');
    $flightType = trim($_POST['flight_type'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $registrationNumber = trim($_POST['registration_number'] ?? '');
    $takeOffTime = trim($_POST['take_off_time'] ?? '');
    $landingTime = trim($_POST['landing_time'] ?? '');

    // Basic validation
    if (empty($operationName) || empty($pilot) || $maxFlightHeight === false || $maxFlightHeight < 0 || empty($location) || $radius === false || $radius < 0 || empty($categoryOfOperation) || empty($activity) || empty($flightType) || empty($manufacturer) || empty($model) || empty($registrationNumber) || empty($takeOffTime) || empty($landingTime)) {
        $message = 'Please fill in all fields correctly. Numerical fields must be positive numbers.';
        $messageType = 'error';
    } else {
        try {
            if ($_POST['action'] === 'add_flight') {
                // Insert new flight
                $stmt = $pdo->prepare("INSERT INTO flights (operation_name, pilot, max_flight_height, location, radius, category_of_operation, activity, flight_type, manufacturer, model, registration_number, take_off_time, landing_time) VALUES (:operation_name, :pilot, :max_flight_height, :location, :radius, :category_of_operation, :activity, :flight_type, :manufacturer, :model, :registration_number, :take_off_time, :landing_time)");
                $stmt->execute([
                    ':operation_name' => $operationName,
                    ':pilot' => $pilot,
                    ':max_flight_height' => $maxFlightHeight,
                    ':location' => $location,
                    ':radius' => $radius,
                    ':category_of_operation' => $categoryOfOperation,
                    ':activity' => $activity,
                    ':flight_type' => $flightType,
                    ':manufacturer' => $manufacturer,
                    ':model' => $model,
                    ':registration_number' => $registrationNumber,
                    ':take_off_time' => $takeOffTime,
                    ':landing_time' => $landingTime
                ]);
                $message = 'Flight entry added successfully.';
                $messageType = 'success';
                // Redirect to prevent re-submission and show updated list
                header('Location: index.php?message=' . urlencode($message) . '&type=' . urlencode($messageType));
                exit();
            } elseif ($_POST['action'] === 'update_flight' && $flightId) {
                // Update existing flight
                $stmt = $pdo->prepare("UPDATE flights SET operation_name = :operation_name, pilot = :pilot, max_flight_height = :max_flight_height, location = :location, radius = :radius, category_of_operation = :category_of_operation, activity = :activity, flight_type = :flight_type, manufacturer = :manufacturer, model = :model, registration_number = :registration_number, take_off_time = :take_off_time, landing_time = :landing_time WHERE id = :id");
                $stmt->execute([
                    ':operation_name' => $operationName,
                    ':pilot' => $pilot,
                    ':max_flight_height' => $maxFlightHeight,
                    ':location' => $location,
                    ':radius' => $radius,
                    ':category_of_operation' => $categoryOfOperation,
                    ':activity' => $activity,
                    ':flight_type' => $flightType,
                    ':manufacturer' => $manufacturer,
                    ':model' => $model,
                    ':registration_number' => $registrationNumber,
                    ':take_off_time' => $takeOffTime,
                    ':landing_time' => $landingTime,
                    ':id' => $flightId
                ]);
                $message = 'Flight entry updated successfully.';
                $messageType = 'success';
                // Refresh the flight data after update
                $stmt = $pdo->prepare("SELECT * FROM flights WHERE id = :id");
                $stmt->execute([':id' => $flightId]);
                $flight = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') {
                $message = 'A flight entry with this Registration Number and Take off Time already exists. Please check your input or update the existing entry.';
            } else {
                $message = 'Error saving flight: ' . $e->getMessage();
            }
            $messageType = 'error';
        }
    }
}

// Handle deletion of an entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_entry') {
    $deleteEntryId = filter_var($_POST['entry_id'] ?? '', FILTER_VALIDATE_INT);

    if ($deleteEntryId === false || $deleteEntryId <= 0) {
        $message = 'Invalid entry ID for deletion.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM flights WHERE id = :id");
            $stmt->execute([':id' => $deleteEntryId]);
            if ($stmt->rowCount() > 0) {
                $message = 'Flight entry deleted successfully.';
                $messageType = 'success';
                // Redirect to index.php after successful deletion
                header('Location: index.php?message=' . urlencode($message) . '&type=' . urlencode($messageType));
                exit();
            } else {
                $message = 'Flight entry not found or could not be deleted.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting entry: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $flightId ? 'Edit Flight Entry' : 'Add New Flight'; ?> - Drone Flight Tracker</title>
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
        /* Modal styles */
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
        <h1 class="text-4xl font-bold text-center text-gray-800 mb-8">
            <?php echo $flightId ? 'Edit Flight Entry' : 'Add New Flight'; ?>
        </h1>

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

        <!-- Flight Details Form -->
        <div class="p-6 bg-gray-50 rounded-lg shadow-sm">
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <input type="hidden" name="action" value="<?php echo $flightId ? 'update_flight' : 'add_flight'; ?>">

                <!-- Operation Details -->
                <div class="lg:col-span-3">
                    <h2 class="text-2xl font-semibold text-gray-700 mb-4">Operation Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label for="operation_name" class="block text-sm font-medium text-gray-700">Operation Name</label>
                            <input type="text" id="operation_name" name="operation_name" required
                                   value="<?php echo htmlspecialchars($flight['operation_name'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="pilot" class="block text-sm font-medium text-gray-700">Pilot</label>
                            <input type="text" id="pilot" name="pilot" required
                                   value="<?php echo htmlspecialchars($flight['pilot'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="max_flight_height" class="block text-sm font-medium text-gray-700">Max Flight Height (ft AGL)</label>
                            <input type="number" step="0.1" id="max_flight_height" name="max_flight_height" required
                                   value="<?php echo htmlspecialchars($flight['max_flight_height'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="location" class="block text-sm font-medium text-gray-700">Location</label>
                            <input type="text" id="location" name="location" required
                                   value="<?php echo htmlspecialchars($flight['location'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="radius" class="block text-sm font-medium text-gray-700">Radius (ft)</label>
                            <input type="number" step="0.1" id="radius" name="radius" required
                                   value="<?php echo htmlspecialchars($flight['radius'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="category_of_operation" class="block text-sm font-medium text-gray-700">Category of Operation</label>
                            <select id="category_of_operation" name="category_of_operation" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border bg-white">
                                <option value="">Select Category</option>
                                <option value="Basic" <?php echo (($flight['category_of_operation'] ?? '') === 'Basic') ? 'selected' : ''; ?>>Basic</option>
                                <option value="Advance" <?php echo (($flight['category_of_operation'] ?? '') === 'Advance') ? 'selected' : ''; ?>>Advance</option>
                            </select>
                        </div>
                        <div>
                            <label for="activity" class="block text-sm font-medium text-gray-700">Activity</label>
                            <input type="text" id="activity" name="activity" required
                                   value="<?php echo htmlspecialchars($flight['activity'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="flight_type" class="block text-sm font-medium text-gray-700">Flight Type</label>
                            <input type="text" id="flight_type" name="flight_type" required
                                   value="<?php echo htmlspecialchars($flight['flight_type'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                    </div>
                </div>

                <!-- Drone Details -->
                <div class="lg:col-span-3 mt-6">
                    <h2 class="text-2xl font-semibold text-gray-700 mb-4">Drone Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label for="manufacturer" class="block text-sm font-medium text-gray-700">Manufacturer</label>
                            <input type="text" id="manufacturer" name="manufacturer" required
                                   value="<?php echo htmlspecialchars($flight['manufacturer'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="model" class="block text-sm font-medium text-gray-700">Model</label>
                            <input type="text" id="model" name="model" required
                                   value="<?php echo htmlspecialchars($flight['model'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="registration_number" class="block text-sm font-medium text-gray-700">Registration Number/SFOC-RPAS-Number</label>
                            <input type="text" id="registration_number" name="registration_number" required
                                   value="<?php echo htmlspecialchars($flight['registration_number'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                    </div>
                </div>

                <!-- Flight Details -->
                <div class="lg:col-span-3 mt-6">
                    <h2 class="text-2xl font-semibold text-gray-700 mb-4">Flight Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="take_off_time" class="block text-sm font-medium text-gray-700">Take off Time</label>
                            <input type="datetime-local" id="take_off_time" name="take_off_time" required
                                   value="<?php echo htmlspecialchars(str_replace(' ', 'T', $flight['take_off_time'] ?? '')); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                        <div>
                            <label for="landing_time" class="block text-sm font-medium text-gray-700">Landing Time</label>
                            <input type="datetime-local" id="landing_time" name="landing_time" required
                                   value="<?php echo htmlspecialchars(str_replace(' ', 'T', $flight['landing_time'] ?? '')); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-3 flex justify-end space-x-4 mt-6">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        <?php echo $flightId ? 'Update Flight' : 'Add Flight'; ?>
                    </button>
                    <?php if ($flightId): ?>
                        <button type="button" onclick="showDeleteModal(<?php echo $flight['id']; ?>, 'Flight for Drone <?php echo htmlspecialchars($flight['registration_number'] . ' on ' . $flight['take_off_time']); ?>')"
                                class="py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            Delete Flight
                        </button>
                    <?php endif; ?>
                </div>
            </form>
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
                    <input type="hidden" name="action" value="delete_entry">
                    <input type="hidden" name="entry_id" id="confirmDeleteEntryId">
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

        // Pre-fill current datetime for new entries
        document.addEventListener('DOMContentLoaded', function() {
            const takeOffTimeInput = document.getElementById('take_off_time');
            const landingTimeInput = document.getElementById('landing_time');

            // Only pre-fill if it's a new entry (no existing value)
            if (!takeOffTimeInput.value) {
                const now = new Date();
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;

                takeOffTimeInput.value = currentDateTime;
                landingTimeInput.value = currentDateTime; // Default landing to same as takeoff
            }
        });
    </script>
</body>
</html>