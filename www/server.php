<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\Socket\Server as ReactSocket;

class CompetitionServer implements MessageComponentInterface {
    protected $clients;
    protected $startTime;
    protected $manager;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->startTime = null;
    }

    public function startKeepAlive($loop) {
        $loop->addPeriodicTimer(30, function () {  // seconds
            foreach ($this->clients as $client) {
                $client->send(json_encode(['type' => 'ping']));
            }
        });
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg);

        switch ($data->action) {
            case 'register':
                // Broadcast the new team's name to all clients
                $this->broadcast(['action' => 'new_team', 'teamName' => $data->teamName]);
                break;    

            case 'start':
                $this->startTime = microtime(true);
                $this->broadcast(['action' => 'start']);
                $this->manager = $from;
                break;

            case 'ready':
                $timeTaken = microtime(true) - $this->startTime;
                $response = [
                    'team' => $data->team,
                    'timestamp' => date('H:i:s'),
                    'timeTaken' => round($timeTaken, 3)
                ];
                $from->         send(json_encode(['action' => 'ready', 'data' => $response]));
                $this->manager->send(json_encode(['action' => 'ready', 'data' => $response]));                
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    private function broadcast($msg) {
        foreach ($this->clients as $client) {
            $client->send(json_encode($msg));
        }
    }
}

$loop = Factory::create();

$competitionServer = new CompetitionServer();

$server = new IoServer(
    new HttpServer(
        new WsServer($competitionServer)
    ),
    new ReactSocket('0.0.0.0:7000', $loop), 
    $loop
);

$competitionServer->startKeepAlive($loop);

echo "WebSocket server running on ws://localhost:7000\n";
$server->run();
