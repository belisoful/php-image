<?php

use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFFImage;

/**
 * Unit tests for the streaming (lazy) TIFF path: {@see TIFFImage::fromStreamLazy()} scans
 * the metadata by seeking and keeps the strip/tile pixel data as deferred ranges into the
 * source, and {@see TIFFImage::streamTo()} copies the strips straight through with their
 * offsets rewritten — a metadata edit rewrites the file without holding its pixels.
 */
class TIFFStreamingTest extends PHPUnit\Framework\TestCase
{
	private function gd(int $w = 24, int $h = 16): \GdImage
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		return $gd;
	}

	private function tiffBytes(): string
	{
		return (string) TIFFImage::fromImage($this->gd())->toBinary();
	}

	public function testStreamingProducesTheSameBytesAsAWholeParseCompose()
	{
		$bytes = $this->tiffBytes();
		$source = TestIOHelper::dataResource($bytes);
		$lazy = TIFFImage::fromStreamLazy($source);
		self::assertSame([24, 16], [$lazy->getWidth(), $lazy->getHeight()]);

		$target = TestIOHelper::memoryResource();
		$written = $lazy->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex((string) TIFFImage::fromString($bytes)->toBinary()), bin2hex($out), 'streamed == whole-parse compose');
		fclose($source);
		fclose($target);
	}

	public function testAStreamedTiffComposesByMaterializingItsDeferredStrips()
	{
		$bytes = $this->tiffBytes();
		$source = TestIOHelper::dataResource($bytes);
		$lazy = TIFFImage::fromStreamLazy($source);
		self::assertSame(
			bin2hex((string) TIFFImage::fromString($bytes)->toBinary()),
			bin2hex((string) $lazy->toBinary()),
			'toBinary() materializes the deferred strips',
		);
		fclose($source);
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->tiffBytes();
		$source = TestIOHelper::dataResource($bytes);
		$tiff = TIFFImage::fromStreamLazy($source);

		$profile = ICCProfileBuilder::sRgb();
		$tiff->setICCProfile($profile);

		$target = TestIOHelper::memoryResource();
		$tiff->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);

		$round = TIFFImage::fromString($out);
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()), 'the ICC edit landed');
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
		self::assertInstanceOf(\GdImage::class, $round->getImage(), 'the strips decode to a raster');
	}

	public function testStreamingZeroFillsWordAlignmentGaps()
	{
		// An odd-length out-of-line value (5 bytes) forces the next allocation to word-align,
		// leaving a one-byte gap the stream must zero-fill to keep the strips on their offsets.
		$t = TIFFImage::fromImage($this->gd());
		$t->getEXIF()->getIfd0()->setTagValues(270, TIFFDataType::Ascii, 'abcde');   // 5 bytes, out-of-line and odd
		$bytes = (string) $t->toBinary();

		$source = TestIOHelper::dataResource($bytes);
		$target = TestIOHelper::memoryResource();
		TIFFImage::fromStreamLazy($source)->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);
		self::assertSame(bin2hex((string) TIFFImage::fromString($bytes)->toBinary()), bin2hex($out));
	}

	public function testStreamingAFreshTiffWritesItsDirectBytes()
	{
		// No parsed EXIF document: streamTo falls back to the raw source bytes (empty here).
		$target = TestIOHelper::memoryResource();
		$written = (new TIFFImage())->streamTo($target);
		fclose($target);
		self::assertSame(0, $written);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		self::expectException(\RuntimeException::class);
		TIFFImage::fromStreamLazy(new TestNonSeekableStream($this->tiffBytes()));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotTiff()
	{
		self::expectException(\UnexpectedValueException::class);
		TIFFImage::fromStreamLazy(TestIOHelper::dataResource('this is not a TIFF file at all'));
	}
}
