<?php

namespace App\Services\Curriculum;

use App\Models\Departments;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The blank curriculum import workbook (resources/templates/curriculum-import-template.xlsx), branded for the
 * downloading department. The template already carries the TCC logo and municipal seal as the exact files the app
 * prints curricula with; its picture named "Department logo" is a placeholder that is replaced here by the
 * department's stored logo, unmodified (WebP, which Excel cannot show, is converted losslessly to PNG at the same
 * pixel size), or removed when the department has none. The logo keeps its proportions inside the placeholder box.
 */
final class CurriculumImportTemplate
{
    public const TEMPLATE = 'templates/curriculum-import-template.xlsx';

    public const PLACEHOLDER = 'Department logo';

    private const DRAWING = 'xl/drawings/drawing1.xml';

    private const DRAWING_RELS = 'xl/drawings/_rels/drawing1.xml.rels';

    private const NS = [
        'xdr' => 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
        'ct' => 'http://schemas.openxmlformats.org/package/2006/content-types',
    ];

    /** Returns the workbook bytes. */
    public function build(Departments $department): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cit');
        if ($path === false || ! copy(resource_path(self::TEMPLATE), $path)) {
            throw new RuntimeException('The curriculum import template could not be prepared.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('The curriculum import template could not be opened.');
            }
            $this->brand($zip, self::logoImage($department->rawLogo()));
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The stored logo as image bytes Excel can show: ['bytes', 'extension', 'mime', 'width', 'height'], or null when
     * the department has no usable inline logo. A logo stored as an external URL is not fetched.
     *
     * @return array{bytes: string, extension: string, mime: string, width: int, height: int, crop: array{0: int, 1: int, 2: int, 3: int}}|null
     */
    public static function logoImage(?string $stored): ?array
    {
        if ($stored === null || ! preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/s', $stored, $matches)) {
            return null;
        }
        $bytes = base64_decode($matches[2], true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        $type = $size['mime'];
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        if ($type === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            $type = 'image/png';
        }
        $logo = ['bytes' => $bytes, 'mime' => $type, 'width' => $size[0], 'height' => $size[1], 'crop' => self::visibleBox($image)];

        return match ($type) {
            'image/jpeg' => $logo + ['extension' => 'jpeg'],
            'image/png' => $logo + ['extension' => 'png'],
            default => null,
        };
    }

    /**
     * Bounds [left, top, right, bottom) of the visible pixels, by the rule the frontend's curriculum printout trims
     * logos with (alpha above 16 of 255 and not near-white), so blank margins do not shrink the logo.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private static function visibleBox(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $trueColor = imageistruecolor($image);
        [$left, $top, $right, $bottom] = [$width, $height, -1, -1];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $rgba = $trueColor ? [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF, ($pixel >> 24) & 0x7F]
                    : array_values(imagecolorsforindex($image, $pixel));
                // GD alpha runs 0 (opaque) to 127 (transparent); 255-scale alpha > 16 is GD alpha < 119.
                if ($rgba[3] < 119 && ($rgba[0] < 245 || $rgba[1] < 245 || $rgba[2] < 245)) {
                    $left = min($left, $x);
                    $top = min($top, $y);
                    $right = max($right, $x);
                    $bottom = max($bottom, $y);
                }
            }
        }

        return $right < 0 ? [0, 0, $width, $height] : [$left, $top, $right + 1, $bottom + 1];
    }

    /** @param array{bytes: string, extension: string, mime: string, width: int, height: int, crop: array{0: int, 1: int, 2: int, 3: int}}|null $logo */
    private function brand(ZipArchive $zip, ?array $logo): void
    {
        $drawing = $this->load($zip, self::DRAWING);
        $rels = $this->load($zip, self::DRAWING_RELS);
        $xpath = $this->xpath($drawing);
        $relsPath = $this->xpath($rels);

        $pic = $xpath->query('//xdr:pic[xdr:nvPicPr/xdr:cNvPr[@name="'.self::PLACEHOLDER.'"]]')->item(0);
        if (! $pic instanceof DOMElement) {
            throw new RuntimeException('The curriculum import template has no department logo placeholder.');
        }
        $anchor = $pic->parentNode;
        $embed = $xpath->query('xdr:blipFill/a:blip/@r:embed', $pic)->item(0)?->nodeValue;
        $relation = $relsPath->query('//rel:Relationship[@Id="'.$embed.'"]')->item(0);
        if (! $anchor instanceof DOMElement || ! $relation instanceof DOMElement) {
            throw new RuntimeException('The curriculum import template department logo placeholder is incomplete.');
        }
        $placeholderMedia = ltrim($relation->getAttribute('Target'), '/');
        $zip->deleteName($placeholderMedia);

        if ($logo === null) {
            $anchor->parentNode?->removeChild($anchor);
            $relation->parentNode?->removeChild($relation);
        } else {
            $media = 'xl/media/department-logo.'.$logo['extension'];
            $zip->addFromString($media, $logo['bytes']);
            $relation->setAttribute('Target', '/'.$media);
            [$left, $top, $right, $bottom] = $logo['crop'];
            $this->crop($xpath, $pic, $logo['width'], $logo['height'], $logo['crop']);
            $this->fit($xpath, $anchor, $right - $left, $bottom - $top);
            $this->registerType($zip, $logo['extension'], $logo['mime']);
        }

        $zip->addFromString(self::DRAWING, (string) $drawing->saveXML());
        $zip->addFromString(self::DRAWING_RELS, (string) $rels->saveXML());
    }

    /**
     * Shows only the visible box through Excel's picture crop (a:srcRect, in thousandths of a percent per side);
     * the embedded image itself is not altered.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box
     */
    private function crop(DOMXPath $xpath, DOMElement $pic, int $width, int $height, array $box): void
    {
        $blip = $xpath->query('xdr:blipFill/a:blip', $pic)->item(0);
        if (! $blip instanceof DOMElement) {
            return;
        }
        foreach ($xpath->query('xdr:blipFill/a:srcRect', $pic) as $old) {
            $old->parentNode?->removeChild($old);
        }
        [$left, $top, $right, $bottom] = $box;
        if ([$left, $top, $right, $bottom] === [0, 0, $width, $height]) {
            return;
        }
        $rect = $blip->ownerDocument->createElementNS(self::NS['a'], 'a:srcRect');
        foreach (['l' => $left / $width, 't' => $top / $height, 'r' => ($width - $right) / $width, 'b' => ($height - $bottom) / $height] as $side => $share) {
            $rect->setAttribute($side, (string) (int) round($share * 100000));
        }
        $blip->parentNode->insertBefore($rect, $blip->nextSibling);
    }

    /** Fits the image inside the placeholder's square box, centred, at its own aspect ratio. */
    private function fit(DOMXPath $xpath, DOMElement $anchor, int $width, int $height): void
    {
        $ext = $xpath->query('xdr:ext', $anchor)->item(0);
        $colOff = $xpath->query('xdr:from/xdr:colOff', $anchor)->item(0);
        $rowOff = $xpath->query('xdr:from/xdr:rowOff', $anchor)->item(0);
        if (! $ext instanceof DOMElement || $colOff === null || $rowOff === null) {
            throw new RuntimeException('The curriculum import template department logo placeholder has no size.');
        }
        $box = max((int) $ext->getAttribute('cx'), (int) $ext->getAttribute('cy'));
        $scale = $box / max($width, $height);
        $cx = (int) round($width * $scale);
        $cy = (int) round($height * $scale);
        $ext->setAttribute('cx', (string) $cx);
        $ext->setAttribute('cy', (string) $cy);
        $colOff->nodeValue = (string) ((int) $colOff->nodeValue + intdiv($box - $cx, 2));
        $rowOff->nodeValue = (string) ((int) $rowOff->nodeValue + intdiv($box - $cy, 2));
    }

    private function registerType(ZipArchive $zip, string $extension, string $mime): void
    {
        $types = $this->load($zip, '[Content_Types].xml');
        $xpath = $this->xpath($types);
        if ($xpath->query('//ct:Default[@Extension="'.$extension.'"]')->length > 0) {
            return;
        }
        $default = $types->createElementNS(self::NS['ct'], 'Default');
        $default->setAttribute('Extension', $extension);
        $default->setAttribute('ContentType', $mime);
        $types->documentElement?->insertBefore($default, $types->documentElement->firstChild);
        $zip->addFromString('[Content_Types].xml', (string) $types->saveXML());
    }

    private function load(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = $zip->getFromName($name);
        $document = new DOMDocument;
        if ($xml === false || ! $document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException("The curriculum import template is missing {$name}.");
        }

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        foreach (self::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }
}
