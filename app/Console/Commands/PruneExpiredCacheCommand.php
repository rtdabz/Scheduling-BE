<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Garbage-collects the "file" cache store.
 *
 * Laravel's file driver only evicts an expired entry when that exact key is
 * requested again. ApiCache invalidates by bumping a version counter into the
 * key, so a superseded key is never requested again and its file is orphaned
 * permanently. Left alone this grows without bound — an audit of this project
 * found 71.4 MB of expired entries against 0.3 MB of live ones.
 *
 * Redis/Memcached expire entries themselves and need none of this, so the
 * command is a no-op on those stores.
 */
class PruneExpiredCacheCommand extends Command
{
    protected $signature = 'cache:prune-expired
                            {--store= : Cache store to prune (defaults to the configured default)}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete expired entries from the file cache store, which has no eviction of its own.';

    public function handle(): int
    {
        $storeName = $this->option('store') ?: Config::get('cache.default');
        $driver = Config::get("cache.stores.{$storeName}.driver");

        if ($driver !== 'file') {
            $this->info("Cache store [{$storeName}] uses the [{$driver}] driver, which expires entries itself. Nothing to prune.");

            return self::SUCCESS;
        }

        $path = Config::get("cache.stores.{$storeName}.path");

        if (! is_dir($path)) {
            $this->info("Cache directory [{$path}] does not exist. Nothing to prune.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = time();
        $deleted = 0;
        $reclaimed = 0;
        $kept = 0;
        $keptBytes = 0;

        foreach (Finder::create()->files()->in($path) as $file) {
            /** @var SplFileInfo $file */
            $expiresAt = $this->expiryOf($file->getPathname());

            // A file we cannot read an expiry from is either mid-write by another
            // request or corrupt. Leave it; the next writer will overwrite it.
            if ($expiresAt === null) {
                continue;
            }

            // Laravel writes an expiry of 9999999999 for "forever" entries.
            if ($expiresAt > $now) {
                $kept++;
                $keptBytes += $file->getSize();

                continue;
            }

            $size = $file->getSize();

            if ($dryRun || @unlink($file->getPathname())) {
                $deleted++;
                $reclaimed += $size;
            }
        }

        $this->removeEmptyDirectories($path, $dryRun);

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info(sprintf(
            '%s %d expired entries (%s). Kept %d live entries (%s).',
            $verb,
            $deleted,
            $this->humanBytes($reclaimed),
            $kept,
            $this->humanBytes($keptBytes),
        ));

        return self::SUCCESS;
    }

    /**
     * The file store writes the expiry as the first 10 bytes of each file.
     */
    private function expiryOf(string $path): ?int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 10);
        fclose($handle);

        if ($header === false || strlen($header) !== 10 || ! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    private function removeEmptyDirectories(string $path, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $directories = iterator_to_array(
            Finder::create()->directories()->in($path)->depth('>= 0'),
            false,
        );

        // Deepest first, so a parent emptied by its children is removed too.
        usort($directories, fn ($a, $b) => substr_count($b->getPathname(), DIRECTORY_SEPARATOR)
            <=> substr_count($a->getPathname(), DIRECTORY_SEPARATOR));

        foreach ($directories as $directory) {
            @rmdir($directory->getPathname());
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }
}
