<?php

use Belisoful\Image\ImageChunk;
use Belisoful\Image\RIFFChunkType;
use Belisoful\Image\RIFFContainer;
use Belisoful\Image\RIFFList;

/**
 * A RIFF `LIST` holds a list type and a nested chunk sequence, so the walk recurses.  The
 * invariants: an untouched container rewrites byte-for-byte, a list that has been looked
 * into still rewrites byte-for-byte, and an edit to a nested child reaches the rewrite.
 */
class RIFFListTest extends PHPUnit\Framework\TestCase
{
	/** Builds one chunk: id, little-endian size, payload, and the even-length pad. */
	private function chunk(string $id, string $payload): string
	{
		return $id . pack('V', strlen($payload)) . $payload . ((strlen($payload) & 1) ? "\0" : '');
	}

	/** Builds a LIST chunk of a list type around already-built child chunks. */
	private function riffList(string $listType, string ...$children): string
	{
		return $this->chunk(RIFFChunkType::RiffList, $listType . implode('', $children));
	}

	/** Builds a whole RIFF container of a form type around already-built chunks. */
	private function riff(string $formType, string ...$chunks): string
	{
		$body = $formType . implode('', $chunks);
		return RIFFChunkType::Riff . pack('V', strlen($body)) . $body;
	}

	/** A WAVE with an INFO list (one odd-length child, to exercise the pad) beside a data chunk. */
	private function wave(): string
	{
		return $this->riff(
			'WAVE',
			$this->chunk('fmt ', str_repeat("\x01", 16)),
			$this->riffList('INFO', $this->chunk('IART', "Photographer\0"), $this->chunk('ICMT', "Shot at dusk\0")),
			$this->chunk('data', str_repeat("\x7f", 10)),
		);
	}

	public function testListChunkReadsAsAList(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		$list = $riff->getChunk(RIFFChunkType::RiffList);
		self::assertInstanceOf(RIFFList::class, $list);
		self::assertSame('INFO', $list->getListType());
		self::assertSame(0, $list->getDepth());
		self::assertCount(2, $list->getChunks());
		self::assertSame(['IART', 'ICMT'], array_map(fn ($c) => $c->getType(), $list->getChunks()));
		self::assertSame("Photographer\0", $list->getChunk('IART')?->getData());
		self::assertNull($list->getChunk('ICOP'));
	}

	public function testANonListChunkIsUnaffected(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		self::assertNotInstanceOf(RIFFList::class, $riff->getChunk('fmt '));
		self::assertSame(str_repeat("\x01", 16), $riff->getChunk('fmt ')?->getData());
	}

	public function testUntouchedContainerRewritesByteForByte(): void
	{
		$bytes = $this->wave();
		self::assertSame($bytes, RIFFContainer::fromString($bytes)->toBinary());
	}

	public function testAListThatHasBeenReadStillRewritesByteForByte(): void
	{
		$bytes = $this->wave();
		$riff = RIFFContainer::fromString($bytes);
		// Materializing the children switches getData() to recomposing them; it must be exact.
		self::assertCount(2, $riff->getList('INFO')?->getChunks() ?? []);
		self::assertSame($bytes, $riff->toBinary());
	}

	public function testEditingANestedChildReachesTheRewrite(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		$riff->getList('INFO')?->getChunk('IART')?->setData("Someone Else\0");
		$rewritten = RIFFContainer::fromString($riff->toBinary());
		self::assertSame("Someone Else\0", $rewritten->getList('INFO')?->getChunk('IART')?->getData());
		self::assertSame("Shot at dusk\0", $rewritten->getList('INFO')?->getChunk('ICMT')?->getData());
		self::assertSame(str_repeat("\x7f", 10), $rewritten->getChunk('data')?->getData());
	}

	public function testOddLengthChildrenKeepTheirPadOnRewrite(): void
	{
		$bytes = $this->riff('WAVE', $this->riffList('INFO', $this->chunk('IART', 'odd'), $this->chunk('ICMT', 'even')));
		$riff = RIFFContainer::fromString($bytes);
		self::assertSame('odd', $riff->getList('INFO')?->getChunk('IART')?->getData());
		self::assertSame($bytes, $riff->toBinary());
	}

