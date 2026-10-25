<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures;

use Bernskiold\LaravelBackgroundJobs\Concerns\HasBackgroundJobs;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasBackgroundJobs;

    protected $guarded = [];

    public function url(): string
    {
        return "https://example.com/reports/{$this->getKey()}";
    }
}
