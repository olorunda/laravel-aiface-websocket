<?php

namespace AiFace\WebSocket\Tests;

use AiFace\WebSocket\AiFaceServiceProvider;
use AiFace\WebSocket\Facades\AiFace;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AiFaceServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'AiFace' => AiFace::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:6C8b2L990v1N8xX7kP3qR5sT7uV9wX1yZ3aB5cD7eF8=');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('aiface.storage.enabled', false);
        $app['config']->set('aiface.webhooks.enabled', false);
    }
}
