<?php

namespace MauricioBernal\DatatablesLaravel;

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
        $this->app->singleton('MauricioBernal\DatatablesLaravel\Contract', function ($app) {
            return new DatatablesService();
        });
    }
}
