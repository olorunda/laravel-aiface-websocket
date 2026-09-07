<?php

namespace AiFace\WebSocket\Core;

use AiFace\WebSocket\Events\DeviceConnected;
use AiFace\WebSocket\Events\DeviceDisconnected;
use AiFace\WebSocket\Events\DeviceRegistered;
use AiFace\WebSocket\Events\AttendanceLogReceived;
use AiFace\WebSocket\Events\UserPushed;
use AiFace\WebSocket\Events\PinReceived;
use AiFace\WebSocket\Events\QrCodeScanned;
use AiFace\WebSocket\Events\GpsReceived;
use AiFace\WebSocket\Events\IntercomCallReceived;
use AiFace\WebSocket\Events\CommandResponseReceived;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * High-performance, pure-PHP RFC 6455 WebSocket Server Engine for TimyTeco AiFace Devices.
 */
class WebSocketServer
{
    protected string $host;
    protected int $port;
    protected string $path;
    protected array $config;
    
    protected mixed $serverSocket = null;
    protected mixed $ipcSocket = null;
    protected mixed $ipcTcpSocket = null;
    protected string $ipcPath;
    protected string $ipcHost = '127.0.0.1';
    protected int $ipcPort = 7789;
    protected ConnectionRegistry $registry;
    protected StorageService $storage;
    protected WebhookForwarder $webhooks;
    protected bool $running = false;

    public function __construct(
        array $config,
        ?ConnectionRegistry $registry = null,
        ?StorageService $storage = null,
        ?WebhookForwarder $webhooks = null
    ) {
        $this->config = $config;
        $this->host = $config['server']['host'] ?? '0.0.0.0';
        $this->port = (int) ($config['server']['port'] ?? 7788);
        $this->path = $config['server']['path'] ?? '/pub/chat';
        $this->registry = $registry ?? new ConnectionRegistry();
        $this->storage = $storage ?? new StorageService($config);
        $this->webhooks = $webhooks ?? new WebhookForwarder($config);
        $this->ipcHost = (string) ($config['server']['ipc_host'] ?? '127.0.0.1');
        $this->ipcPort = (int) ($config['server']['ipc_port'] ?? ($this->port + 1));
        $this->ipcPath = '/tmp/aiface_ipc_' . $this->port . '.sock';
    }

    public function getRegistry(): ConnectionRegistry
    {
        return $this->registry;
    }

    /**
     * Start the WebSocket server listening on the configured host and port.
     */
    public function start(): void
    {
        $context = stream_context_create();

        if (!empty($this->config['server']['ssl']['enabled'])) {
            stream_context_set_option($context, 'ssl', 'local_cert', $this->config['server']['ssl']['local_cert']);
            stream_context_set_option($context, 'ssl', 'local_pk', $this->config['server']['ssl']['local_pk']);
            if (!empty($this->config['server']['ssl']['passphrase'])) {
                stream_context_set_option($context, 'ssl', 'passphrase', $this->config['server']['ssl']['passphrase']);
            }
            stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
        }

        stream_context_set_option($context, 'socket', 'so_reuseport', 1);
        stream_context_set_option($context, 'socket', 'so_reuseaddr', 1);

        $errno = 0;
        $errstr = '';
        $uri = sprintf('tcp://%s:%d', $this->host, $this->port);
        $this->serverSocket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (!$this->serverSocket) {
            throw new \RuntimeException(sprintf('Failed to bind WebSocket server on %s: [%d] %s', $uri, $errno, $errstr));
        }

        stream_set_blocking($this->serverSocket, false);

        // Setup local IPC socket for CLI/HTTP command dispatching
        $this->initIpcSocket();

        $this->running = true;
        $this->log("info", sprintf('AiFace WebSocket server started on %s%s', $uri, $this->path));
    }

