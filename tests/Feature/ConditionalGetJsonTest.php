<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ConditionalGetJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/_test/conditional', fn () => response()->json(['rooms' => [1, 2, 3]]));
    }

    private function etag(): string
    {
        return (string) $this->getJson('/api/_test/conditional')->headers->get('ETag');
    }

    public function test_an_unchanged_payload_comes_back_as_not_modified(): void
    {
        $etag = $this->etag();

        $this->getJson('/api/_test/conditional', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_a_stale_validator_gets_the_full_payload(): void
    {
        $this->getJson('/api/_test/conditional', ['If-None-Match' => '"stale"'])
            ->assertOk()
            ->assertJson(['rooms' => [1, 2, 3]]);
    }

    public function test_the_etag_suffix_added_by_server_compression_still_matches(): void
    {
        $etag = $this->etag();

        foreach (['gzip', 'br'] as $encoding) {
            $compressed = substr($etag, 0, -1).'-'.$encoding.'"';

            $this->getJson('/api/_test/conditional', ['If-None-Match' => $compressed])->assertStatus(304);
            $this->getJson('/api/_test/conditional', ['If-None-Match' => 'W/'.$compressed])->assertStatus(304);
        }
    }
}
