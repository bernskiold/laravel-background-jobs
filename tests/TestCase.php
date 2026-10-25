<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\LaravelBackgroundJobsServiceProvider;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BackgroundJobs::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            LaravelBackgroundJobsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('background-jobs.types', [JobType::class]);
        $app['config']->set('background-jobs.logs.path', sys_get_temp_dir().'/laravel-background-jobs-tests');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function user(string $name = 'Ada Lovelace'): User
    {
        return User::query()->create(['name' => $name, 'email' => str($name)->slug().'@example.com']);
    }
}
