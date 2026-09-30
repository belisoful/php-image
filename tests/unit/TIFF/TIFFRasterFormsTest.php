<?php

use Belisoful\Image\Compression\CCITTFaxCompressor;
use Belisoful\Image\Compression\LZWCompressor;
use Belisoful\Image\Compression\PackBitsCompressor;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFRaster;
use Belisoful\Image\TIFFImage;

/**
 * Exposes the protected block blitter so its clipping guards can be exercised directly:
 * the decoder itself never places a block outside the canvas.
 */
class TTIFFRasterBlitProbe extends TIFFRaster
{
	/**
	 * Blits a block into a plane buffer.
	 * @param string &$plane The plane buffer.
	 * @param string $block The block's sample bytes.
	 * @param array $position The block position and geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @param int $perPlane The samples per pixel in this plane.
	 */
	public static function blitBlock(string &$plane, string $block, array $position, int $width, int $height, int $perPlane): void
	{
		static::blit($plane, $block, $position, $width, $height, $perPlane);
	}
}

/**
 * Exposes the protected block decoder so the T4Options bit can be exercised directly:
 * {@see TIFFRaster::readGeometry()} carries no 't4options' entry, so the whole-file path
 * always reaches the block decoder with the one-dimensional Group 3 default.
 */
class TTIFFRasterDecodeProbe extends TIFFRaster
{
	/**
	 * Decompresses one block and normalizes it to one byte per sample.
	 * @param string $data The block bytes.
	 * @param array $geometry The geometry.
	 * @param int $blockWidth The block width in pixels.
	 * @param int $rows The block height in rows.
	 * @param int $perPlane The samples per pixel in this plane.
	 * @return ?string The block's sample bytes, or null when unsupported.
	 */
	public static function decodeOneBlock(string $data, array $geometry, int $blockWidth, int $rows, int $perPlane): ?string
	{
		return static::decodeBlock($data, $geometry, $blockWidth, $rows, $perPlane);
	}
}

/**
 * The raster forms beyond the simple stripped 8-bit case: tiles, planar separation,
 * sub-byte and 16-bit depths, reversed fill order, and the palette/CMYK/YCbCr/Lab
 * photometrics.
 */
class TIFFRasterFormsTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Builds a TIFF from explicit IFD tags and raster blocks.
	 * @param array $tags The tag id => [type, values] map.
	 * @param int $offsetsTag The offsets tag (273 strips or 324 tiles).
	 * @param array $blocks The block payloads.
	 * @param int $countsTag
	 * @param bool $bigEndian The document byte order, big-endian (MM) by default.
	 */
	private function buildTiff(array $tags, int $offsetsTag, array $blocks, int $countsTag, bool $bigEndian = true): string
	{
		$exif = new EXIF();
		$exif->setSignature('');
		$exif->getTiff()->setIsBigEndian($bigEndian);
		$ifd = $exif->getIfd0();
		foreach ($tags as $id => [$type, $values]) {
			$ifd->setTagValues($id, $type, $values);
		}
		$offsets = $ifd->setTagValues($offsetsTag, TIFFDataType::ULong, array_fill(0, count($blocks), 0));
		$ifd->setTagValues($countsTag, TIFFDataType::ULong, array_map('strlen', $blocks));
		$offsets->setExternalData($blocks);
		return $exif->toBinary();
	}

	/**
	 * Builds a TIFF whose offsets and byte-counts tags disagree, so the parser captures
	 * no raster blocks at all.
	 * @param array $tags The tag id => [type, values] map.
	 * @param int $offsetsTag The offsets tag (273 strips or 324 tiles).
	 * @param int $countsTag The matching byte-counts tag.
	 * @return string The TIFF bytes.
	 */
	private function buildBlocklessTiff(array $tags, int $offsetsTag, int $countsTag): string
	{
		$exif = new EXIF();
		$exif->setSignature('');
		$ifd = $exif->getIfd0();
		foreach ($tags as $id => [$type, $values]) {
			$ifd->setTagValues($id, $type, $values);
		}
		$ifd->setTagValues($offsetsTag, TIFFDataType::ULong, [8, 8]);   // two offsets ...
		$ifd->setTagValues($countsTag, TIFFDataType::ULong, [4]);       // ... but one byte count
		return $exif->toBinary();
	}

	/**
	 * Decodes a one-row grayscale raster and returns each pixel's grey level in hex.
	 * @param int $bits The bits per sample.
	 * @param string $data The strip bytes, whose length sets the width.
	 * @param array $extra Tags to add or override, id => [type, values].
	 * @param bool $bigEndian The document byte order.
	 * @return false|string The grey levels as hex, or false when the raster is refused.
	 */
	private function greyRow(int $bits, string $data, array $extra = [], bool $bigEndian = true): false|string
	{
		$width = intdiv(strlen($data) * 8, $bits);
		$tags = $extra + $this->baseTags($width, 1, TIFFRaster::BlackIsZero, [$bits], 1);
		$image = TIFFImage::fromString($this->buildTiff($tags, 273, [$data], 279, $bigEndian))->getImage();
		if ($image === false) {
			return false;
		}
		$rgb = ImageGraphics::rgbPixels($image);
		$grey = '';
		for ($i = 0; $i < $width; $i++) {
			$grey .= $rgb[$i * 3];
		}
		return bin2hex($grey);
	}

	/**
	 * Applies the floating-point predictor the way a writer does: each row's big-endian
	 * samples split into byte planes, most significant first, then differenced bytewise
	 * across the pixel.
	 * @param string $samples The big-endian samples of one row.
	 * @param int $bytes The bytes per sample.
	 * @param int $stride The samples per pixel.
	 * @return string The predicted row.
	 */
	private static function floatPredict(string $samples, int $bytes, int $stride): string
	{
		$count = intdiv(strlen($samples), $bytes);
		$planes = '';
		for ($plane = 0; $plane < $bytes; $plane++) {
			for ($i = 0; $i < $count; $i++) {
				$planes .= $samples[$i * $bytes + $plane];
			}
		}
		for ($i = strlen($planes) - 1; $i >= $stride; $i--) {
			$planes[$i] = chr((ord($planes[$i]) - ord($planes[$i - $stride])) & 0xFF);
		}
		return $planes;
	}

	private function baseTags(int $width, int $height, int $photometric, array $bits, int $samples): array
	{
		return [
			256 => [TIFFDataType::ULong, [$width]],
			257 => [TIFFDataType::ULong, [$height]],
			258 => [TIFFDataType::UShort, $bits],
			259 => [TIFFDataType::UShort, [1]],
			262 => [TIFFDataType::UShort, [$photometric]],
			277 => [TIFFDataType::UShort, [$samples]],
			278 => [TIFFDataType::ULong, [$height]],
		];
	}

	public function testTiledRgbRaster()
	{
		// 4x4 image in four 2x2 tiles, each a solid color.
		$colors = ["\xFF\x00\x00", "\x00\xFF\x00", "\x00\x00\xFF", "\xFF\xFF\x00"];
		$tiles = array_map(fn ($c) => str_repeat($c, 4), $colors);
		$tags = $this->baseTags(4, 4, TIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[322] = [TIFFDataType::ULong, [2]];
		$tags[323] = [TIFFDataType::ULong, [2]];

		$tiff = TIFFImage::fromString($this->buildTiff($tags, 324, $tiles, 325));
		$image = $tiff->getImage();
		self::assertNotFalse($image);
		$rgb = ImageGraphics::rgbPixels($image);

		// Tile 0 is top-left, tile 1 top-right, tile 2 bottom-left, tile 3 bottom-right.
		self::assertSame("\xFF\x00\x00", substr($rgb, 0, 3));            // (0,0)
		self::assertSame("\x00\xFF\x00", substr($rgb, 2 * 3, 3));        // (2,0)
		self::assertSame("\x00\x00\xFF", substr($rgb, (2 * 4) * 3, 3));  // (0,2)
		self::assertSame("\xFF\xFF\x00", substr($rgb, (2 * 4 + 2) * 3, 3));
	}

	public function testTilesPaddedAtTheEdges()
	{
		// 3x3 image in 2x2 tiles: the right and bottom tiles carry padding.
		$tile = fn (string $color) => str_repeat($color, 4);
		$tiles = [$tile("\x10\x20\x30"), $tile("\x40\x50\x60"), $tile("\x70\x80\x90"), $tile("\xA0\xB0\xC0")];
		$tags = $this->baseTags(3, 3, TIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[322] = [TIFFDataType::ULong, [2]];
		$tags[323] = [TIFFDataType::ULong, [2]];

		$tiff = TIFFImage::fromString($this->buildTiff($tags, 324, $tiles, 325));
		$rgb = ImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame(3 * 3 * 3, strlen($rgb));
		self::assertSame("\x10\x20\x30", substr($rgb, 0, 3));                // (0,0) first tile
		self::assertSame("\x40\x50\x60", substr($rgb, 2 * 3, 3));            // (2,0) second tile
		self::assertSame("\xA0\xB0\xC0", substr($rgb, (2 * 3 + 2) * 3, 3));  // (2,2) fourth tile
	}

	public function testPlanarConfigurationSeparate()
	{
		// Three separate planes, one per channel, two strips' worth of a 2x2 image.
		$width = 2;
		$height = 2;
		$red = "\xFF\xFF\x00\x00";
		$green = "\x00\xFF\x00\xFF";
		$blue = "\x00\x00\xFF\xFF";
		$tags = $this->baseTags($width, $height, TIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[284] = [TIFFDataType::UShort, [2]];

		$tiff = TIFFImage::fromString($this->buildTiff($tags, 273, [$red, $green, $blue], 279));
		$rgb = ImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame("\xFF\x00\x00", substr($rgb, 0, 3));
		self::assertSame("\xFF\xFF\x00", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\xFF", substr($rgb, 6, 3));
		self::assertSame("\x00\xFF\xFF", substr($rgb, 9, 3));
	}

	public function testTiledPlanarConfigurationSeparate()
	{
		// 4x4 in 2x2 tiles with the channels in separate planes: four red tiles, then
		// four green, then four blue, so the tile index carries both plane and position.
		$solid = fn (array $values) => array_map(fn ($value) => str_repeat(chr($value), 4), $values);
		$tiles = array_merge(
			$solid([0x10, 0x20, 0x30, 0x40]),
			$solid([0x50, 0x60, 0x70, 0x80]),
			$solid([0x90, 0xA0, 0xB0, 0xC0]),
		);
		$tags = $this->baseTags(4, 4, TIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[284] = [TIFFDataType::UShort, [2]];
		$tags[322] = [TIFFDataType::ULong, [2]];
		$tags[323] = [TIFFDataType::ULong, [2]];

		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 324, $tiles, 325))->getImage());
		self::assertSame(4 * 4 * 3, strlen($rgb));
		self::assertSame("\x10\x50\x90", substr($rgb, 0, 3));                 // (0,0), tile 0 of each plane
		self::assertSame("\x20\x60\xA0", substr($rgb, 2 * 3, 3));             // (2,0), tile 1
		self::assertSame("\x30\x70\xB0", substr($rgb, (2 * 4) * 3, 3));       // (0,2), tile 2
		self::assertSame("\x40\x80\xC0", substr($rgb, (2 * 4 + 2) * 3, 3));   // (2,2), tile 3
	}

	public function testTwoDimensionalGroup3NeedsTheT4OptionsBit()
	{
		// Four 64-pixel rows, each a run of white shifted one pixel right of the last.
		$rows = [];
		for ($y = 0; $y < 4; $y++) {
			$rows[] = str_repeat('0', 8 + $y) . str_repeat('1', 24) . str_repeat('0', 32 - $y);
		}
		$bits = implode('', $rows);
		$packed = implode('', array_map(fn (string $byte) => chr(bindec($byte)), str_split($bits, 8)));
		$expected = implode('', array_map(fn (string $bit) => chr((int) $bit), str_split($bits)));
		$encoded = (new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3TwoD))->encode($packed);
		$geometry = ['bits' => 1, 'format' => TIFFRaster::FormatUnsigned, 'bigEndian' => true, 'compression' => TIFFImage::CompressionGroup3, 'fillOrder' => 1, 'predictor' => 1];

		// T4Options bit 0 selects the two-dimensional coding, which decodes the rows.
		$decoded = TTIFFRasterDecodeProbe::decodeOneBlock($encoded, ['t4options' => 1] + $geometry, 64, 4, 1);
		self::assertSame(bin2hex($expected), bin2hex($decoded));

		// Without the bit the same bytes are read as one-dimensional Group 3, whose
		// row codings do not begin with a tag bit, so the stream is not decodable.
		try {
			TTIFFRasterDecodeProbe::decodeOneBlock($encoded, ['t4options' => 0] + $geometry, 64, 4, 1);
			self::fail('a two-dimensional Group 3 stream decoded as one-dimensional');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}

	public function testTwoDimensionalGroup3FileDecodesThroughTheNormalPath()
	{
		// A real file, not a probe: Compression 3 with T4Options bit 0 set is how a fax
		// writer records two-dimensional coding, and reading tag 292 is what tells the
		// decoder to expect it.  Four 64-pixel rows, each run shifted one pixel right.
		$rows = [];
		for ($y = 0; $y < 4; $y++) {
			$rows[] = str_repeat('0', 8 + $y) . str_repeat('1', 24) . str_repeat('0', 32 - $y);
		}
		$bits = implode('', $rows);
		$packed = implode('', array_map(fn (string $byte) => chr(bindec($byte)), str_split($bits, 8)));
		$encoded = (new CCITTFaxCompressor(64, CCITTFaxCompressor::Group3TwoD))->encode($packed);

		$tags = $this->baseTags(64, 4, TIFFRaster::WhiteIsZero, [1], 1);
		$tags[259] = [TIFFDataType::UShort, [TIFFImage::CompressionGroup3]];
		$tags[292] = [TIFFDataType::ULong, [1]];   // T4Options: two-dimensional coding
		$tiff = TIFFImage::fromString($this->buildTiff($tags, 273, [$encoded], 279));

		$rgb = ImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame(64 * 4 * 3, strlen($rgb));
		// WhiteIsZero: a set bit is black.  Row 0 runs white 0-7, black 8-31, white 32-63.
		foreach ([[0, 0, "\xFF\xFF\xFF"], [0, 8, "\x00\x00\x00"], [0, 32, "\xFF\xFF\xFF"],
			[3, 10, "\xFF\xFF\xFF"], [3, 11, "\x00\x00\x00"], [3, 34, "\x00\x00\x00"],
			[3, 35, "\xFF\xFF\xFF"]] as [$y, $x, $want]) {
			self::assertSame(bin2hex($want), bin2hex(substr($rgb, ($y * 64 + $x) * 3, 3)), "pixel ($x,$y)");
		}

		// Without the tag the same file is read as one-dimensional and cannot decode --
		// which is exactly what happened to every 2-D fax TIFF before tag 292 was read.
		unset($tags[292]);
		$oneD = TIFFImage::fromString($this->buildTiff($tags, 273, [$encoded], 279));
		try {
			$oneD->getImage();
			self::fail('a two-dimensional Group 3 file decoded as one-dimensional');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\UnexpectedValueException::class, $e);
		}
	}

	public function testFourBitGrayscaleAndFillOrderTwo()
	{
		// 4-bit gray: two pixels per byte, values scaled to the 0-15 range.
		$tags = $this->baseTags(4, 1, TIFFRaster::BlackIsZero, [4], 1);
		$tiff = TIFFImage::fromString($this->buildTiff($tags, 273, ["\x0F\x80"], 279));
		$rgb = ImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame("\x00\x00\x00", substr($rgb, 0, 3));    // 0 -> black
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));    // 15 -> white
		self::assertSame("\x88\x88\x88", substr($rgb, 6, 3));    // 8 -> mid grey
		self::assertSame("\x00\x00\x00", substr($rgb, 9, 3));    // 0

		// The same rows with every byte bit-mirrored and FillOrder 2 decode alike.
		$mirror = static function (string $data): string {
			$out = '';
			foreach (str_split($data) as $byte) {
				$out .= chr(bindec(strrev(str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT))));
			}
			return $out;
		};
		$tags[266] = [TIFFDataType::UShort, [2]];
		$reversed = TIFFImage::fromString($this->buildTiff($tags, 273, [$mirror("\x0F\x80")], 279));
		self::assertSame(bin2hex($rgb), bin2hex(ImageGraphics::rgbPixels($reversed->getImage())));
	}

	public function testOneAndTwoBitDepths()
	{
		// 1-bit black-is-zero: 0b10100000 -> white, black, white, black.
		$tags = $this->baseTags(4, 1, TIFFRaster::BlackIsZero, [1], 1);
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\xA0"], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF\x00\x00\x00\xFF\xFF\xFF\x00\x00\x00", $rgb);

		// 2-bit: values 0,1,2,3 scale across the range.
		$tags = $this->baseTags(4, 1, TIFFRaster::BlackIsZero, [2], 1);
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x1B"], 279))->getImage());
		self::assertSame("\x00", $rgb[0]);
		self::assertSame("\x55", $rgb[3]);
		self::assertSame("\xAA", $rgb[6]);
		self::assertSame("\xFF", $rgb[9]);
	}

	public function testSixteenBitSamples()
	{
		// 16-bit gray reduces to its high byte.
		$tags = $this->baseTags(2, 1, TIFFRaster::BlackIsZero, [16], 1);
		$data = pack('n', 0xFFFF) . pack('n', 0x8000);
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$data], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x80\x80\x80", substr($rgb, 3, 3));
	}

	public function testPaletteColor()
	{
		// A 4-entry palette; ColorMap values are 16-bit, reds then greens then blues.
		$map = [0xFFFF, 0x0000, 0x0000, 0x8000,   // red
			0x0000, 0xFFFF, 0x0000, 0x8000,       // green
			0x0000, 0x0000, 0xFFFF, 0x8000];      // blue
		$tags = $this->baseTags(4, 1, TIFFRaster::Palette, [8], 1);
		$tags[320] = [TIFFDataType::UShort, $map];
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00\x01\x02\x03"], 279))->getImage());
		self::assertSame("\xFF\x00\x00", substr($rgb, 0, 3));
		self::assertSame("\x00\xFF\x00", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\xFF", substr($rgb, 6, 3));
		self::assertSame("\x80\x80\x80", substr($rgb, 9, 3));
	}

	public function testSeparatedCmyk()
	{
		$tags = $this->baseTags(3, 1, TIFFRaster::Separated, [8, 8, 8, 8], 4);
		$pixels = "\x00\x00\x00\x00"      // no ink -> white
			. "\xFF\x00\x00\x00"          // cyan
			. "\x00\x00\x00\xFF";         // black
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$pixels], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x00\xFF\xFF", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 6, 3));
	}

	public function testYCbCrUnsubsampled()
	{
		$tags = $this->baseTags(2, 1, TIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TIFFDataType::UShort, [1, 1]];
		// Y=255 with neutral chroma is white; Y=0 neutral is black.
		$pixels = "\xFF\x80\x80" . "\x00\x80\x80";
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$pixels], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 3, 3));
	}

	public function testYCbCrSubsampled()
	{
		// 2x2 subsampling: four luma samples then one shared Cb/Cr pair per unit.
		$tags = $this->baseTags(2, 2, TIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TIFFDataType::UShort, [2, 2]];
		$unit = "\xFF\xFF\x00\x00" . "\x80\x80";   // two white, two black, neutral chroma
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());
		self::assertSame(2 * 2 * 3, strlen($rgb));
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));       // (0,0)
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));       // (1,0)
		self::assertSame("\x00\x00\x00", substr($rgb, 6, 3));       // (0,1)
		self::assertSame("\x00\x00\x00", substr($rgb, 9, 3));       // (1,1)
	}

	public function testCieLab()
	{
		// L*=100 with neutral a*/b* is white; L*=0 is black.
		$tags = $this->baseTags(2, 1, TIFFRaster::CieLab, [8, 8, 8], 3);
		$pixels = "\xFF\x00\x00" . "\x00\x00\x00";
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$pixels], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 3, 3));
	}

	public function testIccLabOffsetsItsChromaWhereCieLabSignsIt()
	{
		// L*=100 with a*=b*=-128 is cyan and with a*=b*=0 is white; CIE L*a*b* stores
		// those chroma values as signed bytes, ICC L*a*b* as bytes offset by 128, so the
		// two encodings carry the same pair of pixels in the opposite order.
		$tags = $this->baseTags(2, 1, TIFFRaster::CieLab, [8, 8, 8], 3);
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\xFF\x80\x80\xFF\x00\x00"], 279))->getImage());
		self::assertSame("\x00\xFF\xFF", substr($rgb, 0, 3));   // 0x80 -> -128
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));   // 0x00 -> 0

		$tags = $this->baseTags(2, 1, TIFFRaster::ICCLab, [8, 8, 8], 3);
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\xFF\x00\x00\xFF\x80\x80"], 279))->getImage());
		self::assertSame("\x00\xFF\xFF", substr($rgb, 0, 3));   // 0x00 - 128 -> -128
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));   // 0x80 - 128 -> 0
	}

	public function testCompressedTilesWithPredictor()
	{
		// LZW + horizontal predictor inside tiles.
		$tile = function (string $solid): string {
			$rows = str_repeat($solid, 4);
			return LZWCompressor::compress(\Belisoful\Image\Compression\HorizontalPredictor::encode($rows, 2, 3));
		};
		$tiles = [$tile("\x10\x20\x30"), $tile("\x40\x50\x60"), $tile("\x70\x80\x90"), $tile("\xA0\xB0\xC0")];
		$tags = $this->baseTags(4, 4, TIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[259] = [TIFFDataType::UShort, [5]];
		$tags[317] = [TIFFDataType::UShort, [2]];
		$tags[322] = [TIFFDataType::ULong, [2]];
		$tags[323] = [TIFFDataType::ULong, [2]];

		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 324, $tiles, 325))->getImage());
		self::assertSame("\x10\x20\x30", substr($rgb, 0, 3));
		self::assertSame("\x40\x50\x60", substr($rgb, 2 * 3, 3));
		self::assertSame("\xA0\xB0\xC0", substr($rgb, (2 * 4 + 2) * 3, 3));
	}

	public function testPackBitsStripsAndMultipleStrips()
	{
		$rows = [str_repeat("\x11\x22\x33", 4), str_repeat("\x44\x55\x66", 4)];
		$tags = $this->baseTags(4, 2, TIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[259] = [TIFFDataType::UShort, [32773]];
		$tags[278] = [TIFFDataType::ULong, [1]];   // one row per strip
		$strips = array_map(fn ($r) => PackBitsCompressor::compress($r), $rows);

		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, $strips, 279))->getImage());
		self::assertSame("\x11\x22\x33", substr($rgb, 0, 3));
		self::assertSame("\x44\x55\x66", substr($rgb, 4 * 3, 3));
	}

	public function testSubsampledYCbCrCompressedStrips()
	{
		// Two white and two black luma samples with neutral chroma, LZW- and PackBits-coded.
		$unit = "\xFF\xFF\x00\x00" . "\x80\x80";
		$strips = [
			TIFFImage::CompressionLzw => LZWCompressor::compress($unit),
			TIFFImage::CompressionPackBits => PackBitsCompressor::compress($unit),
		];
		foreach ($strips as $compression => $strip) {
			$tags = $this->baseTags(2, 2, TIFFRaster::YCbCr, [8, 8, 8], 3);
			$tags[259] = [TIFFDataType::UShort, [$compression]];
			$tags[530] = [TIFFDataType::UShort, [2, 2]];
			$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$strip], 279))->getImage());
			self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3), "compression $compression");
			self::assertSame("\x00\x00\x00", substr($rgb, 6, 3), "compression $compression");
		}
	}

	public function testSubsampledYCbCrClipsUnitsToTheCanvas()
	{
		// 3x1 image in 2x1 units: the second unit's right luma sample lies off-canvas.
		$tags = $this->baseTags(3, 1, TIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TIFFDataType::UShort, [2, 1]];
		$data = "\xFF\xFF\x80\x80" . "\x00\x40\x80\x80";
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$data], 279))->getImage());
		self::assertSame(3 * 3, strlen($rgb));
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 6, 3));   // the discarded sample was 0x40
	}

	public function testSubsampledYCbCrStopsAtTruncatedData()
	{
		// One 2x2 unit needs six bytes; five leave the whole canvas at its initial black.
		$tags = $this->baseTags(2, 2, TIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TIFFDataType::UShort, [2, 2]];
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\xFF\xFF\xFF\xFF\x80"], 279))->getImage());
		self::assertSame(str_repeat("\x00", 2 * 2 * 3), $rgb);
	}

	public function testSubsampledYCbCrHonoursTheFillOrder()
	{
		// White and black luma under neutral chroma, each byte bit-mirrored: 0x80 is 0x01.
		$tags = $this->baseTags(2, 2, TIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TIFFDataType::UShort, [2, 2]];
		$tags[266] = [TIFFDataType::UShort, [2]];
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, ["\xFF\xFF\x00\x00\x01\x01"], 279))->getImage());
		self::assertSame('ffffff', bin2hex(substr($rgb, 0, 3)));
		self::assertSame('000000', bin2hex(substr($rgb, 6, 3)));
	}

	public function testSubsampledYCbCrUnsupportedFormsAnswerFalse()
	{
		$subsampled = function (array $subsampling, array $overrides = []): array {
			$tags = $this->baseTags(2, 2, TIFFRaster::YCbCr, [8, 8, 8], 3);
			$tags[530] = [TIFFDataType::UShort, $subsampling];
			return $overrides + $tags;
		};
		$unit = "\xFF\xFF\x00\x00\x80\x80";

		// Separate planes are outside the subsampled unit layout.
		$tags = $subsampled([2, 2], [284 => [TIFFDataType::UShort, [2]]]);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// A zero subsampling factor.
		$tags = $subsampled([0, 2]);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// A compression the subsampled path does not decode.
		$tags = $subsampled([2, 2], [259 => [TIFFDataType::UShort, [TIFFImage::CompressionGroup3]]]);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// The unit layout is defined for plain unsigned bytes: no other format, no predictor.
		$tags = $subsampled([2, 2], [339 => [TIFFDataType::UShort, [2, 2, 2]]]);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());
		$tags = $subsampled([2, 2], [317 => [TIFFDataType::UShort, [2]]]);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// No strip data at all.
		$tags = $subsampled([2, 2]);
		self::assertFalse(TIFFImage::fromString($this->buildBlocklessTiff($tags, 273, 279))->getImage());
	}

	public function testZeroSizedRasterAnswersFalse()
	{
		$tags = $this->baseTags(0, 0, TIFFRaster::BlackIsZero, [8], 1);
		$tiff = TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00"], 279));
		self::assertSame(0, $tiff->getWidth());
		self::assertFalse($tiff->getImage());
	}

	public function testUnsupportedGeometriesAnswerFalse()
	{
		// A bit depth outside the modeled 1/2/4/8/16.
		$tags = $this->baseTags(2, 1, TIFFRaster::BlackIsZero, [12], 1);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00\x00\x00"], 279))->getImage());

		// An unmodeled PlanarConfiguration.
		$tags = $this->baseTags(2, 1, TIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[284] = [TIFFDataType::UShort, [3]];
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [str_repeat("\x00", 6)], 279))->getImage());

		// A fax coding on data that is not bilevel.
		$tags = $this->baseTags(2, 1, TIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[259] = [TIFFDataType::UShort, [TIFFImage::CompressionGroup4]];
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00"], 279))->getImage());
	}

	public function testWideSamplesAreReadInTheDocumentsByteOrder()
	{
		// The most significant byte is first in a big-endian file and last in a little-endian
		// one; reading the first byte of either is right only half the time.
		foreach ([true, false] as $bigEndian) {
			[$short, $long] = $bigEndian ? ['n*', 'N*'] : ['v*', 'V*'];
			$order = $bigEndian ? 'MM' : 'II';
			self::assertSame('ff8000', $this->greyRow(16, pack($short, 0xFF00, 0x8000, 0x00FF), [], $bigEndian), "16-bit $order");
			self::assertSame('ff8000', $this->greyRow(32, pack($long, 0xFF000000, 0x80000000, 0x00FFFFFF), [], $bigEndian), "32-bit $order");

			// The "undefined" format is read as unsigned, as the specification directs.
			$undefined = [339 => [TIFFDataType::UShort, [TIFFRaster::FormatUndefined]]];
			self::assertSame('ff8000', $this->greyRow(16, pack($short, 0xFF00, 0x8000, 0x00FF), $undefined, $bigEndian), "undefined $order");
		}
	}

	public function testSignedSamplesAreOffsetSoTheMostNegativeIsBlack()
	{
		// Read as unsigned, -128 (0x80) would be mid-grey and -1 (0xFF) white.
		$signed = [339 => [TIFFDataType::UShort, [TIFFRaster::FormatSigned]]];
		self::assertSame('00807f', $this->greyRow(8, "\x80\x00\xFF", $signed));
		foreach ([true, false] as $bigEndian) {
			[$short, $long] = $bigEndian ? ['n*', 'N*'] : ['v*', 'V*'];
			self::assertSame('0080ff', $this->greyRow(16, pack($short, 0x8000, 0x0000, 0x7FFF), $signed, $bigEndian));
			self::assertSame('0080ff', $this->greyRow(32, pack($long, 0x80000000, 0x00000000, 0x7FFFFFFF), $signed, $bigEndian));
		}
	}

	public function testFloatSamplesScaleFromZeroToOneAndClamp()
	{
		$float = [339 => [TIFFDataType::UShort, [TIFFRaster::FormatFloat]]];
		$values = [0.0, 0.5, 1.0, 2.0, -1.0, NAN, INF, -INF];
		$expected = '0080ffff0000ff00';   // out of range clamps; NaN is black
		foreach ([true, false] as $bigEndian) {
			[$single, $double] = $bigEndian ? ['G*', 'E*'] : ['g*', 'e*'];
			self::assertSame($expected, $this->greyRow(32, pack($single, ...$values), $float, $bigEndian));
			self::assertSame($expected, $this->greyRow(64, pack($double, ...$values), $float, $bigEndian));
		}
	}

	public function testHalfAndTwentyFourBitFloatSamples()
	{
		$float = [339 => [TIFFDataType::UShort, [TIFFRaster::FormatFloat]]];
		// Half: 0, 0.5, 1, -0.5, +inf, -inf, NaN.
		$half = [0x0000, 0x3800, 0x3C00, 0xB800, 0x7C00, 0xFC00, 0x7E00];
		// Adobe's 24-bit form, seven exponent bits biased by 63: 0.5, 1, +inf, NaN.
		$wide = ["\x3E\x00\x00", "\x3F\x00\x00", "\x7F\x00\x00", "\x7F\x00\x01"];
		foreach ([true, false] as $bigEndian) {
			$order = $bigEndian ? 'MM' : 'II';
			self::assertSame('0080ff00ff0000', $this->greyRow(16, pack($bigEndian ? 'n*' : 'v*', ...$half), $float, $bigEndian), "half $order");
			$bytes = implode('', $bigEndian ? $wide : array_map('strrev', $wide));
			self::assertSame('80ffff00', $this->greyRow(24, $bytes, $float, $bigEndian), "24-bit $order");
		}

		// A subnormal half is nonzero: half the smallest normal, shown against a range
		// that ends at the smallest normal.
		$range = $float + [
			340 => [TIFFDataType::Double, [0.0]],
			341 => [TIFFDataType::Double, [2 ** -14]],
		];
		self::assertSame('0080', $this->greyRow(16, pack('n*', 0x0000, 0x0200), $range));
	}

	public function testFloatRangeComesFromSMinAndSMaxSampleValue()
	{
		$rgb = function (array $pixel, array $extra, bool $planar = false): string {
			$tags = $extra + $this->baseTags(1, 1, TIFFRaster::Rgb, [32, 32, 32], 3);
			$tags[339] = [TIFFDataType::UShort, [3, 3, 3]];
			$blocks = [pack('G*', ...$pixel)];
			if ($planar) {
				$tags[284] = [TIFFDataType::UShort, [2]];
				$blocks = array_map(fn ($value) => pack('G', $value), $pixel);
			}
			return bin2hex(ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, $blocks, 279))->getImage()));
		};
		$perSample = [
			340 => [TIFFDataType::Double, [0.0, 0.0, -10.0]],
			341 => [TIFFDataType::Double, [100.0, 1.0, 10.0]],
		];
		// One range per sample, whether the samples are interleaved or in separate planes.
		self::assertSame('808080', $rgb([50.0, 0.5, 0.0], $perSample));
		self::assertSame('808080', $rgb([50.0, 0.5, 0.0], $perSample, true));

		// One range for every sample.
		$shared = [340 => [TIFFDataType::Float, [10.0]], 341 => [TIFFDataType::Float, [20.0]]];
		self::assertSame('8000ff', $rgb([15.0, 10.0, 20.0], $shared));

		// An empty or inverted range scales nothing, so the default stands in for it.
		$inverted = [340 => [TIFFDataType::Double, [1.0]], 341 => [TIFFDataType::Double, [0.0]]];
		self::assertSame('8000ff', $rgb([0.5, 0.0, 1.0], $inverted));
	}

	public function testHorizontalPredictorOnWideSamples()
	{
		$predicted = [317 => [TIFFDataType::UShort, [2]]];
		foreach ([true, false] as $bigEndian) {
			[$short, $long] = $bigEndian ? ['n*', 'N*'] : ['v*', 'V*'];
			// 0x10F0, 0x2010, 0xFFFF stored as differences; the first sum carries into the
			// high byte, which is the byte that is shown.
			$data = pack($short, 0x10F0, 0x0F20, 0xDFEF);
			self::assertSame('1020ff', $this->greyRow(16, $data, $predicted, $bigEndian));

			// 0x01FFFFFF then 0x02000001: a difference of two carries through three bytes.
			self::assertSame('0102', $this->greyRow(32, pack($long, 0x01FFFFFF, 0x00000002), $predicted, $bigEndian));

			// Each sample differs from the same sample of the pixel before, not its neighbour.
			$tags = $predicted + $this->baseTags(2, 1, TIFFRaster::Rgb, [16, 16, 16], 3);
			$data = pack($short, 0x1000, 0x2000, 0x3000, 0x4000, 0x5000, 0x6000);
			$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($tags, 273, [$data], 279, $bigEndian))->getImage());
			self::assertSame('102030507090', bin2hex($rgb));
		}
	}

	public function testFloatingPointPredictor()
	{
		// The predictor's byte planes are most significant first whatever the document's
		// byte order, so the same bytes decode the same in both.
		$tags = [
			339 => [TIFFDataType::UShort, [TIFFRaster::FormatFloat]],
			317 => [TIFFDataType::UShort, [3]],
		];
		$single = self::floatPredict(pack('G*', 0.0, 0.25, 0.5, 1.0), 4, 1);
		$double = self::floatPredict(pack('E*', 1.0, 0.5, 0.0), 8, 1);
		foreach ([true, false] as $bigEndian) {
			self::assertSame('004080ff', $this->greyRow(32, $single, $tags, $bigEndian));
			self::assertSame('ff8000', $this->greyRow(64, $double, $tags, $bigEndian));
		}

		// Across a pixel of three samples, and compressed as a writer would store it.
		$rgbTags = $tags + $this->baseTags(2, 1, TIFFRaster::Rgb, [32, 32, 32], 3);
		$rgbTags[259] = [TIFFDataType::UShort, [TIFFImage::CompressionLzw]];
		$rgbTags[339] = [TIFFDataType::UShort, [3, 3, 3]];
		$row = self::floatPredict(pack('G*', 1.0, 0.0, 0.5, 0.0, 1.0, 0.25), 4, 3);
		$rgb = ImageGraphics::rgbPixels(TIFFImage::fromString($this->buildTiff($rgbTags, 273, [LZWCompressor::compress($row)], 279))->getImage());
		self::assertSame('ff008000ff40', bin2hex($rgb));
	}

	public function testSampleFormsWithNoColourAnswerFalse()
	{
		$format = fn (int ...$formats) => [339 => [TIFFDataType::UShort, $formats]];

		// Complex integer and complex floating-point samples.
		self::assertFalse($this->greyRow(32, "\0\0\0\0", $format(5)));
		self::assertFalse($this->greyRow(64, str_repeat("\0", 8), $format(6)));

		// A depth the format does not come in.
		self::assertFalse($this->greyRow(8, "\0", $format(TIFFRaster::FormatFloat)));
		self::assertFalse($this->greyRow(4, "\0", $format(TIFFRaster::FormatSigned)));

		// Samples of one pixel in different formats.
		$tags = $format(1, 1, 3) + $this->baseTags(1, 1, TIFFRaster::Rgb, [8, 8, 8], 3);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\0\0\0"], 279))->getImage());

		// A palette index must be an unsigned byte or less.
		$map = [320 => [TIFFDataType::UShort, array_fill(0, 3 * 256, 0)]];
		$tags = $format(TIFFRaster::FormatSigned) + $map + $this->baseTags(1, 1, TIFFRaster::Palette, [8], 1);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\0"], 279))->getImage());
		$tags = $map + $this->baseTags(1, 1, TIFFRaster::Palette, [16], 1);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\0\0"], 279))->getImage());

		// L*a*b* already defines which of its samples are signed.
		foreach ([TIFFRaster::CieLab, TIFFRaster::ICCLab] as $photometric) {
			$tags = $format(2, 2, 2) + $this->baseTags(1, 1, $photometric, [8, 8, 8], 3);
			self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\0\0\0"], 279))->getImage());
		}

		// A predictor the samples cannot take: differences of samples smaller than a byte,
		// the floating-point predictor on integers, and one the specification does not define.
		self::assertFalse($this->greyRow(4, "\0", [317 => [TIFFDataType::UShort, [2]]]));
		self::assertFalse($this->greyRow(16, "\0\0", [317 => [TIFFDataType::UShort, [3]]]));
		self::assertFalse($this->greyRow(8, "\0", [317 => [TIFFDataType::UShort, [4]]]));

		// A tag listing no formats at all leaves the default.
		self::assertSame('80', $this->greyRow(8, "\x80", $format()));
	}

	public function testTiledRasterWithoutTileDataAnswersFalse()
	{
		$tags = $this->baseTags(4, 4, TIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[322] = [TIFFDataType::ULong, [2]];
		$tags[323] = [TIFFDataType::ULong, [2]];

		$tiff = TIFFImage::fromString($this->buildBlocklessTiff($tags, 324, 325));
		self::assertNull($tiff->getEXIF()->getIfd0()->getTag(324)->getExternalData());
		self::assertFalse($tiff->getImage());
	}

	public function testPhotometricsMissingTheirRequiredSamplesAnswerFalse()
	{
		// Palette color without a ColorMap.
		$tags = $this->baseTags(2, 1, TIFFRaster::Palette, [8], 1);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00\x01"], 279))->getImage());

		// RGB with only two samples per pixel.
		$tags = $this->baseTags(2, 1, TIFFRaster::Rgb, [8, 8], 2);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [str_repeat("\x40", 4)], 279))->getImage());

		// Separated (CMYK) with three.
		$tags = $this->baseTags(2, 1, TIFFRaster::Separated, [8, 8, 8], 3);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [str_repeat("\x40", 6)], 279))->getImage());

		// CIE L*a*b* with two.
		$tags = $this->baseTags(2, 1, TIFFRaster::CieLab, [8, 8], 2);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, [str_repeat("\x40", 4)], 279))->getImage());

		// An unknown photometric interpretation is refused rather than guessed.
		$tags = $this->baseTags(2, 1, 100, [8], 1);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00\x01"], 279))->getImage());
	}

	public function testBlitClipsBlocksOutsideTheImage()
	{
		$plane = str_repeat("\x11", 4 * 2 * 3);   // a 4x2 RGB plane
		$untouched = $plane;

		// A block placed entirely past the right edge copies nothing.
		TTIFFRasterBlitProbe::blitBlock($plane, str_repeat("\xFF", 12), ['x' => 4, 'y' => 0, 'width' => 2, 'rows' => 2], 4, 2, 3);
		self::assertSame($untouched, $plane);

		// A block with fewer bytes than its declared rows stops when it runs out.
		TTIFFRasterBlitProbe::blitBlock($plane, str_repeat("\xFF", 12), ['x' => 0, 'y' => 0, 'width' => 4, 'rows' => 2], 4, 2, 3);
		self::assertSame(str_repeat("\xFF", 12) . str_repeat("\x11", 12), $plane);
	}

	public function testUnsupportedFormsAnswerFalse()
	{
		// Mixed sample depths are outside the model.
		$tags = $this->baseTags(2, 1, TIFFRaster::Rgb, [8, 4, 8], 3);
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ["\x00\x00\x00\x00\x00\x00"], 279))->getImage());

		// An unknown compression is refused rather than guessed.
		$tags = $this->baseTags(2, 1, TIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[259] = [TIFFDataType::UShort, [34712]];   // JPEG 2000
		self::assertFalse(TIFFImage::fromString($this->buildTiff($tags, 273, ['whatever'], 279))->getImage());
	}
}