    /**
     * Initialize Unix domain socket for inter-process communication.
     */
    protected function initIpcSocket(): void
    {
        $errno = 0;
        $errstr = '';

        // 1. Setup local TCP socket (works seamlessly between CLI and PHP-FPM / Laravel Herd)
        $tcpUri = sprintf('tcp://%s:%d', $this->ipcHost, $this->ipcPort);
        $this->ipcTcpSocket = @stream_socket_server($tcpUri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($this->ipcTcpSocket) {
            stream_set_blocking($this->ipcTcpSocket, false);
            $this->log("info", "AiFace local TCP IPC bridge listening on {$tcpUri}");
        }

        // 2. Setup /tmp Unix domain socket as fallback
        if (file_exists($this->ipcPath)) {
            @unlink($this->ipcPath);
        }

        $this->ipcSocket = @stream_socket_server('unix://' . $this->ipcPath, $errno, $errstr);
        if ($this->ipcSocket) {
            stream_set_blocking($this->ipcSocket, false);
            @chmod($this->ipcPath, 0777);
        }

        // 3. Link to sys_get_temp_dir() for backward compatibility
        $sysPath = sys_get_temp_dir() . '/aiface_ipc_' . $this->port . '.sock';
        if ($sysPath !== $this->ipcPath) {
            if (file_exists($sysPath)) {
                @unlink($sysPath);
            }
            @symlink($this->ipcPath, $sysPath);
        }
    }

    /**
     * Run the event loop indefinitely.
     */
    public function run(?callable $onTick = null): void
    {
        if (!$this->running) {
            $this->start();
        }

        $lastPingCheck = microtime(true);
        $pingInterval = (int) ($this->config['server']['ping_interval'] ?? 10);
        $timeout = (int) ($this->config['server']['timeout'] ?? 30);

        while ($this->running) {
            $this->tick();

            // Periodic heartbeat & timeout checks
            $now = microtime(true);
            if ($now - $lastPingCheck >= 1.0) {
                $lastPingCheck = $now;
                $this->checkHeartbeats($pingInterval, $timeout);
            }

            if ($onTick) {
                call_user_func($onTick, $this);
            }

            // Small sleep to prevent 100% CPU on empty select
            usleep(5000); // 5ms
        }
    }

    /**
     * Single iteration of socket select and I/O processing.
     */
    public function tick(): void
    {
        $read = [$this->serverSocket];
        if ($this->ipcSocket) {
            $read[] = $this->ipcSocket;
        }
        if ($this->ipcTcpSocket) {
            $read[] = $this->ipcTcpSocket;
        }

        /** @var array<int, DeviceConnection> $socketToConn */
        $socketToConn = [];
        foreach ($this->registry->getAll() as $conn) {
            $sock = $conn->getSocket();
            if (is_resource($sock)) {
                $read[] = $sock;
                $sockId = is_resource($sock) ? (int) $sock : spl_object_id($sock);
                $socketToConn[$sockId] = $conn;
            }
        }

        $write = null;
        $except = null;
        $numChanged = @stream_select($read, $write, $except, 0, 20000); // 20ms timeout

        if ($numChanged === false || $numChanged === 0) {
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $this->acceptConnection();
            } elseif ($socket === $this->ipcSocket || $socket === $this->ipcTcpSocket) {
                $this->handleIpcClient($socket);
            } else {
                $sockId = is_resource($socket) ? (int) $socket : spl_object_id($socket);
                if (isset($socketToConn[$sockId])) {
                    $this->readClientSocket($socketToConn[$sockId]);
                }
            }
        }
    }

    /**
     * Accept a new incoming TCP client connection.
     */
    protected function acceptConnection(): void
    {
        $clientSocket = @stream_socket_accept($this->serverSocket, 0);
        if (!$clientSocket) {
            return;
        }

        stream_set_blocking($clientSocket, false);
        $conn = new DeviceConnection($clientSocket);
        $this->registry->add($conn);

        $this->log("info", sprintf('New TCP connection from %s:%d (id: %s)', $conn->getRemoteIp(), $conn->getRemotePort(), $conn->getId()));
    }

