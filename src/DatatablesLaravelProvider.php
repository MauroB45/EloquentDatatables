<?php

namespace MauroB45\EloquentDatatables;

use Illuminate\Support\ServiceProvider;

class DatatablesLaravelProvider extends ServiceProvider
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
        $this->app->singleton('MauroB45\EloquentDatatables\Contract', function ($app) {
            return new DatatablesService();
        });
    }
}
