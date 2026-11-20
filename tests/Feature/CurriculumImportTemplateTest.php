<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Services\Curriculum\CurriculumImportTemplate;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class CurriculumImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** The placeholder box in the template: 72 px at 9525 EMU per px. */
    private const BOX = 685800;

    private Departments $department;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Departments::create(['department_name' => 'Criminal Justice Education', 'department_code' => 'CJE']);
        // curriculum.manage waits for the department's first program.
        Program::create(['department_id' => $this->department->id, 'code' => 'BSCrim', 'name' => 'Criminology']);
        $this->secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
        $this->secretary->assignRole('secretary');
        $this->secretary = $this->secretary->fresh();
    }

    private function image(string $type, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 123, 52));
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image, null, 95),
            'webp' => imagewebp($image, null, 100),
        };

        return (string) ob_get_clean();
    }

    private function useLogo(string $type, string $bytes): void
    {
        $this->department->forceFill(['logo' => "data:image/{$type};base64,".base64_encode($bytes)])->save();
    }

    private function download(): TestResponse
    {
        return $this->actingAs($this->secretary)->get('/api/curriculum-import-template')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="curriculum-import-template-cje.xlsx"');
    }

    /** @return array{zip: ZipArchive, path: string} */
    private function open(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'citt');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The download is not a valid xlsx package.');

        return ['zip' => $zip, 'path' => $path];
    }

    /** Every picture in the drawing: name => [media path, cx, cy]. */
    private function pictures(ZipArchive $zip): array
    {
        $drawing = new DOMDocument;
        $drawing->loadXML($zip->getFromName('xl/drawings/drawing1.xml'));
        $rels = new DOMDocument;
        $rels->loadXML($zip->getFromName('xl/drawings/_rels/drawing1.xml.rels'));
        $x = new DOMXPath($drawing);
        $x->registerNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
        $x->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $x->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $r = new DOMXPath($rels);
        $r->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $pictures = [];
        foreach ($x->query('//xdr:oneCellAnchor') as $anchor) {
            $name = $x->query('xdr:pic/xdr:nvPicPr/xdr:cNvPr/@name', $anchor)->item(0)->nodeValue;
            $embed = $x->query('xdr:pic/xdr:blipFill/a:blip/@r:embed', $anchor)->item(0)->nodeValue;
            $target = $r->query('//rel:Relationship[@Id="'.$embed.'"]/@Target')->item(0)?->nodeValue;
            $ext = $x->query('xdr:ext', $anchor)->item(0);
            $this->assertInstanceOf(DOMElement::class, $ext);
            $rect = $x->query('xdr:pic/xdr:blipFill/a:srcRect', $anchor)->item(0);
            $crop = $rect instanceof DOMElement
                ? array_map(fn ($side) => (int) $rect->getAttribute($side) / 100000, ['l' => 'l', 't' => 't', 'r' => 'r', 'b' => 'b'])
                : ['l' => 0, 't' => 0, 'r' => 0, 'b' => 0];
            $pictures[$name] = [ltrim((string) $target, '/'), (int) $ext->getAttribute('cx'), (int) $ext->getAttribute('cy'), $crop];
        }

        return $pictures;
    }

    private function assertInstitutionLogosIntact(ZipArchive $zip, array $pictures): void
    {
        $assets = base_path('../wicars-ui/src/assets');
        $this->assertArrayHasKey('TCC logo', $pictures);
        $this->assertArrayHasKey('Municipal seal', $pictures);
        // Byte-identical to the template, which embeds the app's own logo files unmodified.
        $template = new ZipArchive;
        $template->open(resource_path(CurriculumImportTemplate::TEMPLATE));
        foreach (['TCC logo', 'Municipal seal'] as $name) {
            [$media, $cx, $cy, $crop] = $pictures[$name];
            $bytes = $zip->getFromName($media);
            $this->assertNotFalse($bytes, "{$name} image is missing from the package.");
            $this->assertSame($template->getFromName($media), $bytes);
            [$width, $height] = getimagesizefromstring($bytes);
            // The shown (cropped) region keeps the image's own proportions.
            $shown = ($width * (1 - $crop['l'] - $crop['r'])) / ($height * (1 - $crop['t'] - $crop['b']));
            $this->assertEqualsWithDelta($shown, $cx / $cy, 0.02, "{$name} is distorted.");
            $this->assertSame(self::BOX, max($cx, $cy), "{$name} does not fill its logo box.");
        }
        $template->close();
        if (is_dir($assets)) {
            $this->assertSame(file_get_contents($assets.'/logo.jpg'), $zip->getFromName($pictures['TCC logo'][0]));
            $this->assertSame(file_get_contents($assets.'/municipal-logo.png'), $zip->getFromName($pictures['Municipal seal'][0]));
        }
    }

    public function test_embeds_the_department_png_logo_unmodified_and_in_proportion(): void
    {
        $logo = $this->image('png', 600, 300);
        $this->useLogo('png', $logo);

        ['zip' => $zip, 'path' => $path] = $this->open($this->download());
        $pictures = $this->pictures($zip);

        [$media, $cx, $cy] = $pictures['Department logo'];
        $this->assertSame('xl/media/department-logo.png', $media);
        $this->assertSame($logo, $zip->getFromName($media));
        $this->assertSame([self::BOX, self::BOX / 2], [$cx, $cy]);
        $this->assertFalse($zip->getFromName('xl/media/image3.png'), 'The placeholder image was left behind.');
        $this->assertStringContainsString('Extension="png" ContentType="image/png"', $zip->getFromName('[Content_Types].xml'));
        $this->assertInstitutionLogosIntact($zip, $pictures);

        $zip->close();
        unlink($path);
    }

    public function test_embeds_a_jpeg_logo_and_converts_webp_losslessly_at_full_resolution(): void
    {
        $jpeg = $this->image('jpeg', 200, 400);
        $this->useLogo('jpeg', $jpeg);
        ['zip' => $zip, 'path' => $path] = $this->open($this->download());
        [$media, $cx, $cy] = $this->pictures($zip)['Department logo'];
        $this->assertSame('xl/media/department-logo.jpeg', $media);
        $this->assertSame($jpeg, $zip->getFromName($media));
        $this->assertSame([self::BOX / 2, self::BOX], [$cx, $cy]);
        $zip->close();
        unlink($path);

        $this->useLogo('webp', $this->image('webp', 512, 512));
        ['zip' => $zip, 'path' => $path] = $this->open($this->download());
        [$media, $cx, $cy] = $this->pictures($zip)['Department logo'];
        $this->assertSame('xl/media/department-logo.png', $media);
        $size = getimagesizefromstring($zip->getFromName($media));
        $this->assertSame([512, 512, 'image/png'], [$size[0], $size[1], $size['mime']]);
        $this->assertSame([self::BOX, self::BOX], [$cx, $cy]);
        $zip->close();
        unlink($path);
    }

    public function test_crops_blank_margins_without_altering_the_logo(): void
    {
        // A 100 x 50 mark centred on a 400 x 400 white canvas, like a seal exported with padding.
        $image = imagecreatetruecolor(400, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 150, 175, 249, 224, imagecolorallocate($image, 30, 123, 52));
        ob_start();
        imagepng($image);
        $logo = (string) ob_get_clean();
        $this->useLogo('png', $logo);

        ['zip' => $zip, 'path' => $path] = $this->open($this->download());
        [$media, $cx, $cy, $crop] = $this->pictures($zip)['Department logo'];

        $this->assertSame($logo, $zip->getFromName($media));
        $this->assertSame(['l' => 0.375, 't' => 0.4375, 'r' => 0.375, 'b' => 0.4375], $crop);
        $this->assertSame([self::BOX, self::BOX / 2], [$cx, $cy]);

        $zip->close();
        unlink($path);
    }

    public function test_omits_the_department_logo_cleanly_when_there_is_none(): void
    {
        ['zip' => $zip, 'path' => $path] = $this->open($this->download());
        $pictures = $this->pictures($zip);

        $this->assertSame(['TCC logo', 'Municipal seal'], array_keys($pictures));
        $this->assertFalse($zip->getFromName('xl/media/image3.png'));
        $this->assertStringNotContainsString('image3', $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels'));
        $this->assertInstitutionLogosIntact($zip, $pictures);

        $zip->close();
        unlink($path);
    }

    public function test_requires_curriculum_management(): void
    {
        $this->getJson('/api/curriculum-import-template')->assertUnauthorized();

        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);
        $dean->assignRole('dean');
        $this->actingAs($dean->fresh())->getJson('/api/curriculum-import-template')->assertForbidden();
    }
}
