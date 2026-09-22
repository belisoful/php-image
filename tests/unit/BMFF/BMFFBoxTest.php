<?php

use Belisoful\Image\BMFF\BMFFBox;

/**
 * A box subclass that declares containers of its own, standing in for the ISO file types
 * (`moov`, `trak`) so the generic nesting can be exercised without a format class.
 */
class TestContainerBox extends BMFFBox
{
	public const ContainerTypes = ['moov', 'trak', 'meta'];

	/** `meta` is a FullBox: four bytes of version and flags sit before its children. */
	public const FullBoxContainers = ['meta' => 4];
}

/**
 * The ISO base media box grammar: the 32-bit length, the 64-bit extended length, the
 * run-to-the-end length, `uuid` user types, and nesting into whichever types a subclass
 * calls containers.  A rewrite keeps the size form it read and any trailing bytes, so it
 * is byte-faithful and not merely valid.
 */
class BMFFBoxTest extends PHPUnit\Framework\TestCase
{
	/** Builds a box with the ordinary 32-bit length. */
	private function box(string $type, string $payload = ''): string
	{
		return pack('N', 8 + strlen($payload)) . $type . $payload;
	}

	/** Builds a box with the 64-bit extended length. */
	private function largeBox(string $type, string $payload = ''): string
	{
		return pack('N', 1) . $type . pack('NN', 0, 16 + strlen($payload)) . $payload;
	}

	public function testTheBaseGrammarReadsAFlatSequence(): void
	{
		$bytes = $this->box('ftyp', 'JXL ') . $this->box('jxlc', 'codestream');
		$boxes = BMFFBox::parseBoxes($bytes);

		self::assertCount(2, $boxes);
		self::assertSame(['ftyp', 'jxlc'], array_map(fn ($b) => $b->getType(), $boxes));
		self::assertSame('JXL ', $boxes[0]->getPayload());
		self::assertFalse($boxes[0]->getIsContainer(), 'the base class names no container types');
		self::assertSame($bytes, $boxes[0]->toBinary() . $boxes[1]->toBinary());
	}

	public function testASubclassNamesItsOwnContainers(): void
	{
		$inner = $this->box('mvhd', 'header') . $this->box('trak', $this->box('tkhd', 'track'));
		$bytes = $this->box('moov', $inner) . $this->box('mdat', 'media');

		self::assertTrue(TestContainerBox::isContainerType('moov'));
		self::assertFalse(TestContainerBox::isContainerType('mdat'));

		$boxes = TestContainerBox::parseBoxes($bytes);
		self::assertCount(2, $boxes);
		$moov = $boxes[0];
		self::assertTrue($moov->getIsContainer());
		self::assertSame(['mvhd', 'trak'], array_map(fn ($b) => $b->getType(), $moov->getChildren()));
		self::assertSame('header', $moov->getChild('mvhd')?->getPayload());
		self::assertNull($moov->getChild('udta'));
		self::assertSame('track', $moov->getChild('trak')?->getChild('tkhd')?->getPayload());

		self::assertFalse($boxes[1]->getIsContainer());
		self::assertSame('media', $boxes[1]->getPayload());
		self::assertSame($bytes, $moov->toBinary() . $boxes[1]->toBinary());
	}

	public function testTheSameBytesReadFlatWithoutTheContainerNames(): void
	{
		$bytes = $this->box('moov', $this->box('mvhd', 'header'));
		$flat = BMFFBox::parse($bytes);
		self::assertInstanceOf(BMFFBox::class, $flat);
		self::assertFalse($flat->getIsContainer());
		self::assertSame([], $flat->getChildren());
		self::assertSame($this->box('mvhd', 'header'), $flat->getPayload());
		self::assertSame($bytes, $flat->toBinary());
	}

	public function testTheExtendedLengthFormIsKeptOnRewrite(): void
	{
		$bytes = $this->largeBox('free', 'padding');
		$box = BMFFBox::parse($bytes);
		self::assertInstanceOf(BMFFBox::class, $box);
		self::assertTrue($box->getUsesLargeSize());
		self::assertSame('padding', $box->getPayload());
		self::assertSame($bytes, $box->toBinary(), 'a 64-bit length is not re-encoded as a 32-bit one');
	}

	public function testTheRunToTheEndLengthFormIsKeptOnRewrite(): void
	{
		$bytes = pack('N', 0) . 'mdat' . 'everything that follows';
		$box = BMFFBox::parse($bytes);
		self::assertInstanceOf(BMFFBox::class, $box);
		self::assertTrue($box->getIsSizeToEnd());
		self::assertSame('everything that follows', $box->getPayload());
		self::assertSame($bytes, $box->toBinary());
	}

	public function testTheSizeFormCanBeChosenWhenAuthoring(): void
	{
		$box = new BMFFBox('free', 'pad');
		self::assertFalse($box->getUsesLargeSize());
		self::assertFalse($box->getIsSizeToEnd());
		self::assertSame($this->box('free', 'pad'), $box->toBinary());

		$box->setUsesLargeSize(true);
		self::assertSame($this->largeBox('free', 'pad'), $box->toBinary());

		$box->setIsSizeToEnd(true);   // the zero size wins: it is the last box of the file
		self::assertSame(pack('N', 0) . 'free' . 'pad', $box->toBinary());

		$box->setIsSizeToEnd(false);
		$box->setUsesLargeSize(false);
		self::assertSame($this->box('free', 'pad'), $box->toBinary());
	}

