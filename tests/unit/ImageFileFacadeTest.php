<?php

use Belisoful\Image\GIFImage;
use Belisoful\Image\ImageFile;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\PNGImage;
use Belisoful\Image\TIFFImage;
use Belisoful\Image\WebPImage;

/**
 * Unit tests for the format-detecting factory and the common metadata surface on the
 * {@see ImageFile} base: a factory called on the base sniffs the container from the bytes,
 * and every container exposes EXIF/XMP/IPTC/ICC the same way (null or a throw where a
 * format has no carrier).
 */
class ImageFileFacadeTest extends PHPUnit\Framework\TestCase
{
	private function gd(int $w = 6, int $h = 4): \GdImage
	{
		$image = imagecreatetruecolor($w, $h);
		imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 10, 120, 200));
		return $image;
	}

	/** Encodes a small solid image to the named raster format's bytes. */
	private function encode(string $format): string
	{
		$gd = $this->gd();
		ob_start();
		match ($format) {
			'JPEG' => imagejpeg($gd),
			'PNG' => imagepng($gd),
			'GIF' => imagegif($gd),
			'WEBP' => imagewebp($gd),
		};
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	public function testAFactoryOnTheBaseDetectsEveryFormat()
	{
		$cases = [
			[JPEGImage::class, 'JPEG', $this->encode('JPEG')],
			[PNGImage::class, 'PNG', $this->encode('PNG')],
			[GIFImage::class, 'GIF', $this->encode('GIF')],
			[WebPImage::class, 'WebP', $this->encode('WEBP')],
			[TIFFImage::class, 'TIFF', TIFFImage::fromImage($this->gd())->toBinary()],
		];
		foreach ($cases as [$class, $format, $bytes]) {
			$image = ImageFile::fromString($bytes);
			self::assertInstanceOf($class, $image, "$format detected by fromString");
			self::assertSame($format, $image->getFormat());
		}
	}

	public function testFromFileAndFromStreamOnTheBaseAlsoDetect()
	{
		$bytes = $this->encode('PNG');
		$path = tempnam(sys_get_temp_dir(), 'facade');
		file_put_contents((string) $path, $bytes);
		self::assertInstanceOf(PNGImage::class, ImageFile::fromFile((string) $path));
		@unlink((string) $path);

		self::assertInstanceOf(PNGImage::class, ImageFile::fromStream(TestIOHelper::dataResource($bytes)));
	}

	public function testFromStringRejectsUnrecognizedBytes()
	{
		// A long non-image, a string too short to carry any signature, and a RIFF container
		// that is not the WEBP form — the last two also drive the isWebP length/form branches.
		foreach (['this is not an image at all', 'xx', 'RIFF' . pack('V', 0) . 'XXXXpad'] as $garbage) {
			try {
				ImageFile::fromString($garbage);
				self::fail('An unrecognized byte string should be rejected.');
			} catch (\UnexpectedValueException $e) {
				self::assertStringContainsString('not a recognized image format', $e->getMessage());
			}
		}
	}

	public function testAConcreteContainerStaysBoundToItsFormat()
	{
		// The detection lives only on the base; a concrete container rejects another format.
		self::expectException(\RuntimeException::class);   // UnexpectedValueException is-a RuntimeException
		JPEGImage::fromString($this->encode('PNG'));
	}

	public function testExifIsCommonWithNullGetterAndThrowingSetter()
	{
		// GIF has no EXIF carrier, so the base defaults apply: null, absent, clear-is-a-no-op.
		$gif = ImageFile::fromString($this->encode('GIF'));
		self::assertNull($gif->getEXIF());
		self::assertFalse($gif->hasEXIF());
		$gif->setEXIF(null);

		// A format with no writable EXIF carrier throws on a non-null value rather than dropping
		// it — GIF (no EXIF at all) and TIFF (EXIF is the document, not replaceable wholesale).
		$tiff = ImageFile::fromString(TIFFImage::fromImage($this->gd())->toBinary());
		foreach ([$gif, $tiff] as $image) {
			try {
				$image->setEXIF(new EXIF());
				self::fail('setEXIF should throw for ' . $image->getFormat());
			} catch (\RuntimeException $e) {
				self::assertStringContainsString($image->getFormat(), $e->getMessage());
			}
		}
	}

	public function testXmpIsCommonAcrossFormats()
	{
		$png = ImageFile::fromString($this->encode('PNG'));
		self::assertFalse($png->hasXMP());
		self::assertNull($png->getXMP());
	}

}
