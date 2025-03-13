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

    // تشخیص نوع درخواست (JSON یا FormData)
    $isJsonRequest = strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;
    $data = $isJsonRequest ? json_decode(file_get_contents('php://input'), true) : $_POST;

    if ($isJsonRequest && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('داده‌های JSON نامعتبر است');
    }

    switch ($data['action'] ?? '') {
        case 'register':
            // ثبت نام از FormData استفاده می‌کند
            if (empty($_POST['username']) || empty($_POST['password']) || empty($_FILES['profile_picture'])) {
                throw new Exception('تمام فیلدها الزامی است');
            }

            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->bindValue(':username', $_POST['username']);
            $exists = $stmt->execute()->fetchArray();

            if ($exists) {
                throw new Exception('نام کاربری قبلا ثبت شده است');
            }

            $uploadDir = __DIR__ . '/uploads/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName = uniqid() . '-' . basename($_FILES['profile_picture']['name']);
            $uploadFile = $uploadDir . $fileName;

            $allowedTypes = ['image/jpeg', 'image/png'];
            if (!in_array($_FILES['profile_picture']['type'], $allowedTypes)) {
                throw new Exception('فقط فایل‌های JPEG و PNG مجاز هستند');
            }

            if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadFile)) {
                throw new Exception('خطا در آپلود فایل');
            }

            $stmt = $db->prepare("
                INSERT INTO users (username, password, profile_picture) 
                VALUES (:username, :password, :profile_picture)
            ");

            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt->bindValue(':username', $_POST['username']);
            $stmt->bindValue(':password', $hash);
            $stmt->bindValue(':profile_picture', $fileName);

            if ($stmt->execute()) {
                echo json_encode(['status' => 'success']);
            } else {
                throw new Exception('خطا در ثبت نام');
            }
            break;

        case 'login':
            // لاگین از raw JSON استفاده می‌کند
            if (empty($data['username']) || empty($data['password'])) {
                throw new Exception('نام کاربری و رمز عبور الزامی است');
            }

            $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->bindValue(':username', $data['username']);
            $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$user || !password_verify($data['password'], $user['password'])) {
                throw new Exception('نام کاربری یا رمز عبور اشتباه است');
            }

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