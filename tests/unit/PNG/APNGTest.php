<?php

use Belisoful\Image\PNG\APNGFrame;
use Belisoful\Image\PNG\PNGChunkType;
use Belisoful\Image\ImageChunk;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\PNGImage;

/**
 * Animated PNG (APNG): the acTL/fcTL/fdAT layer over the chunk model — authoring,
 * byte-faithful preservation, per-frame decode, and coexistence with metadata.
 */
class APNGTest extends PHPUnit\Framework\TestCase
{
	private function solid(int $r, int $g, int $b, int $w = 8, int $h = 6): \GdImage
	{
		$image = imagecreatetruecolor($w, $h);
		imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, $r, $g, $b));
		return $image;
	}

	private function animation(): PNGImage
	{
		return PNGImage::fromApngImages([$this->solid(200, 0, 0), $this->solid(0, 200, 0), $this->solid(0, 0, 200)], 0.2, 0);
	}

	public function testAuthorAndReadBack()
	{
		$apng = $this->animation();
		self::assertTrue($apng->getIsAnimated());
		self::assertSame(3, $apng->getFrameCount());
		self::assertSame(0, $apng->getPlayCount());
		self::assertSame([8, 6], [$apng->getWidth(), $apng->getHeight()]);

		// The acTL sits before IDAT, the default image's fcTL before IDAT, and each later
		// frame is an fcTL followed by fdAT.
		$types = array_map(fn (ImageChunk $c): string => $c->getType(), $apng->getChunks());
		self::assertSame(
			['IHDR', 'acTL', 'pHYs', 'fcTL', 'IDAT', 'fcTL', 'fdAT', 'fcTL', 'fdAT', 'IEND'],
			$types,
		);

		// A no-edit round trip is byte-faithful, and GD still reads the default image.
		$bytes = $apng->toBinary();
		self::assertSame(bin2hex($bytes), bin2hex(PNGImage::fromString($bytes)->toBinary()));
		self::assertInstanceOf(\GdImage::class, @imagecreatefromstring($bytes));
	}

	public function testFramesReadWithGeometryAndDelay()
	{
		$frames = PNGImage::fromString($this->animation()->toBinary())->getApngFrames();
		self::assertCount(3, $frames);

		self::assertTrue($frames[0]->getIsDefault());
		self::assertFalse($frames[1]->getIsDefault());
		foreach ($frames as $i => $frame) {
			self::assertSame([8, 6], [$frame->getWidth(), $frame->getHeight()], "frame $i size");
			self::assertEqualsWithDelta(0.2, $frame->getDelaySeconds(), 0.001, "frame $i delay");
			self::assertNotSame('', $frame->getData(), "frame $i data");
		}
	}

	public function testEachFrameDecodesToItsColor()
	{
		$apng = PNGImage::fromString($this->animation()->toBinary());
		foreach ([0 => [200, 0, 0], 1 => [0, 200, 0], 2 => [0, 0, 200]] as $index => $expected) {
			$image = $apng->getApngFrameImage($index);
			self::assertInstanceOf(\GdImage::class, $image, "frame $index");
			self::assertSame([8, 6], ImageGraphics::getSize($image));
			$rgb = array_map('ord', str_split(substr(ImageGraphics::rgbPixels($image), 0, 3)));
			self::assertEqualsWithDelta($expected, $rgb, 4, "frame $index color");
		}
	}

	public function testGetApngFrameImageRejectsAnUnknownIndex()
	{
		self::expectException(\RuntimeException::class);
		$this->animation()->getApngFrameImage(99);
	}

	public function testSetPlayCountAndAddFrame()
	{
		$apng = PNGImage::fromString($this->animation()->toBinary());
		$apng->setPlayCount(5);
		$apng->addApngFrame($this->solid(50, 50, 50), 0.1, APNGFrame::DisposeBackground, APNGFrame::BlendOver);

		$round = PNGImage::fromString($apng->toBinary());
		self::assertSame(4, $round->getFrameCount());
		self::assertSame(5, $round->getPlayCount());

		$frames = $round->getApngFrames();
		$last = $frames[count($frames) - 1];
		self::assertEqualsWithDelta(0.1, $last->getDelaySeconds(), 0.001);
		self::assertSame(APNGFrame::DisposeBackground, $last->getDisposeOp());
		self::assertSame(APNGFrame::BlendOver, $last->getBlendOp());
	}

	public function testSequenceNumbersAreContiguousAcrossFcTlAndFdAt()
	{
		// The single ascending sequence number is what a decoder validates; a gap or a
		// repeat rejects the file.
		$sequences = [];
		foreach (PNGImage::fromString($this->animation()->toBinary())->getChunks() as $chunk) {
			if (in_array($chunk->getType(), [PNGChunkType::FrameControl, PNGChunkType::FrameData], true)) {
				$sequences[] = unpack('N', substr($chunk->getData(), 0, 4))[1];
			}
		}
		self::assertSame(range(0, count($sequences) - 1), $sequences);
	}

	public function testMetadataCoexistsWithAnimation()
	{
		$apng = PNGImage::fromString($this->animation()->toBinary());
		$apng->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"></x:xmpmeta>');
		$apng->setICCProfile(ICCProfileBuilder::sRgb());

		$round = PNGImage::fromString($apng->toBinary());
		self::assertNotNull($round->getXmpText());
		self::assertSame(ICCProfileBuilder::sRgb(), $round->getICCProfile());
		self::assertTrue($round->getIsAnimated());
		self::assertSame(3, $round->getFrameCount());
		self::assertInstanceOf(\GdImage::class, @imagecreatefromstring($round->toBinary()));
	}

	public function testEditingFrameFieldsAndRebuilding()
	{
		$apng = PNGImage::fromString($this->animation()->toBinary());
		$frames = $apng->getApngFrames();
		$frames[1]->setDelaySeconds(0.5);
		$frames[1]->setDisposeOp(APNGFrame::DisposePrevious);
		$apng->setApngFrames($frames, 2);

		$round = PNGImage::fromString($apng->toBinary());
		self::assertSame(2, $round->getPlayCount());
		self::assertSame(3, $round->getFrameCount());
		$reFrames = $round->getApngFrames();
		self::assertEqualsWithDelta(0.5, $reFrames[1]->getDelaySeconds(), 0.001);
		self::assertSame(APNGFrame::DisposePrevious, $reFrames[1]->getDisposeOp());
	}

	public function testSetApngFramesRejectsAnEmptyList()
	{
		self::expectException(\RuntimeException::class);
		$this->animation()->setApngFrames([]);
	}

	public function testFromApngImagesRejectsAnEmptyList()
	{
		self::expectException(\RuntimeException::class);
		PNGImage::fromApngImages([]);
	}

	public function testStillPngIsNotAnimated()
	{
		ob_start();
		imagepng($this->solid(1, 2, 3));
		$still = PNGImage::fromString((string) ob_get_clean());
		self::assertFalse($still->getIsAnimated());
		self::assertSame(0, $still->getFrameCount());
		self::assertSame(0, $still->getPlayCount());
		self::assertSame([], $still->getApngFrames());
		$still->setPlayCount(4);   // a no-op on a still image
		self::assertSame(0, $still->getPlayCount());
	}

	public function testFramesWithOffsetsSetTheCanvas()
	{
		// A frame placed at an offset extends the canvas the header reports.
		$png = PNGImage::fromImage($this->solid(10, 20, 30, 4, 4));
		$base = $png->getApngFrames();   // none yet
		self::assertSame([], $base);

		$a = new APNGFrame();
		$a->setWidth(4);
		$a->setHeight(4);
		$a->setData($this->frameData($this->solid(10, 20, 30, 4, 4)));
		$b = new APNGFrame();
		$b->setWidth(4);
		$b->setHeight(4);
		$b->setXOffset(6);
		$b->setYOffset(2);
		$b->setData($this->frameData($this->solid(30, 20, 10, 4, 4)));

		$png->setApngFrames([$a, $b], 0);
		self::assertSame(10, $png->getWidth());   // 6 + 4
		self::assertSame(6, $png->getHeight());   // 2 + 4
		$round = PNGImage::fromString($png->toBinary());
		self::assertSame([6, 2], [$round->getApngFrames()[1]->getXOffset(), $round->getApngFrames()[1]->getYOffset()]);
	}

	/** A two-color palette image: blue on the left half, red (transparent) on the right. */
	private function paletted(int $w = 8, int $h = 6): \GdImage
	{
		$image = imagecreate($w, $h);
		$red = imagecolorallocate($image, 250, 40, 40);
		$blue = imagecolorallocate($image, 20, 30, 240);
		imagefilledrectangle($image, 0, 0, intdiv($w, 2) - 1, $h - 1, $blue);
		imagecolortransparent($image, $red);
		return $image;
	}

	public function testAPalettedFrameDecodesThroughThePaletteAndTransparencyChunks()
	{
		// A paletted APNG keeps its colors in PLTE/tRNS, which no frame carries: the
		// standalone PNG rebuilt for a frame has to bring them along or the indexes in the
		// frame data mean nothing.
		ob_start();
		imagepng($this->paletted());
		$png = PNGImage::fromString((string) ob_get_clean());
		self::assertSame(
			['IHDR', 'PLTE', 'tRNS', 'pHYs', 'IDAT', 'IEND'],
			array_map(fn (ImageChunk $c): string => $c->getType(), $png->getChunks()),
		);

		$png->addApngFrame($this->paletted(), 0.2);
		$png->addApngFrame($this->paletted(), 0.2);
		self::assertSame(2, $png->getFrameCount());

		$image = $png->getApngFrameImage(1);
		self::assertInstanceOf(\GdImage::class, $image);
		self::assertSame([8, 6], ImageGraphics::getSize($image));
		$rgb = ImageGraphics::rgbPixels($image);
		self::assertSame([20, 30, 240], array_map('ord', str_split(substr($rgb, 0, 3))));
		self::assertSame([250, 40, 40], array_map('ord', str_split(substr($rgb, -3))));
	}

	public function testFramesAreAppendedWhenTheEndMarkerIsMissing()
	{
		// The frame region belongs before IEND; with no IEND to sit before, it goes last
		// rather than being dropped.
		$png = PNGImage::fromImage($this->solid(90, 90, 90, 4, 4));
		self::assertTrue($png->removeChunk(PNGChunkType::End));

		$png->addApngFrame($this->solid(10, 190, 10, 4, 4), 0.1);
		$types = array_map(fn (ImageChunk $c): string => $c->getType(), $png->getChunks());
		self::assertSame('IDAT', end($types));
		self::assertNotContains(PNGChunkType::End, $types);
		self::assertSame(1, $png->getFrameCount());
		self::assertSame(4, $png->getApngFrames()[0]->getWidth());
	}

	public function testFrameEncodingRefusesAnImageItsLibraryCannotWrite()
	{
		if (!extension_loaded('imagick')) {
			self::markTestSkipped('ext-imagick is not loaded.');
		}
		// encodeFrameData turns an image into the IDAT payload a frame carries; when the
		// library cannot produce PNG bytes for it, the frame is refused rather than stored
		// empty.
		$png = new class () extends PNGImage {
			public function frameData(\GdImage|\Imagick $image): string
			{
				return $this->encodeFrameData($image);
			}
		};
		// A GD image gives the whole zlib stream of its encoding — four filtered rows of
		// four RGB pixels, so a decoder reading the frame gets a complete image...
		$data = $png->frameData($this->solid(4, 5, 6, 4, 4));
		self::assertSame(4 * (1 + 4 * 3), strlen((string) @gzuncompress($data)));

		// ...and an image no PNG bytes can be had from is refused.
		self::expectException(\RuntimeException::class);
		$png->frameData(new \Imagick());
	}

	private function frameData(\GdImage $image): string
	{
		ob_start();
		imagepng($image);
		$data = '';
		foreach (PNGImage::fromString((string) ob_get_clean())->getChunks() as $chunk) {
			if ($chunk->getType() === 'IDAT') {
				$data .= $chunk->getData();
			}
		}
		return $data;
	}

	public function testFrameValueObjectAccessors()
	{
		$frame = new APNGFrame();
		$frame->setWidth(-5);
		self::assertSame(0, $frame->getWidth());   // clamped
		$frame->setHeight(12);
		$frame->setXOffset(3);
		$frame->setYOffset(4);
		$frame->setDelayNum(50);
		$frame->setDelayDen(0);                    // 0 denominator means hundredths
		self::assertSame(0.5, $frame->getDelaySeconds());
		$frame->setDelayDen(25);
		$frame->setDelayNum(5);
		self::assertEqualsWithDelta(0.2, $frame->getDelaySeconds(), 0.001);

		// The fcTL fields round-trip through loadFcTl (with a leading sequence number).
		$payload = pack('N', 7) . $frame->fcTlFields();
		self::assertSame(26, strlen($payload));
		$reloaded = (new APNGFrame())->loadFcTl($payload);
		self::assertSame(12, $reloaded->getHeight());
		self::assertSame([3, 4], [$reloaded->getXOffset(), $reloaded->getYOffset()]);
		self::assertSame(5, $reloaded->getDelayNum());
		self::assertSame(25, $reloaded->getDelayDen());

		// A short payload leaves the defaults.
		self::assertSame(0, (new APNGFrame())->loadFcTl('too short')->getWidth());
	}
}
