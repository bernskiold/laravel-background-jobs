<?php

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->owner = $this->user();
    $this->other = $this->user('Grace Hopper');

    Gate::define('view', fn ($user, BackgroundJob $job) => $job->isOwnedBy($user));
    Gate::define('viewAny', fn ($user) => $user->name === 'Ada Lovelace');

    /** @var Broadcaster $broadcaster */
    $broadcaster = Broadcast::driver();
    $this->channels = $broadcaster->getChannels();
});

it('registers the three channels', function () {
    expect($this->channels->keys()->all())->toContain(
        'background-job-status.{id}',
        'background-job-run.{id}',
        'background-job-changes',
    );
});

it('lets the owner listen to a job and its run', function () {
    $job = BackgroundJob::factory()->for($this->owner)->create();

    expect(($this->channels->get('background-job-status.{id}'))($this->owner, $job->id))->toBeTrue()
        ->and(($this->channels->get('background-job-run.{id}'))($this->owner, $job->id))->toBeTrue()
        ->and(($this->channels->get('background-job-status.{id}'))($this->other, $job->id))->toBeFalse()
        ->and(($this->channels->get('background-job-status.{id}'))($this->owner, 999999))->toBeFalse();
});

it('lets a user who can see the list listen to the shared channel', function () {
    expect(($this->channels->get('background-job-changes'))($this->owner))->toBeTrue()
        ->and(($this->channels->get('background-job-changes'))($this->other))->toBeFalse();
});
