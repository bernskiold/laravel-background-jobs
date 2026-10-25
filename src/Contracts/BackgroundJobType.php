<?php

namespace Bernskiold\LaravelBackgroundJobs\Contracts;

use BackedEnum;

/**
 * A kind of background job.
 *
 * Only a backed enum can implement this contract. The application lists its
 * enums in the "background-jobs.types" config, and a package can add its own
 * with BackgroundJobs::registerTypes(). The database stores the backing value.
 */
interface BackgroundJobType extends BackedEnum
{
    /**
     * The name of the job type that people see.
     */
    public function label(): string;
}
