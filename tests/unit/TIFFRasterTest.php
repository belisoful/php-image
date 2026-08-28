<?php

use Belisoful\Image\ImageGraphics;
use Belisoful\Image\TIFFImage;

class TIFFRasterTest extends PHPUnit\Framework\TestCase
{
	private function colorImage(int $w = 40, int $h = 25): \GdImage
	{
		$im = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($im, $x, $y, (($x * 6) & 0xFF) << 16 | (($y * 9) & 0xFF) << 8 | (($x + $y) & 0xFF));
			}
		}
		return $im;
	}

	private function monoImage(int $w = 64, int $h = 16): \GdImage
	{
		$im = imagecreatetruecolor($w, $h);
		$white = imagecolorallocate($im, 255, 255, 255);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 0, 0));
		imagefilledrectangle($im, 8, 2, 40, 11, $white);
		imagesetpixel($im, 63, 15, $white);
		return $im;
	}

	public function testRgbRoundTripPerCompression()
	{
		$source = $this->colorImage();
		$expected = ImageGraphics::rgbPixels($source);
		foreach ([TIFFImage::CompressionNone, TIFFImage::CompressionLzw, TIFFImage::CompressionPackBits] as $compression) {
			$tiff = TIFFImage::fromImage($source, $compression);
			self::assertSame(40, $tiff->getWidth(), "compression $compression");
			self::assertSame(25, $tiff->getHeight());
			self::assertSame($compression, $tiff->getEXIF()->getIfd0()->getTagValue(259));

			$reparsed = TIFFImage::fromString($tiff->toBinary());
			$image = $reparsed->getImage();
			self::assertNotFalse($image, "compression $compression");
			self::assertSame(bin2hex($expected), bin2hex(ImageGraphics::rgbPixels($image)), "compression $compression");
		}
	}

	public function testBilevelRoundTripPerCompression()
	{
		$source = $this->monoImage();
		$expected = ImageGraphics::rgbPixels($source);
		foreach ([TIFFImage::CompressionCcittRle, TIFFImage::CompressionGroup3, TIFFImage::CompressionGroup4] as $compression) {
			$tiff = TIFFImage::fromImage($source, $compression);
			$reparsed = TIFFImage::fromString($tiff->toBinary());
			self::assertSame(0, $reparsed->getEXIF()->getIfd0()->getTagValue(262));   // WhiteIsZero
			$image = $reparsed->getImage();
			self::assertNotFalse($image, "compression $compression");
			self::assertSame(bin2hex($expected), bin2hex(ImageGraphics::rgbPixels($image)), "compression $compression");
		}
	}

	public function testGroup4TiffIsCompactForText()
	{
		$source = $this->monoImage(400, 100);
		$g4 = TIFFImage::fromImage($source, TIFFImage::CompressionGroup4);
		$raw = TIFFImage::fromImage($source, TIFFImage::CompressionNone);
		self::assertLessThan(strlen($raw->toBinary()) / 4, strlen($g4->toBinary()));
	}

	public function testSetImageKeepsMetadata()
	{
		$tiff = TIFFImage::fromImage($this->colorImage(), TIFFImage::CompressionNone);
		$tiff->getEXIF()->setValueByName('Make', 'PradoCam');
		$tiff->setImage($this->monoImage(), TIFFImage::CompressionGroup4);

		$reparsed = TIFFImage::fromString($tiff->toBinary());
		self::assertSame('PradoCam', $reparsed->getEXIF()->getMake());
		self::assertSame(64, $reparsed->getWidth());
		self::assertSame(TIFFImage::CompressionGroup4, $reparsed->getEXIF()->getIfd0()->getTagValue(259));
		self::assertNotFalse($reparsed->getImage());
	}

	public function testUnsupportedRasterReturnsFalse()
	{
		// Metadata-only TIFF: no strips at all.
		$exif = new Belisoful\Image\Meta\EXIF();
		$exif->setValueByName('Make', 'NoPixels');
		$exif->getIfd0()->setTagValues(TIFFImage::WidthTag, Belisoful\Image\TIFF\TIFFDataType::ULong, [10]);
		$exif->getIfd0()->setTagValues(TIFFImage::HeightTag, Belisoful\Image\TIFF\TIFFDataType::ULong, [10]);
		$tiff = TIFFImage::fromString($exif->getTiff()->toBinary());
		self::assertFalse($tiff->getImage());
	}
}
