<?php

namespace Webkul\Automation\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Webkul\Automation\Http\Middleware\AuthenticateApiToken;
use Webkul\Automation\Http\Middleware\AutomationApiBoundary;
use Webkul\Theme\ViewRenderEventManager;

class AutomationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/admin.php');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'automation');

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('auth.api-token', AuthenticateApiToken::class);
        $router->aliasMiddleware('automation.api-boundary', AutomationApiBoundary::class);

        Event::listen(
            'bagisto.admin.catalog.product.edit.form.after',
            static fn (ViewRenderEventManager $manager) => $manager->addTemplate('automation::admin.products.mappings-panel'),
        );
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);

        $this->mergeConfigFrom(__DIR__.'/../Config/menu.php', 'menu.admin');
    }
}
