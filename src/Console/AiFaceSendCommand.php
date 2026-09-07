<?php

namespace AiFace\WebSocket\Console;

use AiFace\WebSocket\Facades\AiFace;
use Illuminate\Console\Command;

class AiFaceSendCommand extends Command
{
    protected $signature = 'aiface:command 
                            {sn : Device serial number (e.g. LF00000001)}
                            {cmd : Command name (e.g. opendoor, reboot, gettime, getnewlog)}
                            {--params= : Optional JSON encoded parameter string}
                            {--timeout=10 : Command wait timeout in seconds}';

    protected $description = 'Send an interaction command to an online AiFace device';

    public function handle(): int
    {
        $sn = $this->argument('sn');
        $cmd = $this->argument('cmd');
        $paramsRaw = $this->option('params');
        $timeout = (float) $this->option('timeout');

        $params = [];
        if ($paramsRaw) {
            $params = json_decode($paramsRaw, true);
            if (!is_array($params)) {
                $this->error('Invalid JSON provided in --params');
                return 1;
            }
        }

        $this->info("Dispatching command [{$cmd}] to device [{$sn}] (timeout: {$timeout}s)...");

        $response = AiFace::device($sn)->send($cmd, $params, $timeout);

        if (isset($response['result']) && $response['result'] === true) {
            $this->info("Success! Device response:");
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->error("Failed! Response or error:");
        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return 1;
    }
}
