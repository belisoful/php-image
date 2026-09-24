<?php

use Belisoful\Image\AVIImage;
use Belisoful\Image\ImageChunk;
use Belisoful\Image\ImageFile;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\PrivacyCategory;
use Belisoful\Image\RIFFChunkType;
use Belisoful\Image\RIFFList;

/**
 * An AVI is read and written for the carriers the format defines — the `LIST INFO` tags,
 * the `_PMX` XMP packet, and the `IDIT` timestamp — while the `LIST movi` media never
 * moves, because `idx1` and any OpenDML index address it.
 */
class AVIImageTest extends PHPUnit\Framework\TestCase
{
	private function chunk(string $id, string $payload): string
	{
		return $id . pack('V', strlen($payload)) . $payload . ((strlen($payload) & 1) ? "\0" : '');
	}

	private function riffList(string $listType, string ...$children): string
	{
		return $this->chunk(RIFFChunkType::RiffList, $listType . implode('', $children));
	}

	private function riff(string $formType, string ...$chunks): string
	{
		$body = $formType . implode('', $chunks);
		return RIFFChunkType::Riff . pack('V', strlen($body)) . $body;
	}

	/** A `MainAVIHeader`: 14 DWORDs, with the frame size in the ninth and tenth. */
	private function avih(int $width = 320, int $height = 240, int $frames = 12, int $rate = 40000): string
	{
		return pack('VVVVVVVVVVVVVV', $rate, 0, 0, 0x10, $frames, 0, 1, 0, $width, $height, 0, 0, 0, 0);
	}

	private function infoList(string ...$pairs): string
	{
		$children = '';
		foreach (array_chunk($pairs, 2) as [$id, $value]) {
			$children .= $this->chunk($id, $value . "\0");
		}
		return $this->chunk(RIFFChunkType::RiffList, RIFFChunkType::InfoList . $children);
	}

