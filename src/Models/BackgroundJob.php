<?php

namespace Bernskiold\LaravelBackgroundJobs\Models;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Casts\AsBackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Database\Factories\BackgroundJobFactory;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Logging\LogEntry;
use Bernskiold\LaravelBackgroundJobs\Support\TemporaryFile;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * One unit of queued work that a person can follow.
 *
 * A job can have a parent. Then it is one item of a bulk run. The run is the
 * parent job, and it reports one outcome for all of its children. Use
 * {@see BackgroundJobRun} to make a run.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string|null $jobable_type
 * @property int|string|null $jobable_id
 * @property int|string|null $user_id
 * @property BackgroundJobType|null $type
 * @property BackgroundJobStatus $status
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $meta
 * @property int|null $children_expected
 * @property int $children_succeeded
 * @property int $children_failed
 * @property int|null $progress_total
 * @property int $progress_processed
 * @property Carbon|null $sealed_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $rolled_up_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $jobable
 * @property-read Model|null $user
 * @property-read static|null $parent
 */
class BackgroundJob extends Model
{
    /** @use HasFactory<BackgroundJobFactory> */
    use HasFactory, Prunable;

    protected $table = 'background_jobs';

    protected $guarded = [];

    protected $attributes = [
        'status' => BackgroundJobStatus::Waiting->value,
        'children_succeeded' => 0,
        'children_failed' => 0,
        'progress_processed' => 0,
    ];

    /**
     * The package registers the status event here, and not in booted(). Then a
     * model that extends this one can use booted() without side effects.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updated(function (BackgroundJob $job) {
            if (! $job->wasChanged('status')) {
                return;
            }

            $from = $job->getOriginal('status');

            event(new BackgroundJobChangedStatus(
                backgroundJobId: $job->getKey(),
                from: $from instanceof BackgroundJobStatus ? $from : BackgroundJobStatus::tryFrom((int) $from),
                to: $job->status,
                parentId: $job->parent_id,
            ));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AsBackgroundJobType::class,
            'status' => BackgroundJobStatus::class,
            'meta' => 'array',
            'children_expected' => 'integer',
            'children_succeeded' => 'integer',
            'children_failed' => 'integer',
            'progress_total' => 'integer',
            'progress_processed' => 'integer',
            'sealed_at' => 'datetime',
            'finished_at' => 'datetime',
            'rolled_up_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BackgroundJobFactory
    {
        return BackgroundJobFactory::new();
    }

    /**
     * Make a job for a model. The job is waiting until you call start().
     *
     * The owner is the user you give. If you do not give a user, the owner is
     * the owner of the parent run, and then the user who is logged in. A
     * queued listener often has no logged-in user, so the parent is the
     * fallback for children. A job without an owner sends no notification.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function generateFor(
        ?Model $model,
        BackgroundJobType $type,
        array $meta = [],
        int|string|null $userId = null,
        bool $withoutOwner = false,
        ?self $parent = null,
    ): static {
        return static::query()->create([
            'parent_id' => $parent?->getKey(),
            'jobable_id' => $model?->getKey(),
            'jobable_type' => $model?->getMorphClass(),
            'type' => $type,
            'user_id' => $withoutOwner ? null : ($userId ?? $parent->user_id ?? auth()->id()),
            'meta' => $meta,
        ]);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function jobable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(BackgroundJobs::userModel());
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * The URL of the page for this job. The package does not have routes, so
     * the value comes from BackgroundJobs::resolveUrlUsing(). You can also
     * override this method in your own model.
     */
    public function url(): ?string
    {
        return BackgroundJobs::urlFor($this);
    }

    /**
     * The policy ability that lets a user see this job.
     */
    public function viewAbility(): string
    {
        return 'view';
    }

    /**
     * The policy ability that lets a user change this job, for example to
     * mark it as failed.
     */
    public function updateAbility(): string
    {
        return 'update';
    }

    /**
     * The name of the type. A job with an unknown type shows its stored value.
     */
    public function typeLabel(): string
    {
        if ($this->type !== null) {
            return $this->type->label();
        }

        return Str::headline((string) $this->getRawOriginal('type'));
    }

    /**
     * The name of this job in a list: the "label" item of its meta, the name
     * of its model, or its ID.
     */
    public function label(): string
    {
        return $this->meta['label']
            ?? $this->jobable?->getAttribute('name')
            ?? __('Job #:id', ['id' => $this->getKey()]);
    }

    /**
     * A job can live longer than its model. When the model is deleted, the
     * label shows the type and the ID that the job recorded.
     */
    public function jobableLabel(): ?string
    {
        if (! $this->jobable_id) {
            return null;
        }

        $type = Str::headline(class_basename((string) $this->jobable_type));

        return $type.': '.($this->jobable?->getAttribute('name') ?? $this->jobable_id);
    }

    /**
     * The URL of the model of the job. The value is null when the model is
     * deleted or when the model does not have a url() method.
     */
    public function jobableUrl(): ?string
    {
        $jobable = $this->jobable;

        if ($jobable === null || ! method_exists($jobable, 'url')) {
            return null;
        }

        return $jobable->url();
    }

