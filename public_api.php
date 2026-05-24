<?php
require_once __DIR__ . '/bootstrap.php';

af_security_headers('json');
af_start_secure_session();

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?: [];
$action = trim((string) ($_POST['action'] ?? $json_input['action'] ?? $_GET['action'] ?? ''));
if ($action === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $action = 'getPublicFoundItems';
}

$allowed_actions = ['getPublicFoundItems', 'getAirlines', 'reportLost', 'claimItem'];
if (!in_array($action, $allowed_actions, true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $json_input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
}

define('AF_INTERNAL_API_CALL', true);
require __DIR__ . '/api.php';
