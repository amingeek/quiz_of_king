<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Predis\Client;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class QuizWebSocket implements MessageComponentInterface
{
    private \SplObjectStorage $clients;
    private SQLite3 $db;
    private Client $redis;
    private array $queue = [];
    private string $jwtSecret;
    private array $games = [];

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
        foreach ($this->games as $gameId => $game) {
            if (isset($game['players'][$conn->resourceId])) {
                unset($this->games[$gameId]['players'][$conn->resourceId]);
                $this->endGame($gameId, "بازیکن دیگر قطع شد");
            }
        }
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

        $this->games[$gameId] = [
            'players' => $players,
            'question' => $question,
            'answers' => [],
            'correct_answer' => $question['correct_answer']
        ];

        foreach ($players as $player) {
            $player->send(json_encode([
                'action' => 'start_game',
                'game_id' => $gameId,
                'question' => [
                    'question' => $question['question'],
                    'option1' => $question['option1'],
                    'option2' => $question['option2'],
                    'option3' => $question['option3'],
                    'option4' => $question['option4']
                ]
            ]));
            unset($this->queue[$player->resourceId]);
        }
    }

    private function handleAnswer(ConnectionInterface $from, array $data): void
    {
        $gameId = $data['game_id'] ?? null;
        $answer = $data['answer'] ?? null;

        if (!$gameId || !$answer || !isset($this->games[$gameId])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'اطلاعات نادرست']));
            return;
        }

        $game = &$this->games[$gameId];

        // جلوگیری از پاسخ چندباره
        if (isset($game['answers'][$from->resourceId])) {
            $from->send(json_encode(['type' => 'error', 'message' => 'شما قبلاً پاسخ داده‌اید']));
            return;
        }

        $game['answers'][$from->resourceId] = $answer;
        $from->send(json_encode(['type' => 'status', 'message' => 'پاسخ دریافت شد']));

        // اگر هر دو بازیکن پاسخ دادند، بازی را پایان بده
        if (count($game['answers']) === 2) {
            $this->endGame($gameId, $this->determineWinner($game));
        }
    }

    private function determineWinner(array $game): string
    {
        $players = $game['players'];
        $correctAnswer = $game['correct_answer'];
        $scores = [];

        foreach ($players as $resourceId => $player) {
            $answer = $game['answers'][$resourceId] ?? null;
            $scores[$resourceId] = ($answer === $correctAnswer) ? 1 : 0;
        }

        $playerIds = array_keys($players);
        if ($scores[$playerIds[0]] > $scores[$playerIds[1]]) {
            return "بازیکن 1 برنده شد (امتیاز: {$scores[$playerIds[0]]})";
        } elseif ($scores[$playerIds[1]] > $scores[$playerIds[0]]) {
            return "بازیکن 2 برنده شد (امتیاز: {$scores[$playerIds[1]]})";
        } else {
            return "بازی مساوی شد (امتیاز هر دو: {$scores[$playerIds[0]]})";
        }
    }

    private function endGame(string $gameId, string $message): void
    {
        if (!isset($this->games[$gameId])) return;

        $game = $this->games[$gameId];
        foreach ($game['players'] as $player) {
            $player->send(json_encode([
                'action' => 'game_over',
                'message' => $message
            ]));
        }
        unset($this->games[$gameId]);
    }

    private function getRandomQuestion(): array
    {
        $result = $this->db->querySingle("SELECT * FROM questions ORDER BY RANDOM() LIMIT 1", true);
        if (!$result) {
            return [
                'question' => 'سوال نمونه',
                'option1' => 'گزینه 1',
                'option2' => 'گزینه 2',
                'option3' => 'گزینه 3',
                'option4' => 'گزینه 4',
                'correct_answer' => 'گزینه 1'
            ];
        }
        return [
            'question' => $result['question'],
            'option1' => $result['option1'],
            'option2' => $result['option2'],
            'option3' => $result['option3'],
            'option4' => $result['option4'],
            'correct_answer' => $result['correct_answer']
        ];
    }
}

try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new QuizWebSocket()
            )
        ),
        8081
    );
    echo "WebSocket server started on port 8081\n";
    $server->run();
} catch (\Throwable $e) {
    echo "Server error: {$e->getMessage()}\n";
    exit(1);
}