	public function testAContainersTrailingBytesSurviveARewrite(): void
	{
		// Four bytes cannot be a box header, so they are kept as the remainder.
		$bytes = $this->box('moov', $this->box('mvhd', 'header') . 'tail');
		$moov = TestContainerBox::parse($bytes);
		self::assertInstanceOf(TestContainerBox::class, $moov);
		self::assertCount(1, $moov->getChildren());
		self::assertSame('tail', $moov->getRemainder());
		self::assertSame($bytes, $moov->toBinary());
		self::assertSame('', TestContainerBox::parse($this->box('mdat', 'x'))->getRemainder());
	}

	public function testUuidBoxesSplitTheirUserType(): void
	{
		$userType = "\xBE\x7A\xCF\xCB\x97\xA9\x42\xE8\x9C\x71\x99\x94\x91\xE3\xAF\xAC";
		$box = BMFFBox::uuidBox($userType, '<x:xmpmeta/>');
		self::assertSame(BMFFBox::UuidBox, $box->getType());
		self::assertSame($userType, $box->getUserType());
		self::assertSame('<x:xmpmeta/>', $box->getUserPayload());
		self::assertSame($userType . '<x:xmpmeta/>', $box->getPayload(), 'the payload is stored whole');

		$round = BMFFBox::parse($box->toBinary());
		self::assertInstanceOf(BMFFBox::class, $round);
		self::assertSame($userType, $round->getUserType());
		self::assertSame('<x:xmpmeta/>', $round->getUserPayload());

		// A short user type is padded, and a non-uuid box has none.
		self::assertSame(str_pad('short', 16, "\0"), BMFFBox::uuidBox('short')->getUserType());
		self::assertNull((new BMFFBox('free', 'x'))->getUserType());
		self::assertNull((new BMFFBox('free', 'x'))->getUserPayload());
		self::assertNull((new BMFFBox(BMFFBox::UuidBox, 'too short'))->getUserType());
	}

	public function testNestingStopsAtTheDepthCap(): void
	{
		$inner = $this->box('tkhd', 'deep');
		for ($i = 0; $i <= BMFFBox::MaxDepth; $i++) {
			$inner = $this->box('moov', $inner);
		}
		$box = TestContainerBox::parse($inner);
		self::assertInstanceOf(TestContainerBox::class, $box);
		for ($depth = 1; $depth < BMFFBox::MaxDepth; $depth++) {
			$box = $box->getChildren()[0];
			self::assertTrue($box->getIsContainer(), "depth {$depth} should still nest");
		}
		// The next `moov` sits at the cap, so it is kept whole rather than walked.
		$deepest = $box->getChildren()[0];
		self::assertSame('moov', $deepest->getType());
		self::assertSame([], $deepest->getChildren());
		self::assertNotSame('', $deepest->getPayload());
		self::assertSame($inner, TestContainerBox::parse($inner)->toBinary());
	}

	public function testAccessors(): void
	{
		$box = new BMFFBox();
		self::assertSame('    ', $box->getType(), 'a type is padded to four characters');
		$box->setType('verylong');
		self::assertSame('very', $box->getType(), 'and truncated to four');

		$box->setPayload('bytes');
		self::assertSame('bytes', $box->getPayload());

		$container = new TestContainerBox('moov');
		$container->addChild(new TestContainerBox('mvhd', 'one'));
		$container->addChild(new TestContainerBox('udta', 'two'));
		self::assertCount(2, $container->getChildren());

		$container->setChildren([3 => new TestContainerBox('mvhd', 'only')]);
		self::assertSame([0], array_keys($container->getChildren()), 'children are reindexed');
		self::assertSame('only', $container->getChildren()[0]->getPayload());
	}

	public function testAFullBoxContainerKeepsItsVersionAndFlags(): void
	{
		self::assertSame(4, TestContainerBox::fullBoxHeaderLength('meta'));
		self::assertSame(0, TestContainerBox::fullBoxHeaderLength('moov'));

		// Without the prefix the version and flags would be read as a box length and the
		// whole walk would desynchronize.
		$bytes = $this->box('meta', "\x00\x00\x00\x00" . $this->box('hdlr', 'pict') . $this->box('iloc', 'where'));
		$meta = TestContainerBox::parse($bytes);
		self::assertInstanceOf(TestContainerBox::class, $meta);
		self::assertSame(['hdlr', 'iloc'], array_map(fn ($b) => $b->getType(), $meta->getChildren()));
		self::assertSame("\x00\x00\x00\x00", $meta->getFullBoxHeader());
		self::assertSame($bytes, $meta->toBinary());

		$meta->setFullBoxHeader("\x01\x00\x00\x02");
		self::assertSame("\x01\x00\x00\x02", TestContainerBox::parse($meta->toBinary())?->getFullBoxHeader());

		// A plain container has none, and reads the same bytes quite differently.
		self::assertSame('', TestContainerBox::parse($this->box('moov', $this->box('mvhd', 'x')))?->getFullBoxHeader());
	}

	public function testMalformedSequencesAreTolerated(): void
	{
		self::assertSame([], BMFFBox::parseBoxes('tiny'));
		self::assertFalse(BMFFBox::parse(''));
		self::assertSame([], BMFFBox::parseBoxes(pack('N', 9999) . 'moov' . 'short'));
		self::assertSame([], BMFFBox::parseBoxes(pack('N', 1) . 'moov' . pack('N', 0)));
		self::assertSame([], BMFFBox::parseBoxes(pack('N', 4) . 'moov'), 'a length inside its own header stops the walk');
	}

	public function testBoxesReadFromAStream(): void
	{
		$bytes = $this->box('ftyp', 'JXL ');
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $bytes);
		rewind($stream);
		$box = BMFFBox::fromStream($stream);
		self::assertInstanceOf(BMFFBox::class, $box);
		self::assertSame('JXL ', $box->getPayload());
		fclose($stream);
	}
}