	public function testNestedListsRecurse(): void
	{
		$bytes = $this->riff(
			'AVI ',
			$this->riffList('hdrl', $this->chunk('avih', str_repeat("\x02", 8)), $this->riffList('strl', $this->chunk('strh', 'vids'))),
		);
		$riff = RIFFContainer::fromString($bytes);
		$hdrl = $riff->getList('hdrl');
		self::assertInstanceOf(RIFFList::class, $hdrl);
		self::assertSame(0, $hdrl->getDepth());
		$strl = $hdrl->getList('strl');
		self::assertInstanceOf(RIFFList::class, $strl);
		self::assertSame(1, $strl->getDepth());
		self::assertSame('vids', $strl->getChunk('strh')?->getData());
		self::assertNull($strl->getList('odml'));
		self::assertSame($bytes, $riff->toBinary());
	}

	public function testNestingStopsAtTheDepthCap(): void
	{
		$inner = $this->chunk('data', 'deep');
		for ($i = 0; $i <= RIFFList::MaxDepth; $i++) {
			$inner = $this->riffList('lvl' . ($i % 10), $inner);
		}
		$list = RIFFContainer::fromString($this->riff('AVI ', $inner))->getChunk(RIFFChunkType::RiffList);
		self::assertInstanceOf(RIFFList::class, $list);
		for ($depth = 0; $depth < RIFFList::MaxDepth - 1; $depth++) {
			$child = $list->getChunks()[0];
			self::assertInstanceOf(RIFFList::class, $child, "depth {$depth} should still nest");
			$list = $child;
		}
		// The next LIST would sit at MaxDepth, so it is kept opaque rather than walked.
		$deepest = $list->getChunks()[0];
		self::assertNotInstanceOf(RIFFList::class, $deepest);
		self::assertSame(RIFFChunkType::RiffList, $deepest->getType());
	}

	public function testAListTooShortForAListTypeStaysAPlainChunk(): void
	{
		$bytes = $this->riff('WAVE', $this->chunk(RIFFChunkType::RiffList, 'IN'));
		$chunk = RIFFContainer::fromString($bytes)->getChunk(RIFFChunkType::RiffList);
		self::assertNotInstanceOf(RIFFList::class, $chunk);
		self::assertSame($bytes, RIFFContainer::fromString($bytes)->toBinary());
	}

	public function testTrailingBytesInsideAListSurviveARewrite(): void
	{
		// Three bytes cannot be a chunk header, so the walker hands them back as a remainder.
		$bytes = $this->riff('WAVE', $this->chunk(RIFFChunkType::RiffList, 'INFO' . $this->chunk('IART', 'me') . "\x01\x02\x03"));
		$riff = RIFFContainer::fromString($bytes);
		$list = $riff->getChunk(RIFFChunkType::RiffList);
		self::assertInstanceOf(RIFFList::class, $list);
		self::assertCount(1, $list->getChunks());
		self::assertSame($bytes, $riff->toBinary());
	}

	public function testGetListReturnsNullForAnAbsentListType(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		self::assertNull($riff->getList('hdrl'));
		self::assertNull(RIFFContainer::fromString($this->riff('WAVE', $this->chunk('data', 'x')))->getList('INFO'));
	}

	public function testChildMutators(): void
	{
		$list = RIFFContainer::fromString($this->wave())->getList('INFO');
		self::assertInstanceOf(RIFFList::class, $list);

		$list->setChunk(new ImageChunk('IART', 3, 0, 'new'));    // replaces in place
		self::assertCount(2, $list->getChunks());
		self::assertSame('new', $list->getChunk('IART')?->getData());

		$list->setChunk(new ImageChunk('ICOP', 2, 0, 'cc'));     // appends an absent id
		self::assertCount(3, $list->getChunks());
		self::assertSame('ICOP', $list->getChunks()[2]->getType());

		$list->addChunk(new ImageChunk('ICOP', 2, 0, 'dd'));     // appends even when present
		self::assertCount(4, $list->getChunks());

		self::assertTrue($list->removeChunk('ICOP'));             // removes every match
		self::assertCount(2, $list->getChunks());
		self::assertFalse($list->removeChunk('ICOP'));

		$list->setChunks([new ImageChunk('IART', 2, 0, 'me')]);
		self::assertSame(['IART'], array_map(fn ($c) => $c->getType(), $list->getChunks()));
	}

