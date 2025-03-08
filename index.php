<?php

require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Predis\Client;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

$db = new SQLite3('quiz.db');
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS games (id INTEGER PRIMARY KEY, player1 INTEGER, player2 INTEGER, question_id INTEGER, player1_answer TEXT, player2_answer TEXT, status TEXT, current_stage INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE IF NOT EXISTS questions (id INTEGER PRIMARY KEY, question TEXT, option1 TEXT, option2 TEXT, option3 TEXT, option4 TEXT, correct TEXT, stage INTEGER)");
$db->exec("INSERT INTO questions (question, option1, option2, option3, option4, correct, stage) VALUES 
    ('What is the capital of France?', 'Berlin', 'Madrid', 'Paris', 'Rome', 'Paris', 1),
    ('Who wrote "Romeo and Juliet"?', 'Shakespeare', 'Dickens', 'Hemingway', 'Austen', 'Shakespeare', 2),
    ('What is the square root of 64?', '6', '7', '8', '9', '8', 3);
");

$key = "your_secret_key";
$redis = new Client();

class QuizWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $games;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->games = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        global $redis, $db;
        $data = json_decode($msg, true);

        if ($data['action'] === 'join_queue') {
            $playerId = $data['player_id'];
            $waiting = $redis->lpop('waiting_players');

            if ($waiting) {
                $gameId = createGame($waiting, $playerId);
                $this->games[$gameId] = [$waiting, $playerId];
                $question = getRandomQuestion(1); // مرحله اول

                foreach ($this->clients as $client) {
                    $client->send(json_encode([ 'action' => 'start_game', 'game_id' => $gameId, 'question' => $question ]));
                }
            } else {
                $redis->rpush('waiting_players', $playerId);
                $from->send(json_encode(['message' => 'Waiting for an opponent']));
            }
        } elseif ($data['action'] === 'answer_question') {
            $gameId = $data['game_id'];
            $playerId = $data['player_id'];
            $answer = $data['answer'];

            // ذخیره پاسخ‌ها
            $stmt = $db->prepare("UPDATE games SET player1_answer = CASE WHEN player1 = ? THEN ? ELSE player1_answer END, player2_answer = CASE WHEN player2 = ? THEN ? ELSE player2_answer END WHERE id = ?");
            $stmt->bindValue(1, $playerId);
            $stmt->bindValue(2, $answer);
            $stmt->bindValue(3, $playerId);
            $stmt->bindValue(4, $answer);
            $stmt->bindValue(5, $gameId);
            $stmt->execute();

            // بررسی اینکه آیا هر دو بازیکن پاسخ داده‌اند
            $stmt = $db->prepare("SELECT player1_answer, player2_answer, question_id, current_stage FROM games WHERE id = ?");
            $stmt->bindValue(1, $gameId);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if ($result['player1_answer'] && $result['player2_answer']) {
                $correctAnswer = getCorrectAnswer($result['question_id']);
                $scores = [
                    'player1' => $result['player1_answer'] === $correctAnswer ? 1 : 0,
                    'player2' => $result['player2_answer'] === $correctAnswer ? 1 : 0
                ];

                // بروزرسانی مرحله بازی
                $nextStage = $result['current_stage'] + 1;
                $stmt = $db->prepare("UPDATE games SET current_stage = ? WHERE id = ?");
                $stmt->bindValue(1, $nextStage);
                $stmt->bindValue(2, $gameId);
                $stmt->execute();

                // ارسال نتیجه بازی به همه بازیکنان
                foreach ($this->clients as $client) {
                    $client->send(json_encode([ 'action' => 'game_result', 'game_id' => $gameId, 'scores' => $scores, 'next_stage' => $nextStage ]));
                }

                // ارسال سوال جدید برای مرحله بعد
                $nextQuestion = getRandomQuestion($nextStage);
                foreach ($this->clients as $client) {
                    $client->send(json_encode([ 'action' => 'next_question', 'game_id' => $gameId, 'question' => $nextQuestion ]));
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
}

function createGame($player1, $player2) {
    global $db;
    $stmt = $db->prepare("INSERT INTO games (player1, player2, status, current_stage) VALUES (?, ?, 'active', 1)");
    $stmt->bindValue(1, $player1);
    $stmt->bindValue(2, $player2);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function getRandomQuestion($stage) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM questions WHERE stage = ? ORDER BY RANDOM() LIMIT 1");
    $stmt->bindValue(1, $stage);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function getCorrectAnswer($questionId) {
    global $db;
    $stmt = $db->prepare("SELECT correct FROM questions WHERE id = ?");
    $stmt->bindValue(1, $questionId);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC)['correct'];
}

header('Content-Type: application/json');
$request = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        echo json_encode(register($request['username'], $request['password']));
        break;
    case 'login':
        echo json_encode(login($request['username'], $request['password']));
        break;
    case 'start_websocket':
        $server = \Ratchet\Server\IoServer::factory(
            new \Ratchet\WebSocket\WsServer(new QuizWebSocket()),
            8080
        );
        $server->run();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}
