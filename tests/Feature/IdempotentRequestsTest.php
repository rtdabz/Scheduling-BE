<?php

namespace Tests\Feature;

use App\Http\Middleware\IdempotentRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IdempotentRequestsTest extends TestCase
{
    private int $runs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runs = 0;

        Route::middleware('api')->post('/api/_test/idempotent', function () {
            $this->runs++;

            return response()->json(['run' => $this->runs], 201);
        });

        Route::middleware('api')->post('/api/_test/idempotent-conflict', function () {
            $this->runs++;

            return response()->json(['message' => 'Room is taken.'], 409);
        });
    }

    public function test_requests_without_a_key_run_every_time(): void
    {
        $this->postJson('/api/_test/idempotent', ['a' => 1])->assertCreated();
        $this->postJson('/api/_test/idempotent', ['a' => 1])->assertCreated();

        $this->assertSame(2, $this->runs);
    }

    public function test_a_resent_key_replays_the_first_outcome(): void
    {
        $headers = [IdempotentRequests::HEADER => 'key-1'];

        $first = $this->postJson('/api/_test/idempotent', ['a' => 1], $headers);
        $second = $this->postJson('/api/_test/idempotent', ['a' => 1], $headers);

        $first->assertCreated()->assertJson(['run' => 1]);
        $second->assertCreated()
            ->assertJson(['run' => 1])
            ->assertHeader(IdempotentRequests::REPLAY_HEADER, 'true');
        $this->assertSame(1, $this->runs);
    }

    public function test_reusing_a_key_for_a_different_request_is_rejected(): void
    {
        $headers = [IdempotentRequests::HEADER => 'key-2'];

        $this->postJson('/api/_test/idempotent', ['a' => 1], $headers)->assertCreated();
        $this->postJson('/api/_test/idempotent', ['a' => 2], $headers)->assertStatus(422);

        $this->assertSame(1, $this->runs);
    }

    public function test_unsuccessful_outcomes_are_not_replayed(): void
    {
        $headers = [IdempotentRequests::HEADER => 'key-3'];

        $this->postJson('/api/_test/idempotent-conflict', [], $headers)->assertStatus(409);
        $this->postJson('/api/_test/idempotent-conflict', [], $headers)->assertStatus(409);

        $this->assertSame(2, $this->runs);
    }

    public function test_a_retry_while_the_original_is_running_is_not_executed(): void
    {
        $headers = [IdempotentRequests::HEADER => 'key-4'];
        $cacheKey = 'idempotency:'.hash('sha256', 'ip:127.0.0.1|key-4');
        $lock = Cache::lock($cacheKey.':lock', 60);
        $this->assertTrue($lock->get());

        $this->postJson('/api/_test/idempotent', ['a' => 1], $headers)->assertStatus(409);
        $this->assertSame(0, $this->runs);

        $lock->release();
        $this->postJson('/api/_test/idempotent', ['a' => 1], $headers)->assertCreated();
        $this->assertSame(1, $this->runs);
    }

    public function test_keys_are_scoped_to_the_bearer_token(): void
    {
        $this->postJson('/api/_test/idempotent', ['a' => 1], [
            IdempotentRequests::HEADER => 'shared',
            'Authorization' => 'Bearer token-a',
        ])->assertCreated();

        $this->postJson('/api/_test/idempotent', ['a' => 1], [
            IdempotentRequests::HEADER => 'shared',
            'Authorization' => 'Bearer token-b',
        ])->assertCreated()->assertHeaderMissing(IdempotentRequests::REPLAY_HEADER);

        $this->assertSame(2, $this->runs);
    }
}
