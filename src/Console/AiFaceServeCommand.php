<?php

namespace AiFace\WebSocket\Console;

use AiFace\WebSocket\Core\WebSocketServer;
use Illuminate\Console\Command;

class AiFaceServeCommand extends Command
{
    protected $signature = 'aiface:serve 
                            {--host= : Host address to bind (defaults to config or 0.0.0.0)}
                            {--port= : Port to listen on (defaults to config or 7788)}
                            {--path= : Path to listen on (defaults to /pub/chat)}
                            {--wss : Enable WSS / SSL mode}';

    protected $description = 'Start the TimyTeco AiFace WebSocket/WSS communication daemon';

    public function handle(): int
    {
        $config = config('aiface', []);

        if ($this->option('host')) {
            $config['server']['host'] = $this->option('host');
        }
        if ($this->option('port')) {
            $config['server']['port'] = (int) $this->option('port');
        }
        if ($this->option('path')) {
            $config['server']['path'] = $this->option('path');
        }
        if ($this->option('wss')) {
            $config['server']['ssl']['enabled'] = true;
        }

        $host = $config['server']['host'] ?? '0.0.0.0';
        $port = (int) ($config['server']['port'] ?? 7788);
        $path = $config['server']['path'] ?? '/pub/chat';
        $isWss = !empty($config['server']['ssl']['enabled']);
        $protocol = $isWss ? 'wss' : 'ws';

        $this->output->writeln('');
        $this->output->writeln('<fg=cyan;options=bold>===============================================================</>');
        $this->output->writeln('<fg=cyan;options=bold>  AiFace Biometric WebSocket Server Daemon  </>');
        $this->output->writeln('<fg=cyan;options=bold>===============================================================</>');
        $this->output->writeln("  <fg=green>●</> Protocol:       <options=bold>{$protocol}</>");
        $this->output->writeln("  <fg=green>●</> Listening:      <options=bold>{$host}:{$port}{$path}</>");
        $this->output->writeln("  <fg=green>●</> Device URL:     <fg=yellow>{$protocol}://<YOUR_SERVER_IP>:{$port}{$path}</>");
        $this->output->writeln("  <fg=green>●</> Storage:        " . (!empty($config['storage']['enabled']) ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>'));
        $this->output->writeln("  <fg=green>●</> Webhooks:       " . (!empty($config['webhooks']['enabled']) ? '<fg=green>Active</>' : '<fg=yellow>Disabled</>'));
        $this->output->writeln('<fg=cyan;options=bold>===============================================================</>');
        $this->output->writeln('<fg=gray>Press Ctrl+C to gracefully stop the daemon.</>');
        $this->output->writeln('');

        $server = new WebSocketServer($config);

        try {
            $server->start();
            $this->info("Ready to accept AiFace connections on {$protocol}://{$host}:{$port}{$path}");

            $server->run(function () {
                // optional tick callback
            });
        } catch (\Throwable $e) {
            $this->error('Error running AiFace server: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
