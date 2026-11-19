<?php

use Belisoful\Image\AVIImage;
use Belisoful\Image\BMFF\BMFFBox;
use Belisoful\Image\BMFFFile;
use Belisoful\Image\GIFImage;
use Belisoful\Image\ImageFile;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\JXLImage;
use Belisoful\Image\PNGImage;
use Belisoful\Image\RIFFChunkType;
use Belisoful\Image\RIFFContainer;
use Belisoful\Image\RIFFList;
use Belisoful\Image\TIFFImage;
use Belisoful\Image\WebPImage;

/**
 * Every parser makes one record of each part it meets — a segment, a chunk, a box, a block —
 * and a record costs a few hundred bytes however small the part is on disk.  The part
 * ceiling is what stops a file of nothing but empty parts from costing many times its size:
 * each container is given such a file here, eagerly and through its lazy reader, and must
 * refuse it with an exception a caller can catch rather than exhaust the memory limit.
 */
class PartCeilingTest extends PHPUnit\Framework\TestCase
{
	/** @var string The memory limit the suite runs under, which the tests here move. */
	private string $_memoryLimit;

	protected function setUp(): void
	{
		// The ceilings follow the memory limit, so each test starts from none: the fixed
		// defaults, whatever limit the suite itself was started with.
		$this->_memoryLimit = (string) ini_set('memory_limit', '-1');
	}

	protected function tearDown(): void
	{
		ImageFile::setMemoryBudget(null);
		ImageFile::setMaxParts(null);
		PNGImage::setMaxInflatedSize(null);
		ImageGraphics::setMaxPixels(null);
		ini_set('memory_limit', $this->_memoryLimit);
	}

	public function testAMemoryBudgetSetsTheThreeCeilings()
	{
		// 128 MiB is the budget the parts and inflation defaults were sized for.
		ImageFile::setMemoryBudget(128 * 1024 * 1024);
		self::assertSame(128 * 1024 * 1024, ImageFile::getMemoryBudget());
		self::assertSame(ImageFile::DefaultMaxParts, ImageFile::getMaxParts());
		self::assertSame(PNGImage::MaxInflatedSize, PNGImage::getMaxInflatedSize());
		self::assertSame(8 * 1024 * 1024, ImageGraphics::getMaxPixels());

		ImageFile::setMemoryBudget(32 * 1024 * 1024);
		self::assertSame(16384, ImageFile::getMaxParts());
		self::assertSame(4 * 1024 * 1024, PNGImage::getMaxInflatedSize());
		self::assertSame(2 * 1024 * 1024, ImageGraphics::getMaxPixels());

		// Each ceiling's own setter moves it alone, and set back it is the budget's.
		ImageFile::setMaxParts(10);
		PNGImage::setMaxInflatedSize(20);
		ImageGraphics::setMaxPixels(30);
		self::assertSame(10, ImageFile::getMaxParts());
		self::assertSame(20, PNGImage::getMaxInflatedSize());
		self::assertSame(30, ImageGraphics::getMaxPixels());
		ImageFile::setMaxParts(null);
		self::assertSame(16384, ImageFile::getMaxParts());
		self::assertSame(20, PNGImage::getMaxInflatedSize());

		// A ceiling set on its own outlasts a new budget, which moves only the others...
		ImageFile::setMemoryBudget(64 * 1024 * 1024);
		self::assertSame(32768, ImageFile::getMaxParts());
		self::assertSame(20, PNGImage::getMaxInflatedSize());
		self::assertSame(30, ImageGraphics::getMaxPixels());

		// ...until its own setter hands it back.
		PNGImage::setMaxInflatedSize(null);
		ImageGraphics::setMaxPixels(null);
		self::assertSame(8 * 1024 * 1024, PNGImage::getMaxInflatedSize());
		self::assertSame(4 * 1024 * 1024, ImageGraphics::getMaxPixels());

		// With no budget and no memory limit, the fixed defaults -- and still a ceiling's own
		// value where it has one.
		ImageFile::setMaxParts(10);
		ImageFile::setMemoryBudget(null);
		self::assertSame(10, ImageFile::getMaxParts());
		ImageFile::setMaxParts(null);
		self::assertNull(ImageFile::getMemoryBudget());
		self::assertNull(ImageFile::getBudgetCeiling(ImageFile::BudgetBytesPerPart));
		self::assertSame(ImageFile::DefaultMaxParts, ImageFile::getMaxParts());
		self::assertSame(PNGImage::MaxInflatedSize, PNGImage::getMaxInflatedSize());
		self::assertSame(ImageGraphics::DefaultMaxPixels, ImageGraphics::getMaxPixels());
	}

