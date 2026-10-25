<?php

namespace Bernskiold\LaravelBackgroundJobs;

use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Exceptions\InvalidBackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * The configuration of the package, and the registry of the job types.
 */
class BackgroundJobs
{
    /**
     * The type enums that packages added at runtime.
     *
     * @var list<class-string<BackgroundJobType>>
     */
    protected static array $registeredTypes = [];

    /**
     * @var (Closure(BackgroundJob): ?string)|null
     */
    protected static ?Closure $urlResolver = null;

    /**
     * @return class-string<BackgroundJob>
     */
    public static function model(): string
    {
        return config('background-jobs.model', BackgroundJob::class);
    }

    public static function newModel(): BackgroundJob
    {
        $model = static::model();

        return new $model;
    }

    /**
     * A query on the jobs table that does not use Eloquent. It does not fire
     * model events and it does not touch "updated_at". The run roll-up uses
     * it for its atomic updates.
     */
    public static function table(): Builder
    {
        $model = static::newModel();

        return $model->getConnection()->table($model->getTable());
    }

    /**
     * @return class-string
     */
    public static function userModel(): string
    {
        return config('background-jobs.user_model') ?? config('auth.providers.users.model');
    }

    public static function staleAfterHours(): int
    {
        return (int) config('background-jobs.stale_after_hours', 6);
    }

    public static function logPath(): string
    {
        return rtrim(config('background-jobs.logs.path', storage_path('logs/background-jobs')), '/');
    }

    /**
     * Add type enums at runtime. A package uses this in its service provider
     * to add its own job types.
     *
     * @param  class-string<BackgroundJobType>  ...$enums
     *
     * @throws InvalidBackgroundJobType
     */
    public static function registerTypes(string ...$enums): void
    {
        foreach ($enums as $enum) {
            static::ensureIsTypeEnum($enum);

            if (! in_array($enum, static::$registeredTypes, true)) {
                static::$registeredTypes[] = $enum;
            }
        }
    }

    /**
     * The type enums from the config and the enums that packages added.
     *
     * @return list<class-string<BackgroundJobType>>
     */
    public static function typeEnums(): array
    {
        $enums = array_values(array_unique([
            ...config('background-jobs.types', []),
            ...static::$registeredTypes,
        ]));

        foreach ($enums as $enum) {
            static::ensureIsTypeEnum($enum);
        }

        return $enums;
    }

    /**
     * All the cases of all the type enums.
     *
     * @return Collection<int, BackgroundJobType>
     */
    public static function types(): Collection
    {
        return Collection::make(static::typeEnums())
            ->flatMap(fn (string $enum) => $enum::cases())
            ->values();
    }

    /**
     * Find the type for a value from the database. The first enum that has the
     * value wins. The result is null when no enum has the value, for example
     * when a type was removed after the job ran.
     */
    public static function resolveType(BackgroundJobType|string|null $value): ?BackgroundJobType
    {
        if ($value === null || $value instanceof BackgroundJobType) {
            return $value;
        }

        foreach (static::typeEnums() as $enum) {
            if ($type = $enum::tryFrom($value)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Set how the package finds the URL of a job page. The package does not
     * have routes of its own, so the application supplies them.
     *
     * @param  (Closure(BackgroundJob): ?string)|null  $resolver
     */
    public static function resolveUrlUsing(?Closure $resolver): void
    {
        static::$urlResolver = $resolver;
    }

    public static function urlFor(BackgroundJob $job): ?string
    {
        if (static::$urlResolver === null) {
            return null;
        }

        return (static::$urlResolver)($job);
    }

    /**
     * Remove the runtime state. Use it between tests.
     */
    public static function flush(): void
    {
        static::$registeredTypes = [];
        static::$urlResolver = null;
    }

    /**
     * @throws InvalidBackgroundJobType
     */
    protected static function ensureIsTypeEnum(string $enum): void
    {
        if (! is_subclass_of($enum, BackgroundJobType::class)) {
            throw InvalidBackgroundJobType::notATypeEnum($enum);
        }
    }
}