    /**
     * Read and process bytes from a client connection.
     */
    protected function readClientSocket(DeviceConnection $conn): void
    {
        $data = @fread($conn->getSocket(), 65536);

        if ($data === '' || $data === false) {
            // Client closed connection
            $this->disconnect($conn, 'Socket closed by peer');
            return;
        }

        $conn->appendBuffer($data);

        // If WebSocket handshake is not yet complete, handle HTTP upgrade
        if (!$conn->isHandshakeDone()) {
            $this->performHandshake($conn);
            return;
        }

        // Process RFC 6455 WebSocket frames
        $buffer = &$conn->getBuffer();
        while (($frame = WebSocketFrame::decode($buffer)) !== null) {
            $this->handleFrame($conn, $frame);
        }
    }

    /**
     * Perform RFC 6455 HTTP WebSocket Upgrade handshake.
     */
    protected function performHandshake(DeviceConnection $conn): void
    {
        $buffer = $conn->getBuffer();
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return; // Incomplete HTTP header
        }

        $headerText = substr($buffer, 0, $headerEnd);
        // Remove header from buffer
        $conn->setBuffer(substr($buffer, $headerEnd + 4));

        $lines = explode("\r\n", $headerText);
        $headers = [];
        $requestLine = array_shift($lines);

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $val] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($val);
            }
        }

        $secKey = $headers['sec-websocket-key'] ?? null;
        if (!$secKey) {
            $this->disconnect($conn, 'Missing Sec-WebSocket-Key in handshake');
            return;
        }

        $acceptKey = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";

        $conn->write($response);
        $conn->setHandshakeDone(true);

        $this->fireEvent(new DeviceConnected($conn->getId(), $conn->getRemoteIp(), $conn->getRemotePort()));
        $this->webhooks->dispatch('device.connected', [
            'connection_id' => $conn->getId(),
            'ip' => $conn->getRemoteIp(),
            'port' => $conn->getRemotePort(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        $this->log("info", sprintf('WebSocket handshake established with %s:%d', $conn->getRemoteIp(), $conn->getRemotePort()));
    }

    /**
     * Handle a decoded WebSocket frame.
     */
    protected function handleFrame(DeviceConnection $conn, array $frame): void
    {
        $opcode = $frame['opcode'];

        if ($opcode === WebSocketFrame::OPCODE_PING) {
            $conn->sendPong($frame['payload']);
            return;
        }

        if ($opcode === WebSocketFrame::OPCODE_PONG) {
            $conn->touch();
            return;
        }

        if ($opcode === WebSocketFrame::OPCODE_CLOSE) {
            $this->disconnect($conn, 'Received Close Frame from device');
            return;
        }

        if ($opcode === WebSocketFrame::OPCODE_TEXT) {
            $payload = $frame['payload'];
            $data = json_decode($payload, true);

            if (!is_array($data)) {
                $this->log("warning", 'Received invalid non-JSON text frame: ' . substr($payload, 0, 100));
                return;
            }

            $this->processMessage($conn, $data);
        }
    }

    /**
     * Process incoming JSON message from device (either active report/cmd or ret response).
     */
    public function processMessage(DeviceConnection $conn, array $data): void
    {
        // Case 1: Device is reporting or issuing a command
        if (isset($data['cmd'])) {
            $cmd = $data['cmd'];
            $sn = $data['sn'] ?? $conn->getSn();

            if ($sn && !$conn->getSn()) {
                $this->registry->bindSn($sn, $conn);
            }

            $this->routeDeviceCommand($conn, $cmd, $data);
            return;
        }

        // Case 2: Device is returning a response to a server command
        if (isset($data['ret'])) {
            $ret = $data['ret'];
            $sn = $data['sn'] ?? $conn->getSn();

            $this->log("debug", sprintf('Device [%s] returned response for cmd [%s]: %s', $sn ?? 'unknown', $ret, json_encode($data)));

            // Resolve any awaiting synchronous command future
            $conn->resolvePendingCommand($ret, $data);

            $this->fireEvent(new CommandResponseReceived($sn ?? '', $ret, $data));
            $this->storage->logCommand($sn ?? '', $ret, [], $data);
        }
    }

    /**
     * Route and handle commands initiated by the device.
     */
    protected function routeDeviceCommand(DeviceConnection $conn, string $cmd, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? 'unknown';

        switch ($cmd) {
            case 'reg':
                $this->handleRegistration($conn, $data);
                break;

            case 'sendlog':
                $this->handleSendLog($conn, $data);
                break;

            case 'senduser':
                $this->handleSendUser($conn, $data);
                break;

            case 'sendpin':
                $this->handleSendPin($conn, $data);
                break;

            case 'sendqrcode':
                $this->handleSendQrCode($conn, $data);
                break;

            case 'sendgps':
                $this->handleSendGps($conn, $data);
                break;

            case 'otacheck':
                $this->handleOtaCheck($conn, $data);
                break;

            case 'otaget':
                $this->handleOtaGet($conn, $data);
                break;

            case 'talkcall':
                $this->handleTalkCall($conn, $data);
                break;

            case 'vicheckonline':
                $this->handleViCheckOnline($conn, $data);
                break;

            default:
                $this->log("notice", sprintf('Unhandled device command [%s] from device [%s]', $cmd, $sn));
                // Default fallback response
                $conn->sendJson([
                    'ret' => $cmd,
                    'sn' => $sn,
                    'result' => true,
                ]);
                break;
        }
    }

    /**
     * 4. Device Registration (cmd: "reg")
     */
    protected function handleRegistration(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? '';
        $devinfo = $data['devinfo'] ?? [];

        $this->registry->bindSn($sn, $conn);
        $conn->setDevInfo($devinfo);
        $conn->setRegistered(true);

        $cloudTime = date('Y-m-d H:i:s');
        $trySeconds = (int) ($this->config['registration']['tryseconds'] ?? 300);

        $response = [
            'ret' => 'reg',
            'tryseconds' => $trySeconds,
            'result' => true,
            'cloudtime' => $cloudTime,
            'nosenduser' => (bool) ($this->config['registration']['nosenduser'] ?? false),
            'nosendlog' => (bool) ($this->config['registration']['nosendlog'] ?? false),
            'nosendimage' => (bool) ($this->config['registration']['nosendimage'] ?? false),
        ];

        $conn->sendJson($response);

        // Persist device status and metadata to database
        $this->storage->saveDevice($sn, $conn->getRemoteIp(), $conn->getRemotePort(), $devinfo);

        $this->fireEvent(new DeviceRegistered($sn, $devinfo, $conn->getRemoteIp()));
        $this->webhooks->dispatch('device.registered', [
            'sn' => $sn,
            'ip' => $conn->getRemoteIp(),
            'port' => $conn->getRemotePort(),
            'devinfo' => $devinfo,
            'registered_at' => $cloudTime,
        ]);

        $this->log("info", sprintf('Device [%s] successfully registered (model: %s, firmware: %s)', $sn, $devinfo['modelname'] ?? 'AiFace', $devinfo['firmware'] ?? 'N/A'));
    }

    /**
     * 10.1 sendlog — Device reports attendance records
     */
    protected function handleSendLog(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? '';
        $count = (int) ($data['count'] ?? 0);
        $logIndex = (int) ($data['logindex'] ?? 0);
        $records = $data['record'] ?? [];

        // Save records to database
        $savedCount = $this->storage->saveAttendanceLogs($sn, $records);

        $cfg = $this->config['reports']['sendlog'] ?? [];

        $response = [
            'ret' => 'sendlog',
            'result' => true,
            'count' => $count,
            'logindex' => $logIndex,
            'mark' => (bool) ($cfg['auto_mark'] ?? true),
            'access' => (int) ($cfg['default_access'] ?? 1),
            'message' => (string) ($cfg['message'] ?? 'Welcome'),
            'fontsize' => (int) ($cfg['fontsize'] ?? 24),
            'text_color' => $cfg['text_color'] ?? [0, 255, 0],
            'voice' => (string) ($cfg['voice'] ?? ''),
            'voiceindex' => (int) ($cfg['voiceindex'] ?? 0),
            'questionnaire' => (bool) ($cfg['questionnaire'] ?? false),
        ];

        // If single record, include name for display
        if (count($records) === 1 && !empty($records[0]['name'])) {
            $response['name'] = $records[0]['name'];
        }

        $conn->sendJson($response);

        $this->fireEvent(new AttendanceLogReceived($sn, $records, $count));
        $this->webhooks->dispatch('attendance.logged', [
            'sn' => $sn,
            'count' => $count,
            'records' => $records,
            'received_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log("info", sprintf('Device [%s] reported %d attendance logs (saved: %d)', $sn, $count, $savedCount));
    }

    /**
     * 10.2 senduser — Device reports user changes
     */
    protected function handleSendUser(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? '';
        $enrollId = $data['enrollid'] ?? null;
        $backupNum = (int) ($data['backupnum'] ?? 0);

        // Persist user and credential to database
        $this->storage->saveUserReport($sn, $data);

        $response = [
            'ret' => 'senduser',
            'result' => true,
            'enrollid' => $enrollId,
            'backupnum' => $backupNum,
        ];

        $conn->sendJson($response);

        $this->fireEvent(new UserPushed($sn, $data));
        $this->webhooks->dispatch('user.pushed', [
            'sn' => $sn,
            'enrollid' => $enrollId,
            'backupnum' => $backupNum,
            'user_data' => $data,
        ]);

        $this->log("info", sprintf('Device [%s] pushed user enrollment for enrollid [%s], backupnum [%d] (%s)', $sn, $enrollId, $backupNum, Protocol::getBackupNumDescription($backupNum)));
    }

    /**
     * 10.3 sendpin — Device reports PIN entry
     */
    protected function handleSendPin(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? '';
        $pin = $data['pin'] ?? '';
        $time = $data['time'] ?? date('Y-m-d H:i:s');

        $cfg = $this->config['reports']['sendpin'] ?? [];

        $response = [
            'ret' => 'sendpin',
            'result' => true,
            'access' => (int) ($cfg['default_access'] ?? 1),
            'message' => (string) ($cfg['message'] ?? 'Access Granted'),
            'fontsize' => (int) ($cfg['fontsize'] ?? 24),
            'text_color' => $cfg['text_color'] ?? [0, 255, 0],
            'voice' => (string) ($cfg['voice'] ?? ''),
            'voiceindex' => (int) ($cfg['voiceindex'] ?? 0),
        ];

        $conn->sendJson($response);

        $this->fireEvent(new PinReceived($sn, $pin, $time));
        $this->webhooks->dispatch('pin.received', [
            'sn' => $sn,
            'pin' => $pin,
            'time' => $time,
        ]);
    }

    /**
     * 10.4 sendqrcode — Device reports QR code scan
     */
    protected function handleSendQrCode(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? '';
        $qrRecord = $data['record'] ?? '';

        $cfg = $this->config['reports']['sendqrcode'] ?? [];

        $response = [
            'ret' => 'sendqrcode',
            'result' => true,
            'access' => (int) ($cfg['default_access'] ?? 1),
            'message' => (string) ($cfg['message'] ?? 'QR Verified'),
            'fontsize' => (int) ($cfg['fontsize'] ?? 24),
            'text_color' => $cfg['text_color'] ?? [0, 255, 0],
            'voice' => (string) ($cfg['voice'] ?? ''),
            'voiceindex' => (int) ($cfg['voiceindex'] ?? 0),
        ];

        $conn->sendJson($response);

        $this->fireEvent(new QrCodeScanned($sn, $qrRecord));
        $this->webhooks->dispatch('qrcode.scanned', [
            'sn' => $sn,
            'qr_record' => $qrRecord,
        ]);
    }

    /**
     * 10.5 sendgps — Device reports GPS location
     */
    protected function handleSendGps(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? '';
        $satellites = (int) ($data['satellites'] ?? 0);
        $location = $data['location'] ?? '';
        $timeStamp = $data['timeStamp'] ?? null;

        $this->storage->saveGpsLocation($sn, $satellites, $location, $timeStamp);

        $this->fireEvent(new GpsReceived($sn, $satellites, $location, $timeStamp));
        $this->webhooks->dispatch('gps.received', [
            'sn' => $sn,
            'satellites' => $satellites,
            'location' => $location,
            'timestamp' => $timeStamp,
        ]);
        // Note: As specified in official documentation, server does not send a response to sendgps.
    }

    /**
     * 10.6 otacheck — OTA update check
     */
    protected function handleOtaCheck(DeviceConnection $conn, array $data): void
    {
        // By default report no update available unless customized
        $conn->sendJson([
            'ret' => 'otacheck',
            'result' => false,
        ]);
    }

    /**
     * 10.7 otaget — OTA chunk download
     */
    protected function handleOtaGet(DeviceConnection $conn, array $data): void
    {
        $conn->sendJson([
            'ret' => 'otaget',
            'result' => false,
        ]);
    }

    /**
     * 13.10 talkcall — Device initiates intercom call
     */
    protected function handleTalkCall(DeviceConnection $conn, array $data): void
    {
        $sn = $data['sn'] ?? $conn->getSn() ?? '';
        $sessionId = 'call_' . $sn . '_' . time();

        $conn->sendJson([
            'ret' => 'talkcall',
            'result' => true,
            'sessionid' => $sessionId,
        ]);

        $this->fireEvent(new IntercomCallReceived($sn, $data, $sessionId));
    }

    /**
     * 13.11 vicheckonline — Check subscriber online status
     */
    protected function handleViCheckOnline(DeviceConnection $conn, array $data): void
    {
        $conn->sendJson([
            'ret' => 'vicheckonline',
            'result' => true,
            'count' => 1,
            'online' => 'admin',
        ]);
    }

    /**
     * Handle incoming command from local IPC socket (CLI or HTTP API).
     */
    protected function handleIpcClient(mixed $listenSocket = null): void
    {
        $serverSocket = $listenSocket ?? $this->ipcTcpSocket ?? $this->ipcSocket;
        if (!$serverSocket) {
            return;
        }
        $ipcClient = @stream_socket_accept($serverSocket, 0);
        if (!$ipcClient) {
            return;
        }

        stream_set_blocking($ipcClient, true);
        $input = @fread($ipcClient, 65536);
        if ($input === '' || $input === false) {
            @fclose($ipcClient);
            return;
        }

        $request = json_decode($input, true);
        if (!is_array($request)) {
            @fwrite($ipcClient, json_encode(['result' => false, 'error' => 'Invalid JSON']));
            @fclose($ipcClient);
            return;
        }

        $action = $request['action'] ?? 'command';

        if ($action === 'list_devices') {
            $online = $this->registry->getOnlineDevices();
            @fwrite($ipcClient, json_encode(['result' => true, 'devices' => $online]));
            @fclose($ipcClient);
            return;
        }

        if ($action === 'command') {
            $sn = $request['sn'] ?? null;
            $cmd = $request['cmd'] ?? null;
            $payload = $request['payload'] ?? [];
            $timeout = (float) ($request['timeout'] ?? 10.0);

            if (!$sn || !$cmd) {
                @fwrite($ipcClient, json_encode(['result' => false, 'error' => 'Missing sn or cmd']));
                @fclose($ipcClient);
                return;
            }

            $conn = $this->registry->getBySn($sn);
            if (!$conn) {
                @fwrite($ipcClient, json_encode(['result' => false, 'error' => "Device [{$sn}] is offline or not registered"]));
                @fclose($ipcClient);
                return;
            }

            // Send command down WebSocket to device
            $fullPayload = array_merge(['cmd' => $cmd, 'sn' => $sn], $payload);
            
            // Register callback to capture response
            $resolved = false;
            $response = null;

            $conn->registerPendingCommand($cmd, $timeout, function ($res) use (&$resolved, &$response) {
                $resolved = true;
                $response = $res;
            });

            if (!$conn->sendJson($fullPayload)) {
                $conn->removePendingCommand($cmd);
                @fwrite($ipcClient, json_encode(['result' => false, 'error' => 'Failed to write frame to device socket']));
                @fclose($ipcClient);
                return;
            }

            // Wait for response up to timeout
            $startTime = microtime(true);
            while (!$resolved && (microtime(true) - $startTime) < $timeout) {
                $this->tick();
                usleep(5000); // 5ms
            }

            $conn->removePendingCommand($cmd);

            if ($resolved) {
                @fwrite($ipcClient, json_encode(['result' => true, 'data' => $response]));
            } else {
                @fwrite($ipcClient, json_encode(['result' => false, 'error' => "Command [{$cmd}] timed out waiting for device response"]));
            }

            @fclose($ipcClient);
        }
    }

    /**
     * Check heartbeats and clean dead connections.
     */
    protected function checkHeartbeats(int $pingInterval, int $timeout): void
    {
        $now = microtime(true);
        foreach ($this->registry->getAll() as $conn) {
            $lastSeen = $conn->getLastSeenAt();

            if ($now - $lastSeen > $timeout) {
                $this->disconnect($conn, "Heartbeat timeout (inactive for > {$timeout}s)");
                continue;
            }

            if ($now - $lastSeen > $pingInterval) {
                $conn->sendPing();
            }

            $conn->cleanExpiredCommands();
        }
    }

    /**
     * Disconnect a client and clean up.
     */
    public function disconnect(DeviceConnection $conn, string $reason = ''): void
    {
        $sn = $conn->getSn();
        $id = $conn->getId();

        $this->registry->remove($conn);
        $conn->close();

        if ($sn) {
            $this->storage->updateDeviceStatus($sn, 'offline');
        }

        $this->fireEvent(new DeviceDisconnected($sn, $id, $reason));
        $this->webhooks->dispatch('device.disconnected', [
            'sn' => $sn,
            'connection_id' => $id,
            'reason' => $reason,
            'disconnected_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log("info", sprintf('Disconnected connection [%s] (sn: %s). Reason: %s', $id, $sn ?? 'unknown', $reason));
    }

    public function stop(): void
    {
        $this->running = false;
        foreach ($this->registry->getAll() as $conn) {
            $conn->close(1001, 'Server shutting down');
        }

        if (is_resource($this->serverSocket)) {
            @fclose($this->serverSocket);
        }

        if (is_resource($this->ipcSocket)) {
            @fclose($this->ipcSocket);
        }

        if (is_resource($this->ipcTcpSocket)) {
            @fclose($this->ipcTcpSocket);
        }

        if (file_exists($this->ipcPath)) {
            @unlink($this->ipcPath);
        }

        $this->log("info", 'AiFace WebSocket server stopped');
    }

    protected function log(string $level, string $message): void
    {
        try {
            if (class_exists(\Illuminate\Support\Facades\Log::class) && \Illuminate\Support\Facades\Facade::getFacadeApplication()) {
                \Illuminate\Support\Facades\Log::$level($message);
            }
        } catch (\Throwable) {
            // Silently continue outside Laravel
        }
    }

    protected function fireEvent(object $event): void
    {
        try {
            if (class_exists(\Illuminate\Support\Facades\Event::class) && \Illuminate\Support\Facades\Facade::getFacadeApplication()) {
                \Illuminate\Support\Facades\Event::dispatch($event);
            }
        } catch (\Throwable) {
            // Silently continue outside Laravel
        }
    }
}
