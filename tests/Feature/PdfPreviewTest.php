<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PdfPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.3\npreview test\n%%EOF";

    private function upload(?string $token, string|array $filename = 'class-schedule-CIT.pdf', string $pdf = self::PDF): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/pdf-previews?'.http_build_query(['filename' => $filename]), [], [], [], [
            'CONTENT_TYPE' => 'application/pdf',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token ? 'Bearer '.$token : '',
        ], $pdf);
    }

    private function fetchPreview(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($url);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'vpaa']);
    }

    public function test_native_preview_serves_original_bytes_with_an_inline_filename(): void
    {
        $token = $this->user()->createToken('browser')->plainTextToken;
        $result = $this->upload($token)->assertCreated()->json();
        $this->assertStringNotContainsString($token, $result['url']);
        $this->assertStringContainsString('/class-schedule-CIT.pdf?', $result['url']);
        $this->assertStringNotContainsString($token, Cache::get('pdf-preview:'.$result['id']));

        $this->fetchPreview($result['url'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename=class-schedule-CIT.pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertContent(self::PDF);
        // The viewer can request the same bytes again when its arrow is clicked.
        $this->fetchPreview($result['url'])->assertOk();
    }

    public function test_preview_requires_authentication_and_the_existing_view_capability(): void
    {
        $this->upload(null)->assertUnauthorized();
        $user = $this->user();
        $user->syncRoles([]);
        $this->upload($user->createToken('browser')->plainTextToken)->assertForbidden();
    }

    public function test_a_tampered_or_expired_url_cannot_read_a_preview(): void
    {
        $url = $this->upload($this->user()->createToken('browser')->plainTextToken)->assertCreated()->json('url');
        $this->fetchPreview(str_replace('class-schedule-CIT.pdf', 'other.pdf', $url))->assertForbidden();
        $this->fetchPreview(explode('?', $url)[0])->assertForbidden();
        $this->travel(31)->minutes();
        $this->fetchPreview($url)->assertForbidden();
    }

    public function test_logout_or_replaced_session_revokes_preview_access(): void
    {
        $user = $this->user();
        $token = $user->createToken('browser');
        $url = $this->upload($token->plainTextToken)->assertCreated()->json('url');
        $token->accessToken->delete();
        $this->fetchPreview($url)->assertUnauthorized();
    }

    public function test_the_original_login_expiry_also_expires_preview_access(): void
    {
        $token = $this->user()->createToken('browser', ['*'], now()->addMinute());
        $url = $this->upload($token->plainTextToken)->assertCreated()->json('url');
        $this->travel(2)->minutes();
        $this->fetchPreview($url)->assertUnauthorized();
    }

    public function test_disabled_accounts_cannot_read_existing_previews(): void
    {
        $user = $this->user();
        $url = $this->upload($user->createToken('browser')->plainTextToken)->assertCreated()->json('url');
        $user->update(['is_active' => false]);
        $this->fetchPreview($url)->assertForbidden();
    }

    public function test_removing_the_view_permission_blocks_existing_previews(): void
    {
        $user = $this->user();
        $url = $this->upload($user->createToken('browser')->plainTextToken)->assertCreated()->json('url');
        $user->syncRoles([]);
        $this->fetchPreview($url)->assertForbidden();
    }

    public function test_only_the_owner_can_release_a_preview(): void
    {
        $token = $this->user()->createToken('browser')->plainTextToken;
        $preview = $this->upload($token)->assertCreated()->json();
        $other = $this->user()->createToken('other')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/pdf-previews/'.$preview['id'], [], ['Authorization' => 'Bearer '.$other])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/pdf-previews/'.$preview['id'], [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
        $this->fetchPreview($preview['url'])->assertNotFound();
    }

    public function test_pdf_content_and_filename_are_validated(): void
    {
        $token = $this->user()->createToken('browser')->plainTextToken;
        $this->upload($token, 'report.pdf', '<html>not a PDF</html>')->assertUnprocessable();
        foreach (['../report.pdf', 'folder\\report.pdf', "name\r\nInjected.pdf", 'report.html', 'bad:name.pdf', ['report.pdf']] as $filename) {
            $this->upload($token, $filename)->assertUnprocessable()->assertJsonValidationErrors('filename');
        }
        $this->upload($token, 'report.pdf', '%PDF-'.str_repeat('x', 10 * 1024 * 1024))->assertStatus(413);
    }

    public function test_unicode_filenames_have_a_valid_content_disposition(): void
    {
        $url = $this->upload($this->user()->createToken('browser')->plainTextToken, "instructor-Pe\u{00f1}a.pdf")
            ->assertCreated()->json('url');
        $response = $this->fetchPreview($url)->assertOk();
        $this->assertStringContainsString("filename*=utf-8''instructor-Pe%C3%B1a.pdf", $response->headers->get('Content-Disposition'));
    }

    public function test_each_user_has_at_most_five_cached_previews(): void
    {
        $token = $this->user()->createToken('browser')->plainTextToken;
        $oldest = $this->upload($token)->assertCreated()->json();
        for ($index = 0; $index < 5; $index++) {
            $latest = $this->upload($token)->assertCreated()->json();
        }
        $this->assertNull(Cache::get('pdf-preview:'.$oldest['id']));
        $this->fetchPreview($oldest['url'])->assertNotFound();
        $this->fetchPreview($latest['url'])->assertOk();
    }
}
