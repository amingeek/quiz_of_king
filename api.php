<?php
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$db = new SQLite3('quiz.db');
$jwtSecret = getenv('JWT_SECRET') ?: 'default_secret_key';

function sendJsonResponse($data) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data);
    exit;
}

function registerUser($username, $password) {
    global $db;
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (:user, :pass)");
    $stmt->bindValue(':user', $username, SQLITE3_TEXT);
    $stmt->bindValue(':pass', $hashed, SQLITE3_TEXT);
    try {
        $stmt->execute();
        return ['status' => 'success'];
    } catch (Exception $e) {
        return ['error' => 'Username already exists'];
    }
}

function loginUser($username, $password) {
    global $db, $jwtSecret;
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :user");
    $stmt->bindValue(':user', $username, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($result && password_verify($password, $result['password'])) {
        $payload = [
            'user_id' => $result['id'],
            'exp' => time() + 3600
        ];
        return ['token' => JWT::encode($payload, $jwtSecret, 'HS256')];
    }
    return ['error' => 'Invalid credentials'];
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = json_decode(file_get_contents('php://input'), true);

    switch ($request['action'] ?? '') {
        case 'register':
            if (empty($request['username']) || empty($request['password'])) {
                sendJsonResponse(['error' => 'Missing fields']);
            }
            sendJsonResponse(registerUser($request['username'], $request['password']));
            break;

        case 'login':
            if (empty($request['username']) || empty($request['password'])) {
                sendJsonResponse(['error' => 'Missing fields']);
            }
            sendJsonResponse(loginUser($request['username'], $request['password']));
            break;

        default:
            sendJsonResponse(['error' => 'Invalid action']);
    }
}