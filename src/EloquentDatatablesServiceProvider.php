<?php

namespace MauroB\EloquentDatatables;

use Illuminate\Support\ServiceProvider;
use MauroB\EloquentDatatable\Contracts\DatatablesServiceInterface;

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
            return new Datatables($app->make(Request::class));
        });

        $this->app->singleton(DatatablesServiceInterface::class, function ($app) {
            return new DatatablesService();
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
