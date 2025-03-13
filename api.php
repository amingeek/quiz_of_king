<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    $db = new SQLite3('quiz.db');

    // Handle CORS Preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    // Get Input Data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['action'])) {
        throw new Exception('درخواست نامعتبر');
    }

    switch ($data['action']) {
        case 'register':
            if (empty($data['username']) || empty($data['password'])) {
                throw new Exception('نام کاربری و رمز عبور الزامی است');
            }

            // Check Existing User
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->bindValue(':username', $data['username']);
            $exists = $stmt->execute()->fetchArray();

            if ($exists) {
                throw new Exception('نام کاربری قبلا ثبت شده است');
            }

            // Create New User
            $stmt = $db->prepare("
                INSERT INTO users (username, password) 
                VALUES (:username, :password)
            ");

            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt->bindValue(':username', $data['username']);
            $stmt->bindValue(':password', $hash);

            if ($stmt->execute()) {
                echo json_encode(['status' => 'success']);
            } else {
                throw new Exception('خطا در ثبت نام');
            }
            break;

        case 'login':
            if (empty($data['username']) || empty($data['password'])) {
                throw new Exception('نام کاربری و رمز عبور الزامی است');
            }

            // Get User
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->bindValue(':username', $data['username']);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$user || !password_verify($data['password'], $user['password'])) {
                throw new Exception('نام کاربری یا رمز عبور اشتباه است');
            }

            // Generate JWT
            $payload = [
                'sub' => $user['id'],
                'username' => $user['username'],
                'exp' => time() + 86400 // 1 day
            ];

            $jwt = JWT::encode($payload, JWT_SECRET, 'HS256');
            echo json_encode(['token' => $jwt]);
            break;

        default:
            throw new Exception('عملیات نامعتبر');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}