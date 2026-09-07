<?php

namespace AiFace\WebSocket\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AiFace\WebSocket\Services\AiFaceDeviceClient device(string $sn)
 * @method static array getOnlineDevices()
 * @method static bool isOnline(string $sn)
 * @method static array sendCommand(string $sn, string $cmd, array $params = [], ?float $timeout = null)
 * @method static array openDoor(string $sn, int $doorNum = 1)
 * @method static array reboot(string $sn)
 * @method static array syncTime(string $sn)
 * @method static array getNewLog(string $sn)
 *
 * @see \AiFace\WebSocket\Services\AiFaceManager
 */
class AiFace extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'aiface.manager';
    }
}
