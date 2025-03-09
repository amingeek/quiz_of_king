<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Predis\Client;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class QuizWebSocket implements MessageComponentInterface
{
    private \SplObjectStorage $clients;
    private SQLite3 $db;
    private Client $redis;
    private array $queue = [];
    private string $jwtSecret;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->db = new SQLite3('quiz.db');
        $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379]);
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'default_secret_key';
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";
        $conn->send(json_encode(['type' => 'welcome', 'message' => 'Connected to Quiz WebSocket']));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        echo "Message received: $msg\n";
        $data = json_decode($msg, true);

        if (!$data || !isset($data['action'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid message format']));
            return;
        }

        switch ($data['action']) {
            case 'join_queue':
                $this->handleJoinQueue($from, $data);
                break;
            case 'answer_question':
                $this->handleAnswer($from, $data);
                break;
            default:
                $from->send(json_encode(['type' => 'error', 'message' => 'Unknown action']));
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        unset($this->queue[$conn->resourceId]);
        echo "Connection closed: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function validateToken(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (\Exception $e) {
            return null;
        }
    }

    private function handleJoinQueue(ConnectionInterface $from, array $data): void
    {
        if (!isset($data['token']) || !$this->validateToken($data['token'])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Invalid or missing token']));
            return;
        }

        $this->queue[$from->resourceId] = $from;
        $from->send(json_encode(['type' => 'status', 'message' => 'در صف بازی قرار گرفتید']));

        if (count($this->queue) >= 2) {
            $this->startGame();
        }
    }

    private function startGame(): void
    {
        $players = array_slice($this->queue, 0, 2, true);
        $gameId = uniqid('game_');
        $question = $this->getRandomQuestion();

        foreach ($players as $player) {
            $player->send(json_encode([
                'action' => 'start_game',
                'game_id' => $gameId,
                'question' => $question
            ]));
            unset($this->queue[$player->resourceId]);
        }
    }

    private function handleAnswer(ConnectionInterface $from, array $data): void
    {
        // منطق بررسی پاسخ و ادامه بازی اینجا اضافه شود
        $from->send(json_encode(['type' => 'status', 'message' => 'پاسخ دریافت شد']));
    }

    private function getRandomQuestion(): array
    {
        $result = $this->db->querySingle("SELECT * FROM questions ORDER BY RANDOM() LIMIT 1", true);
        return [
            'question' => $result['question'],
            'option1' => $result['option1'],
            'option2' => $result['option2'],
            'option3' => $result['option3'],
            'option4' => $result['option4']
        ];
    }
}

try {
    $server = \Ratchet\Server\IoServer::factory(
        new \Ratchet\WebSocket\WsServer(new QuizWebSocket()),
        8081
    );
    echo "WebSocket server started on port 8081\n";
    $server->run();
} catch (\Throwable $e) {
    echo "Server error: {$e->getMessage()}\n";
    exit(1);
}