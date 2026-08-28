<?php

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFFImage;

/**
 * The private-spaces bridge: EXIF and TIFFImage report where their maker notes and
 * private IFDs sit inside the composed bytes, as `[offset, length]` pairs, so a consumer
 * can rewrite the editable regions while leaving pinned bytes exactly as the writer
 * placed them.  {@see EXIF::getFreeSpaces()} is the complement of
 * {@see EXIF::getReservedSpaces()} over the composed length.
 */
class ReservedSpaceIOTest extends PHPUnit\Framework\TestCase
{
	private const MAKER = 'MAKERNOTE-PRIVATE-BYTES-1234567890';

	/**
	 * A parsed EXIF whose maker note carries a real on-disk offset (so it is pinned). A
	 * freshly built tag has no offset to preserve, which is why the source is reparsed.
	 */
	private function exifWithMakerNote(): EXIF
	{
		$build = new EXIF();
		$build->setValueByName('Make', 'TestCam');
		$build->getExifIfd(true)->setTagValues(EXIF::MakerNoteTag, TIFFDataType::Undefined, self::MAKER);
		return EXIF::fromSegment($build->toBinary());   // reparse: pinMakernote() runs
	}

	/**
	 * Asserts a set of [offset, length] ranges is sorted, non-overlapping, and inside the
	 * composed length.
	 * @param array<int, array{0: int, 1: int}> $spaces
	 * @param int $total
	 */
	private function assertWellFormed(array $spaces, int $total): void
	{
		$cursor = 0;
		foreach ($spaces as [$offset, $length]) {
			self::assertGreaterThanOrEqual($cursor, $offset, 'ranges are sorted and disjoint');
			self::assertGreaterThan(0, $length, 'every range is non-empty');
			self::assertLessThanOrEqual($total, $offset + $length, 'every range lies inside the bytes');
			$cursor = $offset + $length;
		}
	}

	public function testReservedSpaceLandsExactlyOnTheMakerNote()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();
		$spaces = $exif->getReservedSpaces();

		self::assertCount(1, $spaces);
		[$offset, $length] = $spaces[0];
		self::assertSame(strlen(self::MAKER), $length);
		// The reserved range indexes the composed output, past the 'Exif\0\0' signature.
		self::assertSame(self::MAKER, substr($bytes, $offset, $length));
		self::assertGreaterThanOrEqual(strlen($exif->getSignature()), $offset);

		// A built-but-never-parsed maker note has no offset to pin, so nothing is reserved.
		$fresh = new EXIF();
		$fresh->getExifIfd(true)->setTagValues(EXIF::MakerNoteTag, TIFFDataType::Undefined, self::MAKER);
		$pin = new \ReflectionMethod($fresh, 'pinMakernote');
		$pin->invoke($fresh);
		self::assertSame([], $fresh->getReservedSpaces());
	}

	public function testFreeSpacesAreTheComplementOfTheReservedOnes()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();
		$total = strlen($bytes);

		$reserved = $exif->getReservedSpaces();
		$free = $exif->getFreeSpaces();
		$this->assertWellFormed($reserved, $total);
		$this->assertWellFormed($free, $total);

		// Together they tile the whole composed block exactly once.
		$covered = 0;
		foreach ([...$reserved, ...$free] as [, $length]) {
			$covered += $length;
		}
		self::assertSame($total, $covered, 'reserved and free together cover every byte');

		// And the free ranges never touch the maker note.
		foreach ($free as [$offset, $length]) {
			self::assertStringNotContainsString(self::MAKER, substr($bytes, $offset, $length));
		}
	}

	public function testFreeSpaceBytesReadAroundTheMakerNote()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();

		$free = '';
		foreach ($exif->getFreeSpaces() as [$offset, $length]) {
			$free .= substr($bytes, $offset, $length);
		}
		self::assertStringNotContainsString(self::MAKER, $free, 'the private bytes never appear');
		self::assertSame(strlen($bytes) - strlen(self::MAKER), strlen($free));
	}

	public function testRewritingOnlyTheFreeSpacesLeavesTheMakerNoteIntact()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();
		[$offset, $length] = $exif->getReservedSpaces()[0];

		// Overwrite every free byte; the reserved range must survive untouched.
		$edited = $bytes;
		foreach ($exif->getFreeSpaces() as [$freeOffset, $freeLength]) {
			$edited = substr_replace($edited, str_repeat('#', $freeLength), $freeOffset, $freeLength);
		}
		self::assertSame(self::MAKER, substr($edited, $offset, $length));
		self::assertSame(strlen($bytes), strlen($edited), 'the length is unchanged');
	}

	public function testFreeSpacesStopAtAMakerNoteThatEndsTheBlock()
	{
		// The writer pins the maker note last, so the complement is the single range that
		// precedes it and there is no trailing range to emit.
		$exif = $this->exifWithMakerNote();
		[$offset, $length] = $exif->getReservedSpaces()[0];
		self::assertSame(strlen($exif->toBinary()), $offset + $length, 'the maker note ends the block');

		self::assertSame([[0, $offset]], $exif->getFreeSpaces());
	}

	public function testEmptyWhenThereAreNoPrivateSpaces()
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', 'NoMakerNote');
		$bytes = $exif->toBinary();

		self::assertSame([], $exif->getReservedSpaces());
		self::assertSame([[0, strlen($bytes)]], $exif->getFreeSpaces(), 'the whole block is free');
	}

	public function testTiffContainerBridgesToItsExif()
	{
		$exif = $this->exifWithMakerNote();
		$tiff = TIFFImage::fromString($exif->getTiff()->toBinary());
		$bytes = $tiff->toBinary();

		$reserved = $tiff->getReservedSpaces();
		self::assertCount(1, $reserved);
		[$offset, $length] = $reserved[0];
		self::assertSame(self::MAKER, substr($bytes, $offset, $length));

		$this->assertWellFormed($tiff->getFreeSpaces(), strlen($bytes));
		$covered = 0;
		foreach ([...$reserved, ...$tiff->getFreeSpaces()] as [, $len]) {
			$covered += $len;
		}
		self::assertSame(strlen($bytes), $covered);
	}

	public function testTiffWithoutExifReservesNothing()
	{
		$tiff = new TIFFImage();
		self::assertSame([], $tiff->getReservedSpaces());
	}

	public function testTiffFreeSpaceIsTheWholeBlockWhenNothingIsPinned()
	{
		// With no pinned maker note the complement is one range spanning every byte, which
		// is the trailing-range arm of the complement.
		$exif = new EXIF();
		$exif->setValueByName('Make', 'NoMakerNote');
		$tiff = TIFFImage::fromString($exif->getTiff()->toBinary());

		self::assertSame([], $tiff->getReservedSpaces());
		self::assertSame([[0, strlen($tiff->toBinary())]], $tiff->getFreeSpaces());
	}
}