	/** An AVI whose leading chunks are headers, then the media, then the index. */
	private function avi(string ...$extra): string
	{
		return $this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::HeaderList, $this->chunk(RIFFChunkType::AviHeader, $this->avih())),
			...[
				...$extra,
				$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', str_repeat("\x11", 64))),
				$this->chunk(RIFFChunkType::Index, str_repeat("\x22", 16)),
			],
		);
	}

	/**
	 * An AVI whose `idx1` is correct and `movi`-relative, as a real writer's is — the shape
	 * that lets the chunks be rearranged instead of fitted into padding.  The single media
	 * chunk's header sits four bytes into the list's payload, right after the `movi` id.
	 * @param string[] $extra
	 */
	private function indexedAvi(string ...$extra): string
	{
		return $this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::HeaderList, $this->chunk(RIFFChunkType::AviHeader, $this->avih())),
			...[
				...$extra,
				$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', str_repeat("\x11", 64))),
				$this->chunk(RIFFChunkType::Index, '00dc' . pack('V', 0x10) . pack('V', 4) . pack('V', 64)),
			],
		);
	}

	/** Checks that every index entry still names the chunk it points at. */
	private function indexIsValid(string $bytes): bool
	{
		$index = AVIImage::fromString($bytes)->getRIFF()->getChunk(RIFFChunkType::Index)?->getData() ?? '';
		$movi = (int) strpos($bytes, RIFFChunkType::MovieList);
		for ($i = 0; $i + 16 <= strlen($index); $i += 16) {
			if (substr($bytes, $movi + (int) unpack('V', substr($index, $i + 8, 4))[1], 4) !== substr($index, $i, 4)) {
				return false;
			}
		}
		return true;
	}

	/** The byte offset the media list sits at, which no metadata write may change. */
	private function moviOffset(string $bytes): int
	{
		return (int) strpos($bytes, RIFFChunkType::MovieList);
	}

	//
	// ─── Detection and headers ───────────────────────────────────────────────
	//

	public function testDetection(): void
	{
		self::assertTrue(AVIImage::isAVI($this->avi()));
		self::assertFalse(AVIImage::isAVI('RIFF' . pack('V', 4) . 'WEBP'));
		self::assertFalse(AVIImage::isAVI('RIFF'));
		self::assertInstanceOf(AVIImage::class, ImageFile::fromString($this->avi()));
		self::assertSame('AVI', AVIImage::fromString($this->avi())->getFormat());
	}

	public function testMainHeaderIsRead(): void
	{
		$avi = AVIImage::fromString($this->avi());
		self::assertSame(320, $avi->getWidth());
		self::assertSame(240, $avi->getHeight());
		self::assertSame(12, $avi->getTotalFrames());
		self::assertSame(40000, $avi->getMicroSecPerFrame());
	}

	public function testAMissingOrShortMainHeaderLeavesTheFactsUnknown(): void
	{
		$noHdrl = AVIImage::fromString($this->riff(AVIImage::FormType, $this->chunk('JUNK', 'xxxx')));
		self::assertNull($noHdrl->getWidth());
		self::assertNull($noHdrl->getTotalFrames());

		$shortAvih = AVIImage::fromString($this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::HeaderList, $this->chunk(RIFFChunkType::AviHeader, str_repeat("\0", 12))),
		));
		self::assertNull($shortAvih->getWidth());
		self::assertNull($shortAvih->getMicroSecPerFrame());
	}

	public function testNonAviRiffIsRefused(): void
	{
		$this->expectException(\RuntimeException::class);
		AVIImage::fromString('RIFF' . pack('V', 4) . 'WAVE');
	}

	public function testUntouchedAviRewritesByteForByte(): void
	{
		$bytes = $this->avi($this->infoList('INAM', 'A Clip'), $this->chunk(RIFFChunkType::XmpRiff, '<x:xmpmeta/>'));
		self::assertSame($bytes, AVIImage::fromString($bytes)->toBinary());
	}

	public function testAFreshContainerStartsAnEmptyAviForm(): void
	{
		$avi = new AVIImage();
		self::assertSame(AVIImage::FormType, $avi->getRIFF()->getFormType());
		$avi->setInfoValue(AVIImage::InfoName, 'Fresh');
		self::assertSame('Fresh', AVIImage::fromString($avi->toBinary())->getInfoValue(AVIImage::InfoName));
	}

	//
	// ─── The INFO list ───────────────────────────────────────────────────────
	//

	public function testInfoTagsAreRead(): void
	{
		$avi = AVIImage::fromString($this->avi($this->infoList('INAM', 'A Clip', 'IART', 'A Director')));
		self::assertSame(['INAM' => 'A Clip', 'IART' => 'A Director'], $avi->getInfo());
		self::assertSame('A Clip', $avi->getInfoValue(AVIImage::InfoName));
		self::assertNull($avi->getInfoValue(AVIImage::InfoComment));
		self::assertSame([], AVIImage::fromString($this->avi())->getInfo());
	}

	public function testInfoTagsAreWritten(): void
	{
		$avi = AVIImage::fromString($this->avi());
		$avi->setInfoValue(AVIImage::InfoName, 'Written');
		$avi->setInfoValue(AVIImage::InfoArtist, 'Someone');
		$round = AVIImage::fromString($avi->toBinary());
		self::assertSame(['INAM' => 'Written', 'IART' => 'Someone'], $round->getInfo());

		$round->setInfoValue(AVIImage::InfoArtist, null);
		self::assertSame(['INAM' => 'Written'], AVIImage::fromString($round->toBinary())->getInfo());
	}

	public function testOddLengthInfoValuesArePadded(): void
	{
		$avi = AVIImage::fromString($this->avi());
		$avi->setInfo(['ICMT' => 'odd', 'INAM' => 'even']);      // 'odd' + NUL is even, 'even' + NUL is odd
		self::assertSame(['ICMT' => 'odd', 'INAM' => 'even'], AVIImage::fromString($avi->toBinary())->getInfo());
	}

	public function testAnEmptyInfoArrayDropsTheList(): void
	{
		$avi = AVIImage::fromString($this->avi($this->infoList('INAM', 'Gone')));
		$avi->setInfo([]);
		$round = AVIImage::fromString($avi->toBinary());
		self::assertSame([], $round->getInfo());
		self::assertNull($round->getRIFF()->getList(RIFFChunkType::InfoList));
	}

	//
	// ─── XMP and the digitization time ───────────────────────────────────────
	//

	public function testXmpRoundTrips(): void
	{
		$avi = AVIImage::fromString($this->avi());
		self::assertNull($avi->getXMP());
		self::assertNull($avi->getXmpText());
		self::assertFalse($avi->hasXMP());

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'An AVI');
		$avi->setXMP($xmp);

		$round = AVIImage::fromString($avi->toBinary());
		self::assertTrue($round->hasXMP());
		self::assertSame(['An AVI'], $round->getXMP()?->getProperty(XMP::NS_DC, 'title'), 'dc:title is a LangAlt, per XMPSchemas');
		self::assertNotNull($round->getRIFF()->getChunk(RIFFChunkType::XmpRiff));

		$round->setXMP(null);
		self::assertNull(AVIImage::fromString($round->toBinary())->getXmpText());
	}

	public function testUnparsableXmpReadsAsAbsent(): void
	{
		$avi = AVIImage::fromString($this->avi($this->chunk(RIFFChunkType::XmpRiff, 'not xmp at all')));
		self::assertSame('not xmp at all', $avi->getXmpText());
		self::assertNull($avi->getXMP());
	}

	public function testDigitizationTimeRoundTrips(): void
	{
		$avi = AVIImage::fromString($this->avi());
		self::assertNull($avi->getDigitizationTime());
		$avi->setDigitizationTime('Mon Sep 21 10:00:00 2026');
		self::assertSame('Mon Sep 21 10:00:00 2026', AVIImage::fromString($avi->toBinary())->getDigitizationTime());

		$avi->setDigitizationTime(null);
		self::assertNull(AVIImage::fromString($avi->toBinary())->getDigitizationTime());
	}

	//
	// ─── Carriers AVI does not have ──────────────────────────────────────────
	//

	public function testIptcIsRefusedRatherThanDropped(): void
	{
		$avi = AVIImage::fromString($this->avi());
		self::assertNull($avi->getIPTC());
		self::assertFalse($avi->hasIPTC());
		$avi->setIPTC(null);    // clearing is always fine
		$this->expectException(\RuntimeException::class);
		$avi->setIPTC(new IPTC());
	}

	public function testIccProfileIsRefusedRatherThanDropped(): void
	{
		$avi = AVIImage::fromString($this->avi());
		self::assertNull($avi->getICCProfile());
		self::assertFalse($avi->hasICCProfile());
		$avi->setICCProfile(null);
		$this->expectException(\RuntimeException::class);
		$avi->setICCProfile('a profile');
	}

	public function testExifIsRefusedRatherThanDropped(): void
	{
		$avi = AVIImage::fromString($this->avi());
		self::assertNull($avi->getEXIF());
		$this->expectException(\RuntimeException::class);
		$avi->setEXIF(new \Belisoful\Image\Meta\EXIF());
	}

	//
	// ─── Placement: the media list never moves ───────────────────────────────
	//

	public function testANewMetadataChunkIsAppendedPastTheMedia(): void
	{
		$bytes = $this->avi();
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText('<x:xmpmeta>a packet</x:xmpmeta>');
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(RIFFChunkType::XmpRiff, $avi->getRIFF()->getChunks()[count($avi->getRIFF()->getChunks()) - 1]->getType());
	}

	public function testAChunkBeforeTheMediaIsRewrittenInPlaceAtTheSameLength(): void
	{
		$bytes = $this->avi($this->chunk(RIFFChunkType::XmpRiff, '12345678'));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText('abcdefgh');   // same length, so nothing shifts and no JUNK is needed
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(strlen($bytes), strlen($out));
		self::assertStringNotContainsString(RIFFChunkType::Junk, $out);
		self::assertSame('abcdefgh', AVIImage::fromString($out)->getXmpText());
	}

	public function testASmallerChunkBeforeTheMediaKeepsItsSlot(): void
	{
		// 24 bytes of payload gives a 32-byte slot; 8 bytes of payload needs 16, leaving 16 —
		// room for a JUNK chunk of 8 payload bytes.
		$bytes = $this->avi($this->chunk(RIFFChunkType::XmpRiff, str_repeat('x', 24)));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 8));
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media does not move');
		self::assertSame(strlen($bytes), strlen($out), 'and the file is the same length');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(RIFFChunkType::XmpRiff, $chunks[1]->getType(), 'the chunk stays where it was');
		self::assertSame(RIFFChunkType::Junk, $chunks[2]->getType(), 'and the remainder is padding');
		self::assertSame(8, $chunks[2]->getSize());
		self::assertSame(str_repeat('y', 8), AVIImage::fromString($out)->getXmpText());
	}

	public function testAChunkExactlyEightBytesSmallerStillKeepsItsSlot(): void
	{
		// The boundary: freeing exactly eight bytes leaves room for an empty JUNK chunk.
		$bytes = $this->avi($this->chunk(RIFFChunkType::XmpRiff, str_repeat('x', 16)));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 8));
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(strlen($bytes), strlen($out));
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(RIFFChunkType::Junk, $chunks[2]->getType());
		self::assertSame(0, $chunks[2]->getSize(), 'an empty JUNK chunk is exactly eight bytes');
	}

	public function testAChunkTooLittleSmallerCannotKeepItsSlot(): void
	{
		// Freeing only two bytes leaves nowhere to put a padding chunk, so it has to move.
		$bytes = $this->avi($this->chunk(RIFFChunkType::XmpRiff, str_repeat('x', 16)));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 14));
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media still does not move');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(RIFFChunkType::Junk, $chunks[1]->getType(), 'the old slot is vacated');
		self::assertSame(RIFFChunkType::XmpRiff, $chunks[count($chunks) - 1]->getType(), 'and the chunk went to the end');
		self::assertSame(str_repeat('y', 14), AVIImage::fromString($out)->getXmpText());
	}

	public function testAResizedChunkBeforeTheMediaBecomesJunkAndMovesToTheEnd(): void
	{
		$bytes = $this->avi($this->chunk(RIFFChunkType::XmpRiff, '12345678'));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText('a much longer packet than before');
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media list must not move');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(RIFFChunkType::Junk, $chunks[1]->getType());
		self::assertSame(8, $chunks[1]->getSize(), 'the JUNK fills exactly the old slot');
		self::assertSame(RIFFChunkType::XmpRiff, $chunks[count($chunks) - 1]->getType());
		self::assertSame('a much longer packet than before', AVIImage::fromString($out)->getXmpText());
	}

	public function testRemovingAChunkBeforeTheMediaLeavesJunkBehind(): void
	{
		$bytes = $this->avi($this->chunk(RIFFChunkType::XmpRiff, '12345678'));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText(null);
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertNull(AVIImage::fromString($out)->getXmpText());
		self::assertSame(RIFFChunkType::Junk, $avi->getRIFF()->getChunks()[1]->getType());
	}

	public function testAChunkAfterTheMediaIsRewrittenWhereItIs(): void
	{
		$bytes = $this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::HeaderList, $this->chunk(RIFFChunkType::AviHeader, $this->avih())),
			$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', str_repeat("\x11", 32))),
			$this->chunk(RIFFChunkType::XmpRiff, 'short'),
		);
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText('a considerably longer packet');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertCount(3, $chunks, 'nothing is appended and no JUNK is added');
		self::assertSame(RIFFChunkType::XmpRiff, $chunks[2]->getType());
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($avi->toBinary()));

		$avi->setXmpText(null);
		self::assertCount(2, $avi->getRIFF()->getChunks(), 'and it is simply removed');
	}

	public function testWithoutMediaEveryChunkIsFreeToMove(): void
	{
		$bytes = $this->riff(AVIImage::FormType, $this->chunk(RIFFChunkType::XmpRiff, 'short'));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText('a considerably longer packet');
		self::assertCount(1, $avi->getRIFF()->getChunks());
		self::assertSame('a considerably longer packet', AVIImage::fromString($avi->toBinary())->getXmpText());
	}

	public function testTheInfoListFollowsTheSamePlacementRule(): void
	{
		$bytes = $this->avi($this->infoList('INAM', 'A Clip'));
		$avi = AVIImage::fromString($bytes);
		$avi->setInfoValue(AVIImage::InfoComment, 'A much longer comment than the list held');
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(
			['INAM' => 'A Clip', 'ICMT' => 'A much longer comment than the list held'],
			AVIImage::fromString($out)->getInfo(),
		);
		self::assertInstanceOf(RIFFList::class, $avi->getRIFF()->getChunks()[count($avi->getRIFF()->getChunks()) - 1]);
	}

	public function testAGrownChunkIsWrittenIntoTheFilesOtherPadding(): void
	{
		// Padding the file already carries is space it has set aside, so filling it moves
		// nothing.  This file cannot be rearranged, so that is the only way to avoid growing.
		$bytes = $this->avi(
			$this->chunk(RIFFChunkType::XmpRiff, 'short'),
			$this->chunk(RIFFChunkType::Junk, str_repeat("\0", 200)),
		);
		$avi = AVIImage::fromString($bytes);
		self::assertFalse($avi->getCanRearrange());

		$avi->setXmpText(str_repeat('a much longer packet ', 6));
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the padding paid for it');
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(str_repeat('a much longer packet ', 6), AVIImage::fromString($out)->getXmpText());
	}

	public function testAdjacentPaddingIsMergedSoItCanBeUsedTogether(): void
	{
		// The vacated slot is 14 bytes and the padding beside it 56; neither can take a
		// 60-byte chunk, but the 70 bytes they make together can.
		$bytes = $this->avi(
			$this->chunk(RIFFChunkType::XmpRiff, 'short'),
			$this->chunk(RIFFChunkType::Junk, str_repeat("\0", 48)),
		);
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText(str_repeat('x', 52));
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out));
		self::assertSame(str_repeat('x', 52), AVIImage::fromString($out)->getXmpText());
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
	}

	public function testANewChunkAlsoLooksForPaddingFirst(): void
	{
		$bytes = $this->avi($this->chunk(RIFFChunkType::Junk, str_repeat("\0", 200)));
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText('<x:xmpmeta/>');
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'it went into the padding, not onto the end');
		self::assertSame('<x:xmpmeta/>', AVIImage::fromString($out)->getXmpText());
		self::assertSame(RIFFChunkType::XmpRiff, $avi->getRIFF()->getChunks()[1]->getType(), 'where the padding was');
	}

	public function testPaddingTooSmallStillSendsTheChunkToTheEnd(): void
	{
		$bytes = $this->avi(
			$this->chunk(RIFFChunkType::XmpRiff, 'short'),
			$this->chunk(RIFFChunkType::Junk, str_repeat("\0", 8)),
		);
		$avi = AVIImage::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 200));
		$out = $avi->toBinary();

		self::assertGreaterThan(strlen($bytes), strlen($out));
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media still never moves');
		self::assertSame(RIFFChunkType::XmpRiff, $avi->getRIFF()->getChunks()[count($avi->getRIFF()->getChunks()) - 1]->getType());
	}

	//
	// ─── Rearranging, when the file can be shown to tolerate it ──────────────
	//

	public function testAFileWithARelativeIndexMayBeRearranged(): void
	{
		$bytes = $this->indexedAvi($this->infoList('INAM', 'A Clip'));
		$avi = AVIImage::fromString($bytes);
		self::assertTrue($avi->getCanRearrange());
		self::assertTrue($this->indexIsValid($bytes), 'the fixture itself is sound');

		$avi->setInfoValue(AVIImage::InfoComment, str_repeat('A long comment. ', 8));
		$out = $avi->toBinary();

		self::assertGreaterThan(strlen($bytes), strlen($out), 'the file grows by what was added');
		self::assertStringNotContainsString(RIFFChunkType::Junk, $out, 'and no padding is left behind');
		self::assertTrue($this->indexIsValid($out), 'the index still names what it points at');
		self::assertSame(str_repeat('A long comment. ', 8), AVIImage::fromString($out)->getInfoValue(AVIImage::InfoComment));
		self::assertSame('A Clip', AVIImage::fromString($out)->getInfoValue(AVIImage::InfoName));
	}

	public function testRearrangingShrinksAndRemovesWithoutPadding(): void
	{
		$bytes = $this->indexedAvi($this->infoList('INAM', str_repeat('A long title ', 4)));
		$avi = AVIImage::fromString($bytes);

		$avi->setInfoValue(AVIImage::InfoName, 'Short');
		$out = $avi->toBinary();
		self::assertLessThan(strlen($bytes), strlen($out), 'the file shrinks');
		self::assertStringNotContainsString(RIFFChunkType::Junk, $out);
		self::assertTrue($this->indexIsValid($out));

		$dropped = AVIImage::fromString($out);
		$dropped->setInfo([]);
		$gone = $dropped->toBinary();
		self::assertNull(AVIImage::fromString($gone)->getInfoValue(AVIImage::InfoName));
		self::assertStringNotContainsString(RIFFChunkType::Junk, $gone, 'removal leaves nothing behind either');
		self::assertTrue($this->indexIsValid($gone));

		// Removing what is not there changes nothing at all.
		$absent = AVIImage::fromString($gone);
		$absent->setXmpText(null);
		self::assertSame($gone, $absent->toBinary());
	}

	public function testANewChunkIsPlacedAheadOfTheMedia(): void
	{
		$avi = AVIImage::fromString($this->indexedAvi());
		$avi->setXmpText('<x:xmpmeta/>');
		$types = array_map(fn ($c) => $c->getType(), $avi->getRIFF()->getChunks());
		self::assertSame(
			[RIFFChunkType::RiffList, RIFFChunkType::XmpRiff, RIFFChunkType::RiffList, RIFFChunkType::Index],
			$types,
			'metadata belongs before the media, not after the index',
		);
		self::assertTrue($this->indexIsValid($avi->toBinary()));
	}

	public function testAFileWithNoIndexAtAllMayBeRearranged(): void
	{
		$bytes = $this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::HeaderList, $this->chunk(RIFFChunkType::AviHeader, $this->avih())),
			$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
		);
		self::assertTrue(AVIImage::fromString($bytes)->getCanRearrange(), 'nothing addresses the media');
	}

	public function testAnAbsoluteIndexIsNotRearranged(): void
	{
		// The same file with an index measured from the start of the file instead.
		$relative = $this->indexedAvi();
		$absolute = str_replace(
			'00dc' . pack('V', 0x10) . pack('V', 4) . pack('V', 64),
			'00dc' . pack('V', 0x10) . pack('V', (int) strpos($relative, RIFFChunkType::MovieList) + 4) . pack('V', 64),
			$relative,
		);
		self::assertFalse(AVIImage::fromString($absolute)->getCanRearrange());
		self::assertTrue(AVIImage::fromString($relative)->getCanRearrange());
	}

	public function testAnOpenDmlIndexIsNotRearranged(): void
	{
		$direct = $this->riff(
			AVIImage::FormType,
			$this->riffList(
				RIFFChunkType::HeaderList,
				$this->chunk(RIFFChunkType::AviHeader, $this->avih()) . $this->chunk(RIFFChunkType::OpenDmlIndex, str_repeat("\0", 32)),
			),
			$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
		);
		self::assertFalse(AVIImage::fromString($direct)->getCanRearrange(), 'a super-index holds absolute positions');

		$nested = $this->riff(
			AVIImage::FormType,
			$this->riffList(
				RIFFChunkType::HeaderList,
				$this->chunk(RIFFChunkType::AviHeader, $this->avih())
				. $this->riffList(RIFFChunkType::StreamList, $this->chunk(RIFFChunkType::OpenDmlIndex, str_repeat("\0", 32))),
			),
			$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
		);
		self::assertFalse(AVIImage::fromString($nested)->getCanRearrange(), 'including one inside a stream list');
	}

	public function testAnUnreadableMediaListIsNotRearranged(): void
	{
		// A deferred media list has not been read, so nothing can be proven about it.
		$bytes = $this->indexedAvi();
		$lazy = AVIImage::fromStreamLazy(TestIOHelper::dataResource($bytes));
		self::assertFalse($lazy->getCanRearrange());

		// Nor can a file whose index is too short to hold an entry, or which has no media.
		$short = $this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
			$this->chunk(RIFFChunkType::Index, 'tiny'),
		);
		self::assertFalse(AVIImage::fromString($short)->getCanRearrange());
		$noMedia = $this->riff(AVIImage::FormType, $this->chunk(RIFFChunkType::Index, str_repeat("\0", 16)));
		self::assertFalse(AVIImage::fromString($noMedia)->getCanRearrange());
	}

	public function testAnEmptyMediaListIsNotRearranged(): void
	{
		$bytes = $this->riff(
			AVIImage::FormType,
			$this->riffList(RIFFChunkType::MovieList),
			$this->chunk(RIFFChunkType::Index, str_repeat("\0", 16)),
		);
		self::assertFalse(AVIImage::fromString($bytes)->getCanRearrange(), 'there is no first chunk to check against');
	}

	//
	// ─── Streaming ───────────────────────────────────────────────────────────
	//

	public function testLazyReadDefersTheMediaAndRewritesFaithfully(): void
	{
		$bytes = $this->avi($this->infoList('INAM', 'Streamed'));
		$avi = AVIImage::fromStreamLazy(TestIOHelper::dataResource($bytes));
		self::assertSame(320, $avi->getWidth());
		self::assertSame('Streamed', $avi->getInfoValue(AVIImage::InfoName));

		$movi = $avi->getRIFF()->getChunks()[2];
		self::assertNotNull($movi->getDeferredRange(), 'the media list is never materialized');
		self::assertNotInstanceOf(RIFFList::class, $movi);

		$target = TestIOHelper::memoryResource();
		$avi->streamTo($target);
		rewind($target);
		self::assertSame($bytes, (string) stream_get_contents($target));
	}

	public function testLazyReadEditsMetadataWithoutMovingTheMedia(): void
	{
		$bytes = $this->avi();
		$source = TestIOHelper::dataResource($bytes);
		$avi = AVIImage::fromStreamLazy($source);
		$avi->setInfoValue(AVIImage::InfoArtist, 'Streamer');

		$target = TestIOHelper::memoryResource();
		$avi->streamTo($target);
		rewind($target);
		$out = (string) stream_get_contents($target);
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame('Streamer', AVIImage::fromString($out)->getInfoValue(AVIImage::InfoArtist));
	}

	public function testLazyReadRefusesANonAvi(): void
	{
		$this->expectException(\UnexpectedValueException::class);
		AVIImage::fromStreamLazy(TestIOHelper::dataResource('RIFF' . pack('V', 4) . 'WAVE'));
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	private function privateAvi(): string
	{
		return $this->avi(
			$this->infoList(
				'IART',
				'A Director',
				'ICOP',
				'(c) Someone',
				'INAM',
				'A Clip',
				'ICMT',
				'Filmed at home',
				'ICRD',
				'2026-09-21',
				'ISFT',
				'Some Editor 1.0',
			),
			$this->chunk(RIFFChunkType::DigitizationTime, "Mon Sep 21 10:00:00 2026\0"),
		);
	}

	public function testScrubbingByCategoryIsIsolated(): void
	{
		$avi = AVIImage::fromString($this->privateAvi());
		self::assertSame(2, $avi->clearPrivateData(PrivacyCategory::Author));
		$info = $avi->getInfo();
		self::assertArrayNotHasKey('IART', $info);
		self::assertArrayNotHasKey('ICOP', $info);
		self::assertSame('A Clip', $info['INAM'], 'another category is untouched');
		self::assertSame('Some Editor 1.0', $info['ISFT']);

		$avi = AVIImage::fromString($this->privateAvi());
		self::assertSame(2, $avi->clearPrivateData(PrivacyCategory::Timestamp));
		self::assertArrayNotHasKey('ICRD', $avi->getInfo());
		self::assertNull($avi->getDigitizationTime());
		self::assertSame('A Director', $avi->getInfoValue(AVIImage::InfoArtist));

		$avi = AVIImage::fromString($this->privateAvi());
		self::assertSame(1, $avi->clearPrivateData(PrivacyCategory::Software));
		self::assertArrayNotHasKey('ISFT', $avi->getInfo());
	}

	public function testScrubbingEverythingLeavesThePlayableFacts(): void
	{
		$bytes = $this->privateAvi();
		$avi = AVIImage::fromString($bytes);
		self::assertGreaterThan(0, $avi->clearPrivateData());
		$out = $avi->toBinary();
		$round = AVIImage::fromString($out);

		self::assertSame([], $round->getInfo());
		self::assertNull($round->getDigitizationTime());
		self::assertSame(320, $round->getWidth(), 'the video itself still describes itself');
		self::assertSame(12, $round->getTotalFrames());
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'and the media never moved');

		self::assertSame(0, $round->clearPrivateData(), 'a scrub is idempotent');
	}

	public function testScrubbingAFileWithNothingPrivateChangesNothing(): void
	{
		$bytes = $this->avi();
		$avi = AVIImage::fromString($bytes);
		self::assertSame(0, $avi->clearPrivateData());
		self::assertSame($bytes, $avi->toBinary());
	}

	public function testScrubbingReachesTheXmpCarrier(): void
	{
		$avi = AVIImage::fromString($this->avi());
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'creator', 'A Director');
		$avi->setXMP($xmp);
		self::assertGreaterThan(0, $avi->clearPrivateData(PrivacyCategory::Author));
		self::assertNull($avi->getXMP()?->getProperty(XMP::NS_DC, 'creator'));
	}

	public function testChunksCanBeReplacedWholesale(): void
	{
		$avi = AVIImage::fromString($this->avi());
		$riff = $avi->getRIFF();
		$riff->setChunks([new ImageChunk('JUNK', 4, 0, 'keep')]);
		self::assertCount(1, $riff->getChunks());
		self::assertSame('keep', $riff->getChunks()[0]->getData());
	}

	public function testTheSmallestSufficientPaddingRunTakesTheChunk(): void
	{
		// Two runs can hold the 20-byte chunk: 208 bytes with 188 to spare, and 28 with 8.
		// Best fit takes the tighter one, leaving the larger run whole for a larger write.
		$bytes = $this->avi(
			$this->chunk(RIFFChunkType::Junk, str_repeat("\0", 200)),
			$this->chunk(RIFFChunkType::Junk, str_repeat("\0", 20)),
		);
		$avi = AVIImage::fromString($bytes);
		self::assertFalse($avi->getCanRearrange());

		$avi->setXmpText('<x:xmpmeta/>');
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the padding paid for it');
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame('<x:xmpmeta/>', AVIImage::fromString($out)->getXmpText());

		$chunks = AVIImage::fromString($out)->getRIFF()->getChunks();
		self::assertSame(
			['LIST', RIFFChunkType::Junk, RIFFChunkType::XmpRiff, RIFFChunkType::Junk, 'LIST', RIFFChunkType::Index],
			array_map(fn ($c) => $c->getType(), $chunks),
			'the second, tighter run took the chunk',
		);
		self::assertSame(200, $chunks[1]->getSize(), 'the larger run is untouched');
	}
}
