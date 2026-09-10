<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scheduling\RecommendationPlanBackfillService;
use Illuminate\Console\Command;

final class BackfillRecommendationPlansCommand extends Command
{
    protected $signature = 'scheduling:backfill-recommendation-plans {--id= : Backfill one recommendation}';

    protected $description = 'Backfill canonical schedule plans into recommendation payloads.';

    public function handle(RecommendationPlanBackfillService $backfill): int
    {
        $result = $backfill->backfill($this->option('id') !== null ? (int) $this->option('id') : null);
        $this->info("Updated: {$result['updated']}; skipped: {$result['skipped']}; failed: ".count($result['failed']));
        if ($result['failed'] !== []) {
            $this->warn('Regeneration required for recommendation IDs: '.implode(', ', $result['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
