<?php

namespace Bernskiold\LaravelBackgroundJobs\Database\Factories;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * @extends Factory<BackgroundJob>
 */
class BackgroundJobFactory extends Factory
{
    /**
     * The factory makes the model from the "background-jobs.model" config, so
     * it also makes your own model when you extend the package model.
     *
     * @return class-string<BackgroundJob>
     */
    public function modelName(): string
    {
        return BackgroundJobs::model();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->defaultType(),
            'status' => BackgroundJobStatus::Waiting,
            'meta' => [],
        ];
    }

    /**
     * The first registered type. Override this method to use a different
     * default.
     *
     * @throws LogicException
     */
    protected function defaultType(): BackgroundJobType
    {
        return BackgroundJobs::types()->first()
            ?? throw new LogicException('Register a background job type before you use the factory.');
    }

    public function ofType(BackgroundJobType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function waiting(): static
    {
        return $this->state(fn () => ['status' => BackgroundJobStatus::Waiting]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => BackgroundJobStatus::Processing]);
    }

    public function finished(): static
    {
        return $this->state(fn () => [
            'status' => BackgroundJobStatus::Finished,
            'finished_at' => now(),
        ]);
    }

    public function failed(?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => BackgroundJobStatus::Failed,
            'finished_at' => now(),
            'failure_reason' => $reason ?? 'Something went wrong.',
        ]);
    }

    /**
     * A job without an owner does not send a notification.
     */
    public function withoutOwner(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    /**
     * A job that keeps its owner but sends no notification.
     */
    public function withoutNotifications(): static
    {
        return $this->state(fn () => ['notify_owner' => false]);
    }

    /**
     * A child of the run. The child gets the type and the owner of the run.
     */
    public function childOf(BackgroundJob $run): static
    {
        return $this->state(fn () => [
            'parent_id' => $run->getKey(),
            'type' => $run->type,
            'user_id' => $run->user_id,
            'notify_owner' => $run->notify_owner,
        ]);
    }
}
