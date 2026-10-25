<?php

namespace Bernskiold\LaravelBackgroundJobs\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * A file that a background job uses, for example an upload that it imports.
 * The package deletes the file when the job is finished or failed.
 *
 * The file is on a filesystem disk. When the disk is shared, for example S3,
 * all servers can read and delete it. A file without a disk is a local path
 * from an earlier version. Only the server that has the file can use it.
 */
final class TemporaryFile
{
    /**
     * The local copy of a file on a remote disk.
     */
    private ?string $localCopy = null;

    public function __construct(
        public readonly string $path,
        public readonly ?string $disk = null,
    ) {}

    /**
     * Read the file from the meta of a job. The "temporary_file" item has a
     * disk and a path. The "file_path" item of earlier versions has a local
     * path.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function fromMeta(?array $meta): ?self
    {
        $file = $meta['temporary_file'] ?? null;

        if (is_array($file) && is_string($file['path'] ?? null) && $file['path'] !== '') {
            return new self($file['path'], is_string($file['disk'] ?? null) ? $file['disk'] : null);
        }

        $legacyPath = $meta['file_path'] ?? null;

        return is_string($legacyPath) && $legacyPath !== '' ? new self($legacyPath) : null;
    }

    /**
     * @return array{disk: string|null, path: string}
     */
    public function toMeta(): array
    {
        return ['disk' => $this->disk, 'path' => $this->path];
    }

    public function exists(): bool
    {
        return $this->disk === null
            ? File::exists($this->path)
            : $this->filesystem()->exists($this->path);
    }

    public function delete(): void
    {
        if ($this->disk === null) {
            File::delete($this->path);

            return;
        }

        $this->filesystem()->delete($this->path);
    }

    /**
     * A local path to the file, for code that can only read local files. A
     * file on a remote disk is downloaded to the temporary directory one time.
     * Call cleanUp() to delete that copy.
     *
     * @throws RuntimeException When the file does not exist.
     */
    public function localPath(): string
    {
        if ($this->disk === null) {
            return $this->path;
        }

        $filesystem = $this->filesystem();

        if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return $filesystem->path($this->path);
        }

        if ($this->localCopy !== null && File::exists($this->localCopy)) {
            return $this->localCopy;
        }

        $stream = $filesystem->readStream($this->path);

        if ($stream === null) {
            throw new RuntimeException("The temporary file [{$this->path}] does not exist on the [{$this->disk}] disk.");
        }

        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $localCopy = tempnam(sys_get_temp_dir(), 'background-job-').($extension !== '' ? ".{$extension}" : '');

        file_put_contents($localCopy, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $this->localCopy = $localCopy;
    }

    /**
     * Delete the local copy that localPath() made. The file on the disk stays.
     */
    public function cleanUp(): void
    {
        if ($this->localCopy !== null) {
            File::delete($this->localCopy);
            $this->localCopy = null;
        }
    }

    protected function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
