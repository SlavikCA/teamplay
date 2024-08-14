<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\Socket\Server as ReactSocket;
use React\Socket\Server;

class CompetitionServer implements MessageComponentInterface {
    protected $clients;
    protected $startTime;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->startTime = null;
    }

    public function startKeepAlive() {
        $loop = React\EventLoop\Factory::create();
        $loop->addPeriodicTimer(30, function () {  //seconds
            foreach ($this->clients as $client) {
                $client->send(json_encode(['type' => 'ping']));
            }
        });
        $loop->run();
    }

    public function onOpen(ConnectionInterface $conn) {
        // Store the new connection
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg);

        switch ($data->action) {
            case 'start':
                $this->startTime = microtime(true);
                $this->broadcast(['action' => 'start']);
                break;

            case 'ready':
                $timeTaken = microtime(true) - $this->startTime;
                $response = [
                    'team' => $data->team,
                    'timestamp' => date('H:i:s'),
                    'timeTaken' => round($timeTaken, 3)
                ];
                $this->broadcast(['action' => 'ready', 'data' => $response]);
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Remove the connection
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

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new CompetitionServer()
        )
    ),
    7000
);

echo "WebSocket server running on ws://localhost:7000\n";
$server->run();
