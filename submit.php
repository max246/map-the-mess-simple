<?php
// Forwards a collection report straight to FixMyStreet. Nothing is stored.

require_once __DIR__ . '/fms.php';

header('Content-Type: application/json');

function fail(int $status, string $detail): void
{
    http_response_code($status);
    echo json_encode(['detail' => $detail]);
    exit;
}

/** 'Sam Taylor' → 'Sam T.', 'Sam' → 'Sam' */
function abbreviate_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) <= 1) {
        return trim($fullName);
    }
    return $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}

$lat = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
$lon = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
$description = trim((string) ($_POST['description'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);

if ($lat === false || $lat === null || $lon === false || $lon === null) {
    fail(400, 'latitude and longitude are required');
}
if ($lat < UK_LAT_MIN || $lat > UK_LAT_MAX || $lon < UK_LON_MIN || $lon > UK_LON_MAX) {
    fail(400, 'Coordinates must be within the UK');
}
if (!$email) {
    fail(400, 'A valid email is required');
}
if ($name === '') {
    fail(400, 'Name is required');
}

$imagePath = null;
$imageMime = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > MAX_UPLOAD_BYTES) {
        fail(400, 'Photo upload failed or is too large');
    }
    $imageMime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($imageMime, ALLOWED_MIME_TYPES, true)) {
        fail(400, 'Photo must be a JPEG or PNG');
    }
    $imagePath = $file['tmp_name']; // PHP deletes it at the end of the request
}

try {
    $result = fms_submit(
        $email,
        abbreviate_name($name),
        $lat,
        $lon,
        $description,
        $description,
        'Flytipping',
        $imagePath,
        $imageMime
    );
} catch (FixMyStreetError $e) {
    error_log('FixMyStreet submission failed: ' . $e->getMessage());
    fail(502, 'FixMyStreet submission failed: ' . $e->getMessage());
}

http_response_code(201);
echo json_encode($result);
