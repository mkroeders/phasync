<?php
namespace phasync;

use Closure;
use Evenement\EventEmitter;
use Exception;
use FiberError;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Asynchronous generic TCP server class for phasync.
 * 
 * @package phasync
 */
class Server extends EventEmitter {

    public readonly string $ip;
    public readonly int $port;
    public readonly ServerOptions $serverOptions;
    public readonly ConnectionOptions $connectionOptions;

    private mixed $context;
    private mixed $socket;
    private int $connectionCount = 0;

    public function __construct(string $ip, int $port, array|ServerOptions $serverOptions = null, array|ConnectionOptions $connectionOptions = null) {
        $this->ip = $ip;
        $this->port = $port;
        $this->serverOptions = ServerOptions::create($serverOptions);
        $this->connectionOptions = ConnectionOptions::create($connectionOptions);

        $this->context = stream_context_create([
            'socket' => [
                'backlog' => $this->serverOptions->socket_backlog,
                'ipv6_v6only' => $this->serverOptions->socket_ipv6_v6only,
                'so_reuseport' => $this->serverOptions->socket_so_reuseport,
                'so_broadcast' => $this->serverOptions->socket_so_broadcast,
            ],
        ]);

        if ($this->serverOptions->connect) {
            $this->open();
        }
    }

    /**
     * Close the listening socket.
     * 
     * @return void 
     * @throws DisconnectedException 
     */
    public function close(): void {
        $this->assertOpen();
        fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Open the socket and start listening for connections.
     * 
     * @return void 
     * @throws LogicException 
     * @throws IOException 
     */
    public function open(): void {
        if ($this->isOpen()) {
            throw new LogicException("tcp://" . $this->ip.':' . $this->port . ' is already opened');
        }
        $errorCode = null;
        $errorMessage = null;
        $socket = stream_socket_server(
            "tcp://" . $this->ip . ":" . $this->port,
            $errorCode,
            $errorMessage,
            $this->serverOptions->serverFlags,
            $this->context
        );
        stream_set_blocking($socket, false);
        if (false === $socket) {
            throw new IOException($errorMessage, $errorCode);
        }

        $this->socket = $socket;        
    }

    /**
     * Returns true if the socket is open.
     * 
     * @return bool 
     */
    public function isOpen(): bool {
        return is_resource($this->socket);
    }


    /**
     * Check that the socket is open and fail with a DisconnectedException
     * if not.
     * 
     * @return void 
     * @throws DisconnectedException 
     */
    private function assertOpen(): void {
        if (!$this->isOpen()) {
            throw new DisconnectedException();
        }
    }    

    /**
     * Run the server until it is closed.
     * 
     * @param Closure $connectionHandler 
     * @return void 
     * @throws DisconnectedException 
     * @throws FiberError 
     * @throws Throwable 
     * @throws Exception 
     * @throws InvalidArgumentException 
     */
    public function run(Closure $connectionHandler) {
        return Loop::go(function() use ($connectionHandler) {
            while ($this->isOpen() && ($connection = $this->accept())) {
                Loop::go($connectionHandler($connection));
            }    
        });
    }

    public function accept(): Connection {
        $this->assertOpen();
        Loop::readable($this->socket);
        if (!is_resource($this->socket)) {
            throw new Exception("Server socket closed");
        }

        $peerName = null;
        while (is_resource($this->socket)) {

            // limit the number of active connections being handled
            if ($this->connectionCount >= $this->serverOptions->maxConnections) {
                while ($this->connectionCount >= $this->serverOptions->maxConnections) {
                    Loop::yield(); // Context switch
                }
                continue;
            }
            Loop::readable($this->socket);

            // socket may have been closed by a call to $this->close() or an error
            if (is_resource($this->socket)) {
                $socket = @stream_socket_accept($this->socket, 0, $peerName);
                if ($socket) {
                    $connection = new Connection($socket, $peerName, $this->connectionOptions);
                    $this->connectionCount++;
                    $connection->on(Connection::CLOSE_EVENT, function() {
                        $this->connectionCount--;
                    });
                    return $connection;
                }
            }
        }
        return null;
    }

}