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
                    $this->redis->hset('user_map', $from->resourceId, $userId);
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
        if (!isset($data['game_id']) || !isset($data['message'])) {
            throw new Exception('داده‌های ناقص برای ارسال پیام');
        }

        $gameId = $data['game_id'];
        $message = htmlspecialchars($data['message'], ENT_QUOTES, 'UTF-8');

        $gameKey = "game:{$gameId}";
        $gameData = $this->redis->hgetall($gameKey);

        if (!$gameData) {
            throw new Exception('بازی یافت نشد');
        }

        $player1Id = $gameData['player1'];
        $player2Id = $gameData['player2'];
        $fromUserId = $this->userConnections[$from];

        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->bindValue(':id', $fromUserId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $fromUsername = $row['username'] ?? 'Unknown';

        foreach ([$player1Id, $player2Id] as $playerId) {
            if ($conn = $this->findConnectionById($playerId)) {
                $conn->send(json_encode([
                    'type' => 'chat_message',
                    'from_username' => $fromUsername,
                    'message' => $message
                ]));
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        $this->userConnections->detach($conn);
        $this->redis->lrem('queue', 0, $conn->resourceId);
        $this->redis->hdel('connections', $conn->resourceId);
        echo "Connection closed: {$conn->resourceId}\n";
    }

    private function initializeDatabase() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                profile_picture TEXT,
                score INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

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

            $player1Id = $this->redis->hget('user_map', $player1);
            $player2Id = $this->redis->hget('user_map', $player2);
            $player1Info = $this->getUserInfo($player1Id);
            $player2Info = $this->getUserInfo($player2Id);

            foreach ([$player1, $player2] as $playerId) {
                if ($conn = $this->findConnectionById($playerId)) {
                    $conn->send(json_encode([
                        'type' => 'players_matched',
                        'players' => [
                            'player1' => $player1Info,
                            'player2' => $player2Info
                        ]
                    ]));
                }
            }

            $this->startGame($player1, $player2);
        }
    }

    private function startGame($p1Id, $p2Id) {
        $gameId = uniqid('game_');
        $question = $this->getRandomQuestion();

        $this->redis->hmset("game:$gameId", [
            'player1' => $p1Id,
            'player2' => $p2Id,
            'question' => json_encode($question),
            'answers' => json_encode([]),
            'scores' => json_encode([$p1Id => 0, $p2Id => 0]), // امتیازات هر بازیکن
            'round' => 1 // شماره مرحله
        ]);

        foreach ([$p1Id, $p2Id] as $playerId) {
            if ($conn = $this->findConnectionById($playerId)) {
                $conn->send(json_encode([
                    'type' => 'game_start',
                    'game_id' => $gameId,
                    'question' => $question,
                    'round' => 1 // مرحله اول
                ]));
            }
        }
    }

    private function handleAnswer($from, $data, $userId) {
        if (!isset($data['game_id']) || !isset($data['answer'])) {
            throw new Exception('داده‌های ناقص');
        }

        $gameKey = "game:{$data['game_id']}";
        $this->redis->hset($gameKey, "answer:{$userId}", $data['answer']);
        $answers = $this->redis->hgetall($gameKey);

        $answerCount = count(array_filter(array_keys($answers), function($key) {
            return strpos($key, 'answer:') === 0;
        }));

        if ($answerCount === 2) {
            $this->endRound($gameKey, $answers);
        }
    }

    private function endRound($gameKey, $gameData) {
        $question = json_decode($gameData['question'], true);
        $player1Id = $this->redis->hget('user_map', $gameData['player1']);
        $player2Id = $this->redis->hget('user_map', $gameData['player2']);

        $answer1 = $gameData["answer:{$player1Id}"] ?? null;
        $answer2 = $gameData["answer:{$player2Id}"] ?? null;

        $correctAnswer = $question['correct_answer'];

        // بررسی پاسخ‌ها
        $p1Correct = ($answer1 === $correctAnswer);
        $p2Correct = ($answer2 === $correctAnswer);

        // به‌روزرسانی امتیازات
        $scores = json_decode($gameData['scores'], true);
        if ($p1Correct) $scores[$player1Id]++;
        if ($p2Correct) $scores[$player2Id]++;
        $this->redis->hset($gameKey, 'scores', json_encode($scores));

        // ارسال نتیجه مرحله
        foreach ([$gameData['player1'], $gameData['player2']] as $connId) {
            if ($conn = $this->findConnectionById($connId)) {
                $conn->send(json_encode([
                    'type' => 'round_result',
                    'round' => $gameData['round'],
                    'scores' => $scores,
                    'message' => $p1Correct ? 'شما پاسخ صحیح دادید!' : 'شما پاسخ اشتباه دادید!'
                ]));
            }
        }

        // بررسی پایان بازی
        if ($gameData['round'] >= 5) {
            $this->endGame($gameKey, $gameData);
        } else {
            // شروع مرحله بعدی
            $this->redis->hincrby($gameKey, 'round', 1);
            $newQuestion = $this->getRandomQuestion();
            $this->redis->hset($gameKey, 'question', json_encode($newQuestion));
            $this->redis->hset($gameKey, 'answers', json_encode([]));

            foreach ([$gameData['player1'], $gameData['player2']] as $connId) {
                if ($conn = $this->findConnectionById($connId)) {
                    $conn->send(json_encode([
                        'type' => 'next_round',
                        'round' => $gameData['round'] + 1,
                        'question' => $newQuestion
                    ]));
                }
            }
        }
    }

    private function endGame($gameKey, $gameData) {
        $scores = json_decode($gameData['scores'], true);
        $player1Id = $this->redis->hget('user_map', $gameData['player1']);
        $player2Id = $this->redis->hget('user_map', $gameData['player2']);

        $p1Score = $scores[$player1Id] ?? 0;
        $p2Score = $scores[$player2Id] ?? 0;

        $resultMessage = '';
        if ($p1Score > $p2Score) {
            $resultMessage = "{$this->getUserInfo($player1Id)['username']} برنده شد! 🎉";
            $this->updateScore($player1Id, 20);
        } elseif ($p2Score > $p1Score) {
            $resultMessage = "{$this->getUserInfo($player2Id)['username']} برنده شد! 🎉";
            $this->updateScore($player2Id, 20);
        } else {
            $resultMessage = "مساوی! 🤷";
            $this->updateScore($player1Id, 10);
            $this->updateScore($player2Id, 10);
        }

        // ارسال نتیجه نهایی
        foreach ([$gameData['player1'], $gameData['player2']] as $connId) {
            if ($conn = $this->findConnectionById($connId)) {
                $conn->send(json_encode([
                    'type' => 'game_result',
                    'message' => $resultMessage,
                    'scores' => $scores
                ]));
            }
        }

        $this->redis->del($gameKey);
    }

    private function updateScore($userId, $score) {
        $stmt = $this->db->prepare("UPDATE users SET score = score + :score WHERE id = :id");
        $stmt->bindValue(':score', $score, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    private function validateToken(string $token): array {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            throw new RuntimeException('Invalid token: ' . $e->getMessage());
        }
    }

    private function getRandomQuestion() {
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

    private function getUserInfo($userId) {
        $stmt = $this->db->prepare("SELECT username, profile_picture FROM users WHERE id = :id");
        $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result ?: ['username' => 'Unknown', 'profile_picture' => 'default.jpg'];
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