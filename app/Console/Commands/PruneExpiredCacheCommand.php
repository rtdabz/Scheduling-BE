<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

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

            if ($expiresAt === null) {
                continue;
            }

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
