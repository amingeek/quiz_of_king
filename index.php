<?php
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Predis\Client;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

$db = new SQLite3('quiz.db');
$db->exec("PRAGMA foreign_keys = ON");
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT UNIQUE,
    password TEXT
)");
$db->exec("CREATE TABLE IF NOT EXISTS games (
    id INTEGER PRIMARY KEY,
    player1 INTEGER,
    player2 INTEGER,
    question_id INTEGER,
    player1_answer TEXT,
    player2_answer TEXT,
    status TEXT DEFAULT 'active',
    current_stage INTEGER DEFAULT 1,
    FOREIGN KEY(player1) REFERENCES users(id),
    FOREIGN KEY(player2) REFERENCES users(id)
)");
$db->exec("CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY,
    question TEXT,
    option1 TEXT,
    option2 TEXT,
    option3 TEXT,
    option4 TEXT,
    correct TEXT,
    stage INTEGER
)");

$redis = new Client();
$jwtSecret = getenv('JWT_SECRET') ?: 'default_secret_key';

function sendJsonResponse($data) {
    header('Content-Type: application/json');
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

class QuizWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $playerConnections;
    protected $games;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->playerConnections = [];
        $this->games = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        global $redis, $db, $jwtSecret;
        $data = json_decode($msg, true);

        // احراز هویت
        if (in_array($data['action'], ['join_queue', 'answer_question'])) {
            try {
                $decoded = JWT::decode($data['token'], new Key($jwtSecret, 'HS256'));
                $playerId = $decoded->user_id;
                $this->playerConnections[$playerId] = $from;
            } catch (Exception $e) {
                $from->send(json_encode(['error' => 'Authentication failed']));
                $from->close();
                return;
            }
        }

        switch ($data['action']) {
            case 'join_queue':
                $this->handleJoinQueue($playerId, $from);
                break;

            case 'answer_question':
                $this->handleAnswerQuestion(
                    $playerId,
                    $data['game_id'],
                    $data['answer']
                );
                break;
        }
    }

    private function handleJoinQueue($playerId, $connection) {
        global $redis;
        $waiting = $redis->lpop('waiting_players');

        if ($waiting) {
            $gameId = $this->createGame($waiting, $playerId);
            $this->games[$gameId] = [
                'players' => [$waiting, $playerId],
                'stage' => 1
            ];

            $question = $this->getRandomQuestion(1);
            $this->sendToPlayers(
                [$waiting, $playerId],
                ['action' => 'start_game', 'game_id' => $gameId, 'question' => $question]
            );
        } else {
            $redis->rpush('waiting_players', $playerId);
            $connection->send(json_encode(['status' => 'waiting']));
        }
    }

    private function handleAnswerQuestion($playerId, $gameId, $answer) {
        global $db;

        $stmt = $db->prepare("SELECT status FROM games WHERE id = :gameId");
        $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
        $gameStatus = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['status'];

        if ($gameStatus !== 'active') {
            $this->sendToPlayer($playerId, ['error' => 'Game ended']);
            return;
        }

        $stmt = $db->prepare("UPDATE games SET
            player1_answer = CASE WHEN player1 = :pid THEN :ans ELSE player1_answer END,
            player2_answer = CASE WHEN player2 = :pid THEN :ans ELSE player2_answer END
            WHERE id = :gameId");
        $stmt->bindValue(':pid', $playerId, SQLITE3_INTEGER);
        $stmt->bindValue(':ans', $answer, SQLITE3_TEXT);
        $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
        $stmt->execute();

        $stmt = $db->prepare("SELECT * FROM games WHERE id = :gameId");
        $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
        $game = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($game['player1_answer'] && $game['player2_answer']) {
            $correct = $this->getCorrectAnswer($game['question_id']);
            $scores = [
                $game['player1'] => ($game['player1_answer'] === $correct) ? 1 : 0,
                $game['player2'] => ($game['player2_answer'] === $correct) ? 1 : 0
            ];

            $nextStage = $game['current_stage'] + 1;

            // به‌روزرسانی مرحله
            $stmt = $db->prepare("UPDATE games SET current_stage = :stage WHERE id = :gameId");
            $stmt->bindValue(':stage', $nextStage, SQLITE3_INTEGER);
            $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
            $stmt->execute();

            // ارسال نتیجه
            $this->sendToPlayers(
                [$game['player1'], $game['player2']],
                ['action' => 'game_result', 'scores' => $scores, 'next_stage' => $nextStage]
            );

            if ($nextStage > 3) {
                $this->sendToPlayers(
                    [$game['player1'], $game['player2']],
                    ['action' => 'game_over', 'final_scores' => $scores]
                );
                $db->exec("UPDATE games SET status = 'finished' WHERE id = $gameId");
            } else {
                $question = $this->getRandomQuestion($nextStage);
                if ($question) {
                    $this->sendToPlayers(
                        [$game['player1'], $game['player2']],
                        ['action' => 'next_question', 'question' => $question]
                    );
                } else {
                    $this->sendToPlayers(
                        [$game['player1'], $game['player2']],
                        ['action' => 'game_over', 'error' => 'No more questions']
                    );
                }
            }
        }
    }


    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        foreach ($this->playerConnections as $playerId => $connection) {
            if ($connection === $conn) {
                unset($this->playerConnections[$playerId]);
                $this->handleDisconnectedPlayer($playerId);
                break;
            }
        }
    }

    private function handleDisconnectedPlayer($playerId) {
        global $redis;
        $redis->lrem('waiting_players', 0, $playerId);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
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

$server = Ratchet\Server\IoServer::factory(
    new Ratchet\WebSocket\WsServer(new QuizWebSocket()),
    8081
);
$server->run();