<?php

namespace AiFace\WebSocket\Core;

/**
 * Represents an active WebSocket client connection from an AiFace hardware terminal.
 */
class DeviceConnection
{
    protected mixed $socket;
    protected string $id;
    protected string $remoteIp = '';
    protected int $remotePort = 0;
    protected bool $handshakeDone = false;
    protected string $readBuffer = '';
    
    protected ?string $sn = null;
    protected array $devInfo = [];
    protected float $connectedAt;
    protected float $lastSeenAt;
    protected bool $registered = false;

    /**
     * Map of pending commands awaiting device response:
     * [cmd => ['future' => callable|array, 'timestamp' => float, 'timeout' => float, 'response' => ?array]]
     */
    protected array $pendingCommands = [];

    public function __construct(mixed $socket)
    {
        $this->socket = $socket;
        $sockId = is_resource($socket) ? (int) $socket : (is_object($socket) ? spl_object_id($socket) : random_int(1, 999999));
        $this->id = 'conn_' . $sockId . '_' . bin2hex(random_bytes(4));
        $this->connectedAt = microtime(true);
        $this->lastSeenAt = microtime(true);

        $peerName = stream_socket_get_name($socket, true);
        if ($peerName) {
            $parts = explode(':', $peerName);
            $this->remotePort = (int) array_pop($parts);
            $this->remoteIp = implode(':', $parts);
        }
    }

    public function getSocket(): mixed
    {
        return $this->socket;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRemoteIp(): string
    {
        return $this->remoteIp;
    }

    public function getRemotePort(): int
    {
        return $this->remotePort;
    }

    public function isHandshakeDone(): bool
    {
        return $this->handshakeDone;
    }

    public function setHandshakeDone(bool $done): void
    {
        $this->handshakeDone = $done;
    }

    public function appendBuffer(string $data): void
    {
        $this->readBuffer .= $data;
        $this->lastSeenAt = microtime(true);
    }

    public function &getBuffer(): string
    {
        return $this->readBuffer;
    }

    public function setBuffer(string $buffer): void
    {
        $this->readBuffer = $buffer;
    }

    public function getSn(): ?string
    {
        return $this->sn;
    }

    public function setSn(string $sn): void
    {
        $this->sn = $sn;
    }

    public function getDevInfo(): array
    {
        return $this->devInfo;
    }

    public function setDevInfo(array $info): void
    {
        $this->devInfo = $info;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    public function setRegistered(bool $registered): void
    {
        $this->registered = $registered;
    }

    public function getConnectedAt(): float
    {
        return $this->connectedAt;
    }

    public function getLastSeenAt(): float
    {
        return $this->lastSeenAt;
    }

    public function touch(): void
    {
        $this->lastSeenAt = microtime(true);
    }

    /**
     * Send raw data down the socket.
     */
    public function write(string $data): int|false
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        return @fwrite($this->socket, $data);
    }

    /**
     * Send JSON text frame down WebSocket connection.
     */
    public function sendJson(array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $frame = WebSocketFrame::encode($json, WebSocketFrame::OPCODE_TEXT);
        return $this->write($frame) !== false;
    }

    /**
     * Send WebSocket PING frame.
     */
    public function sendPing(string $payload = ''): bool
    {
        $frame = WebSocketFrame::encodePing($payload);
        return $this->write($frame) !== false;
    }

    /**
     * Send WebSocket PONG frame.
     */
    public function sendPong(string $payload = ''): bool
    {
        $frame = WebSocketFrame::encodePong($payload);
        return $this->write($frame) !== false;
    }

    /**
     * Close the connection gracefully.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, WebSocketFrame::encodeClose($code, $reason));
            @fclose($this->socket);
        }
    }

    /**
     * Register a pending command awaiting device response.
     */
    public function registerPendingCommand(string $cmd, float $timeout = 10.0, ?callable $callback = null): void
    {
        $this->pendingCommands[$cmd] = [
            'cmd' => $cmd,
            'timestamp' => microtime(true),
            'timeout' => $timeout,
            'callback' => $callback,
            'resolved' => false,
            'response' => null,
        ];
    }

    /**
     * Resolve pending command if device sent response.
     */
    public function resolvePendingCommand(string $ret, array $response): bool
    {
        if (isset($this->pendingCommands[$ret])) {
            $this->pendingCommands[$ret]['resolved'] = true;
            $this->pendingCommands[$ret]['response'] = $response;

            if (is_callable($this->pendingCommands[$ret]['callback'])) {
                call_user_func($this->pendingCommands[$ret]['callback'], $response);
            }
            return true;
        }
        return false;
    }

    public function getPendingCommand(string $cmd): ?array
    {
        return $this->pendingCommands[$cmd] ?? null;
    }

    public function removePendingCommand(string $cmd): void
    {
        unset($this->pendingCommands[$cmd]);
    }

    /**
     * Clean up expired commands.
     */
    public function cleanExpiredCommands(): array
    {
        $expired = [];
        $now = microtime(true);

        foreach ($this->pendingCommands as $cmd => $info) {
            if ($now - $info['timestamp'] > $info['timeout']) {
                $expired[$cmd] = $info;
                unset($this->pendingCommands[$cmd]);
            }
        }

        return $expired;
    }
}
