<?php

namespace AiFace\WebSocket\Services;

/**
 * Main AiFace Manager Service registered in the Laravel Service Container.
 */
class AiFaceManager
{
    protected array $config;
    /** @var array<string, AiFaceDeviceClient> */
    protected array $clients = [];
    protected string $ipcPath;

    public function __construct(array $config)
    {
        $this->config = $config;
        $port = (int) ($config['server']['port'] ?? 7788);
        $this->ipcPath = sys_get_temp_dir() . '/aiface_ipc_' . $port . '.sock';
    }

    /**
     * Get a fluent device client for a specific serial number.
     */
    public function device(string $sn): AiFaceDeviceClient
    {
        if (!isset($this->clients[$sn])) {
            $this->clients[$sn] = new AiFaceDeviceClient($sn, $this->config);
        }
        return $this->clients[$sn];
    }

    /**
     * Get list of currently connected online devices via the running daemon.
     */
    public function getOnlineDevices(): array
    {
        if (!file_exists($this->ipcPath)) {
            return [];
        }

        $fp = @stream_socket_client('unix://' . $this->ipcPath, $errno, $errstr, 1.0);
        if (!$fp) {
            return [];
        }

        @fwrite($fp, json_encode(['action' => 'list_devices']));
        stream_set_timeout($fp, 2);

        $responseRaw = '';
        while (!feof($fp)) {
            $chunk = @fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $responseRaw .= $chunk;
        }
        @fclose($fp);

        $data = json_decode($responseRaw, true);
        return $data['devices'] ?? [];
    }

    /**
     * Check if a device is currently online.
     */
    public function isOnline(string $sn): bool
    {
        $devices = $this->getOnlineDevices();
        return isset($devices[$sn]);
    }

    /**
     * Send command to a device.
     */
    public function sendCommand(string $sn, string $cmd, array $params = [], ?float $timeout = null): array
    {
        return $this->device($sn)->send($cmd, $params, $timeout);
    }

    /**
     * Shortcut: Remote open door.
     */
    public function openDoor(string $sn, int $doorNum = 1): array
    {
        return $this->device($sn)->openDoor($doorNum);
    }

    /**
     * Shortcut: Reboot device.
     */
    public function reboot(string $sn): array
    {
        return $this->device($sn)->reboot();
    }

    /**
     * Shortcut: Synchronize device clock.
     */
    public function syncTime(string $sn): array
    {
        return $this->device($sn)->syncTime();
    }

    /**
     * Shortcut: Pull new attendance logs.
     */
    public function getNewLog(string $sn): array
    {
        return $this->device($sn)->getNewLog();
    }
}
