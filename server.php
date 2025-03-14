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
                throw new RuntimeException('توکن الزامی است');
            }

            $decoded = $this->validateToken($data['token']);
            $userId = $decoded['sub'] ?? null;

            if ($userId && $this->userConnections[$from] === null) {
                $this->userConnections[$from] = $userId;
                $this->redis->hset('connections', $from->resourceId, $userId);
                $this->redis->hset('user_map', $from->resourceId, $userId);
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

        $player1 = $gameData['player1'];
        $player2 = $gameData['player2'];
        $fromUserId = $this->userConnections[$from];

        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->bindValue(':id', $fromUserId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $fromUsername = $row['username'] ?? 'Unknown';

        foreach ([$player1, $player2] as $playerId) {
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
        $this->redis->hdel('user_map', $conn->resourceId);
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

            if (!$player1Id || !$player2Id) {
                throw new Exception("خطا در تطابق کاربران");
            }

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

        $user1Id = $this->redis->hget('user_map', $p1Id);
        $user2Id = $this->redis->hget('user_map', $p2Id);

        if (!$user1Id || !$user2Id) {
            throw new Exception("خطا در شناسایی کاربران");
        }

        $this->redis->hmset("game:$gameId", [
            'player1'   => $p1Id,
            'player2'   => $p2Id,
            'user1'     => $user1Id,
            'user2'     => $user2Id,
            'question'  => json_encode($question),
            'scores'    => json_encode([$user1Id => 0, $user2Id => 0]),
            'round'     => 1
        ]);

        $this->redis->expire("game:$gameId", 3600);

        foreach ([$p1Id, $p2Id] as $playerId) {
            if ($conn = $this->findConnectionById($playerId)) {
                $conn->send(json_encode([
                    'type' => 'game_start',
                    'game_id' => $gameId,
                    'question' => $question,
                    'round' => 1
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

        if (!$gameData) {
            throw new Exception('بازی یافت نشد');
        }

        if ($this->redis->hsetnx($gameKey, "answer:{$userId}", $data['answer'])) {
            $from->send(json_encode([
                'type' => 'answer_received',
                'message' => 'پاسخ شما ثبت شد. منتظر حریف...'
            ])); // پرانتز اضافی حذف شد
        }

        $user1Id = $gameData['user1'];
        $user2Id = $gameData['user2'];

        $answer1Exists = $this->redis->hexists($gameKey, "answer:{$user1Id}");
        $answer2Exists = $this->redis->hexists($gameKey, "answer:{$user2Id}");

        if ($answer1Exists && $answer2Exists) {
            $this->endRound($gameKey, $gameData);
        }
    }
    private function endRound($gameKey, $gameData) {
        $question = json_decode($gameData['question'], true);
        $player1ResourceId = $gameData['player1'];
        $player2ResourceId = $gameData['player2'];
        $user1Id = $gameData['user1'];
        $user2Id = $gameData['user2'];

        $answer1 = $this->redis->hget($gameKey, "answer:{$user1Id}");
        $answer2 = $this->redis->hget($gameKey, "answer:{$user2Id}");
        $correctAnswer = $question['correct_answer'];

        $p1Correct = ($answer1 === $correctAnswer);
        $p2Correct = ($answer2 === $correctAnswer);

        $scores = json_decode($gameData['scores'], true);
        $scores[$user1Id] = ($scores[$user1Id] ?? 0) + ($p1Correct ? 1 : 0);
        $scores[$user2Id] = ($scores[$user2Id] ?? 0) + ($p2Correct ? 1 : 0);

        $this->redis->hset($gameKey, 'scores', json_encode($scores));

        foreach ([$player1ResourceId, $player2ResourceId] as $connId) {
            if ($conn = $this->findConnectionById($connId)) {
                $currentUserId = $this->redis->hget('user_map', $connId);
                $isPlayer1 = ($currentUserId == $user1Id);

                $yourScore = $isPlayer1 ? $scores[$user1Id] : $scores[$user2Id];
                $opponentScore = $isPlayer1 ? $scores[$user2Id] : $scores[$user1Id];

                $conn->send(json_encode([
                    'type' => 'round_result',
                    'round' => (int)$gameData['round'],
                    'your_score' => $yourScore,
                    'opponent_score' => $opponentScore,
                    'message' => ($isPlayer1 ? $p1Correct : $p2Correct) ? 'پاسخ صحیح!' : 'پاسخ اشتباه!',
                    'your_answer' => $isPlayer1 ? $answer1 : $answer2,
                    'correct_answer' => $correctAnswer
                ]));
            }
        }

        $this->redis->hdel($gameKey, "answer:{$user1Id}", "answer:{$user2Id}");

        $currentRound = (int)$gameData['round'];
        if ($currentRound >= 5) {
            $this->endGame($gameKey, $gameData);
        } else {
            $newRound = $currentRound + 1;
            $this->redis->hset($gameKey, 'round', $newRound);
            $newQuestion = $this->getRandomQuestion();
            $this->redis->hset($gameKey, 'question', json_encode($newQuestion));

            foreach ([$player1ResourceId, $player2ResourceId] as $connId) {
                if ($conn = $this->findConnectionById($connId)) {
                    $conn->send(json_encode([
                        'type' => 'next_round',
                        'round' => $newRound,
                        'question' => $newQuestion,
                        'scores' => $scores
                    ]));
                }
            }
        }
    }

    private function endGame($gameKey, $gameData) {
        $scores = json_decode($gameData['scores'], true);
        $player1ResourceId = $gameData['player1'];
        $player2ResourceId = $gameData['player2'];
        $user1Id = $gameData['user1'];
        $user2Id = $gameData['user2'];

        $p1Score = $scores[$user1Id] ?? 0;
        $p2Score = $scores[$user2Id] ?? 0;

        $this->updateScore($user1Id, $p1Score);
        $this->updateScore($user2Id, $p2Score);

        foreach ([$player1ResourceId, $player2ResourceId] as $connId) {
            if ($conn = $this->findConnectionById($connId)) {
                $currentUserId = $this->redis->hget('user_map', $connId);
                $isPlayer1 = ($currentUserId == $user1Id);

                $yourScore = $isPlayer1 ? $p1Score : $p2Score;
                $opponentScore = $isPlayer1 ? $p2Score : $p1Score;

                $conn->send(json_encode([
                    'type' => 'game_result',
                    'message' => $this->getResultMessage($p1Score, $p2Score, $user1Id, $user2Id),
                    'your_score' => $yourScore,
                    'opponent_score' => $opponentScore
                ]));
            }
        }

        $this->redis->del($gameKey);
    }

    private function getResultMessage($p1Score, $p2Score, $user1Id, $user2Id) {
        $p1Info = $this->getUserInfo($user1Id);
        $p2Info = $this->getUserInfo($user2Id);

        if ($p1Score > $p2Score) {
            return "{$p1Info['username']} برنده شد! 🎉";
        } elseif ($p2Score > $p1Score) {
            return "{$p2Info['username']} برنده شد! 🎉";
        } else {
            return "بازی مساوی شد! 🤝";
        }
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
            throw new RuntimeException('توکن نامعتبر: ' . $e->getMessage());
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