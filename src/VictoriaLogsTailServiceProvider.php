<?php

namespace Wndr\VictoriaLogsTail;

use Illuminate\Support\ServiceProvider;
use Wndr\VictoriaLogsTail\Commands\ShipLogsToVictoriaLogs;

class VictoriaLogsTailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/victoria-logs-tail.php',
            'victoria-logs-tail'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/victoria-logs-tail.php' => config_path('victoria-logs-tail.php'),
            ], 'victoria-logs-tail-config');

            $this->commands([
                ShipLogsToVictoriaLogs::class,
            ]);
        }
    }
}
