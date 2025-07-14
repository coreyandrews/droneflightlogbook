<?php
// Include Dompdf autoloader at the very top
require_once 'vendor/autoload.php';

// Reference the Dompdf namespace at the global scope
use Dompdf\Dompdf;
use Dompdf\Options;

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

    // Create flights table if it doesn't exist (ensure it matches detail.php)
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

    // Create documents table if it doesn't exist (for uploads)
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

$message = $_GET['message'] ?? '';
$messageType = $_GET['type'] ?? '';

// Handle date filter submission
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$filterPilot = $_GET['filter_pilot'] ?? ''; // New filter for pilot

$whereClauses = [];
$params = [];

if (!empty($startDate)) {
    $whereClauses[] = "substr(take_off_time, 1, 10) >= :start_date"; // Extract date part
    $params[':start_date'] = $startDate;
}
if (!empty($endDate)) {
    $whereClauses[] = "substr(take_off_time, 1, 10) <= :end_date"; // Extract date part
    $params[':end_date'] = $endDate;
}
if (!empty($filterPilot)) {
    $whereClauses[] = "pilot = :filter_pilot";
    $params[':filter_pilot'] = $filterPilot;
}

$sql = "SELECT * FROM flights"; // Select all fields for export
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}
$sql .= " ORDER BY take_off_time DESC"; // Order by most recent flights

// Fetch all flight data for display and export
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$flights = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all unique pilots for the filter dropdown
$stmt = $pdo->query("SELECT DISTINCT pilot FROM flights ORDER BY pilot ASC");
$allPilots = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch latest Drone Registration and Pilot License PDFs
$droneRegistrationPdf = null;
$pilotLicensePdf = null;

$stmt = $pdo->prepare("SELECT file_path FROM documents WHERE document_type = 'drone_registration' ORDER BY upload_date DESC LIMIT 1");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $droneRegistrationPdf = $result['file_path'];
}

$stmt = $pdo->prepare("SELECT file_path FROM documents WHERE document_type = 'pilot_license' ORDER BY upload_date DESC LIMIT 1");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $pilotLicensePdf = $result['file_path'];
}


