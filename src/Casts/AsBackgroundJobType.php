<?php

namespace Bernskiold\LaravelBackgroundJobs\Casts;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts the stored type to a case of one of the registered type enums.
 *
 * The cast is not tied to one enum, so different domains and packages can each
 * have an enum of their own.
 *
 * @implements CastsAttributes<BackgroundJobType|null, BackgroundJobType|string|null>
 */
class AsBackgroundJobType implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackgroundJobType
    {
        return BackgroundJobs::resolveType($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof BackgroundJobType) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
