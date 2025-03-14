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
    protected $gameScores;

    public function __construct() {
        $this->jwtSecret = JWT_SECRET;
        $this->clients = new \SplObjectStorage;
        $this->userConnections = new \SplObjectStorage;
        $this->gameScores = [];

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
                VALUES 
                ('پایتخت فرانسه کدام است؟', 'پاریس', 'لندن', 'برلین', 'مادرید', 'option1'),
                ('2 + 2 چند می‌شود؟', '3', '4', '5', '6', 'option2')
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
        $gameId = bin2hex(random_bytes(8));
        $question = $this->getRandomQuestion();

        $user1Id = (int)$this->redis->hget('user_map', $p1Id);
        $user2Id = (int)$this->redis->hget('user_map', $p2Id);

        $this->redis->hmset("game:$gameId", [
            'player1'   => $p1Id,
            'player2'   => $p2Id,
            'user1'     => $user1Id,
            'user2'     => $user2Id,
            'question'  => json_encode($question),
            'round'     => 1
        ]);
        $this->redis->expire("game:$gameId", 3600);

        $this->gameScores[$gameId] = [
            $user1Id => 0,
            $user2Id => 0
        ];

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

        // دیباگ: نمایش دقیق پاسخ دریافت‌شده
        echo "User $userId answered: " . json_encode($data['answer']) . " for game {$data['game_id']}\n";

        $this->redis->hset($gameKey, "answer:{$userId}", $data['answer']);
        $from->send(json_encode([
            'type' => 'answer_received',
            'message' => 'پاسخ شما ثبت شد. منتظر حریف...'
        ]));

        $user1Id = (int)$gameData['user1'];
        $user2Id = (int)$gameData['user2'];

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
        $user1Id = (int)$gameData['user1'];
        $user2Id = (int)$gameData['user2'];
        $gameId = str_replace('game:', '', $gameKey);

        $answer1 = $this->redis->hget($gameKey, "answer:{$user1Id}");
        $answer2 = $this->redis->hget($gameKey, "answer:{$user2Id}");
        $correctAnswer = $question['correct_answer'];

        // دیباگ دقیق
        echo "Game $gameId - Question: {$question['question']}\n";
        echo "Options: " . json_encode([
                'option1' => $question['option1'],
                'option2' => $question['option2'],
                'option3' => $question['option3'],
                'option4' => $question['option4']
            ]) . "\n";
        echo "Correct Answer: $correctAnswer\n";
        echo "User1 ($user1Id) Answer: " . json_encode($answer1) . "\n";
        echo "User2 ($user2Id) Answer: " . json_encode($answer2) . "\n";

        // تبدیل پاسخ صحیح به ایندکس (1 تا 4)
        $correctAnswerIndex = array_search($correctAnswer, ['option1', 'option2', 'option3', 'option4']) + 1;
        $options = [
            1 => $question['option1'],
            2 => $question['option2'],
            3 => $question['option3'],
            4 => $question['option4']
        ];
        $correctText = $options[$correctAnswerIndex];

        // بررسی پاسخ‌ها با انعطاف‌پذیری بیشتر
        $p1Correct = $this->isAnswerCorrect($answer1, $correctAnswerIndex, $correctText);
        $p2Correct = $this->isAnswerCorrect($answer2, $correctAnswerIndex, $correctText);

        echo "P1 Correct: " . (int)$p1Correct . ", P2 Correct: " . (int)$p2Correct . "\n";

        // به‌روزرسانی امتیازها
        if ($p1Correct) {
            $this->gameScores[$gameId][$user1Id] = ($this->gameScores[$gameId][$user1Id] ?? 0) + 1;
        }
        if ($p2Correct) {
            $this->gameScores[$gameId][$user2Id] = ($this->gameScores[$gameId][$user2Id] ?? 0) + 1;
        }

        echo "Scores - User1 ($user1Id): " . ($this->gameScores[$gameId][$user1Id] ?? 0) . ", User2 ($user2Id): " . ($this->gameScores[$gameId][$user2Id] ?? 0) . "\n";

        // ارسال نتایج به کلاینت‌ها
        foreach ([$player1ResourceId, $player2ResourceId] as $connId) {
            if ($conn = $this->findConnectionById($connId)) {
                $currentUserId = (int)$this->redis->hget('user_map', $connId);
                $isPlayer1 = ($currentUserId === $user1Id);

                $yourScore = $isPlayer1 ? ($this->gameScores[$gameId][$user1Id] ?? 0) : ($this->gameScores[$gameId][$user2Id] ?? 0);
                $opponentScore = $isPlayer1 ? ($this->gameScores[$gameId][$user2Id] ?? 0) : ($this->gameScores[$gameId][$user1Id] ?? 0);

                $conn->send(json_encode([
                    'type' => 'round_result',
                    'round' => (int)$gameData['round'],
                    'your_score' => $yourScore,
                    'opponent_score' => $opponentScore,
                    'message' => ($isPlayer1 ? $p1Correct : $p2Correct) ? 'پاسخ صحیح!' : 'پاسخ اشتباه!',
                    'your_answer' => $isPlayer1 ? $answer1 : $answer2,
                    'correct_answer' => $correctAnswerIndex
                ]));
            }
        }

        $this->redis->hdel($gameKey, "answer:{$user1Id}");
        $this->redis->hdel($gameKey, "answer:{$user2Id}");

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
                        'scores' => [
                            $user1Id => $this->gameScores[$gameId][$user1Id] ?? 0,
                            $user2Id => $this->gameScores[$gameId][$user2Id] ?? 0
                        ]
                    ]));
                }
            }
        }
    }

    private function isAnswerCorrect($answer, $correctIndex, $correctText) {
        // دیباگ: نمایش پاسخ دریافت‌شده
        echo "Checking answer: " . json_encode($answer) . " against index: $correctIndex, text: $correctText\n";

        // بررسی فرمت‌های مختلف پاسخ
        if (is_numeric($answer) && intval($answer) === $correctIndex) {
            return true;
        }
        if ($answer === "option{$correctIndex}") {
            return true;
        }
        if ($answer === $correctText) {
            return true;
        }
        return false;
    }

    private function endGame($gameKey, $gameData) {
        $player1ResourceId = $gameData['player1'];
        $player2ResourceId = $gameData['player2'];
        $user1Id = (int)$gameData['user1'];
        $user2Id = (int)$gameData['user2'];
        $gameId = str_replace('game:', '', $gameKey);

        $p1Score = $this->gameScores[$gameId][$user1Id] ?? 0;
        $p2Score = $this->gameScores[$gameId][$user2Id] ?? 0;

        echo "Final Scores - User1 ($user1Id): $p1Score, User2 ($user2Id): $p2Score\n";

        $this->updateScore($user1Id, $p1Score);
        $this->updateScore($user2Id, $p2Score);

        foreach ([$player1ResourceId, $player2ResourceId] as $connId) {
            if ($conn = $this->findConnectionById($connId)) {
                $currentUserId = (int)$this->redis->hget('user_map', $connId);
                $isPlayer1 = ($currentUserId === $user1Id);

                $yourScore = $isPlayer1 ? $p1Score : $p2Score;
                $opponentScore = $isPlayer1 ? $p2Score : $p1Score;

                $conn->send(json_encode([
                    'type' => 'game_result',
                    'message' => $this->getResultMessage($p1Score, $p2Score, $user1Id, $user2Id),
                    'your_score' => $yourScore,
                    'opponent_score' => $opponentScore,
                    'scores' => [
                        $user1Id => $p1Score,
                        $user2Id => $p2Score
                    ]
                ]));
            }
        }

        $this->redis->del($gameKey);
        unset($this->gameScores[$gameId]);
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