	public function testTheBudgetIsHalfTheMemoryLimitUntilOneIsSet()
	{
		$gib = 1 << 30;
		self::assertSame(0.5, ImageFile::DefaultBudgetShare);

		ini_set('memory_limit', '16G');
		self::assertSame(8 * $gib, ImageFile::getMemoryBudget());
		self::assertSame(intdiv(8 * $gib, ImageFile::BudgetBytesPerPart), ImageFile::getMaxParts());
		self::assertSame($gib, PNGImage::getMaxInflatedSize());
		self::assertSame(intdiv($gib, 2), ImageGraphics::getMaxPixels());

		// The limit is read each time, in whichever way PHP was given it.
		ini_set('memory_limit', '32g');
		self::assertSame(16 * $gib, ImageFile::getMemoryBudget());
		ini_set('memory_limit', (string) (64 * $gib));
		self::assertSame(32 * $gib, ImageFile::getMemoryBudget());
		self::assertSame(32 * $gib, ImageFile::getMemoryBudget(), 'and parsed once for each value it takes');

		// A budget that was set does not follow the limit, and unset it does again.
		ImageFile::setMemoryBudget($gib);
		self::assertSame($gib, ImageFile::getMemoryBudget());
		ImageFile::setMemoryBudget(null);
		self::assertSame(32 * $gib, ImageFile::getMemoryBudget());

		// No limit is no budget: nothing to take half of.
		ini_set('memory_limit', '-1');
		self::assertNull(ImageFile::getMemoryBudget());
		self::assertSame(ImageFile::DefaultMaxParts, ImageFile::getMaxParts());
	}

	public function testABudgetMayBeASizeOrAShareOfTheMemoryLimit()
	{
		$sizes = [
			'1024' => 1024,
			'512K' => 512 << 10,
			'256M' => 256 << 20,
			'1G' => 1 << 30,
			'2g' => 2 << 30,
			' 64 M ' => 64 << 20,
			'0' => 0,
			'99999999999999999999G' => intdiv(PHP_INT_MAX, 1 << 30) << 30,
		];
		foreach ($sizes as $text => $bytes) {
			ImageFile::setMemoryBudget((string) $text);
			self::assertSame($bytes, ImageFile::getMemoryBudget(), "'$text'");
		}
		self::assertSame(intdiv(256 << 20, ImageFile::BudgetBytesPerPart), (function () {
			ImageFile::setMemoryBudget('256M');
			return ImageFile::getMaxParts();
		})());

		// A share is of the limit the process runs under, read when it is asked for.
		ini_set('memory_limit', '16G');
		$gib = 1 << 30;
		foreach (['25%' => 4 * $gib, '12.5%' => 2 * $gib, ' 100 % ' => 16 * $gib, '0%' => 0] as $text => $bytes) {
			ImageFile::setMemoryBudget($text);
			self::assertSame($bytes, ImageFile::getMemoryBudget(), "'$text'");
		}
		ImageFile::setMemoryBudget('25%');
		ini_set('memory_limit', '32G');
		self::assertSame(8 * $gib, ImageFile::getMemoryBudget());
		self::assertSame($gib, PNGImage::getMaxInflatedSize());

		// A share of no limit is no budget.
		ini_set('memory_limit', '-1');
		self::assertNull(ImageFile::getMemoryBudget());
		self::assertSame(ImageGraphics::DefaultMaxPixels, ImageGraphics::getMaxPixels());
	}

	public function testABudgetThatIsNotOneIsRefused()
	{
		ImageFile::setMemoryBudget('256M');
		foreach ([-1, '-5M', '150%', '100.5%', '5.5M', '1T', '1GB', 'lots', '', '%'] as $budget) {
			try {
				ImageFile::setMemoryBudget($budget);
				self::fail("'$budget' is not a budget");
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString("'$budget'", $e->getMessage());
			}
			self::assertSame(256 << 20, ImageFile::getMemoryBudget(), 'and the budget is as it was');
		}
	}

