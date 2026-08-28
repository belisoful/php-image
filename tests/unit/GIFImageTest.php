<?php

use Belisoful\Image\Compression\GIFLZWCompressor;
use Belisoful\Image\GIF\GIFBlockType;
use Belisoful\Image\GIF\GIFExtension;
use Belisoful\Image\GIF\GIFFrame;
use Belisoful\Image\GIFImage;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\ImageGraphicsMode;
use Belisoful\Image\PrivacyCategory;

class GIFImageTest extends PHPUnit\Framework\TestCase
{
	/** The four colors of the global table: red, green, blue, white. */
	private const GlobalTable = "\xff\x00\x00\x00\xff\x00\x00\x00\xff\xff\xff\xff";

	/** A distinct four-color local table. */
	private const LocalTable = "\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc";

	/**
	 * Frames pixel indexes into a minimum-code-size byte plus LZW sub-blocks.
	 * @param string $indexes
	 * @param int $minCodeSize
	 */
	private function lzwBlocks(string $indexes, int $minCodeSize): string
	{
		$out = chr($minCodeSize);
		foreach (str_split(GIFLZWCompressor::compress($indexes, $minCodeSize), 255) as $block) {
			$out .= chr(strlen($block)) . $block;
		}
		return $out . "\x00";
	}

	/**
	 * Builds a GIF89a exercising the whole standard: a global table, a loop block, a
	 * private application extension, a comment, two frames with graphic control
	 * extensions, an interlaced first frame, and a local table plus sub-rectangle on
	 * the second.
	 */
	private function richGif(): string
	{
		$gif = GIFImage::Signature89a . pack('vv', 4, 1) . chr(0x80 | 0x01) . chr(2) . chr(0) . self::GlobalTable;
		$gif .= "\x21\xff\x0bNETSCAPE2.0\x03\x01" . pack('v', 7) . "\x00";
		$gif .= "\x21\xff\x0b" . GIFImage::XmpIdentity . chr(4) . 'xmp!' . "\x00";
		$gif .= "\x21\xfe" . chr(11) . 'prado-image' . "\x00";
		// Frame 1: 50cs delay, restore-background disposal, transparent index 3, interlaced.
		$gif .= "\x21\xf9\x04" . chr((2 << 2) | 0x01) . pack('v', 50) . chr(3) . "\x00";
		$gif .= "\x2c" . pack('vvvv', 0, 0, 4, 1) . chr(0x40) . $this->lzwBlocks("\x00\x01\x02\x03", 2);
		// Frame 2: 10cs delay, do-not-dispose, local color table, sub-rectangle at +1+0.
		$gif .= "\x21\xf9\x04" . chr(1 << 2) . pack('v', 10) . chr(0) . "\x00";
		$gif .= "\x2c" . pack('vvvv', 1, 0, 2, 1) . chr(0x80 | 0x01) . self::LocalTable . $this->lzwBlocks("\x03\x02", 2);
		return $gif . chr(GIFBlockType::Trailer);
	}

