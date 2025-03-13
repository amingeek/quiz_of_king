<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class QuizWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $redis;
    protected $db;
    protected $jwtSecret;
    protected $userConnections;

    public function __construct() {
        $this->jwtSecret = JWT_SECRET;
        $this->clients = new \SplObjectStorage;
        $this->userConnections = new \SplObjectStorage;

        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);

        $this->db = new SQLite3(__DIR__ . '/quiz.db');
        $this->initializeDatabase();
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->userConnections->attach($conn, null);
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $data = json_decode($msg, true);

            if (!isset($data['token'])) {
                throw new RuntimeException('Token is required');
            }

            $decoded = $this->validateToken($data['token']);
            $userId = $decoded['sub'] ?? null;

            if ($userId && $this->userConnections[$from] === null) {
                $this->userConnections[$from] = $userId;
                $this->redis->hset('connections', $from->resourceId, $userId);
            }

            switch ($data['action']) {
                case 'auth':
                    $from->send(json_encode(['type' => 'status', 'message' => 'احراز هویت موفق']));
                    break;

                case 'join_queue':
                    $this->redis->lpush('queue', $from->resourceId);
                    $from->send(json_encode(['type' => 'status', 'message' => 'در حال پیدا کردن حریف...']));
                    $this->checkQueue();
                    break;

                case 'answer_question':
                    $this->handleAnswer($from, $data, $userId);
                    break;

                case 'send_message':
                    $this->handleSendMessage($from, $data);
                    break;

                default:
                    throw new Exception('عملیات نامعتبر');
            }
        } catch (Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage()
            ]));
        }
    }

    private function handleSendMessage($from, $data) {
        if (!isset($data['to_user_id']) || !isset($data['message'])) {
            throw new Exception('داده‌های ناقص برای ارسال پیام');
        }

        $toUserId = $data['to_user_id'];
        $message = $data['message'];

        $toConn = null;
        foreach ($this->userConnections as $conn) {
            if ($this->userConnections[$conn] === $toUserId) {
                $toConn = $conn;
                break;
            }
        }

        if ($toConn) {
            $toConn->send(json_encode([
                'type' => 'chat_message',
                'from_user_id' => $this->userConnections[$from],
                'message' => $message
            ]));
            $from->send(json_encode([
                'type' => 'status',
                'message' => 'پیام ارسال شد'
            ]));
        } else {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'کاربر مقصد آنلاین نیست'
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        $this->userConnections->detach($conn);
        $this->redis->lrem('queue', 0, $conn->resourceId);
        echo "Connection closed: {$conn->resourceId}\n";
    }


    private function initializeDatabase() {
        // Create Users Table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                score INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create Questions Table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS questions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                question TEXT NOT NULL,
                option1 TEXT NOT NULL,
                option2 TEXT NOT NULL,
                option3 TEXT NOT NULL,
                option4 TEXT NOT NULL,
                correct_answer TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Insert Sample Question
        if ($this->db->querySingle("SELECT COUNT(*) FROM questions") == 0) {
            $this->db->exec("
                INSERT INTO questions 
                (question, option1, option2, option3, option4, correct_answer)
                VALUES (
                    'پایتخت فرانسه کدام است؟',
                    'پاریس',
                    'لندن',
                    'برلین',
                    'مادرید',
                    'option1'
                )
            ");
        }
    }

    private function checkQueue() {
        while ($this->redis->llen('queue') >= 2) {
            $player1 = $this->redis->lpop('queue');
            $player2 = $this->redis->lpop('queue');
            $this->startGame($player1, $player2);
        }
    }

    private function startGame($p1Id, $p2Id) {
        $gameId = uniqid('game_');
        $question = $this->getRandomQuestion();

        // Store Game Data
        $this->redis->hmset("game:$gameId", [
            'player1' => $p1Id,
            'player2' => $p2Id,
            'question' => json_encode($question),
            'answers' => json_encode([])
        ]);

        // Notify Players
        foreach ([$p1Id, $p2Id] as $playerId) {
            if ($conn = $this->findConnectionById($playerId)) {
                $conn->send(json_encode([
                    'type' => 'game_start',
                    'game_id' => $gameId,
                    'question' => $question
                ]));
            }
        }
    }

    private function handleAnswer($from, $data, $userId) {
        if (!isset($data['game_id']) || !isset($data['answer'])) {
            throw new Exception('داده‌های ناقص');
        }

        $gameKey = "game:{$data['game_id']}";
        $gameData = $this->redis->hgetall($gameKey);

        if (empty($gameData)) {
            throw new Exception('بازی یافت نشد');
        }

        // ذخیره پاسخ با شناسه کاربر
        $this->redis->hset($gameKey, "answer:{$userId}", $data['answer']);

        // بررسی آیا هر دو بازیکن پاسخ دادند
        $answersCount = $this->redis->hlen($gameKey) - 3; // 3 فیلد پایه: player1, player2, question
        if ($answersCount === 2) {
            $this->endGame($gameKey, $gameData);
        }
    }

    private function endGame($gameKey, $gameData) {
        $question = json_decode($gameData['question'], true);
        $player1 = $gameData['player1'];
        $player2 = $gameData['player2'];

        // دریافت پاسخ‌ها
        $answer1 = $gameData["answer:{$player1}"] ?? null;
        $answer2 = $gameData["answer:{$player2}"] ?? null;

        // تعیین برنده
        $winner = null;
        if ($answer1 === $question['correct_answer']) $winner = $player1;
        if ($answer2 === $question['correct_answer']) $winner = $player2;

        // آپدیت امتیاز در دیتابیس
        if ($winner) {
            $userId = $this->redis->hget('user_map', $winner);
            $stmt = $this->db->prepare("UPDATE users SET score = score + 20 WHERE id = :id");
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // ارسال نتایج به بازیکنان
        foreach ([$player1, $player2] as $playerId) {
            if ($conn = $this->findConnectionById($playerId)) {
                $conn->send(json_encode([
                    'type' => 'game_result',
                    'winner_id' => $winner ? $this->redis->hget('user_map', $winner) : null,
                    'message' => $winner ? "🎉 برنده شدید!" : "مساوی!"
                ]));
            }
        }

        $this->redis->del($gameKey);
    }

    private function validateToken(string $token): array {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            throw new RuntimeException('Invalid token: ' . $e->getMessage());
        }
    }    private function getRandomQuestion() {
        $stmt = $this->db->prepare("SELECT * FROM questions ORDER BY RANDOM() LIMIT 1");
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result ?: [
            'question' => 'سوال نمونه',
            'option1' => 'گزینه ۱',
            'option2' => 'گزینه ۲',
            'option3' => 'گزینه ۳',
            'option4' => 'گزینه ۴',
            'correct_answer' => 'option1'
        ];
    }

    private function findConnectionById($resourceId) {
        foreach ($this->clients as $client) {
            if ($client->resourceId == $resourceId) {
                return $client;
            }
        }
        return null;
    }


    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

$server = IoServer::factory(
    new HttpServer(new WsServer(new QuizWebSocket())),
    8081
);
$server->run();