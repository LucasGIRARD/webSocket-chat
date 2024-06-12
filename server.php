<?php require __DIR__.'/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

define('APP_PORT', 8080);

class room {
    protected $clients;
    protected $message;
    protected $id;
    protected $vote;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->message = [];
        $this->id = 0;
        $this->vote = [];
    }

    public function addClient(ConnectionInterface $conn) {
        $this->clients->attach($conn);
    }

    public function removeClient(ConnectionInterface $conn) {
         $this->clients->detach($conn);
    }

    public function getClients() {
        return $this->clients;
    }

    public function vote(ConnectionInterface $conn, $text) {
        if (isset($this->vote[$text])) {
            $this->vote[$text]++;
        } else {
            $this->vote[$text] = 1;
        }
    }

    public function getVote() {
        return $this->vote;
    }

    public function addLike(ConnectionInterface $conn, $id) {
        $this->message[$id]['likes'][$conn->resourceId] = 1;
    }

    public function removeLike(ConnectionInterface $conn, $id) {
        unset($this->message[$id]['likes'][$conn->resourceId]);
    }


    public function countLike($id) {
        return count($this->message[$id]['likes']);
    }

    public function addMessage($msg) {
        $this->id++;
        $this->message[$this->id]['id'] = $this->id;
        $this->message[$this->id]['message'] = $msg;
        $this->message[$this->id]['likes'] = [];

        return $this->id;
    }

    public function getMessage($id) {        
        return $this->message[$id];
    }    
}

class ServerImpl implements MessageComponentInterface {
    protected $room;

    public function __construct() {
        $this->room['default'] = new room();
    }

    public function onOpen(ConnectionInterface $conn) {
        $roomUser = $this->getRoom($conn);

        if (!isset($this->room[$roomUser])) {
            $this->room[$roomUser] = new room();
        }

        $this->room[$roomUser]->addClient($conn);
    }

    public function onMessage(ConnectionInterface $conn, $msg) {
        $roomUser = $this->getRoom($conn);

        $json = json_decode($msg, true);
        if (json_last_error() === 0) {
            if ($json['type'] == "vote") {
                $return = $msg;
            } else if ($json['type'] == "result") {
                $this->room[$roomUser]->vote($conn, $json['text']);
                $temp['type'] = 'result';
                $temp['results'] = $this->room[$roomUser]->getVote();
                $return = json_encode($temp);
            } else if ($json['type'] == "like" || $json['type'] == "unlike") {
                if ($json['type'] == "like") {
                    $this->room[$roomUser]->addLike($conn, $json['id']);
                } else {
                    $this->room[$roomUser]->removeLike($conn, $json['id']);
                }                    
                $return['like'] = $this->room[$roomUser]->countLike($json['id']);
                $return['id'] = $json['id'];
                $return['type'] = 'like';
                $return = json_encode($return);
            }
        } else {
            $id = $this->room[$roomUser]->addMessage($msg);
            $temp = $this->room[$roomUser]->getMessage($id);
            $temp['type'] = 'message';
            $return = json_encode($temp);
        }

        foreach ($this->room[$roomUser]->getClients() as $client) {
            $client->send($return);                    
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $roomUser = $this->getRoom($conn);
        $this->room[$roomUser]->removeClient($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error occured on connection {$conn->resourceId}: {$e->getMessage()}\n\n\n";
        $conn->close();
    }

    private function getRoom(ConnectionInterface $conn) {
        $roomUser = ltrim(parse_url($conn->httpRequest->getUri(), PHP_URL_PATH), '/');
        $roomUser = ($roomUser==''?'default':$roomUser);
        return $roomUser;
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ServerImpl()
        )
    ),
    APP_PORT
);
echo "Server created on port " . APP_PORT . "\n\n";
$server->run();