// --- Export Logic ---
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="drone_flights.csv"');

    $output = fopen('php://output', 'w');

    // Get column names for CSV header
    if (!empty($flights)) {
        fputcsv($output, array_keys($flights[0]));
    }

    // Output data rows
    foreach ($flights as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'export_pdf') {
    // Configure Dompdf options
    $options = new Options();
    $options->set('defaultFont', 'Inter'); // Use Inter font
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true); // Enable remote URLs if you link external assets (not used here, but good practice)

    // Instantiate Dompdf with options
    $dompdf = new Dompdf($options);

    // Build HTML content for the PDF
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Drone Flight Logbook Export</title>
        <style>
            body { font-family: "Inter", sans-serif; margin: 20px; }
            h1 { text-align: center; color: #333; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 10px; }
            th { background-color: #f2f2f2; color: #555; }
            .header { background-color: #e0e0e0; font-weight: bold; }
        </style>
    </head>
    <body>
        <h1>Drone Flight Logbook</h1>';

    if (empty($flights)) {
        $html .= '<p>No flight entries to export.</p>';
    } else {
        $html .= '<table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Operation Name</th>
                    <th>Pilot</th>
                    <th>Max Height (ft AGL)</th>
                    <th>Location</th>
                    <th>Radius (ft)</th>
                    <th>Category</th>
                    <th>Activity</th>
                    <th>Flight Type</th>
                    <th>Manufacturer</th>
                    <th>Model</th>
                    <th>Registration No.</th>
                    <th>Take off Time</th>
                    <th>Landing Time</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($flights as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['id']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['operation_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['pilot']) . '</td>';
            $html .= '<td>' . htmlspecialchars(number_format($row['max_flight_height'], 1)) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['location']) . '</td>';
            $html .= '<td>' . htmlspecialchars(number_format($row['radius'], 1)) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['category_of_operation']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['activity']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['flight_type']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['manufacturer']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['model']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['registration_number']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['take_off_time']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['landing_time']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '</body></html>';

    // Load HTML to Dompdf
    $dompdf->loadHtml($html);

    // (Optional) Set paper size and orientation
    $dompdf->setPaper('A4', 'landscape'); // Landscape is better for more columns

    // Render the HTML as PDF
    $dompdf->render();

    // Output the generated PDF to Browser
    $dompdf->stream("drone_flight_logbook.pdf", array("Attachment" => true));
    exit();
}
// --- End Export Logic ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drone Flight Logbook</title>
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
    </style>
</head>
<body class="bg-gray-100 p-4">
    <div class="container mx-auto bg-white shadow-lg rounded-xl p-8 my-8">
        <h1 class="text-4xl font-bold text-center text-gray-800 mb-8">Drone Flight Logbook</h1>

        <?php if ($message): ?>
            <div class="rounded-lg p-4 mb-6
                <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Action Buttons: Add New Flight, Upload Documents, Export CSV, Export PDF -->
        <div class="mb-8 flex flex-wrap justify-end gap-4">
            <a href="detail.php"
               class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150 ease-in-out">
                Add New Flight
            </a>
            <a href="upload.php"
               class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition duration-150 ease-in-out">
                Upload Documents
            </a>
            <a href="index.php?action=export_csv<?php echo http_build_query($_GET); ?>"
               class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150 ease-in-out">
                Export to CSV
            </a>
            <a href="index.php?action=export_pdf<?php echo http_build_query($_GET); ?>"
               class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-150 ease-in-out">
                Export to PDF
            </a>
        </div>

        <!-- Quick Access to Documents -->
        <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Quick Document Access</h2>
            <div class="flex flex-wrap gap-4">
                <?php if ($droneRegistrationPdf): ?>
                    <a href="<?php echo htmlspecialchars($droneRegistrationPdf); ?>" target="_blank"
                       class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition duration-150 ease-in-out">
                        View Drone Registration PDF
                    </a>
                <?php else: ?>
                    <span class="text-gray-600 py-2 px-6 border border-gray-300 rounded-md">No Drone Registration PDF uploaded.</span>
                <?php endif; ?>

                <?php if ($pilotLicensePdf): ?>
                    <a href="<?php echo htmlspecialchars($pilotLicensePdf); ?>" target="_blank"
                       class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition duration-150 ease-in-out">
                        View Pilot License PDF
                    </a>
                <?php else: ?>
                    <span class="text-gray-600 py-2 px-6 border border-gray-300 rounded-md">No Pilot License PDF uploaded.</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Options -->
        <div class="mb-8 p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Filter Logbook</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label for="filter_pilot" class="block text-sm font-medium text-gray-700">Filter by Pilot</label>
                    <select id="filter_pilot" name="filter_pilot"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border bg-white">
                        <option value="">All Pilots</option>
                        <?php foreach ($allPilots as $pilotName): ?>
                            <option value="<?php echo htmlspecialchars($pilotName); ?>"
                                <?php echo ($filterPilot === $pilotName) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pilotName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($startDate); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" id="end_date" name="end_date"
                           value="<?php echo htmlspecialchars($endDate); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2 border">
                </div>
                <div class="flex space-x-2">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150 ease-in-out flex-grow">
                        Apply Filter
                    </button>
                    <a href="index.php"
                       class="inline-flex justify-center py-2 px-6 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        Clear Filter
                    </a>
                </div>
            </form>
        </div>

        <!-- Flight History Table -->
        <div class="p-6 bg-gray-50 rounded-lg shadow-sm">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4">Flight History</h2>
            <?php if (empty($flights)): ?>
                <p class="text-gray-600">No flight entries yet or no entries match the current filter. Add some using the "Add New Flight" button!</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">
                                    Pilot
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Location
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Activity
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Take off Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Landing Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($flights as $entry): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($entry['pilot']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($entry['location']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($entry['activity']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars(substr($entry['take_off_time'], 0, 10)); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars(substr($entry['landing_time'], 0, 10)); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="detail.php?id=<?php echo $entry['id']; ?>"
                                           class="text-indigo-600 hover:text-indigo-900 transition duration-150 ease-in-out">
                                            More Info
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
