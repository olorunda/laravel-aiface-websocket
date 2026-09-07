<?php

namespace AiFace\WebSocket;

use AiFace\WebSocket\Console\AiFaceServeCommand;
use AiFace\WebSocket\Console\AiFaceSendCommand;
use AiFace\WebSocket\Core\ConnectionRegistry;
use AiFace\WebSocket\Core\WebSocketServer;
use AiFace\WebSocket\Http\Controllers\AiFaceApiController;
use AiFace\WebSocket\Http\Controllers\AiFaceDashboardController;
use AiFace\WebSocket\Http\Middleware\AiFaceApiAuthMiddleware;
use AiFace\WebSocket\Listeners\DispatchAiFaceWebhook;
use AiFace\WebSocket\Services\AiFaceManager;
use AiFace\WebSocket\Services\StorageService;
use AiFace\WebSocket\Services\WebhookForwarder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiFaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/aiface.php', 'aiface');

        $this->app->singleton(ConnectionRegistry::class, function () {
            return new ConnectionRegistry();
        });

        $this->app->singleton(StorageService::class, function ($app) {
            return new StorageService($app['config']->get('aiface', []));
        });

        $this->app->singleton(WebhookForwarder::class, function ($app) {
            return new WebhookForwarder($app['config']->get('aiface', []));
        });

        $this->app->singleton(DispatchAiFaceWebhook::class, function ($app) {
            return new DispatchAiFaceWebhook($app->make(WebhookForwarder::class));
        });

        $this->app->singleton('aiface.manager', function ($app) {
            return new AiFaceManager($app['config']->get('aiface', []));
        });

        $this->app->singleton(AiFaceManager::class, function ($app) {
            return $app['aiface.manager'];
        });

        $this->app->singleton(WebSocketServer::class, function ($app) {
            return new WebSocketServer(
                $app['config']->get('aiface', []),
                $app->make(ConnectionRegistry::class),
                $app->make(StorageService::class),
                $app->make(WebhookForwarder::class)
            );
        });
    }

    public function boot(): void
    {
        if (isset($this->app['events'])) {
            $this->app['events']->subscribe(DispatchAiFaceWebhook::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/aiface.php' => config_path('aiface.php'),
            ], 'aiface-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'aiface-migrations');

            $this->commands([
                AiFaceServeCommand::class,
                AiFaceSendCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/Views', 'aiface');

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        $config = config('aiface', []);

        // 1. REST API Routes
        if (!empty($config['api']['enabled'])) {
            $prefix = $config['api']['prefix'] ?? 'api/aiface';
            $middleware = $config['api']['middleware'] ?? ['api'];
            $middleware[] = AiFaceApiAuthMiddleware::class;

            Route::prefix($prefix)
                ->middleware($middleware)
                ->group(function () {
                    Route::get('/devices', [AiFaceApiController::class, 'listDevices']);
                    Route::get('/devices/{sn}', [AiFaceApiController::class, 'getDevice']);
                    Route::post('/devices/{sn}/command', [AiFaceApiController::class, 'executeCommand']);

                    // Shortcut actions
                    Route::post('/devices/{sn}/opendoor', [AiFaceApiController::class, 'openDoor']);
                    Route::post('/devices/{sn}/reboot', [AiFaceApiController::class, 'reboot']);
                    Route::post('/devices/{sn}/sync-time', [AiFaceApiController::class, 'syncTime']);
                    Route::get('/devices/{sn}/new-logs', [AiFaceApiController::class, 'getNewLogs']);
                    Route::get('/devices/{sn}/users', [AiFaceApiController::class, 'getUsers']);
                    Route::post('/devices/{sn}/users', [AiFaceApiController::class, 'saveUser']);
                    Route::delete('/devices/{sn}/users/{enrollid}', [AiFaceApiController::class, 'deleteUser']);

                    Route::get('/logs', [AiFaceApiController::class, 'listLogs']);
                    Route::get('/commands/catalog', [AiFaceApiController::class, 'commandCatalog']);
                });
        }

        // 2. Web Management Dashboard Route
        if (!empty($config['dashboard']['enabled'])) {
            $dashPrefix = $config['dashboard']['prefix'] ?? 'aiface/dashboard';
            $dashMiddleware = $config['dashboard']['middleware'] ?? ['web'];

            Route::prefix($dashPrefix)
                ->middleware($dashMiddleware)
                ->group(function () {
                    Route::get('/', [AiFaceDashboardController::class, 'index'])->name('aiface.dashboard');
                });
        }
    }
}
