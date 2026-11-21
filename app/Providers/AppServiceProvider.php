<?php

namespace App\Providers;

use App\Support\LegacyAdminAliases;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Portals\Finance\FinanceSourceStorage::class);
        $this->app->singleton(\App\Portals\Finance\FinanceExternalChanges::class);
        \Illuminate\Database\Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new \App\Portals\Finance\FinanceMySqlConnection($pdo, $database, $prefix, $config));
        \Illuminate\Database\Connection::resolverFor('mariadb', fn ($pdo, $database, $prefix, $config) => new \App\Portals\Finance\FinanceMariaDbConnection($pdo, $database, $prefix, $config));
        \Illuminate\Database\Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) => new \App\Portals\Finance\FinanceSqliteConnection($pdo, $database, $prefix, $config));
        LegacyAdminAliases::register();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
