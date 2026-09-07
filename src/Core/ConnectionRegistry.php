<?php

namespace AiFace\WebSocket\Core;

/**
 * In-memory registry tracking connected AiFace devices.
 */
class ConnectionRegistry
{
    /** @var array<string, DeviceConnection> */
    protected array $connections = [];

    /** @var array<string, string> Map of sn => connectionId */
    protected array $snMap = [];

    public function add(DeviceConnection $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    public function remove(DeviceConnection $connection): void
    {
        $id = $connection->getId();
        unset($this->connections[$id]);

        if ($connection->getSn() && isset($this->snMap[$connection->getSn()])) {
            if ($this->snMap[$connection->getSn()] === $id) {
                unset($this->snMap[$connection->getSn()]);
            }
        }
    }

    public function bindSn(string $sn, DeviceConnection $connection): void
    {
        $connection->setSn($sn);
        $this->snMap[$sn] = $connection->getId();
    }

    public function getBySn(string $sn): ?DeviceConnection
    {
        $id = $this->snMap[$sn] ?? null;
        if ($id && isset($this->connections[$id])) {
            return $this->connections[$id];
        }
        return null;
    }

    public function getById(string $id): ?DeviceConnection
    {
        return $this->connections[$id] ?? null;
    }

    public function getBySocket(mixed $socket): ?DeviceConnection
    {
        foreach ($this->connections as $conn) {
            if ($conn->getSocket() === $socket) {
                return $conn;
            }
        }
        return null;
    }

    /**
     * @return array<string, DeviceConnection>
     */
    public function getAll(): array
    {
        return $this->connections;
    }

    /**
     * Get array of registered, online devices.
     */
    public function getOnlineDevices(): array
    {
        $online = [];
        foreach ($this->connections as $conn) {
            if ($conn->isRegistered() && $conn->getSn()) {
                $online[$conn->getSn()] = [
                    'sn' => $conn->getSn(),
                    'ip' => $conn->getRemoteIp(),
                    'port' => $conn->getRemotePort(),
                    'connected_at' => date('Y-m-d H:i:s', (int) $conn->getConnectedAt()),
                    'last_seen' => date('Y-m-d H:i:s', (int) $conn->getLastSeenAt()),
                    'devinfo' => $conn->getDevInfo(),
                ];
            }
        }
        return $online;
    }

    public function count(): int
    {
        return count($this->connections);
    }
}
