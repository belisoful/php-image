<?php

use Belisoful\Image\ImageGraphicsLibraryInterface;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\ImageGraphicsGD;
use Belisoful\Image\ImageGraphicsImagick;
use Belisoful\Image\ImageGraphicsMode;
use Belisoful\Image\Meta\JFIF;
use Belisoful\Image\Meta\JFXX;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;

class ImageGraphicsTest extends PHPUnit\Framework\TestCase
{
	protected function tearDown(): void
	{
		ImageGraphics::setDefaultMode(null);
	}

	private function requireImagick(): void
	{
		if (!ImageGraphics::hasImagick()) {
			self::markTestSkipped('ext-imagick is not loaded.');
		}
	}

	private function gdImage(int $w, int $h, array $rgb = [10, 120, 200]): \GdImage
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$rgb));
		return $im;
	}

	public function testModeDetection()
	{
		self::assertSame(extension_loaded('gd'), ImageGraphics::hasGd());
		self::assertSame(extension_loaded('imagick'), ImageGraphics::hasImagick());
		self::assertSame(ImageGraphics::hasGd(), ImageGraphics::hasMode(ImageGraphicsMode::GD));
		self::assertSame(ImageGraphics::hasImagick(), ImageGraphics::hasMode(ImageGraphicsMode::Imagick));
		self::assertSame(ImageGraphics::hasGd() || ImageGraphics::hasImagick(), ImageGraphics::hasMode());
		self::assertFalse(ImageGraphics::hasMode('bogus'));
	}

	public function testDefaultMode()
	{
		self::assertSame(ImageGraphicsMode::GD, ImageGraphics::getDefaultMode());

		ImageGraphics::setDefaultMode(ImageGraphicsMode::GD);
		self::assertSame(ImageGraphicsMode::GD, ImageGraphics::getDefaultMode());

		if (ImageGraphics::hasImagick()) {
			ImageGraphics::setDefaultMode(ImageGraphicsMode::Imagick);
			self::assertSame(ImageGraphicsMode::Imagick, ImageGraphics::getDefaultMode());
		} else {
			try {
				ImageGraphics::setDefaultMode(ImageGraphicsMode::Imagick);
				self::fail('setDefaultMode accepted an unloaded library');
			} catch (\RuntimeException $e) {
			}
		}
		ImageGraphics::setDefaultMode(null);
		self::assertSame(ImageGraphicsMode::GD, ImageGraphics::getDefaultMode());

		self::expectException(\InvalidArgumentException::class);
		ImageGraphics::setDefaultMode('bogus');
	}

	public function testIsImageAndModeOf()
	{
		$im = $this->gdImage(2, 2);
		self::assertTrue(ImageGraphics::isImage($im));
		self::assertFalse(ImageGraphics::isImage('image'));
		self::assertFalse(ImageGraphics::isImage(null));
		self::assertSame(ImageGraphicsMode::GD, ImageGraphics::getModeOf($im));
	}

	public function testRgbRoundTripGd()
	{
		$rgb = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\x10\x20\x30";
		$image = ImageGraphics::fromRgbPixels($rgb, 2, 2, ImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $image);
		self::assertSame([2, 2], ImageGraphics::getSize($image));
		self::assertSame($rgb, ImageGraphics::rgbPixels($image));
	}

	public function testRgbPixelsPaletteGd()
	{
		$im = imagecreate(2, 1);
		imagecolorallocate($im, 255, 0, 0);
		$white = imagecolorallocate($im, 255, 255, 255);
		imagesetpixel($im, 1, 0, $white);
		self::assertSame("\xFF\x00\x00\xFF\xFF\xFF", ImageGraphics::rgbPixels($im));
	}

	public function testDecodeAndEncodeJpegGd()
	{
		$jpeg = ImageGraphics::encodeJpeg($this->gdImage(8, 6), 90);
		self::assertSame("\xFF\xD8", substr($jpeg, 0, 2));

		$decoded = ImageGraphics::decode($jpeg, ImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $decoded);
		self::assertSame([8, 6], ImageGraphics::getSize($decoded));

		self::assertFalse(ImageGraphics::decode('not an image'));
	}

	public function testResampledGd()
	{
		$resampled = ImageGraphics::resampled($this->gdImage(40, 20), 20, 10);
		self::assertInstanceOf(\GdImage::class, $resampled);
		self::assertSame([20, 10], ImageGraphics::getSize($resampled));
	}

	public function testMonoPixelsGd()
	{
		$im = imagecreatetruecolor(4, 2);
		imagefilledrectangle($im, 0, 0, 1, 1, imagecolorallocate($im, 0, 0, 0));
		imagefilledrectangle($im, 2, 0, 3, 1, imagecolorallocate($im, 255, 255, 255));
		$mono = ImageGraphics::monoPixels($im, false);
		self::assertSame("\x00\x00\x01\x01\x00\x00\x01\x01", $mono);
	}

	public function testPaletteQuantizeGd()
	{
		$im = imagecreatetruecolor(4, 2);
		imagefilledrectangle($im, 0, 0, 1, 1, imagecolorallocate($im, 255, 0, 0));
		imagefilledrectangle($im, 2, 0, 3, 1, imagecolorallocate($im, 0, 0, 255));
		[$palette, $pixels] = ImageGraphics::paletteQuantize($im);
		self::assertSame(768, strlen($palette));
		self::assertSame(8, strlen($pixels));
		// Every pixel's palette entry approximates its original color (GD's quantizer
		// shifts channels slightly even below the color budget).
		$expected = [[255, 0, 0], [255, 0, 0], [0, 0, 255], [0, 0, 255]];
		for ($i = 0; $i < 8; $i++) {
			$this->assertNearColor($expected[$i % 4], substr($palette, ord($pixels[$i]) * 3, 3), "pixel $i");
		}
	}

	private function assertNearColor(array $expected, string $entry, string $message, int $tolerance = 8): void
	{
		foreach ($expected as $channel => $value) {
			self::assertLessThanOrEqual($tolerance, abs($value - ord($entry[$channel])), "$message channel $channel");
		}
	}

	public function testLibraryResolution()
	{
		$gd = ImageGraphics::getLibrary(ImageGraphicsMode::GD);
		self::assertInstanceOf(ImageGraphicsGD::class, $gd);
		self::assertInstanceOf(ImageGraphicsLibraryInterface::class, $gd);
		self::assertSame(ImageGraphicsMode::GD, $gd->getMode());
		self::assertTrue($gd->getIsAvailable());

		// The implementations are stateless and shared, and a null mode takes the default.
		self::assertSame($gd, ImageGraphics::getLibrary(ImageGraphicsMode::GD));
		self::assertSame($gd, ImageGraphics::getLibrary());

		// An image routes to its own library.
		$image = $this->gdImage(2, 2);
		self::assertSame($gd, ImageGraphics::getLibraryOf($image));
		self::assertSame(ImageGraphicsMode::GD, ImageGraphics::getModeOf($image));

		self::assertTrue($gd->isImage($image));
		self::assertFalse($gd->isImage('image'));

		self::expectException(\InvalidArgumentException::class);
		ImageGraphics::getLibrary('bogus');
	}

	public function testCapabilities()
	{
		// GD's format support follows how the extension was built.
		self::assertSame(function_exists('imagejpeg'), ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityJpeg, ImageGraphicsMode::GD));
		self::assertSame(function_exists('imagewebp'), ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityWebP, ImageGraphicsMode::GD));
		self::assertSame(function_exists('imagepng'), ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityPng, ImageGraphicsMode::GD));
		self::assertTrue(ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityPalette, ImageGraphicsMode::GD));

		// The abilities GD gets in software: separating CMYK and the matrix/TRC transform.
		self::assertTrue(ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityCmyk, ImageGraphicsMode::GD));
		self::assertTrue(ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityICCTransform, ImageGraphicsMode::GD));

		// The two that need the library itself: carrying a profile, and deep samples.
		foreach ([ImageGraphicsLibraryInterface::CapabilityICCEmbed, ImageGraphicsLibraryInterface::CapabilityHighBitDepth] as $capability) {
			self::assertFalse(ImageGraphics::supports($capability, ImageGraphicsMode::GD), $capability);
		}
		self::assertFalse(ImageGraphics::supports('no-such-capability', ImageGraphicsMode::GD));

		// A null mode asks the default library; an unavailable or unknown one answers
		// false instead of throwing, so a caller may ask about a library it lacks.
		self::assertSame(
			ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityPalette, ImageGraphics::getDefaultMode()),
			ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityPalette),
		);
		self::assertFalse(ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityPalette, 'bogus'));
		if (!ImageGraphics::hasImagick()) {
			self::assertFalse(ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityCmyk, ImageGraphicsMode::Imagick));
		}
	}

	public function testCapabilitiesImagick()
	{
		$this->requireImagick();
		$imagick = ImageGraphics::getLibrary(ImageGraphicsMode::Imagick);
		self::assertInstanceOf(ImageGraphicsImagick::class, $imagick);
		self::assertSame(ImageGraphicsMode::Imagick, $imagick->getMode());
		self::assertTrue($imagick->getIsAvailable());

		// The color forms GD cannot hold.
		self::assertTrue($imagick->supports(ImageGraphicsLibraryInterface::CapabilityCmyk));
		self::assertTrue($imagick->supports(ImageGraphicsLibraryInterface::CapabilityICCEmbed));
		self::assertTrue($imagick->supports(ImageGraphicsLibraryInterface::CapabilityICCTransform));
		self::assertSame(\Imagick::queryFormats('PNG') !== [], $imagick->supports(ImageGraphicsLibraryInterface::CapabilityPng));
		self::assertTrue($imagick->supports(ImageGraphicsLibraryInterface::CapabilityPalette));
		self::assertSame(\Imagick::queryFormats('JPEG') !== [], $imagick->supports(ImageGraphicsLibraryInterface::CapabilityJpeg));
		self::assertSame(\Imagick::queryFormats('WEBP') !== [], $imagick->supports(ImageGraphicsLibraryInterface::CapabilityWebP));
		self::assertSame(((int) \Imagick::getQuantumDepth()['quantumDepthLong']) > 8, $imagick->supports(ImageGraphicsLibraryInterface::CapabilityHighBitDepth));
		self::assertFalse($imagick->supports('no-such-capability'));

		self::assertTrue($imagick->isImage(ImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, ImageGraphicsMode::Imagick)));
		self::assertFalse($imagick->isImage($this->gdImage(1, 1)));
	}

	public function testLibraryRejectsAnotherLibrarysImage()
	{
		$this->requireImagick();
		// The facade routes by image type, so a mismatch only reaches a library when it
		// is called directly: a programming error, not a conversion.
		$imagick = ImageGraphics::getLibrary(ImageGraphicsMode::Imagick);
		self::expectException(\InvalidArgumentException::class);
		$imagick->getSize($this->gdImage(2, 2));
	}

	public function testGdLibraryRejectsImagickImage()
	{
		$this->requireImagick();
		$image = ImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, ImageGraphicsMode::Imagick);
		self::expectException(\InvalidArgumentException::class);
		ImageGraphics::getLibrary(ImageGraphicsMode::GD)->rgbPixels($image);
	}

	/**
	 * Builds a minimal but valid ICC v2 RGB matrix/TRC display profile, so the profile
	 * paths are exercised without a binary fixture.
	 */
	private function iccProfile(): string
	{
		$s15 = fn (float $v): string => pack('N', (int) round($v * 65536));
		$xyz = fn (float $x, float $y, float $z): string => 'XYZ ' . "\0\0\0\0" . $s15($x) . $s15($y) . $s15($z);
		$curv = fn (float $gamma): string => 'curv' . "\0\0\0\0" . pack('N', 1) . pack('n', (int) round($gamma * 256));

		$tags = [
			'desc' => 'desc' . "\0\0\0\0" . pack('N', 13) . "Minimal sRGB\0" . str_repeat("\0", 78),
			'wtpt' => $xyz(0.9642, 1.0, 0.8249),
			'rXYZ' => $xyz(0.4360, 0.2225, 0.0139),
			'gXYZ' => $xyz(0.3851, 0.7169, 0.0971),
			'bXYZ' => $xyz(0.1431, 0.0606, 0.7141),
			'rTRC' => $curv(2.2),
			'gTRC' => $curv(2.2),
			'bTRC' => $curv(2.2),
			'cprt' => 'text' . "\0\0\0\0" . "Public Domain\0",
		];
		$offset = 132 + count($tags) * 12;
		$table = pack('N', count($tags));
		$data = '';
		foreach ($tags as $signature => $blob) {
			$table .= $signature . pack('N', $offset) . pack('N', strlen($blob));
			$padding = (4 - strlen($blob) % 4) % 4;
			$data .= $blob . str_repeat("\0", $padding);
			$offset += strlen($blob) + $padding;
		}
		$body = $table . $data;
		// The 128-byte header: size, CMM, version 2.3.0, class, data and connection
		// spaces, date, 'acsp', platform, flags, maker, model, attributes and intent,
		// the D50 illuminant, creator, then the profile id and reserved bytes.
		$header = pack('N', 128 + strlen($body)) . '    ' . pack('N', 0x02300000) . 'mntr' . 'RGB ' . 'XYZ '
			. pack('nnnnnn', 2026, 1, 1, 0, 0, 0) . 'acsp' . 'APPL' . pack('N', 0)
			. '    ' . '    ' . str_repeat("\0", 12) . $s15(0.9642) . $s15(1.0) . $s15(0.8249)
			. '    ' . str_repeat("\0", 44);
		return $header . $body;
	}

	public function testEncodeFormatsGd()
	{
		$image = $this->gdImage(8, 6);
		self::assertSame("\xFF\xD8", substr((string) ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatJpeg, 90), 0, 2));
		self::assertSame("\x89PNG", substr((string) ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatPng), 0, 4));

		// Every format the build carries decodes back to the same dimensions.
		foreach ([ImageGraphicsLibraryInterface::FormatJpeg, ImageGraphicsLibraryInterface::FormatPng, ImageGraphicsLibraryInterface::FormatWebP] as $format) {
			$encoded = ImageGraphics::encode($image, $format);
			if (!ImageGraphics::supports($format, ImageGraphicsMode::GD)) {
				self::assertFalse($encoded, "$format should be unsupported");
				continue;
			}
			self::assertIsString($encoded, $format);
			self::assertSame([8, 6], ImageGraphics::getSize(ImageGraphics::decode($encoded, ImageGraphicsMode::GD)), $format);
		}

		// PNG is lossless: the quality moves GD's compression level, never the pixels.
		$source = ImageGraphics::rgbPixels($image);
		foreach ([10, 100] as $quality) {
			$decoded = ImageGraphics::decode((string) ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatPng, $quality), ImageGraphicsMode::GD);
			self::assertInstanceOf(\GdImage::class, $decoded, "quality $quality");
			self::assertSame(bin2hex($source), bin2hex(ImageGraphics::rgbPixels($decoded)), "quality $quality");
		}

		self::assertFalse(ImageGraphics::encode($image, 'tiff'));
		self::assertFalse(ImageGraphics::encode($image, ''));

		// The JPEG convenience is the same encoding.
		self::assertSame("\xFF\xD8", substr(ImageGraphics::encodeJpeg($image, 90), 0, 2));
	}

	public function testEncodeFormatsImagick()
	{
		$this->requireImagick();
		$image = ImageGraphics::fromRgbPixels(str_repeat("\x0A\x78\xC8", 48), 8, 6, ImageGraphicsMode::Imagick);
		self::assertSame("\xFF\xD8", substr((string) ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatJpeg, 90), 0, 2));

		foreach ([ImageGraphicsLibraryInterface::FormatJpeg, ImageGraphicsLibraryInterface::FormatPng, ImageGraphicsLibraryInterface::FormatWebP] as $format) {
			$encoded = ImageGraphics::encode($image, $format);
			if (!ImageGraphics::supports($format, ImageGraphicsMode::Imagick)) {
				self::assertFalse($encoded, "$format should be unsupported");
				continue;
			}
			self::assertIsString($encoded, $format);
			self::assertSame([8, 6], ImageGraphics::getSize(ImageGraphics::decode($encoded, ImageGraphicsMode::Imagick)), $format);
		}
		self::assertSame("\x89PNG", substr((string) ImageGraphics::encode($image, ImageGraphicsLibraryInterface::FormatPng), 0, 4));
		self::assertFalse(ImageGraphics::encode($image, 'not-a-format'));

		// Encoding leaves the source untouched.
		self::assertSame([8, 6], ImageGraphics::getSize($image));
		self::assertSame(str_repeat("\x0A\x78\xC8", 48), ImageGraphics::rgbPixels($image));
	}

	public function testGdCannotCarryAnEmbeddedProfile()
	{
		$image = $this->gdImage(2, 2);
		self::assertFalse(ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityICCEmbed, ImageGraphicsMode::GD));
		self::assertNull(ImageGraphics::getICCProfile($image));
		self::assertFalse(ImageGraphics::setICCProfile($image, $this->iccProfile()));
		self::assertFalse(ImageGraphics::setICCProfile($image, null));
	}

	public function testGdTransformsBetweenColorSpacesInSoftware()
	{
		// GD has no color management, so the conversion runs through ICCTransform.
		$sRgb = ICCProfileBuilder::sRgb();
		$wide = ICCProfileBuilder::wideGamut();

		$image = imagecreatetruecolor(3, 1);
		imagesetpixel($image, 0, 0, 0xFF0000);
		imagesetpixel($image, 1, 0, 0xFFFFFF);
		imagesetpixel($image, 2, 0, 0x808080);

		self::assertTrue(ImageGraphics::transformICCProfile($image, $sRgb, $wide));
		// The published Adobe RGB encoding of sRGB red; white stays white and the neutral
		// stays neutral.
		self::assertEqualsWithDelta(
			[219, 0, 0, 255, 255, 255, 127, 127, 127],
			array_map('ord', str_split(ImageGraphics::rgbPixels($image))),
			2,
		);

		// A profile it cannot evaluate is refused rather than approximated, and so is a
		// byte string that is not a profile at all.
		self::assertFalse(ImageGraphics::transformICCProfile($image, $sRgb, ICCProfileBuilder::cmykLut()));
		self::assertFalse(ImageGraphics::transformICCProfile($image, ICCProfileBuilder::cmykLut(), $sRgb));
		self::assertFalse(ImageGraphics::transformICCProfile($image, 'not a profile', $sRgb));
		self::assertFalse(ImageGraphics::transformICCProfile($image, $sRgb, 'not a profile'));
	}

	public function testImagickTransformsBetweenColorSpaces()
	{
		$this->requireImagick();
		$image = ImageGraphics::fromRgbPixels("\xFF\x00\x00\xFF\xFF\xFF", 2, 1, ImageGraphicsMode::Imagick);

		self::assertTrue(ImageGraphics::transformICCProfile($image, ICCProfileBuilder::sRgb(), ICCProfileBuilder::wideGamut()));
		// ImageMagick leaves the destination profile attached to the image.
		self::assertSame(bin2hex(ICCProfileBuilder::wideGamut()), bin2hex((string) ImageGraphics::getICCProfile($image)));

		// Converting again from the space it now carries is a no-op on the first profile.
		self::assertTrue(ImageGraphics::transformICCProfile($image, ICCProfileBuilder::wideGamut(), ICCProfileBuilder::sRgb()));
		self::assertFalse(ImageGraphics::transformICCProfile($image, ICCProfileBuilder::sRgb(), 'not a profile'));
	}

	public function testCmykPixelsRoundTrip()
	{
		foreach ([ImageGraphicsMode::GD, ImageGraphicsMode::Imagick] as $mode) {
			if (!ImageGraphics::hasMode($mode)) {
				continue;
			}
			$rgb = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\xFF\xFF\xFF" . "\x00\x00\x00" . "\x80\x40\xC0";
			$image = ImageGraphics::fromRgbPixels($rgb, 6, 1, $mode);

			$cmyk = ImageGraphics::cmykPixels($image);
			self::assertIsString($cmyk, $mode);
			self::assertSame(24, strlen($cmyk), $mode);

			$restored = ImageGraphics::fromCmykPixels($cmyk, 6, 1, $mode);
			self::assertTrue(ImageGraphics::isImage($restored), $mode);
			self::assertSame([6, 1], ImageGraphics::getSize($restored), $mode);

			// The separation is reversible to within the rounding of one 8-bit step.
			self::assertEqualsWithDelta(
				array_map('ord', str_split($rgb)),
				array_map('ord', str_split(ImageGraphics::rgbPixels($restored))),
				2,
				$mode,
			);
		}
	}

	public function testCmykSeparationOfKnownColors()
	{
		// The straightforward separation GD performs: no ink for white, black only for
		// black, and one full primary ink for each secondary.
		$image = ImageGraphics::fromRgbPixels("\xFF\xFF\xFF" . "\x00\x00\x00" . "\x00\xFF\xFF" . "\xFF\x00\xFF", 4, 1, ImageGraphicsMode::GD);
		self::assertSame(
			bin2hex("\x00\x00\x00\x00" . "\x00\x00\x00\xFF" . "\xFF\x00\x00\x00" . "\x00\xFF\x00\x00"),
			bin2hex((string) ImageGraphics::cmykPixels($image)),
		);
	}

	public function testGetCapableLibrary()
	{
		// The default library answers when it can do the job.
		self::assertSame(
			ImageGraphics::getLibrary(ImageGraphicsMode::GD),
			ImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityPalette),
		);

		// For something GD cannot do, the other library answers -- or nothing does.
		$embedding = ImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityICCEmbed);
		if (ImageGraphics::hasImagick()) {
			self::assertInstanceOf(ImageGraphicsImagick::class, $embedding);
			self::assertSame(
				ImageGraphics::getLibrary(ImageGraphicsMode::Imagick),
				ImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityHighBitDepth),
			);
		} else {
			self::assertNull($embedding);
			self::assertNull(ImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityHighBitDepth));
		}

		self::assertNull(ImageGraphics::getCapableLibrary('no-such-capability'));

		// The preference follows the default mode.
		ImageGraphics::setDefaultMode(ImageGraphicsMode::GD);
		self::assertInstanceOf(ImageGraphicsGD::class, ImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityCmyk));
		if (ImageGraphics::hasImagick()) {
			ImageGraphics::setDefaultMode(ImageGraphicsMode::Imagick);
			self::assertInstanceOf(ImageGraphicsImagick::class, ImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityCmyk));
		}
	}

	public function testICCProfileImagick()
	{
		$this->requireImagick();
		$profile = $this->iccProfile();
		$image = ImageGraphics::fromRgbPixels(str_repeat("\x40\x80\xC0", 4), 2, 2, ImageGraphicsMode::Imagick);

		// A fresh image carries no profile, and removing nothing is a no-op success.
		self::assertNull(ImageGraphics::getICCProfile($image));
		self::assertTrue(ImageGraphics::setICCProfile($image, null));

		self::assertTrue(ImageGraphics::setICCProfile($image, $profile));
		self::assertSame(bin2hex($profile), bin2hex((string) ImageGraphics::getICCProfile($image)));

		// Attaching over an existing profile is the ICC transform path.
		self::assertTrue(ImageGraphics::setICCProfile($image, $profile));

		self::assertTrue(ImageGraphics::setICCProfile($image, null));
		self::assertNull(ImageGraphics::getICCProfile($image));

		// An unusable profile fails rather than throwing.
		self::assertFalse(ImageGraphics::setICCProfile($image, 'not an ICC profile'));
	}

	public function testValidateModeErrors()
	{
		self::expectException(\InvalidArgumentException::class);
		ImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, 'bogus');
	}

	public function testUnavailableModeThrows()
	{
		if (ImageGraphics::hasImagick()) {
			self::markTestSkipped('ext-imagick is loaded; the unavailable path cannot be exercised.');
		}
		self::expectException(\RuntimeException::class);
		ImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, ImageGraphicsMode::Imagick);
	}

	public function testDefaultModeFollowsTheAvailableLibraries()
	{
		// The extension detection is the seam every resolution routes through, so a
		// subclass reporting a library missing stands in for an installation without it.
		if (ImageGraphics::hasImagick()) {
			self::assertSame(ImageGraphicsMode::Imagick, TNoGdImageGraphics::getDefaultMode());
			self::assertInstanceOf(ImageGraphicsImagick::class, TNoGdImageGraphics::getLibrary());
		} else {
			self::assertNull(TNoGdImageGraphics::getDefaultMode());
		}
		self::assertNull(TNoLibraryImageGraphics::getDefaultMode());
		self::assertFalse(TNoLibraryImageGraphics::hasMode());

		// An explicit default outranks the detection.
		ImageGraphics::setDefaultMode(ImageGraphicsMode::GD);
		self::assertSame(ImageGraphicsMode::GD, TNoLibraryImageGraphics::getDefaultMode());
	}

	public function testMissingLibrariesAreReportedNotGuessed()
	{
		try {
			TNoLibraryImageGraphics::getLibrary();
			self::fail('a library was resolved with none available');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\RuntimeException::class, $e);
		}

		try {
			TNoGdImageGraphics::getLibrary(ImageGraphicsMode::GD);
			self::fail('an unloaded library was resolved by name');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\RuntimeException::class, $e);
		}

		// Asking about a capability of a library that is not there is answered, not
		// thrown, and no library is capable of anything.
		self::assertFalse(TNoLibraryImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityJpeg));
		self::assertFalse(TNoLibraryImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityJpeg, ImageGraphicsMode::GD));
		self::assertNull(TNoLibraryImageGraphics::getCapableLibrary(ImageGraphicsLibraryInterface::CapabilityJpeg));
	}

	public function testGdReportsAnImpossibleAllocationAsFailure()
	{
		// GD refuses an allocation whose size overflows; both entry points answer false
		// rather than handing back a broken image.
		$huge = 200000000;
		self::assertFalse(@ImageGraphics::fromRgbPixels('', $huge, $huge, ImageGraphicsMode::GD));
		self::assertFalse(@ImageGraphics::resampled($this->gdImage(4, 4), $huge, $huge));
	}

	public function testUnavailableImagickReportsNoCapabilities()
	{
		// Without the extension the library provides nothing at all, which is what lets
		// ImageGraphics::supports() ask about a library this installation lacks.
		$unavailable = new class () extends ImageGraphicsImagick {
			public function getIsAvailable(): bool
			{
				return false;
			}
		};
		self::assertFalse($unavailable->getIsAvailable());
		foreach ([
			ImageGraphicsLibraryInterface::CapabilityJpeg,
			ImageGraphicsLibraryInterface::CapabilityPng,
			ImageGraphicsLibraryInterface::CapabilityWebP,
			ImageGraphicsLibraryInterface::CapabilityCmyk,
			ImageGraphicsLibraryInterface::CapabilityICCEmbed,
			ImageGraphicsLibraryInterface::CapabilityHighBitDepth,
		] as $capability) {
			self::assertFalse($unavailable->supports($capability), $capability);
		}
	}

	public function testImagickFailuresAreContained()
	{
		$this->requireImagick();
		$library = ImageGraphics::getLibrary(ImageGraphicsMode::Imagick);

		// A wand holding no image cannot be encoded: the ImagickException is answered as
		// false, and the working copy is still released.
		self::assertFalse($library->encode(new \Imagick(), ImageGraphicsLibraryInterface::FormatJpeg, 80));

		// A pixel array that does not fill the geometry is refused rather than padded.
		self::assertFalse($library->fromCmykPixels("\x01\x02\x03\x04", 4, 4));
		self::assertFalse($library->fromCmykPixels('', 2, 2));

		// The separation reports the same way when the conversion itself cannot run;
		// getSize() is what stands between an empty wand and the export, so overriding it
		// lets the empty wand reach the conversion.
		$stubbed = new class () extends ImageGraphicsImagick {
			public function getSize(\GdImage|\Imagick $image): array
			{
				return [1, 1];
			}
		};
		self::assertFalse($stubbed->cmykPixels(new \Imagick()));
	}

	public function testRgbRoundTripImagick()
	{
		$this->requireImagick();
		$rgb = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\x10\x20\x30";
		$image = ImageGraphics::fromRgbPixels($rgb, 2, 2, ImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		self::assertSame(ImageGraphicsMode::Imagick, ImageGraphics::getModeOf($image));
		self::assertSame([2, 2], ImageGraphics::getSize($image));
		self::assertSame($rgb, ImageGraphics::rgbPixels($image));
	}

	public function testDecodeAndEncodeJpegImagick()
	{
		$this->requireImagick();
		$image = ImageGraphics::fromRgbPixels(str_repeat("\x0A\x78\xC8", 48), 8, 6, ImageGraphicsMode::Imagick);
		$jpeg = ImageGraphics::encodeJpeg($image, 90);
		self::assertSame("\xFF\xD8", substr($jpeg, 0, 2));

		$decoded = ImageGraphics::decode($jpeg, ImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $decoded);
		self::assertSame([8, 6], ImageGraphics::getSize($decoded));

		self::assertFalse(ImageGraphics::decode('not an image', ImageGraphicsMode::Imagick));
	}

	public function testResampledImagick()
	{
		$this->requireImagick();
		$image = ImageGraphics::fromRgbPixels(str_repeat("\x80\x80\x80", 800), 40, 20, ImageGraphicsMode::Imagick);
		$resampled = ImageGraphics::resampled($image, 20, 10);
		self::assertInstanceOf(\Imagick::class, $resampled);
		self::assertSame([20, 10], ImageGraphics::getSize($resampled));
	}

	public function testMonoPixelsImagick()
	{
		$this->requireImagick();
		$rgb = str_repeat("\x00\x00\x00", 2) . str_repeat("\xFF\xFF\xFF", 2)
			. str_repeat("\x00\x00\x00", 2) . str_repeat("\xFF\xFF\xFF", 2);
		$image = ImageGraphics::fromRgbPixels($rgb, 4, 2, ImageGraphicsMode::Imagick);
		$mono = ImageGraphics::monoPixels($image, false);
		self::assertSame("\x00\x00\x01\x01\x00\x00\x01\x01", $mono);
	}

	public function testMonoPixelsImagickFlatImage()
	{
		$this->requireImagick();
		// A flat image quantizes to one level, so the midpoint of the levels present says
		// nothing about it: an all-black canvas comes back all-white if the threshold
		// follows the quantized levels instead of a fixed mid-grey.
		$black = ImageGraphics::fromRgbPixels(str_repeat("\x00\x00\x00", 6), 3, 2, ImageGraphicsMode::Imagick);
		self::assertSame(str_repeat("\x00", 6), ImageGraphics::monoPixels($black, false));

		$white = ImageGraphics::fromRgbPixels(str_repeat("\xFF\xFF\xFF", 6), 3, 2, ImageGraphicsMode::Imagick);
		self::assertSame(str_repeat("\x01", 6), ImageGraphics::monoPixels($white, false));
	}

	public function testPaletteQuantizeImagick()
	{
		$this->requireImagick();
		$rgb = str_repeat("\xFF\x00\x00", 2) . str_repeat("\x00\x00\xFF", 2)
			. str_repeat("\xFF\x00\x00", 2) . str_repeat("\x00\x00\xFF", 2);
		$image = ImageGraphics::fromRgbPixels($rgb, 4, 2, ImageGraphicsMode::Imagick);
		[$palette, $pixels] = ImageGraphics::paletteQuantize($image);
		self::assertSame(768, strlen($palette));
		self::assertSame(8, strlen($pixels));
		for ($i = 0; $i < 8; $i++) {
			$expected = [ord($rgb[$i * 3]), ord($rgb[$i * 3 + 1]), ord($rgb[$i * 3 + 2])];
			$this->assertNearColor($expected, substr($palette, ord($pixels[$i]) * 3, 3), "pixel $i");
		}
	}

	public function testJfifCrossLibrary()
	{
		$this->requireImagick();
		$rgb = str_repeat("\x40\x80\xC0", 6 * 4);
		$thumb = ImageGraphics::fromRgbPixels($rgb, 6, 4, ImageGraphicsMode::Imagick);

		$jfif = new JFIF();
		$jfif->setImage($thumb);
		self::assertSame(6, $jfif->getXThumbnail());
		self::assertSame(4, $jfif->getYThumbnail());
		self::assertSame($rgb, $jfif->getThumbnail());

		self::assertInstanceOf(\GdImage::class, $jfif->getImage(ImageGraphicsMode::GD));
		self::assertInstanceOf(\Imagick::class, $jfif->getImage(ImageGraphicsMode::Imagick));
	}

	public function testJfxxImagickColorThumb()
	{
		$this->requireImagick();
		$rgb = str_repeat("\x20\x40\x60", 8 * 8);
		$thumb = ImageGraphics::fromRgbPixels($rgb, 8, 8, ImageGraphicsMode::Imagick);

		$jfxx = new JFXX();
		self::assertTrue($jfxx->setImage($thumb, JFXX::COLOR_THUMB));
		self::assertSame($rgb, $jfxx->getThumbnail());

		$image = $jfxx->getImage(ImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		self::assertSame($rgb, ImageGraphics::rgbPixels($image));
	}

	public function testRasterizedCaptionImagick()
	{
		$this->requireImagick();
		$rgb = '';
		for ($y = 0; $y < 128; $y++) {
			for ($x = 0; $x < 460; $x++) {
				$rgb .= $x < 230 ? "\x00\x00\x00" : "\xFF\xFF\xFF";
			}
		}
		$caption = ImageGraphics::fromRgbPixels($rgb, 460, 128, ImageGraphicsMode::Imagick);

		$iptc = new IPTC();
		self::assertTrue($iptc->setRasterizedCaptionImage($caption, false));
		$raster = $iptc[IPTCTags::RasterizedCaption];
		self::assertSame(7360, strlen($raster));
		// The left half is black bits, the right half white bits (column-major packing).
		self::assertSame(str_repeat("\x00", 3680), substr($raster, 0, 3680));
		self::assertSame(str_repeat("\xFF", 3680), substr($raster, 3680));

		$image = $iptc->getRasterizedCaptionImage(ImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		self::assertSame($rgb, ImageGraphics::rgbPixels($image));
	}
}

/**
 * The facade with GD reported missing: the extension checks are the seam the resolution
 * routes through, so overriding them stands in for an installation without the extension.
 */
class TNoGdImageGraphics extends ImageGraphics
{
	public static function hasGd(): bool
	{
		return false;
	}
}

/**
 * The facade with neither graphics library available.
 */
class TNoLibraryImageGraphics extends TNoGdImageGraphics
{
	public static function hasImagick(): bool
	{
		return false;
	}
}
