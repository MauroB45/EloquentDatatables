<?php

namespace MauroB45\EloquentDatatables;

use Illuminate\Support\ServiceProvider;
use MauroB45\EloquentDatatables\Contracts\DatatablesServiceInterface;
use MauroB45\EloquentDatatables\Models\Request;

class EloquentDatatablesServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {

    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('datatables', function ($app) {
            return new DatatablesService($app->make(Request::class));
        });

        $this->app->singleton(DatatablesServiceInterface::class, function ($app) {
            return new DatatablesService($app->make(Request::class));
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return string[]
     */
    public function provides()
    {
        return ['datatables'];
    }
}