	/** Generates a paletted GIF in memory with GD. */
	private function gdGif(int $width = 6, int $height = 4): string
	{
		$im = imagecreate($width, $height);
		$colors = [
			imagecolorallocate($im, 255, 0, 0),
			imagecolorallocate($im, 0, 255, 0),
			imagecolorallocate($im, 0, 0, 255),
		];
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				imagesetpixel($im, $x, $y, $colors[($x + $y) % 3]);
			}
		}
		ob_start();
		imagegif($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	/**
	 * Returns the Image Descriptor's packed field: the byte after the separator and the
	 * four geometry words, past the fixed eight bytes of a Graphic Control Extension.
	 * @param GIFFrame $frame
	 */
	private function packedField(GIFFrame $frame): int
	{
		$binary = $frame->toBinary();
		$at = $frame->getHasGraphicControl() ? 8 : 0;
		self::assertSame(chr(GIFBlockType::ImageSeparator), $binary[$at]);
		return ord($binary[$at + 9]);
	}

	//
	// ─── Parsing and byte faithfulness ───────────────────────────────────────
	//

	public function testParsesEveryPartOfTheStandard()
	{
		$gif = GIFImage::fromString($this->richGif());

		self::assertSame('GIF', $gif->getFormat());
		self::assertSame(GIFImage::Signature89a, $gif->getVersion());
		self::assertSame(4, $gif->getWidth());
		self::assertSame(1, $gif->getHeight());
		self::assertSame(2, $gif->getBackgroundIndex());
		self::assertSame(0, $gif->getAspectRatio());
		self::assertSame(self::GlobalTable, $gif->getGlobalColorTable());
		self::assertSame(7, $gif->getLoopCount());
		self::assertSame(['prado-image'], $gif->getComments());
		self::assertSame(2, $gif->getFrameCount());
		self::assertTrue($gif->getIsAnimated());
	}

	public function testFrameFieldsAreParsed()
	{
		$gif = GIFImage::fromString($this->richGif());

		$first = $gif->getFrame(0);
		self::assertSame([0, 0, 4, 1], [$first->getLeft(), $first->getTop(), $first->getWidth(), $first->getHeight()]);
		self::assertTrue($first->getHasGraphicControl());
		self::assertSame(50, $first->getDelayTime());
		self::assertSame(GIFFrame::DisposalRestoreBackground, $first->getDisposalMethod());
		self::assertSame(3, $first->getTransparentIndex());
		self::assertFalse($first->getUserInput());
		self::assertTrue($first->getInterlaced());
		self::assertFalse($first->getHasLocalColorTable());
		self::assertSame("\x00\x01\x02\x03", $first->getPixels());

		$second = $gif->getFrame(1);
		self::assertSame([1, 0, 2, 1], [$second->getLeft(), $second->getTop(), $second->getWidth(), $second->getHeight()]);
		self::assertSame(10, $second->getDelayTime());
		self::assertSame(GIFFrame::DisposalNone, $second->getDisposalMethod());
		self::assertNull($second->getTransparentIndex());
		self::assertFalse($second->getInterlaced());
		self::assertTrue($second->getHasLocalColorTable());
		self::assertSame(self::LocalTable, $second->getLocalColorTable());
		self::assertSame("\x03\x02", $second->getPixels());

		self::assertNull($gif->getFrame(2));
	}

	public function testRoundTripIsByteFaithful()
	{
		$source = $this->richGif();
		self::assertSame($source, GIFImage::fromString($source)->toBinary());

		// And stable across repeated cycles.
		$once = GIFImage::fromString($source)->toBinary();
		self::assertSame($once, GIFImage::fromString($once)->toBinary());
	}

	public function testGdWrittenGifRoundTripsByteFaithfully()
	{
		$bytes = $this->gdGif();
		$gif = GIFImage::fromString($bytes);
		self::assertSame(6, $gif->getWidth());
		self::assertSame(4, $gif->getHeight());
		self::assertSame(1, $gif->getFrameCount());
		self::assertSame($bytes, $gif->toBinary());
	}

	public function testGif87aRoundTrips()
	{
		$bytes = GIFImage::Signature87a . substr($this->gdGif(4, 4), 6);
		$gif = GIFImage::fromString($bytes);
		self::assertSame(GIFImage::Signature87a, $gif->getVersion());
		self::assertNull($gif->getLoopCount());
		self::assertSame($bytes, $gif->toBinary());
	}

	public function testTrailingBytesAfterTheTrailerArePreserved()
	{
		$bytes = $this->gdGif() . 'JUNKJUNK';
		self::assertSame($bytes, GIFImage::fromString($bytes)->toBinary());
	}

	public function testSubBlockFramingIsPreserved()
	{
		// A comment deliberately split at an unusual boundary must be re-emitted
		// with the same split, not re-packed.
		$split = "\x21\xfe" . chr(3) . 'abc' . chr(2) . 'de' . "\x00";
		$bytes = substr($this->richGif(), 0, -1) . $split . chr(GIFBlockType::Trailer);
		$gif = GIFImage::fromString($bytes);
		self::assertContains('abcde', $gif->getComments());
		self::assertSame($bytes, $gif->toBinary());
	}

	//
	// ─── Application extensions ──────────────────────────────────────────────
	//

	public function testApplicationIdentityCaseIsPreserved()
	{
		$gif = GIFImage::fromString($this->richGif());
		// The metadata identities are case-sensitive and must survive verbatim.
		$xmp = $gif->getApplicationExtension(GIFImage::XmpIdentity);
		self::assertNotNull($xmp);
		self::assertSame('XMP DataXMP', $xmp->getApplicationIdentifier());
		self::assertSame('xmp!', $xmp->getApplicationData());
		self::assertNull($gif->getApplicationExtension('xmp dataxmp'));
		self::assertNull($gif->getApplicationExtension(GIFImage::ICCIdentity));

		self::assertStringContainsString('XMP DataXMP', $gif->toBinary());
	}

	public function testExtensionAccessors()
	{
		$gif = GIFImage::fromString($this->richGif());
		self::assertCount(3, $gif->getExtensions());
		self::assertCount(2, $gif->getExtensions(GIFBlockType::ApplicationLabel));
		self::assertCount(1, $gif->getExtensions(GIFBlockType::CommentLabel));
		self::assertCount(0, $gif->getExtensions(GIFBlockType::PlainTextLabel));

		$comment = $gif->getExtensions(GIFBlockType::CommentLabel)[0];
		self::assertFalse($comment->getIsApplication());
		self::assertNull($comment->getApplicationIdentifier());
		self::assertSame('', $comment->getApplicationData());
	}

	public function testExtensionDataSplitsAtTheSubBlockMaximum()
	{
		$extension = GIFExtension::comment(str_repeat('x', 600));
		self::assertCount(3, $extension->getSubBlocks());
		self::assertSame([255, 255, 90], array_map('strlen', $extension->getSubBlocks()));
		self::assertSame(str_repeat('x', 600), $extension->getData());

		$empty = new GIFExtension(GIFBlockType::CommentLabel);
		$empty->setData('');
		self::assertSame([], $empty->getSubBlocks());
		self::assertSame("\x21\xfe\x00", $empty->toBinary());
	}

	public function testAddCommentAndPlainTextSurviveARoundTrip()
	{
		$gif = GIFImage::fromString($this->gdGif());
		$gif->addComment('written by prado-image');
		$gif->addExtension(new GIFExtension(GIFBlockType::PlainTextLabel, ["\x00" . str_repeat("\x01", 11)]));

		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame(['written by prado-image'], $reread->getComments());
		self::assertCount(1, $reread->getExtensions(GIFBlockType::PlainTextLabel));
	}

	//
	// ─── Loop count ──────────────────────────────────────────────────────────
	//

	public function testLoopCountReadWriteAndRemove()
	{
		$gif = GIFImage::fromString($this->gdGif());
		self::assertNull($gif->getLoopCount());

		$gif->setLoopCount(0);            // forever
		self::assertSame(0, $gif->getLoopCount());
		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame(0, $reread->getLoopCount());
		// The loop block must precede the first frame.
		$blocks = $reread->getBlocks();
		self::assertInstanceOf(GIFExtension::class, $blocks[0]);
		self::assertSame(GIFImage::NetscapeIdentity, $blocks[0]->getApplicationIdentifier());

		$reread->setLoopCount(12);
		self::assertSame(12, $reread->getLoopCount());
		self::assertCount(1, $reread->getExtensions(GIFBlockType::ApplicationLabel));

		$reread->setLoopCount(null);
		self::assertNull($reread->getLoopCount());
		self::assertCount(0, $reread->getExtensions(GIFBlockType::ApplicationLabel));
		self::assertNull(GIFImage::fromString($reread->toBinary())->getLoopCount());
	}

	public function testLoopCountEditKeepsTheExistingBlockPosition()
	{
		$gif = GIFImage::fromString($this->richGif());
		$gif->setLoopCount(1);
		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame(1, $reread->getLoopCount());
		self::assertCount(2, $reread->getExtensions(GIFBlockType::ApplicationLabel));
	}

	public function testTruncatedLoopBlockReadsAsNoLoopCountAndIsKeptAsWritten()
	{
		// The sub-block is a 1-byte id then a 16-bit count; a writer that emitted only the
		// id leaves no count to read.  The block itself is another writer's data, so it is
		// preserved rather than repaired or dropped.
		$gif = GIFImage::fromString($this->gdGif());
		$gif->addExtension(GIFExtension::application(GIFImage::NetscapeIdentity, "\x01"));
		self::assertNull($gif->getLoopCount());

		$reread = GIFImage::fromString($gif->toBinary());
		self::assertNull($reread->getLoopCount());
		self::assertSame("\x01", $reread->getApplicationExtension(GIFImage::NetscapeIdentity)?->getApplicationData());

		// A whole block in the same place does have a count.
		$reread->setLoopCount(4);
		self::assertSame(4, $reread->getLoopCount());
		self::assertCount(1, $reread->getExtensions(GIFBlockType::ApplicationLabel));
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	public function testCommentsAreRemovedOnlyByTheDescriptionCategory()
	{
		// A comment is free text, so it goes under Description; a scrub of any other
		// category leaves the GIF's own carrier alone rather than emptying it.
		$gif = GIFImage::fromString($this->richGif());
		self::assertSame(['prado-image'], $gif->getComments());

		self::assertSame(0, $gif->clearPrivateData(PrivacyCategory::Location | PrivacyCategory::Author));
		self::assertSame(['prado-image'], $gif->getComments());
		self::assertSame($this->richGif(), $gif->toBinary());

		self::assertSame(1, $gif->clearPrivateData(PrivacyCategory::Description));
		self::assertSame([], $gif->getComments());
		// Only the comment went: the frames, their controls, and the other extensions stay.
		self::assertSame(2, $gif->getFrameCount());
		self::assertSame(7, $gif->getLoopCount());
		self::assertNotNull($gif->getXmpText());
	}

	//
	// ─── Pixels and interlacing ──────────────────────────────────────────────
	//

	public function testInterlaceHelpersAreInverse()
	{
		$rows = '';
		for ($y = 0; $y < 9; $y++) {
			$rows .= str_repeat(chr($y), 3);
		}
		$woven = GIFFrame::interlace($rows, 3, 9);
		self::assertNotSame($rows, $woven);
		self::assertSame($rows, GIFFrame::deinterlace($woven, 3, 9));
		// Pass one is rows 0 and 8, so the woven data starts with row 0 then row 8.
		self::assertSame(str_repeat(chr(0), 3) . str_repeat(chr(8), 3), substr($woven, 0, 6));
	}

	public function testInterlacingAnEmptyFrameLeavesTheDataAlone()
	{
		// A frame with no rows or no columns has no pass structure to weave: the four-pass
		// loop would silently drop the data, so both helpers hand it back untouched.
		self::assertSame('abc', GIFFrame::interlace('abc', 0, 4));
		self::assertSame('abc', GIFFrame::interlace('abc', 4, 0));
		self::assertSame('abc', GIFFrame::deinterlace('abc', 0, 4));
		self::assertSame('abc', GIFFrame::deinterlace('abc', 4, 0));
		self::assertSame('', GIFFrame::interlace('', 0, 0));
		self::assertSame('', GIFFrame::deinterlace('', 0, 0));
	}

	public function testFlippingInterlacePreservesPixels()
	{
		$gif = GIFImage::fromString($this->gdGif(8, 8));
		$frame = $gif->getFrame(0);
		$before = $frame->getPixels();

		$frame->setInterlaced(true);
		self::assertTrue($frame->getInterlaced());
		self::assertSame($before, $frame->getPixels());

		$frame->setInterlaced(false);
		self::assertSame($before, $frame->getPixels());

		// Setting the same value again is a no-op.
		$frame->setInterlaced(false);
		self::assertSame($before, $frame->getPixels());
	}

	public function testPixelEditRoundTrips()
	{
		$gif = GIFImage::fromString($this->gdGif());
		$frame = $gif->getFrame(0);
		$flooded = str_repeat(chr(1), $frame->getWidth() * $frame->getHeight());
		$frame->setPixels($flooded);

		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame($flooded, $reread->getFrame(0)->getPixels());
	}

	public function testMinCodeSizeRisesToFitTheIndexes()
	{
		$frame = new GIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(1);
		self::assertSame(8, $frame->getMinCodeSize());

		$frame->setMinCodeSize(2);
		// Index 9 needs four bits, so the code size is raised rather than failing.
		$frame->setPixels("\x00\x09\x02\x03");
		self::assertSame(4, $frame->getMinCodeSize());
		self::assertSame("\x00\x09\x02\x03", $frame->getPixels());

		self::assertSame(2, GIFFrame::minCodeSizeForPixels("\x00\x01\x03"));
		self::assertSame(3, GIFFrame::minCodeSizeForPixels("\x00\x04"));
		self::assertSame(8, GIFFrame::minCodeSizeForPixels("\xff"));
		self::assertSame(2, GIFFrame::minCodeSizeForPixels(''));
	}

	public function testExplicitMinCodeSizeTooSmallIsRejected()
	{
		$frame = new GIFFrame();
		$frame->setWidth(2);
		$frame->setHeight(1);
		self::expectException(\InvalidArgumentException::class);
		$frame->setPixels("\x00\x09", 2);
	}

	public function testExplicitMinCodeSizeAboveWhatTheIndexesNeedIsKept()
	{
		// A code size wider than the indexes require is legal — it is the palette's size,
		// not the pixels' — so an explicit one is stored as given rather than shrunk.
		$gif = GIFImage::fromString($this->gdGif(4, 2));
		$frame = $gif->getFrame(0);
		$pixels = str_repeat("\x01\x00", 4);
		$frame->setPixels($pixels, 7);
		self::assertSame(7, $frame->getMinCodeSize());

		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame(7, $reread->getFrame(0)->getMinCodeSize());
		self::assertSame($pixels, $reread->getFrame(0)->getPixels());
	}

	public function testPixelCountMustMatchTheFrame()
	{
		$frame = new GIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(2);
		self::expectException(\InvalidArgumentException::class);
		$frame->setPixels("\x00\x01");
	}

	public function testShortAndLongLzwDataAreNormalizedToTheFrameSize()
	{
		$frame = new GIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(1);
		$frame->setMinCodeSize(2);
		// Six pixels of data in a four-pixel frame is truncated...
		$frame->setLzwData(GIFLZWCompressor::compress("\x01\x02\x03\x00\x01\x02", 2));
		self::assertSame("\x01\x02\x03\x00", $frame->getPixels());
		// ...and two pixels of data is zero-filled.
		$frame->setLzwData(GIFLZWCompressor::compress("\x01\x02", 2));
		self::assertSame("\x01\x02\x00\x00", $frame->getPixels());
	}

	public function testEmptyLzwDataLeavesNoSubBlocks()
	{
		$frame = new GIFFrame();
		$frame->setWidth(2);
		$frame->setHeight(1);
		$frame->setMinCodeSize(2);
		$frame->setPixels("\x01\x02");
		self::assertNotSame([], $frame->getDataSubBlocks());

		// Emptying the data writes no sub-block at all, not a zero-length one — a
		// zero-length sub-block is the terminator, so an empty block would end the chain
		// twice.
		$frame->setLzwData('');
		self::assertSame([], $frame->getDataSubBlocks());
		self::assertSame('', $frame->getLzwData());
		$binary = $frame->toBinary();
		self::assertSame(chr(2) . "\x00", substr($binary, -2));
		self::assertSame(12, strlen($binary));   // separator, geometry, packed, code size, terminator

		// The frame still reports its declared pixel count, zero-filled.
		self::assertSame("\x00\x00", $frame->getPixels());
	}

	//
	// ─── Color tables ────────────────────────────────────────────────────────
	//

	public function testColorTablesArePaddedToAPowerOfTwo()
	{
		// Three colors pad to four entries.
		$frame = new GIFFrame();
		$frame->setLocalColorTable("\x01\x02\x03\x04\x05\x06\x07\x08\x09");
		self::assertSame(12, strlen($frame->getLocalColorTable()));
		self::assertSame("\x00\x00\x00", substr($frame->getLocalColorTable(), 9, 3));
		self::assertSame(1, GIFFrame::tableSizeBits($frame->getLocalColorTable()));

		self::assertSame(0, GIFFrame::tableSizeBits(str_repeat("\0", 6)));      // 2 colors
		self::assertSame(7, GIFFrame::tableSizeBits(str_repeat("\0", 768)));    // 256 colors
		// Four colors need two bits, eight need three; GIF never goes below two.
		self::assertSame(2, GIFFrame::minCodeSizeFor(str_repeat("\0", 12)));
		self::assertSame(2, GIFFrame::minCodeSizeFor(str_repeat("\0", 6)));
		self::assertSame(3, GIFFrame::minCodeSizeFor(str_repeat("\0", 24)));
		self::assertSame(8, GIFFrame::minCodeSizeFor(str_repeat("\0", 768)));
		self::assertSame(8, GIFFrame::minCodeSizeFor(null));
	}

	public function testMalformedColorTablesAreRejected()
	{
		$frame = new GIFFrame();
		self::expectException(\InvalidArgumentException::class);
		$frame->setLocalColorTable("\x01\x02");    // not whole triplets
	}

	public function testOversizedColorTableIsRejected()
	{
		$gif = GIFImage::fromString($this->gdGif());
		self::expectException(\InvalidArgumentException::class);
		$gif->setGlobalColorTable(str_repeat("\0", 3 * 257));
	}

	public function testGlobalColorTableCanBeClearedAndSet()
	{
		$gif = GIFImage::fromString($this->gdGif());
		self::assertNotNull($gif->getGlobalColorTable());
		$gif->setGlobalColorTable(null);
		self::assertNull($gif->getGlobalColorTable());
		// With no global table the packed field drops its flag.
		self::assertSame(0, ord($gif->toBinary()[10]) & 0x80);

		$gif->setGlobalColorTable(self::GlobalTable);
		self::assertSame(self::GlobalTable, GIFImage::fromString($gif->toBinary())->getGlobalColorTable());
	}

	public function testClearingALocalColorTableSendsTheFrameBackToTheGlobalOne()
	{
		$gif = GIFImage::fromString($this->richGif());
		$frame = $gif->getFrame(1);
		self::assertTrue($frame->getHasLocalColorTable());
		$withTable = strlen($frame->toBinary());

		$frame->setLocalColorTable(null);
		self::assertNull($frame->getLocalColorTable());
		self::assertFalse($frame->getHasLocalColorTable());

		// The descriptor drops the local-table flag and its size bits with the table.
		self::assertSame(0, $this->packedField($frame) & 0x87);
		self::assertSame($withTable - strlen(self::LocalTable), strlen($frame->toBinary()));

		// So the frame now renders against the file's global table.
		self::assertSame(self::GlobalTable, $gif->getFramePalette(1));
		$reread = GIFImage::fromString($gif->toBinary());
		self::assertFalse($reread->getFrame(1)->getHasLocalColorTable());
		self::assertSame("\x03\x02", $reread->getFrame(1)->getPixels());
	}

	public function testSortedLocalColorTableFlagIsWrittenAndRead()
	{
		$gif = GIFImage::fromString($this->richGif());
		$frame = $gif->getFrame(1);
		self::assertFalse($frame->getSorted());
		self::assertSame(0, $this->packedField($frame) & 0x20);

		// The sort flag says the table is ordered by importance; it is the writer's claim
		// about its own table, so it must survive the trip rather than being normalized.
		$frame->setSorted(true);
		self::assertSame(0x20, $this->packedField($frame) & 0x20);

		$reread = GIFImage::fromString($gif->toBinary());
		self::assertTrue($reread->getFrame(1)->getSorted());
		self::assertSame(self::LocalTable, $reread->getFrame(1)->getLocalColorTable());
	}

	//
	// ─── Frame collection ────────────────────────────────────────────────────
	//

	public function testFramesCanBeAddedAndRemoved()
	{
		$gif = GIFImage::fromString($this->richGif());
		self::assertSame(2, $gif->getFrameCount());

		$frame = new GIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(1);
		$frame->setDelayTime(5);
		$frame->setPixels("\x01\x01\x01\x01");
		$gif->addFrame($frame);
		self::assertSame(3, $gif->getFrameCount());

		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame(3, $reread->getFrameCount());
		self::assertSame(5, $reread->getFrame(2)->getDelayTime());

		self::assertTrue($reread->removeFrame(1));
		self::assertSame(2, $reread->getFrameCount());
		// The remaining frames keep their order and their extensions survive.
		self::assertSame(50, $reread->getFrame(0)->getDelayTime());
		self::assertSame(5, $reread->getFrame(1)->getDelayTime());
		self::assertSame(['prado-image'], $reread->getComments());
		self::assertFalse($reread->removeFrame(9));
	}

	public function testGraphicControlIsImpliedByItsFields()
	{
		$frame = new GIFFrame();
		self::assertFalse($frame->getHasGraphicControl());

		$frame->setDelayTime(10);
		self::assertTrue($frame->getHasGraphicControl());

		$other = new GIFFrame();
		$other->setTransparentIndex(4);
		self::assertTrue($other->getHasGraphicControl());

		$third = new GIFFrame();
		$third->setDisposalMethod(GIFFrame::DisposalRestorePrevious);
		self::assertTrue($third->getHasGraphicControl());

		$fourth = new GIFFrame();
		$fourth->setUserInput(true);
		self::assertTrue($fourth->getHasGraphicControl());
	}

	public function testUserInputAndReservedBitsRoundTrip()
	{
		$gif = GIFImage::fromString($this->gdGif());
		$frame = $gif->getFrame(0);
		$frame->setUserInput(true);
		$frame->setGraphicControlReserved(5);
		$frame->setTransparentIndex(2);

		$reread = GIFImage::fromString($gif->toBinary())->getFrame(0);
		self::assertTrue($reread->getUserInput());
		self::assertSame(5, $reread->getGraphicControlReserved());
		self::assertSame(2, $reread->getTransparentIndex());

		// Clearing transparency keeps the extension (user input still needs it) but
		// drops the flag and the index.
		$frame->setTransparentIndex(null);
		$cleared = GIFImage::fromString($gif->toBinary())->getFrame(0);
		self::assertNull($cleared->getTransparentIndex());
		self::assertTrue($cleared->getHasGraphicControl());
		self::assertTrue($cleared->getUserInput());
	}

	public function testInvalidFrameFieldsAreRejected()
	{
		$frame = new GIFFrame();
		try {
			$frame->setDisposalMethod(9);
			self::fail('an out-of-range disposal method was accepted');
		} catch (\InvalidArgumentException $e) {
		}
		try {
			$frame->setTransparentIndex(300);
			self::fail('an out-of-range transparent index was accepted');
		} catch (\InvalidArgumentException $e) {
		}
		self::expectException(\InvalidArgumentException::class);
		$frame->setMinCodeSize(1);
	}

	public function testInvalidVersionIsRejected()
	{
		$gif = GIFImage::fromString($this->gdGif());
		$gif->setVersion(GIFImage::Signature87a);
		self::assertSame(GIFImage::Signature87a, $gif->getVersion());
		self::expectException(\InvalidArgumentException::class);
		$gif->setVersion('GIF90a');
	}

	//
	// ─── Raster conversion ───────────────────────────────────────────────────
	//

	public function testFrameRendersThroughTheGraphicsSeam()
	{
		$gif = GIFImage::fromString($this->gdGif());
		$image = $gif->getFrameImage(0);
		self::assertSame(6, imagesx($image));
		self::assertSame(4, imagesy($image));

		// The palette maps index 0 to red, 1 to green, 2 to blue, laid out (x+y)%3.
		$rgb = imagecolorat($image, 0, 0);
		self::assertSame(0xFF0000, $rgb & 0xFFFFFF);
		self::assertSame(0x00FF00, imagecolorat($image, 1, 0) & 0xFFFFFF);
		self::assertSame(0x0000FF, imagecolorat($image, 2, 0) & 0xFFFFFF);
		imagedestroy($image);

		// getImage() is the first frame.
		$first = $gif->getImage();
		self::assertSame(6, imagesx($first));
		imagedestroy($first);
	}

	public function testFrameWithLocalTableRendersAgainstIt()
	{
		$gif = GIFImage::fromString($this->richGif());
		self::assertSame(self::LocalTable, $gif->getFramePalette(1));
		self::assertSame(self::GlobalTable, $gif->getFramePalette(0));

		$image = $gif->getFrameImage(1);
		// Frame 2 is indexes 3 then 2 against the local table.
		self::assertSame(0xAABBCC, imagecolorat($image, 0, 0) & 0xFFFFFF);
		self::assertSame(0x778899, imagecolorat($image, 1, 0) & 0xFFFFFF);
		imagedestroy($image);
	}

	public function testIndexesPastTheEndOfTheTableRenderAsEntryZero()
	{
		// A frame may hold indexes its table has no entry for — a code size of two allows
		// four indexes against a two-color table.  Those pixels take entry zero rather
		// than reading past the table.
		$frame = new GIFFrame();
		$frame->setWidth(3);
		$frame->setHeight(1);
		$frame->setPixels("\x01\x03\x00");

		$image = $frame->getImage("\xff\x00\x00" . "\x00\xff\x00", ImageGraphicsMode::GD);
		self::assertSame(0x00FF00, imagecolorat($image, 0, 0) & 0xFFFFFF);   // index 1: green
		self::assertSame(0xFF0000, imagecolorat($image, 1, 0) & 0xFFFFFF);   // index 3: past the end
		self::assertSame(0xFF0000, imagecolorat($image, 2, 0) & 0xFFFFFF);   // index 0: red
		imagedestroy($image);
	}

	public function testAPaletteTooShortForOneColorRendersFromWhatItHas()
	{
		// Less than a whole triplet is not a color table; every index then takes what
		// stands at the start of it.  GD warns about the bytes the triplet is missing;
		// the point of the test is that the render is attempted, not abandoned.
		$frame = new GIFFrame();
		$frame->setWidth(1);
		$frame->setHeight(1);
		$frame->setPixels("\x05");

		$image = @$frame->getImage("\xaa\xbb", ImageGraphicsMode::GD);
		self::assertSame([1, 1], ImageGraphics::getSize($image));
		self::assertSame(0xAABB00, imagecolorat($image, 0, 0) & 0xFFFFFF);
		imagedestroy($image);
	}

	public function testSetImageOnAnInterlacedFrameStoresTheRowsInterlaced()
	{
		// The interlace flag is the frame's own, kept as authored; quantizing an image
		// into it must write the rows in the four-pass order the flag promises.
		$source = imagecreatetruecolor(8, 8);
		for ($y = 0; $y < 8; $y++) {
			imagefilledrectangle($source, 0, $y, 7, $y, imagecolorallocate($source, $y * 30, 255 - $y * 30, 60));
		}

		$plain = new GIFFrame();
		$plain->setImage($source);

		$interlaced = new GIFFrame();
		$interlaced->setInterlaced(true);
		$interlaced->setImage($source);
		imagedestroy($source);

		// Same pixels, laid down in a different order: the stored bytes differ, and
		// getPixels() undoes the interlace to give the rows back top to bottom.
		self::assertTrue($interlaced->getInterlaced());
		self::assertSame($plain->getPixels(), $interlaced->getPixels());
		self::assertNotSame($plain->getLzwData(), $interlaced->getLzwData());
		self::assertSame(
			GIFFrame::interlace($plain->getPixels(), 8, 8),
			GIFLZWCompressor::decompress($interlaced->getLzwData(), $interlaced->getMinCodeSize()),
		);

		// And the rows still read back as the colors they were drawn with.
		$image = $interlaced->getImage((string) $interlaced->getLocalColorTable(), ImageGraphicsMode::GD);
		for ($y = 0; $y < 8; $y++) {
			$rgb = imagecolorat($image, 0, $y) & 0xFFFFFF;
			self::assertEqualsWithDelta($y * 30, ($rgb >> 16) & 0xFF, 8, "row $y red");
			self::assertEqualsWithDelta(255 - $y * 30, ($rgb >> 8) & 0xFF, 8, "row $y green");
		}
		imagedestroy($image);
	}

	public function testSetImageQuantizesIntoTheFrame()
	{
		$source = imagecreatetruecolor(12, 6);
		for ($y = 0; $y < 6; $y++) {
			for ($x = 0; $x < 12; $x++) {
				imagesetpixel($source, $x, $y, imagecolorallocate($source, $x * 20, $y * 40, 128));
			}
		}
		$gif = GIFImage::fromString($this->gdGif());
		$frame = $gif->getFrame(0);
		$frame->setImage($source);
		imagedestroy($source);

		self::assertSame(12, $frame->getWidth());
		self::assertSame(6, $frame->getHeight());
		self::assertTrue($frame->getHasLocalColorTable());
		self::assertSame(72, strlen($frame->getPixels()));

		$gif->setScreenSize(12, 6);
		$reread = GIFImage::fromString($gif->toBinary());
		self::assertSame(12, $reread->getWidth());
		self::assertSame(72, strlen($reread->getFrame(0)->getPixels()));
	}

	public function testFromImageBuildsASingleFrameGif()
	{
		$source = imagecreatetruecolor(20, 10);
		for ($y = 0; $y < 10; $y++) {
			for ($x = 0; $x < 20; $x++) {
				imagesetpixel($source, $x, $y, imagecolorallocate($source, $x * 12, $y * 25, 90));
			}
		}
		$gif = GIFImage::fromImage($source);
		imagedestroy($source);

		self::assertSame(20, $gif->getWidth());
		self::assertSame(10, $gif->getHeight());
		self::assertSame(1, $gif->getFrameCount());
		self::assertFalse($gif->getIsAnimated());
		self::assertNotNull($gif->getGlobalColorTable());

		$bytes = $gif->toBinary();
		self::assertSame($bytes, GIFImage::fromString($bytes)->toBinary());

		// GD must accept what we produced.
		$decoded = @imagecreatefromstring($bytes);
		self::assertNotFalse($decoded);
		self::assertSame(20, imagesx($decoded));
		self::assertSame(10, imagesy($decoded));
		imagedestroy($decoded);
	}

	public function testRenderingRejectsMissingFramesAndTables()
	{
		$gif = GIFImage::fromString($this->gdGif());
		try {
			$gif->getFrameImage(7);
			self::fail('an out-of-range frame index was accepted');
		} catch (\InvalidArgumentException $e) {
		}

		$gif->setGlobalColorTable(null);
		self::expectException(\InvalidArgumentException::class);
		$gif->getFrameImage(0);
	}

	public function testRenderingAnEmptyFrameIsRejected()
	{
		$frame = new GIFFrame();
		self::expectException(\InvalidArgumentException::class);
		$frame->getImage(self::GlobalTable);
	}

	public function testAFrameTooLargeForTheLibraryIsRejected()
	{
		// The image descriptor's size fields are 16 bits, so a frame may legally declare
		// 65535x65535 — more than GD will allocate.  The renderer must say so rather than
		// hand back a broken image.  getPixels() is overridden only to keep the test from
		// materializing the four gigabytes of indexes such a frame would pad out to.
		$frame = new class () extends GIFFrame {
			public function getPixels(): string
			{
				return "\x00\x01";
			}
		};
		$frame->setWidth(65535);
		$frame->setHeight(65535);

		self::expectException(\InvalidArgumentException::class);
		// GD warns as it declines the allocation; the refusal is the exception, not the warning.
		@$frame->getImage(self::GlobalTable, ImageGraphicsMode::GD);
	}

	//
	// ─── The composed file is decodable by other readers ─────────────────────
	//

	public function testComposedAnimationIsReadableByGd()
	{
		$bytes = $this->richGif();
		$recomposed = GIFImage::fromString($bytes)->toBinary();
		$decoded = @imagecreatefromstring($recomposed);
		self::assertNotFalse($decoded, 'GD could not decode the recomposed animation');
		self::assertSame(4, imagesx($decoded));
		imagedestroy($decoded);
	}

	public function testBuiltAnimationIsReadableByImagick()
	{
		if (!extension_loaded('imagick')) {
			self::markTestSkipped('ext-imagick is not loaded.');
		}
		$gif = GIFImage::fromString($this->gdGif(8, 8));
		$gif->setLoopCount(0);
		$gif->getFrame(0)->setDelayTime(50);

		$second = new GIFFrame();
		$second->setWidth(8);
		$second->setHeight(8);
		$second->setDelayTime(25);
		$second->setDisposalMethod(GIFFrame::DisposalRestoreBackground);
		$second->setPixels(str_repeat(chr(2), 64));
		$gif->addFrame($second);

		$imagick = new Imagick();
		$imagick->readImageBlob($gif->toBinary());
		self::assertSame(2, $imagick->getNumberImages());
		$delays = [];
		foreach ($imagick as $frame) {
			$delays[] = $frame->getImageDelay();
		}
		$imagick->clear();
		self::assertSame([50, 25], $delays);
	}

	//
	// ─── Malformed input ─────────────────────────────────────────────────────
	//

	public function testDetectionOfGifBytes()
	{
		self::assertTrue(GIFImage::isGIF($this->gdGif()));
		self::assertTrue(GIFImage::isGIF(GIFImage::Signature87a . 'rest'));
		self::assertFalse(GIFImage::isGIF('GIF88a' . 'rest'));
		self::assertFalse(GIFImage::isGIF(''));
	}

	public function testRejectsNonGifData()
	{
		self::expectException(\RuntimeException::class);
		GIFImage::fromString(str_repeat("\x00", 32));
	}

	public function testRejectsTruncatedHeader()
	{
		self::expectException(\RuntimeException::class);
		GIFImage::fromString(GIFImage::Signature89a . "\x01\x00");
	}

	public function testRejectsTruncatedGlobalColorTable()
	{
		// Declares a 256-entry table but supplies nothing.
		self::expectException(\RuntimeException::class);
		GIFImage::fromString(GIFImage::Signature89a . pack('vv', 1, 1) . chr(0x80 | 0x07) . "\x00\x00");
	}

	public function testRejectsUnknownBlockMarker()
	{
		$bytes = substr($this->gdGif(), 0, -1) . "\x99" . chr(GIFBlockType::Trailer);
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testRejectsTruncatedImageDescriptor()
	{
		$bytes = GIFImage::Signature89a . pack('vv', 4, 4) . chr(0) . "\x00\x00" . "\x2c" . "\x00\x00";
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testRejectsUnterminatedSubBlockChain()
	{
		// A comment whose sub-block chain never reaches the zero terminator.
		$bytes = GIFImage::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . "\x21\xfe" . chr(4) . 'abcd';
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testRejectsSubBlockRunningPastTheEnd()
	{
		$bytes = GIFImage::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . "\x21\xfe" . chr(200) . 'abcd';
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testRejectsAnExtensionIntroducerWithNoLabel()
	{
		// The last byte of the data is an extension introducer, so the label is missing.
		$bytes = GIFImage::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . chr(GIFBlockType::ExtensionIntroducer);
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testApplicationExtensionWithAForeignBlockSizeReadsAsSubBlocks()
	{
		// The specification fixes the identity block at 11 bytes; a writer that used
		// another size is not a raw XMP packet, so it is read through the ordinary
		// sub-block path and its framing is kept byte for byte.
		$bytes = GIFImage::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00"
			. chr(GIFBlockType::ExtensionIntroducer) . chr(GIFBlockType::ApplicationLabel)
			. chr(5) . 'ODD01' . chr(3) . 'xyz' . "\x00"
			. chr(GIFBlockType::Trailer);

		$gif = GIFImage::fromString($bytes);
		$extension = $gif->getExtensions(GIFBlockType::ApplicationLabel)[0];
		self::assertSame(['ODD01', 'xyz'], $extension->getSubBlocks());
		self::assertFalse($extension->getIsRaw());
		self::assertSame($bytes, $gif->toBinary());
	}

	public function testRejectsTruncatedGraphicControlExtension()
	{
		$bytes = GIFImage::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . "\x21\xf9" . chr(2) . "\x00\x00" . "\x00";
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testRejectsTruncatedLocalColorTable()
	{
		$bytes = GIFImage::Signature89a . pack('vv', 4, 1) . chr(0) . "\x00\x00"
			. "\x2c" . pack('vvvv', 0, 0, 4, 1) . chr(0x80 | 0x07);
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	public function testRejectsMissingMinimumCodeSize()
	{
		$bytes = GIFImage::Signature89a . pack('vv', 4, 1) . chr(0) . "\x00\x00"
			. "\x2c" . pack('vvvv', 0, 0, 4, 1) . chr(0);
		self::expectException(\RuntimeException::class);
		GIFImage::fromString($bytes);
	}

	//
	// ─── Stream IO ───────────────────────────────────────────────────────────
	//

	public function testStreamRoundTrip()
	{
		$bytes = $this->richGif();
		$in = fopen('php://temp', 'w+b');
		fwrite($in, $bytes);
		rewind($in);
		$gif = GIFImage::fromStream($in);
		fclose($in);
		self::assertSame(2, $gif->getFrameCount());

		$out = fopen('php://temp', 'w+b');
		$written = $gif->writeTo($out);
		rewind($out);
		$composed = stream_get_contents($out);
		fclose($out);
		self::assertSame(strlen($bytes), $written);
		self::assertSame($bytes, $composed);
	}

	public function testSaveAndLoadFromFile()
	{
		$path = tempnam(sys_get_temp_dir(), 'tgif') . '.gif';
		$gif = GIFImage::fromString($this->richGif());
		$gif->save($path);
		$loaded = GIFImage::fromFile($path);
		unlink($path);
		self::assertSame(2, $loaded->getFrameCount());
		self::assertSame($this->richGif(), $loaded->toBinary());
	}
}