	public function testListTypeIsWritable(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		$list = $riff->getList('INFO');
		self::assertInstanceOf(RIFFList::class, $list);
		$list->setListType('exif');
		self::assertSame('exif', $list->getListType());
		self::assertNull($riff->getList('INFO'));
		self::assertSame('IART', RIFFContainer::fromString($riff->toBinary())->getList('exif')?->getChunks()[0]->getType());

		$list->setListType('ab');   // padded to four characters
		self::assertSame('ab  ', $list->getListType());
	}

	public function testSizeTracksTheRecomposedPayload(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		$list = $riff->getList('INFO');
		self::assertInstanceOf(RIFFList::class, $list);
		$stored = $list->getSize();                  // reported from the parse, before materializing
		self::assertSame($stored, strlen($list->getData()));

		$list->removeChunk('ICMT');                  // materialized and edited: measured from the children
		self::assertSame(strlen($list->getData()), $list->getSize());
		self::assertLessThan($stored, $list->getSize());
	}

	public function testSetDataReplacesTheListWholesale(): void
	{
		$riff = RIFFContainer::fromString($this->wave());
		$list = $riff->getList('INFO');
		self::assertInstanceOf(RIFFList::class, $list);
		self::assertCount(2, $list->getChunks());    // materialize first, so a stale parse would show

		$list->setData('exif' . $this->chunk('ever', '1.0'));
		self::assertSame('exif', $list->getListType());
		self::assertSame(['ever'], array_map(fn ($c) => $c->getType(), $list->getChunks()));
		self::assertSame('1.0', $list->getChunk('ever')?->getData());
	}

	public function testLazyParseReadsListsAndDefersByQualifiedType(): void
	{
		$bytes = $this->riff(
			'AVI ',
			$this->riffList('hdrl', $this->chunk('avih', str_repeat("\x03", 8))),
			$this->riffList('INFO', $this->chunk('ISFT', "Writer\0")),
			$this->riffList('movi', $this->chunk('00dc', str_repeat("\x04", 64))),
			$this->chunk('idx1', str_repeat("\x05", 16)),
		);
		$source = TestIOHelper::dataResource($bytes);
		$riff = RIFFContainer::fromStreamLazy($source, [RIFFChunkType::RiffList . ':movi']);

		$lists = array_values(array_filter($riff->getChunks(), fn ($c) => $c->getType() === RIFFChunkType::RiffList));
		self::assertCount(3, $lists);
		self::assertInstanceOf(RIFFList::class, $lists[0]);           // hdrl was read
		self::assertSame('hdrl', $lists[0]->getListType());
		self::assertInstanceOf(RIFFList::class, $lists[1]);           // INFO was read
		self::assertSame("Writer\0", $lists[1]->getChunk('ISFT')?->getData());
		self::assertNotInstanceOf(RIFFList::class, $lists[2]);        // movi stayed opaque
		self::assertNotNull($lists[2]->getDeferredRange());

		$target = TestIOHelper::memoryResource();
		$riff->streamTo($target);
		rewind($target);
		self::assertSame($bytes, (string) stream_get_contents($target));
		fclose($source);
	}

	public function testLazyParseDefersEveryListByTheBareId(): void
	{
		$bytes = $this->riff('AVI ', $this->riffList('INFO', $this->chunk('ISFT', "Writer\0")), $this->chunk('idx1', 'xxxx'));
		$riff = RIFFContainer::fromStreamLazy(TestIOHelper::dataResource($bytes), [RIFFChunkType::RiffList]);
		$list = $riff->getChunk(RIFFChunkType::RiffList);
		self::assertNotInstanceOf(RIFFList::class, $list);
		self::assertNotNull($list?->getDeferredRange());
		self::assertNull($riff->getChunk('idx1')?->getDeferredRange());
	}

	public function testLazyParseLeavesAShortListOpaque(): void
	{
		$bytes = $this->riff('WAVE', $this->chunk(RIFFChunkType::RiffList, 'IN'), $this->chunk('data', 'ok'));
		$riff = RIFFContainer::fromStreamLazy(TestIOHelper::dataResource($bytes), []);
		self::assertNotInstanceOf(RIFFList::class, $riff->getChunk(RIFFChunkType::RiffList));
		self::assertSame('ok', $riff->getChunk('data')?->getData());
	}
}