	public function testABudgetIsWhatRefusesAFileThatWouldNotFitIt()
	{
		// Three hundred empty chunks are nothing to the default ceiling and too many for a
		// budget of a few hundred kilobytes, which allows two hundred.
		$png = $this->png(str_repeat($this->pngChunk('tEXt'), 300));
		self::assertInstanceOf(PNGImage::class, PNGImage::fromString($png));

		foreach ([200 * ImageFile::BudgetBytesPerPart, '400K'] as $budget) {
			ImageFile::setMemoryBudget($budget);
			try {
				PNGImage::fromString($png);
				self::fail('a file over its budget was opened');
			} catch (\UnexpectedValueException $e) {
				self::assertStringContainsString('parts', $e->getMessage());
			}
		}
	}

	private function chunk(string $id, string $payload = ''): string
	{
		return ContainerFixtures::chunk($id, $payload);
	}

	private function riff(string $form, string $body): string
	{
		return 'RIFF' . pack('V', 4 + strlen($body)) . $form . $body;
	}

	private function pngChunk(string $type, string $payload = ''): string
	{
		return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
	}

	private function png(string $chunks): string
	{
		return "\x89PNG\r\n\x1a\n" . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0)) . $chunks . $this->pngChunk('IEND');
	}

	private function gif(string $blocks): string
	{
		return 'GIF89a' . pack('vv', 1, 1) . "\x00\x00\x00" . $blocks . "\x3B";
	}

	private function assertRefused(callable $open, string $what): void
	{
		try {
			$open();
			self::fail("$what is over the part ceiling");
		} catch (\UnexpectedValueException $e) {
			self::assertStringContainsString('parts its ceiling and length allow', $e->getMessage(), $what);
		}
	}

	public function testPartCeiling(): void
	{
		self::assertSame(1 << 16, ImageFile::DefaultMaxParts);
		self::assertSame(ImageFile::DefaultMaxParts, ImageFile::getMaxParts());
		ImageFile::assertPartCount(65536);   // exactly at the ceiling
		$this->assertRefused(fn () => ImageFile::assertPartCount(65537), 'one part more');

		// A file earns one more part for every 256 bytes of its length, so a large file of
		// ordinary parts is never refused for being large.
		ImageFile::assertPartCount(65537, 256);
		ImageFile::assertPartCount(65536 + 4096, 1 << 20);
		$this->assertRefused(fn () => ImageFile::assertPartCount(65538, 511), 'two parts more in 511 bytes');

		ImageFile::setMaxParts(4);
		self::assertSame(4, ImageFile::getMaxParts());
		self::assertTrue(ImageFile::allowsPartCount(4));
		self::assertFalse(ImageFile::allowsPartCount(5), 'the same answer as a question, for metadata that reads as absent');
		self::assertTrue(ImageFile::allowsPartCount(5, 256));
		ImageFile::assertPartCount(4);
		$this->assertRefused(fn () => ImageFile::assertPartCount(5), 'five parts under a ceiling of four');

		ImageFile::setMaxParts(null);
		self::assertSame(ImageFile::DefaultMaxParts, ImageFile::getMaxParts());
	}

	/**
	 * Each container, given forty of the smallest part its format has.
	 * @return array<string, array{0: class-string<ImageFile>, 1: string, 2: bool}> The class, the bytes,
	 *   and whether the class has a lazy reader.
	 */
	private function filesOfEmptyParts(): array
	{
		return [
			'JPEG segments' => [JPEGImage::class, "\xFF\xD8" . str_repeat("\xFF\xE5\x00\x02", 40) . "\xFF\xD9", true],
			'PNG chunks' => [PNGImage::class, $this->png(str_repeat($this->pngChunk('tEXt'), 40)), true],
			'GIF blocks' => [GIFImage::class, $this->gif(str_repeat("\x21\xFE\x00", 40)), true],
			'GIF data sub-blocks' => [GIFImage::class, $this->gif("\x21\xFE" . str_repeat("\x01x", 40) . "\x00"), true],
			'WebP chunks' => [WebPImage::class, $this->riff('WEBP', str_repeat($this->chunk('JUNK'), 40)), true],
			'AVI chunks' => [AVIImage::class, $this->riff('AVI ', str_repeat($this->chunk('JUNK'), 40)), true],
			'HEIF boxes' => [BMFFFile::class, ContainerFixtures::box('ftyp', 'heic' . pack('N', 0) . 'mif1heic') . str_repeat(ContainerFixtures::box('free'), 40), false],
			'JPEG XL boxes' => [JXLImage::class, ContainerFixtures::jxl(...array_fill(0, 40, ContainerFixtures::box('free'))), false],
			'TIFF directory entries' => [TIFFImage::class, "MM\x00\x2A\x00\x00\x00\x08" . pack('n', 40) . str_repeat(pack('nnNN', 0x0100, 4, 1, 1), 40) . pack('N', 0), true],
		];
	}

	public function testEveryContainerRefusesAFileOfMorePartsThanItsCeilingAllows(): void
	{
		foreach ($this->filesOfEmptyParts() as $what => [$class, $bytes, $hasLazy]) {
			ImageFile::setMaxParts(null);
			self::assertInstanceOf($class, $class::fromString($bytes), "$what: forty parts are nothing under the default ceiling");

			ImageFile::setMaxParts(8);
			$this->assertRefused(fn () => $class::fromString($bytes), $what);
			if ($hasLazy) {
				$this->assertRefused(fn () => $class::fromStreamLazy(TestIOHelper::dataResource($bytes)), "$what, read lazily");
			}
		}
	}

	public function testALargeFileOfOrdinaryPartsIsNotRefused(): void
	{
		// Eight chunks under a ceiling of two: allowed, because they span over 1800 bytes.
		ImageFile::setMaxParts(2);
		$bytes = $this->png(str_repeat($this->pngChunk('tEXt', str_repeat('a', 300)), 6));

		self::assertCount(8, PNGImage::fromString($bytes)->getChunks());
		self::assertCount(8, PNGImage::fromStreamLazy(TestIOHelper::dataResource($bytes))->getChunks());
	}

	public function testNestedBoxesCountTowardTheOneCeiling(): void
	{
		// No level holds more than eight boxes, but the file holds thirteen.
		$free = ContainerFixtures::box('free');
		$bytes = ContainerFixtures::box('ftyp', 'isom' . pack('N', 0) . 'isommp41')
			. ContainerFixtures::box('moov', str_repeat($free, 6))
			. str_repeat($free, 5);
		ImageFile::setMaxParts(13);
		self::assertInstanceOf(BMFFFile::class, BMFFFile::fromString($bytes));

		ImageFile::setMaxParts(8);
		$this->assertRefused(fn () => BMFFFile::fromString($bytes), 'thirteen boxes across two levels');

		// A walk started on its own is counted on its own.
		self::assertCount(5, BMFFBox::parseBoxes(str_repeat($free, 5)));
		$this->assertRefused(fn () => BMFFBox::parseBoxes(str_repeat($free, 9)), 'nine boxes');
	}

	public function testAListsChunksCountTowardItsContainersCeiling(): void
	{
		// A list is walked only when it is asked for its children, so each list of a file
		// would otherwise start counting again from nothing.
		$list = fn (string $type, int $children) => $this->chunk('LIST', $type . str_repeat($this->chunk('JUNK'), $children));
		$bytes = $this->riff('AVI ', $list('hdrl', 6) . str_repeat($this->chunk('JUNK'), 4));
		ImageFile::setMaxParts(8);

		$riff = RIFFContainer::fromString($bytes);
		self::assertCount(5, $riff->getChunks(), 'the five top-level chunks are under the ceiling');
		$this->assertRefused(fn () => $riff->getList('hdrl')->getChunks(), 'six more chunks in a list of that container');

		$lazy = RIFFContainer::fromStreamLazy(TestIOHelper::dataResource($bytes), [RIFFChunkType::RiffList . ':movi']);
		$this->assertRefused(fn () => $lazy->getList('hdrl')->getChunks(), 'the same list of a lazily read container');

		// A list built on its own is a walk of its own.
		$payload = fn (int $children) => 'INFO' . str_repeat($this->chunk('JUNK'), $children);
		self::assertCount(6, (new RIFFList('LIST', strlen($payload(6)), 0, $payload(6)))->getChunks());
		$this->assertRefused(fn () => (new RIFFList('LIST', strlen($payload(9)), 0, $payload(9)))->getChunks(), 'nine chunks in a list on its own');
	}
}
