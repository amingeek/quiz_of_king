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

$redis = new Client(['host' => '127.0.0.1', 'port' => 6379]);
$jwtSecret = getenv('JWT_SECRET') ?: 'default_secret_key';

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
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        global $redis, $db, $jwtSecret;
        $data = json_decode($msg, true);

        $authRequiredActions = ['join_queue', 'answer_question'];
        if (in_array($data['action'], $authRequiredActions)) {
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
                $this->handleAnswerQuestion($playerId, $data['game_id'], $data['answer']);
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
            if ($question) {
                $this->sendToPlayers(
                    [$waiting, $playerId],
                    ['action' => 'start_game', 'game_id' => $gameId, 'question' => $question]
                );
            } else {
                $this->sendToPlayers(
                    [$waiting, $playerId],
                    ['action' => 'game_error', 'message' => 'No questions available for stage 1']
                );
            }
        } else {
            $redis->rpush('waiting_players', $playerId);
            $connection->send(json_encode(['status' => 'waiting']));
        }
    }

    private function handleAnswerQuestion($playerId, $gameId, $answer) {
        global $db;

        $stmt = $db->prepare("SELECT * FROM games WHERE id = :gameId");
        $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
        $game = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$game || $game['status'] !== 'active') {
            $this->sendToPlayer($playerId, ['error' => 'Game not active']);
            return;
        }

        $isPlayer1 = $game['player1'] == $playerId;
        $answerColumn = $isPlayer1 ? 'player1_answer' : 'player2_answer';

        $stmt = $db->prepare("UPDATE games SET $answerColumn = :ans WHERE id = :gameId");
        $stmt->bindValue(':ans', $answer, SQLITE3_TEXT);
        $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
        $stmt->execute();

        $stmt = $db->prepare("SELECT player1_answer, player2_answer FROM games WHERE id = :gameId");
        $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
        $answers = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($answers['player1_answer'] && $answers['player2_answer']) {
            $correct = $this->getCorrectAnswer($game['question_id']);
            $scores = [
                $game['player1'] => ($answers['player1_answer'] === $correct) ? 1 : 0,
                $game['player2'] => ($answers['player2_answer'] === $correct) ? 1 : 0
            ];

            $nextStage = $game['current_stage'] + 1;

            $stmt = $db->prepare("UPDATE games SET current_stage = :stage WHERE id = :gameId");
            $stmt->bindValue(':stage', $nextStage, SQLITE3_INTEGER);
            $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
            $stmt->execute();

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
                    $stmt = $db->prepare("UPDATE games SET question_id = :qid WHERE id = :gameId");
                    $stmt->bindValue(':qid', $question['id'], SQLITE3_INTEGER);
                    $stmt->bindValue(':gameId', $gameId, SQLITE3_INTEGER);
                    $stmt->execute();

                    $this->sendToPlayers(
                        [$game['player1'], $game['player2']],
                        ['action' => 'next_question', 'question' => $question]
                    );
                } else {
                    $this->sendToPlayers(
                        [$game['player1'], $game['player2']],
                        ['action' => 'game_over', 'message' => 'No more questions for next stage']
                    );
                    $db->exec("UPDATE games SET status = 'finished' WHERE id = $gameId");
                }
            }
        }
    }

    private function createGame($player1, $player2) {
        global $db;
        $question = $this->getRandomQuestion(1);
        $stmt = $db->prepare("INSERT INTO games (player1, player2, question_id) VALUES (:p1, :p2, :qid)");
        $stmt->bindValue(':p1', $player1, SQLITE3_INTEGER);
        $stmt->bindValue(':p2', $player2, SQLITE3_INTEGER);
        $stmt->bindValue(':qid', $question ? $question['id'] : null, SQLITE3_INTEGER);
        $stmt->execute();
        return $db->lastInsertRowID();
    }

    private function getRandomQuestion($stage) {
        global $db;
        $stmt = $db->prepare("SELECT * FROM questions WHERE stage = :stage ORDER BY RANDOM() LIMIT 1");
        $stmt->bindValue(':stage', $stage, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result ?: null;
    }

    private function getCorrectAnswer($questionId) {
        global $db;
        $stmt = $db->prepare("SELECT correct FROM questions WHERE id = :qid");
        $stmt->bindValue(':qid', $questionId, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC)['correct'];
    }

    private function sendToPlayers($playerIds, $message) {
        foreach ($playerIds as $playerId) {
            if (isset($this->playerConnections[$playerId])) {
                $this->playerConnections[$playerId]->send(json_encode($message));
            }
        }
    }

    private function sendToPlayer($playerId, $message) {
        if (isset($this->playerConnections[$playerId])) {
            $this->playerConnections[$playerId]->send(json_encode($message));
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
        global $redis, $db;
        $redis->lrem('waiting_players', 0, $playerId);

        $stmt = $db->prepare("SELECT * FROM games WHERE (player1 = :pid OR player2 = :pid) AND status = 'active'");
        $stmt->bindValue(':pid', $playerId, SQLITE3_INTEGER);
        $game = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($game) {
            $otherPlayer = ($game['player1'] == $playerId) ? $game['player2'] : $game['player1'];
            $this->sendToPlayer($otherPlayer, ['action' => 'game_over', 'message' => 'Opponent disconnected']);
            $db->exec("UPDATE games SET status = 'finished' WHERE id = {$game['id']}");
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

// اجرای سرور WebSocket
$server = Ratchet\Server\IoServer::factory(
    new Ratchet\WebSocket\WsServer(new QuizWebSocket()),
    8081
);
$server->run();