    /**
     * A run is a parent job. It divides a bulk operation into children and
     * reports one outcome.
     */
    public function isRun(): bool
    {
        return $this->children_expected !== null || $this->children()->exists();
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * A system job has no owner. It belongs to nobody.
     */
    public function isOwnedBy(Authenticatable $user): bool
    {
        return $this->user_id !== null && (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    public function isSealed(): bool
    {
        return $this->sealed_at !== null;
    }

    public function settledChildren(): int
    {
        return $this->children_succeeded + $this->children_failed;
    }

    /**
     * The progress in percent. A run counts its settled children. A standalone
     * job counts the progress that it reports with advanceProgress().
     */
    public function progressPercentage(): int
    {
        if ($this->children_expected) {
            return (int) floor(($this->settledChildren() / $this->children_expected) * 100);
        }

        if ($this->progress_total) {
            return (int) min(100, floor(($this->progress_processed / $this->progress_total) * 100));
        }

        return $this->status->hasFinishedRunning() ? 100 : 0;
    }

    /**
     * Tells if the job reports its own progress.
     */
    public function hasProgress(): bool
    {
        return $this->progress_total !== null;
    }

    /**
     * Set the number of items that the job will process.
     */
    public function setProgressTotal(?int $total): static
    {
        $this->update(['progress_total' => $total]);

        return $this;
    }

    /**
     * Add processed items to the progress. The database adds the number, so
     * parallel workers do not lose an update.
     */
    public function advanceProgress(int $by = 1): static
    {
        if ($by === 0) {
            return $this;
        }

        BackgroundJobs::table()
            ->where('id', $this->getKey())
            ->update([
                'progress_processed' => BackgroundJobs::table()->raw('progress_processed + '.(int) $by),
                'updated_at' => now(),
            ]);

        $this->progress_processed += $by;
        $this->syncOriginalAttribute('progress_processed');

        return $this;
    }

    /**
     * Mark a waiting job as processing. A job that is not waiting does not
     * change.
     */
    public function start(): static
    {
        if ($this->status !== BackgroundJobStatus::Waiting) {
            return $this;
        }

        $this->update([
            'status' => BackgroundJobStatus::Processing,
        ]);

        return $this;
    }

    public function finish(): static
    {
        $this->flushLog();

        $this->update([
            'status' => BackgroundJobStatus::Finished,
            'finished_at' => now(),
        ]);

        return $this;
    }

    public function fail(?string $reason = null): static
    {
        $this->flushLog();

        $this->update([
            'status' => BackgroundJobStatus::Failed,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);

        return $this;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForModel(Builder $query, Model|int|string $model, ?string $modelType = null): void
    {
        if ($model instanceof Model) {
            $modelType = $model->getMorphClass();
            $model = $model->getKey();
        }

        $query->where($query->qualifyColumn('jobable_id'), $model)
            ->where($query->qualifyColumn('jobable_type'), $modelType);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOfType(Builder $query, BackgroundJobType|string $type): void
    {
        $query->where($query->qualifyColumn('type'), $type instanceof BackgroundJobType ? $type->value : $type);
    }

    /**
     * The children of one or more runs.
     *
     * @param  Builder<static>  $query
     * @param  self|int|iterable<int, self|int>  $parents
     */
    public function scopeForParents(Builder $query, self|int|iterable $parents): void
    {
        $ids = collect(is_iterable($parents) ? $parents : [$parents])
            ->map(fn (self|int $parent) => $parent instanceof self ? $parent->getKey() : $parent)
            ->all();

        $query->whereIn($query->qualifyColumn('parent_id'), $ids);
    }

    /**
     * Standalone jobs and runs. The children of a run are not included.
     *
     * @param  Builder<static>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('parent_id'));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOwnedBy(Builder $query, Authenticatable|int|string|null $user): void
    {
        $query->where(
            $query->qualifyColumn('user_id'),
            $user instanceof Authenticatable ? $user->getAuthIdentifier() : $user,
        );
    }

    /**
     * The jobs of the user who is logged in.
     *
     * @param  Builder<static>  $query
     */
    public function scopeMine(Builder $query): void
    {
        $query->where($query->qualifyColumn('user_id'), auth()->id());
    }

    /**
     * Runs that are sealed but are not at a final status.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnsettledRuns(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('sealed_at'))
            ->whereIn($query->qualifyColumn('status'), BackgroundJobStatus::running());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithStatus(Builder $query, BackgroundJobStatus ...$statuses): void
    {
        $query->whereIn($query->qualifyColumn('status'), $statuses);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), BackgroundJobStatus::Waiting);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeProcessing(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), BackgroundJobStatus::Processing);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeFinished(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), BackgroundJobStatus::Finished);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), BackgroundJobStatus::Failed);
    }

    /**
     * Jobs that are waiting or processing.
     *
     * @param  Builder<static>  $query
     */
    public function scopeNotFinished(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), BackgroundJobStatus::running());
    }

    /**
     * Jobs that started in the stale window.
     *
     * When a worker stops during a job, nothing changes the status of the job.
     * The job then stays at "processing". A page that shows "still running"
     * must stop to trust such a job after some time.
     *
     * @param  Builder<static>  $query
     */
    public function scopeStartedRecently(Builder $query): void
    {
        $query->where($query->qualifyColumn('created_at'), '>=', now()->subHours(BackgroundJobs::staleAfterHours()));
    }

    /**
     * A logger that writes to the log of this job. The log store in the config
     * decides where the log is kept.
     */
    public function logChannel(): LoggerInterface
    {
        return BackgroundJobs::logs()->logger($this->getKey());
    }

    /**
     * Write the buffered log entries of this job to the log store.
     */
    public function flushLog(): void
    {
        BackgroundJobs::logs()->flush($this->getKey());
    }

    public function hasLog(): bool
    {
        $this->flushLog();

        return BackgroundJobs::logs()->store()->exists($this->getKey());
    }

    /**
     * The latest log entries, oldest first. The "logs.display_limit" config
     * limits the number of entries when you do not give a limit.
     *
     * @param  string|null  $level  Only entries of this level, for example "error". The value "all" gives all levels.
     * @return list<LogEntry>
     */
    public function logEntries(?string $level = null, ?int $limit = null): array
    {
        $this->flushLog();

        return BackgroundJobs::logs()->store()->read(
            jobId: $this->getKey(),
            level: $level === 'all' ? null : $level,
            limit: $limit ?? (int) config('background-jobs.logs.display_limit', 5000),
        );
    }

    /**
     * The latest log entries as text, one line for each entry.
     */
    public function getLogContents(): string
    {
        return $this->getLogContentsForLevel('all');
    }

    /**
     * The log lines of one level, for example "error". The value "all" gives
     * all the lines.
     */
    public function getLogContentsForLevel(string $level = 'all'): string
    {
        return implode("\n", array_map(
            fn (LogEntry $entry) => $entry->format(),
            $this->logEntries(strtolower($level)),
        ));
    }

    /**
     * The file that the job uses, for example an upload that it imports. The
     * package deletes the file when the job is finished or failed.
     */
    public function temporaryFile(): ?TemporaryFile
    {
        return TemporaryFile::fromMeta($this->meta);
    }

    /**
     * Record the file that the job uses. Use a disk that all servers can read,
     * so that any worker can process the job.
     */
    public function setTemporaryFile(string $path, ?string $disk = null): static
    {
        return $this->updateMetaItem('temporary_file', (new TemporaryFile($path, $disk))->toMeta());
    }

    /**
     * The settled jobs that are older than the retention in the config. The
     * query only finds roots. The database deletes the children of a run with
     * the run.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('background-jobs.retention.days');
        $failedDays = config('background-jobs.retention.failed_days', $days);

        $query = static::query()->roots()->withStatus(...BackgroundJobStatus::settled());

        if ($days === null && $failedDays === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($days, $failedDays) {
            $query->when($days !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('status', '!=', BackgroundJobStatus::Failed)
                ->where('created_at', '<', now()->subDays((int) $days))));

            $query->when($failedDays !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('status', BackgroundJobStatus::Failed)
                ->where('created_at', '<', now()->subDays((int) $failedDays))));
        });
    }

    /**
     * Delete the logs and the temporary files of the job and its children. A
     * database log store also deletes the logs with the foreign key, but a
     * file log store needs this step.
     */
    protected function pruning(): void
    {
        $store = BackgroundJobs::logs()->store();

        foreach ([$this->getKey(), ...$this->children()->pluck('id')->all()] as $jobId) {
            $store->delete($jobId);
        }

        $this->temporaryFile()?->delete();
    }

    public function updateMetaItem(string $key, mixed $value): static
    {
        $meta = $this->meta ?? [];
        $meta[$key] = $value;

        $this->update([
            'meta' => $meta,
        ]);

        return $this;
    }

    /**
     * Add an amount to a number in the meta. The number starts at 0.
     *
     * The method locks the row. Then parallel jobs that increment the same
     * number do not lose an increment.
     */
    public function incrementMetaItem(string $key, int $amount = 1): static
    {
        $this->getConnection()->transaction(function () use ($key, $amount) {
            $job = static::query()->lockForUpdate()->findOrFail($this->getKey());

            $meta = $job->meta ?? [];
            $meta[$key] = ($meta[$key] ?? 0) + $amount;

            $job->update(['meta' => $meta]);
        });

        return $this->refresh();
    }

    /**
     * Add a value to a list in the meta. The list starts empty.
     *
     * The method locks the row. Then parallel jobs that add to the same list
     * do not lose a value.
     */
    public function appendMetaArrayItem(string $key, mixed $value): static
    {
        $this->getConnection()->transaction(function () use ($key, $value) {
            $job = static::query()->lockForUpdate()->findOrFail($this->getKey());

            $meta = $job->meta ?? [];
            $meta[$key] = [...($meta[$key] ?? []), $value];

            $job->update(['meta' => $meta]);
        });

        return $this->refresh();
    }
}
