<?php

use Belisoful\Image\BMFF\BMFFFileBox;
use Belisoful\Image\BMFF\BMFFItem;
use Belisoful\Image\BMFFFile;
use Belisoful\Image\ImageFile;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\PrivacyCategory;

/**
 * An ISO base media file is read and written for the two carriers the format actually has —
 * the Adobe `uuid` box a movie carries and the `meta` items a HEIF does — while nothing in
 * the file ever moves, because `iloc` extents and `stco` chunk offsets address it absolutely.
 *
 * The structures here are the ones real writers emit: the layouts were checked against files
 * from libheif (HEIC, AVIF) and ffmpeg (MP4, MOV), including libheif's habit of putting an
 * item's whole address in the `iloc` base offset and leaving the extent offset zero.
 */
class BMFFFileTest extends PHPUnit\Framework\TestCase
{
	private function box(string $type, string $payload = ''): string
	{
		return pack('N', 8 + strlen($payload)) . $type . $payload;
	}

	private function fullBox(string $type, string $payload, int $version = 0): string
	{
		return $this->box($type, chr($version) . "\x00\x00\x00" . $payload);
	}

	private function ftyp(string $brand = 'heic', string ...$compatible): string
	{
		return $this->box('ftyp', $brand . pack('N', 0) . implode('', $compatible ?: ['mif1', $brand]));
	}

	/** One `infe` entry: version 2 ids are 16-bit, version 3 ids are 32-bit. */
	private function infe(int $id, string $type, string $name = '', string $contentType = '', int $version = 2): string
	{
		$body = ($version === 2 ? pack('n', $id) : pack('N', $id)) . pack('n', 0) . $type . $name . "\0";
		if ($type === BMFFItem::MimeType) {
			$body .= $contentType . "\0";
		}
		return $this->fullBox('infe', $body, $version);
	}

	private function iinf(string ...$entries): string
	{
		return $this->fullBox('iinf', pack('n', count($entries)) . implode('', $entries));
	}

	/**
	 * An `iloc` version 0 with 4-byte offset, length and base-offset fields — the widths
	 * libheif writes.  Each entry is [id, baseOffset, extentOffset, length].
	 * @param array<int, array{0: int, 1: int, 2: int, 3: int}> $entries
	 */
	private function iloc(array $entries): string
	{
		$body = chr(0x44) . chr(0x40) . pack('n', count($entries));
		foreach ($entries as [$id, $base, $offset, $length]) {
			$body .= pack('n', $id) . pack('n', 0) . pack('N', $base) . pack('n', 1) . pack('N', $offset) . pack('N', $length);
		}
		return $this->fullBox('iloc', $body);
	}

	private function meta(string ...$children): string
	{
		return $this->fullBox('meta', $this->box('hdlr', str_repeat("\0", 8) . 'pict' . str_repeat("\0", 12)) . implode('', $children));
	}

	/**
	 * A HEIF holding one item whose bytes sit in a trailing `mdat`, laid out the way
	 * libheif does it: the address in the base offset, the extent offset zero.
	 * @param string $itemType
	 * @param string $data
	 * @param string $contentType
	 */
	private function heif(string $itemType = BMFFItem::ExifType, string $data = "\x00\x00\x00\x00MM\x00\x2a\x00\x00\x00\x08", string $contentType = ''): string
	{
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, $itemType, $itemType, $contentType)),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
		);
		$mdatOffset = strlen($ftyp) + strlen($build(0)) + 8;
		return $ftyp . $build($mdatOffset) . $this->box('mdat', $data);
	}

	private function movie(string ...$extra): string
	{
		return $this->ftyp('isom', 'isom', 'mp41')
			. $this->box('mdat', str_repeat("\x11", 32))
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)) . $this->box('udta', $this->meta()))
			. implode('', $extra);
	}

	/** A classic QuickTime user-data text atom: length, language, then the text. */
	private function atom(string $type, string $text, int $language = BMFFFile::UndefinedLanguage): string
	{
		return $this->box($type, pack('n', strlen($text)) . pack('n', $language) . $text);
	}

	/** One iTunes key holding a UTF-8 `data` value. */
	private function ilstKey(string $key, string $text, int $typeIndicator = BMFFFile::TextTypeIndicator): string
	{
		return $this->box($key, $this->box('data', pack('N', $typeIndicator) . pack('N', 0) . $text));
	}

	/** A movie whose `moov` is last, so resizing it moves nothing. */
	private function movieWithUserData(string $udta): string
	{
		return $this->ftyp('isom', 'isom', 'mp41')
			. $this->box('mdat', str_repeat("\x11", 32))
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)) . $this->box('udta', $udta));
	}

	/** A faststart movie: `moov` comes first, with a free-space box before the media. */
	private function faststart(string $udta, int $freePayload = 0): string
	{
		return $this->ftyp('isom', 'isom', 'mp41')
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)) . $this->box('udta', $udta))
			. $this->box('free', str_repeat("\0", $freePayload))
			. $this->box('mdat', str_repeat("\x11", 32));
	}

	/** An `ipma` entry list: one item with [essential, index] associations. */
	private function ipma(int $item, array $associations, int $version = 0, int $flags = 0): string
	{
		$body = pack('N', 1) . ($version < 1 ? pack('n', $item) : pack('N', $item)) . chr(count($associations));
		foreach ($associations as [$essential, $index]) {
			$top = ($flags & 1) ? 0x8000 : 0x80;
			$value = $index | ($essential ? $top : 0);
			$body .= ($flags & 1) ? pack('n', $value) : chr($value);
		}
		return $this->box('ipma', chr($version) . substr(pack('N', $flags), 1) . $body);
	}

	/**
	 * A still holding one item whose bytes are in a trailing `mdat`, with an `iprp` tree of
	 * item properties — the shape a HEIF keeps an ICC profile in.
	 * @param array $properties
	 * @param ?string $ipma
	 * @param string $data
	 */
	private function heifWithProperties(array $properties, ?string $ipma = null, string $data = 'item bytes'): string
	{
		$ipco = $this->box('ipco', implode('', $properties));
		$associations = [];
		for ($i = 1; $i <= count($properties); $i++) {
			$associations[] = [false, $i];
		}
		$iprp = $this->box('iprp', $ipco . ($ipma ?? $this->ipma(1, $associations)));
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, 'hvc1', 'image')),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
			$iprp,
		);
		return $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);
	}

	/** A `colr` box of a colour type. */
	private function colr(string $type, string $payload): string
	{
		return $this->box('colr', $type . $payload);
	}

	private function exif(string $artist = 'A. Photographer'): EXIF
	{
		$exif = new EXIF();
		$exif->setSignature('');
		$exif->setValueByName('Artist', $artist);
		return $exif;
	}

	//
	// ─── Detection and brands ────────────────────────────────────────────────
	//

	public function testDetection(): void
	{
		self::assertTrue(BMFFFile::isBMFF($this->heif()));
		self::assertTrue(BMFFFile::isBMFF($this->movie()));
		self::assertFalse(BMFFFile::isBMFF('short'));
		self::assertFalse(BMFFFile::isBMFF(str_repeat('x', 32)));
		self::assertFalse(BMFFFile::isBMFF(pack('N', 999) . 'ftyp' . str_repeat('x', 16)), 'a length past the data is not a ftyp');
		self::assertInstanceOf(BMFFFile::class, ImageFile::fromString($this->heif()));
	}

	public function testBrandsNameTheFormat(): void
	{
		self::assertSame('HEIF', BMFFFile::fromString($this->heif())->getFormat());
		self::assertSame('heic', BMFFFile::fromString($this->heif())->getBrand());
		self::assertSame(['mif1', 'heic'], BMFFFile::fromString($this->heif())->getCompatibleBrands());

		self::assertSame('MP4', BMFFFile::fromString($this->movie())->getFormat());
		self::assertSame('AVIF', BMFFFile::fromString($this->ftyp('avif', 'mif1', 'avif'))->getFormat());
		self::assertSame('MOV', BMFFFile::fromString($this->ftyp('qt  ', 'qt  '))->getFormat());
		self::assertSame('BMFF', BMFFFile::fromString($this->ftyp('zzzz', 'zzzz'))->getFormat(), 'an unknown brand is still readable');
	}

	public function testSomethingWithoutAFtypIsRefused(): void
	{
		$this->expectException(\RuntimeException::class);
		BMFFFile::fromString($this->box('moov', 'x') . $this->box('mdat', 'y'));
	}

	public function testUntouchedFilesRewriteByteForByte(): void
	{
		foreach ([$this->heif(), $this->movie(), $this->heif() . 'tail'] as $bytes) {
			self::assertSame($bytes, BMFFFile::fromString($bytes)->toBinary());
		}
	}

	//
	// ─── Items ───────────────────────────────────────────────────────────────
	//

	public function testItemsJoinTheirDescriptionToTheirLocation(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		$items = $bmff->getItems();
		self::assertCount(1, $items);

		$item = $items[0];
		self::assertSame(1, $item->getId());
		self::assertSame(BMFFItem::ExifType, $item->getType());
		self::assertSame(BMFFItem::ExifType, $item->getName());
		self::assertSame(BMFFItem::FileConstruction, $item->getConstructionMethod());
		self::assertSame(12, $item->getLength());
		self::assertTrue($item->getIsWritable());
		self::assertGreaterThan(0, $item->getBaseOffset(), 'libheif puts the address in the base offset');
		self::assertSame($item->getBaseOffset(), $item->getOffset(), 'and leaves the extent offset zero');

		self::assertSame("\x00\x00\x00\x00MM\x00\x2a\x00\x00\x00\x08", $bmff->getItemData($item));
		self::assertSame($item, $bmff->getItem(BMFFItem::ExifType));
		self::assertNull($bmff->getItem('av01'));
		self::assertSame([], BMFFFile::fromString($this->ftyp())->getItems(), 'a file with no meta box has no items');
	}

	public function testAMimeItemCarriesItsContentType(): void
	{
		$bmff = BMFFFile::fromString($this->heif(BMFFItem::MimeType, '<x:xmpmeta/>', BMFFItem::XmpContentType));
		$item = $bmff->getItem(BMFFItem::MimeType);
		self::assertNotNull($item);
		self::assertSame(BMFFItem::XmpContentType, $item->getContentType());
		self::assertTrue($item->getIsXmp());
		self::assertSame('<x:xmpmeta/>', $bmff->getItemData($item));

		$other = BMFFFile::fromString($this->heif(BMFFItem::MimeType, 'data', 'text/plain'));
		self::assertFalse($other->getItem(BMFFItem::MimeType)?->getIsXmp());
	}

	public function testVersionThreeEntriesUseAWiderItemId(): void
	{
		$data = 'payload';
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(70000, BMFFItem::ExifType, 'big', '', 3)),
			$this->iloc([[70000, $base, 0, strlen($data)]]),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);
		$item = BMFFFile::fromString($bytes)->getItems()[0] ?? null;
		self::assertSame(70000, $item?->getId());
		self::assertSame('big', $item?->getName());
	}

	public function testAnItemLocatedInIdatIsRead(): void
	{
		$bytes = $this->ftyp() . $this->meta(
			$this->iinf($this->infe(1, BMFFItem::ExifType)),
			$this->box('idat', 'XXhello'),
			$this->fullBox('iloc', chr(0x44) . chr(0x40) . pack('n', 1)
				. pack('n', 1) . pack('n', 1) . pack('n', 0) . pack('N', 0) . pack('n', 1) . pack('N', 2) . pack('N', 5), 1),
		);
		$item = BMFFFile::fromString($bytes)->getItems()[0] ?? null;
		self::assertSame(BMFFItem::IdatConstruction, $item?->getConstructionMethod());
		self::assertSame('hello', BMFFFile::fromString($bytes)->getItemData($item));
		self::assertFalse($item->getIsWritable(), 'an idat item is not repointed into a new mdat');
	}

	public function testUnreadableLocationsReadAsNull(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		$zero = new BMFFItem(9, BMFFItem::ExifType);
		self::assertNull($bmff->getItemData($zero), 'an item with no length has no data');

		$other = new BMFFItem(9, BMFFItem::ExifType);
		$other->setLocation(2, 0, -1, 0, 0, 4, 0, 4, 4, 4, true);
		self::assertNull($bmff->getItemData($other), 'construction method 2 is not resolved');

		$past = new BMFFItem(9, BMFFItem::ExifType);
		$past->setLocation(BMFFItem::FileConstruction, 0, -1, 0, 1 << 24, 4, 0, 4, 4, 4, true);
		self::assertNull($bmff->getItemData($past));

		$noIdat = new BMFFItem(9, BMFFItem::ExifType);
		$noIdat->setLocation(BMFFItem::IdatConstruction, 0, -1, 0, 0, 4, 0, 4, 4, 4, true);
		self::assertNull($bmff->getItemData($noIdat), 'there is no idat box to read from');
	}

	public function testOddlyShapedItemTablesAreToleratedRatherThanTrusted(): void
	{
		// A box inside iinf that is not an infe entry, an infe of a version older than the
		// one that defines this layout, and an unterminated item name.
		$data = 'payload';
		$ftyp = $this->ftyp();
		$entries = $this->box('free', 'pad')
			. $this->fullBox('infe', pack('n', 5) . pack('n', 0) . 'Exif' . 'old', 0)
			. $this->fullBox('infe', pack('n', 1) . pack('n', 0) . BMFFItem::ExifType . 'unterminated', 2);
		$build = fn (int $base) => $this->meta(
			$this->fullBox('iinf', pack('n', 3) . $entries),
			$this->iloc([[1, $base, 0, strlen($data)]]),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$items = BMFFFile::fromString($bytes)->getItems();
		self::assertCount(1, $items, 'the stray box and the obsolete entry are skipped');
		self::assertSame('unterminated', $items[0]->getName());
		self::assertSame($data, BMFFFile::fromString($bytes)->getItemData($items[0]));
	}

	public function testAVersionTwoItemLocationTableIsRead(): void
	{
		// iloc version 2 widens the item count and the ids to 32 bits and carries a
		// construction method per item.
		$data = 'in the file';
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(70000, BMFFItem::ExifType, 'wide', '', 3)),
			$this->fullBox('iloc', chr(0x44) . chr(0x40) . pack('N', 1)
				. pack('N', 70000) . pack('n', BMFFItem::FileConstruction) . pack('n', 0)
				. pack('N', $base) . pack('n', 1) . pack('N', 0) . pack('N', strlen($data)), 2),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$item = BMFFFile::fromString($bytes)->getItems()[0] ?? null;
		self::assertSame(70000, $item?->getId());
		self::assertSame(BMFFItem::FileConstruction, $item?->getConstructionMethod());
		self::assertSame($data, BMFFFile::fromString($bytes)->getItemData($item));
	}

	//
	// ─── Writing an item without moving the file ─────────────────────────────
	//

	public function testRepointingAnItemMovesNothingThatWasAlreadyThere(): void
	{
		$bytes = $this->heif();
		$bmff = BMFFFile::fromString($bytes);
		$item = $bmff->getItem(BMFFItem::ExifType);
		self::assertNotNull($item);

		$bmff->setItemData($item, 'a considerably longer exif payload than before');
		$out = $bmff->toBinary();

		// Every byte of the original file is where it was, except the two iloc fields.
		$differing = [];
		for ($i = 0; $i < strlen($bytes); $i++) {
			if ($out[$i] !== $bytes[$i]) {
				$differing[] = $i;
			}
		}
		self::assertCount(2, $differing, 'only the extent offset and length change');
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'), 'the media does not move');
		self::assertGreaterThan(strlen($bytes), strlen($out), 'the new bytes are appended');

		$round = BMFFFile::fromString($out);
		self::assertSame('a considerably longer exif payload than before', $round->getItemData($round->getItem(BMFFItem::ExifType)));
	}

	public function testAnItemThatCannotBeRepointedThrows(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		$fresh = new BMFFItem(9, BMFFItem::ExifType);   // never located, so not writable
		$this->expectException(\RuntimeException::class);
		$bmff->setItemData($fresh, 'data');
	}

	public function testWritingWithoutAnIlocThrows(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		$item = $bmff->getItem(BMFFItem::ExifType);
		self::assertNotNull($item);
		$bmff->setBoxes([new BMFFFileBox('ftyp', 'heic' . pack('N', 0) . 'heic')]);
		$this->expectException(\RuntimeException::class);
		$bmff->setItemData($item, 'data');
	}

	public function testALocationThatWouldNotFitItsFieldsThrows(): void
	{
		// A one-byte length field cannot hold a payload of 300 bytes.
		$data = 'x';
		$ftyp = $this->ftyp();
		$narrow = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, BMFFItem::ExifType)),
			$this->fullBox('iloc', chr(0x41) . chr(0x40) . pack('n', 1)
				. pack('n', 1) . pack('n', 0) . pack('N', $base) . pack('n', 1) . pack('N', 0) . chr(strlen($data))),
		);
		$bytes = $ftyp . $narrow(strlen($ftyp) + strlen($narrow(0)) + 8) . $this->box('mdat', $data);
		$bmff = BMFFFile::fromString($bytes);
		$item = $bmff->getItem(BMFFItem::ExifType);
		self::assertNotNull($item);
		self::assertSame('x', $bmff->getItemData($item));

		$this->expectException(\RuntimeException::class);
		$bmff->setItemData($item, str_repeat('y', 300));
	}

	//
	// ─── EXIF ────────────────────────────────────────────────────────────────
	//

	public function testExifRoundTripsThroughItsItem(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		self::assertNull($bmff->getEXIF()?->getValueByName('Artist'), 'the stub TIFF carries no tags yet');

		$bmff->setEXIF($this->exif());
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertTrue($round->hasEXIF());
	}

	public function testANonZeroTiffOffsetIsHonoured(): void
	{
		$tiff = $this->exif('Offset Photographer')->toBinary();
		$bmff = BMFFFile::fromString($this->heif(BMFFItem::ExifType, pack('N', 3) . 'pad' . $tiff));
		self::assertSame('Offset Photographer', $bmff->getEXIF()?->getValueByName('Artist'));
	}

	public function testUnreadableExifReadsAsAbsent(): void
	{
		self::assertNull(BMFFFile::fromString($this->heif(BMFFItem::ExifType, 'ab'))->getEXIF());
		self::assertNull(BMFFFile::fromString($this->heif(BMFFItem::ExifType, pack('N', 0)))->getEXIF());
		self::assertNull(BMFFFile::fromString($this->heif(BMFFItem::ExifType, pack('N', 0) . 'garbage'))->getEXIF());
	}

	public function testExifOnAMovieIsRefusedRatherThanDropped(): void
	{
		// A movie has a meta box but its item locations cannot be corrected, so no item can
		// be added to it.
		$bmff = BMFFFile::fromString($this->movie());
		self::assertNull($bmff->getEXIF());
		$bmff->setEXIF(null);   // clearing what is not there is a no-op
		$this->expectException(\RuntimeException::class);
		$bmff->setEXIF($this->exif());
	}

	public function testExifCreatesItsItemWhenTheFileHasNone(): void
	{
		$bytes = $this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))]);
		$bmff = BMFFFile::fromString($bytes);
		$image = $bmff->getItems()[0];
		$pixels = $bmff->getItemData($image);
		self::assertNull($bmff->getEXIF());

		$bmff->setEXIF($this->exif());
		$round = BMFFFile::fromString($bmff->toBinary());

		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertCount(2, $round->getItems(), 'the Exif item joined the picture');
		self::assertSame($pixels, $round->getItemData($round->getItemById($image->getId())), 'the picture is intact');

		// The new item says it describes the picture, which is how a reader finds it.
		$cdsc = $round->getBox('meta')?->getChild('iref')?->getChild('cdsc');
		self::assertNotNull($cdsc);
		self::assertSame(
			bin2hex(pack('n', $image->getId() + 1) . pack('n', 1) . pack('n', $image->getId())),
			bin2hex($cdsc->getPayload()),
		);
	}

	public function testAnItemIsAddedWithItsDescriptionLocationAndReference(): void
	{
		$bytes = $this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))]);
		$bmff = BMFFFile::fromString($bytes);
		$image = $bmff->getItems()[0];

		$item = $bmff->addItem('mime', 'some bytes', 'note', 'text/plain');
		self::assertSame(2, $item->getId(), 'the id is the next one free');
		self::assertSame('mime', $item->getType());

		$round = BMFFFile::fromString($bmff->toBinary());
		$added = $round->getItemById(2);
		self::assertNotNull($added);
		self::assertSame('note', $added->getName());
		self::assertSame('text/plain', $added->getContentType());
		self::assertSame('some bytes', $round->getItemData($added));
		self::assertSame($bmff->getItemData($image), $round->getItemData($round->getItemById(1)));
	}

	public function testAnItemCanBeAddedWhenTheFileHasNoItemTables(): void
	{
		// A meta box with only a primary item: iinf, iloc and iref are all created.
		$bytes = $this->ftyp() . $this->meta($this->fullBox('pitm', pack('n', 1)));
		$bmff = BMFFFile::fromString($bytes);
		self::assertSame([], $bmff->getItems());

		$item = $bmff->addItem(BMFFItem::ExifType, pack('N', 0) . $this->exif()->toBinary(), 'Exif');
		self::assertSame(1, $item->getId());

		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertNotNull($round->getBox('meta')?->getChild('iinf'));
		self::assertNotNull($round->getBox('meta')?->getChild('iloc'));
		self::assertNull($round->getBox('meta')?->getChild('iref'), 'the new item is the primary one, so it describes nothing');
	}

	public function testAWiderItemInfoCountIsKept(): void
	{
		// An iinf of version 1 counts its entries in four bytes rather than two.
		$data = 'item bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->fullBox('iinf', pack('N', 1) . $this->infe(1, 'hvc1'), 1),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		$bmff->addItem('mime', 'x', 'n', 'text/plain');
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertCount(2, $round->getItems());
		self::assertSame(1, ord((string) $round->getBox('meta')?->getChild('iinf')?->getPayload()), 'the version is unchanged');
		self::assertSame($data, $round->getItemData($round->getItemById(1)));
	}

	public function testAddingAnItemToAVersionOneLocationTable(): void
	{
		// An iloc of version 1 carries a construction method per entry, so a new entry must too.
		$data = 'item bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->fullBox('iloc', chr(0x44) . chr(0x40) . pack('n', 1)
				. pack('n', 1) . pack('n', BMFFItem::FileConstruction) . pack('n', 0)
				. pack('N', $base) . pack('n', 1) . pack('N', 0) . pack('N', strlen($data)), 1),
			$this->fullBox('pitm', pack('n', 1)),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		$bmff->addItem(BMFFItem::ExifType, pack('N', 0) . $this->exif()->toBinary(), 'Exif');
		$round = BMFFFile::fromString($bmff->toBinary());

		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertSame(BMFFItem::FileConstruction, $round->getItemById(2)?->getConstructionMethod());
		self::assertSame($data, $round->getItemData($round->getItemById(1)), 'the picture is intact');
	}

	public function testAFileWithNoItemIdLeftIsRefused(): void
	{
		// An entry describes its item in sixteen bits, so 65535 is the last usable id.
		$data = 'x';
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(65535, 'hvc1')),
			$this->iloc([[65535, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 65535)),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		self::assertSame(65535, $bmff->getItems()[0]->getId());
		try {
			$bmff->addItem(BMFFItem::ExifType, 'data');
			self::fail('there is no id left to give');
		} catch (\RuntimeException $e) {
		}
		self::assertSame($bytes, $bmff->toBinary(), 'the refused addition is undone');
	}

	public function testAddingAnItemToAFileWithNoMetaBoxIsRefused(): void
	{
		$bmff = BMFFFile::fromString($this->ftyp());
		$this->expectException(\RuntimeException::class);
		$bmff->addItem(BMFFItem::ExifType, 'data');
	}

	public function testAnAbsentItemIdReadsAsNull(): void
	{
		self::assertNull(BMFFFile::fromString($this->heif())->getItemById(99));
	}

	public function testXmpOnAStillBecomesAMimeItem(): void
	{
		$bytes = $this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))]);
		$bmff = BMFFFile::fromString($bytes);

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'A Still');
		$bmff->setXMP($xmp);
		$out = $bmff->toBinary();

		self::assertStringNotContainsString('uuid', $out, 'a still uses the item form HEIF defines');
		$round = BMFFFile::fromString($out);
		self::assertSame(['A Still'], $round->getXMP()?->getProperty(XMP::NS_DC, 'title'));
		self::assertTrue($round->getItemById(2)?->getIsXmp());

		// Rewriting goes back through the same item, and clearing empties it.
		$round->setXmpText('<x:xmpmeta>replaced</x:xmpmeta>');
		self::assertSame('<x:xmpmeta>replaced</x:xmpmeta>', BMFFFile::fromString($round->toBinary())->getXmpText());
		$round->setXmpText(null);
		self::assertNull(BMFFFile::fromString($round->toBinary())->getXmpText());
	}

	public function testClearingExifEmptiesItsItem(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		$bmff->setEXIF($this->exif());
		$bmff->setEXIF(null);
		self::assertNull(BMFFFile::fromString($bmff->toBinary())->getEXIF());
	}

	//
	// ─── XMP ─────────────────────────────────────────────────────────────────
	//

	public function testXmpRoundTripsThroughTheAdobeUuidBox(): void
	{
		$bytes = $this->movie();
		$bmff = BMFFFile::fromString($bytes);
		self::assertNull($bmff->getXmpText());
		self::assertFalse($bmff->hasXMP());

		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'A Movie');
		$bmff->setXMP($xmp);
		$out = $bmff->toBinary();
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'), 'the media does not move');

		$round = BMFFFile::fromString($out);
		self::assertSame(['A Movie'], $round->getXMP()?->getProperty(XMP::NS_DC, 'title'));
		self::assertTrue($round->hasXMP());
	}

	public function testRewritingTheUuidBoxKeepsTheFileStillWhenItCan(): void
	{
		$bmff = BMFFFile::fromString($this->movie());
		$bmff->setXmpText('12345678');
		$bytes = $bmff->toBinary();

		$same = BMFFFile::fromString($bytes);
		$same->setXmpText('abcdefgh');   // identical length: rewritten in place
		self::assertSame(strlen($bytes), strlen($same->toBinary()));
		self::assertStringNotContainsString('free', $same->toBinary());
		self::assertSame('abcdefgh', $same->getXmpText());

		$grown = BMFFFile::fromString($bytes);
		$grown->setXmpText('a much longer packet than the slot holds');
		$out = $grown->toBinary();
		self::assertStringContainsString('free', $out, 'the vacated slot is padded');
		self::assertSame('a much longer packet than the slot holds', BMFFFile::fromString($out)->getXmpText());
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
	}

	public function testASmallerUuidBoxKeepsItsSlot(): void
	{
		$bmff = BMFFFile::fromString($this->movie());
		$bmff->setXmpText(str_repeat('x', 40));
		$bytes = $bmff->toBinary();

		$shrunk = BMFFFile::fromString($bytes);
		$shrunk->setXmpText(str_repeat('y', 20));   // frees 20 bytes, more than a free box needs
		$out = $shrunk->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the file is the same length');
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
		$types = array_map(fn ($b) => $b->getType(), $shrunk->getBoxes());
		self::assertSame('uuid', $types[3], 'the box stays where it was');
		self::assertSame('free', $types[4], 'and the remainder is free space');
		self::assertSame(str_repeat('y', 20), BMFFFile::fromString($out)->getXmpText());
	}

	public function testAUuidBoxTooLittleSmallerCannotKeepItsSlot(): void
	{
		$bmff = BMFFFile::fromString($this->movie());
		$bmff->setXmpText(str_repeat('x', 40));
		$bytes = $bmff->toBinary();

		$shrunk = BMFFFile::fromString($bytes);
		$shrunk->setXmpText(str_repeat('y', 36));   // frees only four bytes
		$out = $shrunk->toBinary();

		$types = array_map(fn ($b) => $b->getType(), $shrunk->getBoxes());
		self::assertSame('free', $types[3], 'the old slot is vacated');
		self::assertSame('uuid', $types[count($types) - 1], 'and the box went to the end');
		self::assertSame(str_repeat('y', 36), BMFFFile::fromString($out)->getXmpText());
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
	}

	public function testAGrownUuidBoxIsWrittenIntoTheFilesOtherFreeSpace(): void
	{
		$bytes = $this->ftyp('isom', 'isom', 'mp41')
			. $this->box('mdat', str_repeat("\x11", 32))
			. $this->box('free', str_repeat("\0", 200))
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)) . $this->box('udta', $this->meta()));
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setXmpText('<x:xmpmeta/>');
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the free space paid for it');
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
		self::assertSame('<x:xmpmeta/>', BMFFFile::fromString($out)->getXmpText());
		self::assertSame('uuid', $bmff->getBoxes()[2]->getType(), 'where the free space was');
	}

	public function testAdjacentFreeSpaceIsMergedSoItCanBeUsedTogether(): void
	{
		$bytes = $this->ftyp('isom', 'isom', 'mp41')
			. $this->box('mdat', str_repeat("\x11", 32))
			. $this->box('uuid', BMFFFile::XmpUuid . 'short')
			. $this->box('free', str_repeat("\0", 40))
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)));
		$bmff = BMFFFile::fromString($bytes);
		// The vacated slot is 29 bytes and the free run beside it 48; neither can take the
		// 60-byte box, but the 77 they make together can.
		$bmff->setXmpText(str_repeat('x', 36));
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes), strlen($out));
		self::assertSame(str_repeat('x', 36), BMFFFile::fromString($out)->getXmpText());
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
	}

	public function testRemovingTheUuidBoxLeavesFreeSpaceBehind(): void
	{
		$bmff = BMFFFile::fromString($this->movie());
		$bmff->setXmpText('<x:xmpmeta/>');
		$bytes = $bmff->toBinary();

		$dropped = BMFFFile::fromString($bytes);
		$dropped->setXMP(null);
		$out = $dropped->toBinary();
		self::assertNull(BMFFFile::fromString($out)->getXmpText());
		self::assertStringContainsString('free', $out);
		self::assertSame(strlen($bytes), strlen($out), 'the slot is padded, not removed');
	}

	public function testXmpInAMimeItemIsRewrittenAsAnItem(): void
	{
		$bmff = BMFFFile::fromString($this->heif(BMFFItem::MimeType, '<x:xmpmeta>old</x:xmpmeta>', BMFFItem::XmpContentType));
		self::assertSame('<x:xmpmeta>old</x:xmpmeta>', $bmff->getXmpText());

		$bmff->setXmpText('<x:xmpmeta>new and longer</x:xmpmeta>');
		$out = $bmff->toBinary();
		self::assertSame('<x:xmpmeta>new and longer</x:xmpmeta>', BMFFFile::fromString($out)->getXmpText());
		self::assertStringNotContainsString('uuid', $out, 'an item file keeps using its item');
	}

	public function testUnparsableXmpReadsAsAbsent(): void
	{
		$bmff = BMFFFile::fromString($this->movie());
		$bmff->setXmpText('not xmp at all');
		self::assertSame('not xmp at all', $bmff->getXmpText());
		self::assertNull($bmff->getXMP());
	}

	//
	// ─── User data: the two conventions ──────────────────────────────────────
	//

	public function testQuickTimeTextAtomsAreRead(): void
	{
		$bmff = BMFFFile::fromString($this->movieWithUserData(
			$this->atom(BMFFFile::KeyTitle, 'A Clip') . $this->atom(BMFFFile::KeyEncoder, 'Lavf63.1.101'),
		));
		self::assertSame(
			[BMFFFile::KeyTitle => 'A Clip', BMFFFile::KeyEncoder => 'Lavf63.1.101'],
			$bmff->getUserDataAtoms(),
		);
		self::assertSame('A Clip', $bmff->getUserDataValue(BMFFFile::KeyTitle));
		self::assertNull($bmff->getUserDataValue(BMFFFile::KeyArtist));
		self::assertSame([], $bmff->getItunesTags());
		self::assertSame([], BMFFFile::fromString($this->heif())->getUserDataAtoms(), 'a still has no movie user data');
	}

	public function testATruncatedTextAtomIsSkipped(): void
	{
		$bmff = BMFFFile::fromString($this->movieWithUserData($this->box(BMFFFile::KeyTitle, 'ab')));
		self::assertSame([], $bmff->getUserDataAtoms());
	}

	public function testItunesTagsAreRead(): void
	{
		$bmff = BMFFFile::fromString($this->movieWithUserData($this->fullBox(
			'meta',
			$this->box('hdlr', str_repeat("\0", 8) . 'mdir' . 'appl' . str_repeat("\0", 9))
			. $this->box('ilst', $this->ilstKey(BMFFFile::KeyTitle, 'A Clip') . $this->ilstKey(BMFFFile::KeyArtist, 'A Director')),
		)));
		self::assertSame([BMFFFile::KeyTitle => 'A Clip', BMFFFile::KeyArtist => 'A Director'], $bmff->getItunesTags());
		self::assertSame('A Director', $bmff->getUserDataValue(BMFFFile::KeyArtist));
		self::assertSame([], $bmff->getUserDataAtoms(), 'the iTunes meta box is not a text atom');
	}

	public function testOnlyTextValuesAreReadFromTheItunesList(): void
	{
		$bmff = BMFFFile::fromString($this->movieWithUserData($this->fullBox('meta', $this->box(
			'ilst',
			$this->ilstKey('covr', 'binary cover art', 13)     // 13 is JPEG, not text
			. $this->box('trkn', $this->box('free', 'no data box here'))
			. $this->box('shrt', $this->box('data', 'tiny'))   // too short for a header
			. $this->ilstKey(BMFFFile::KeyTitle, 'A Clip'),
		))));
		self::assertSame([BMFFFile::KeyTitle => 'A Clip'], $bmff->getItunesTags());
	}

	public function testATextAtomRoundTripsAndKeepsItsLanguage(): void
	{
		$bytes = $this->movieWithUserData($this->atom(BMFFFile::KeyTitle, 'Old', 0x15C7));
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setUserDataAtom(BMFFFile::KeyTitle, 'A considerably longer title');
		$out = $bmff->toBinary();

		self::assertSame('A considerably longer title', BMFFFile::fromString($out)->getUserDataAtoms()[BMFFFile::KeyTitle]);
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'), 'moov is last, so nothing moves');
		$payload = BMFFFile::fromString($out)->getBox('moov')?->getChild('udta')?->getChild(BMFFFile::KeyTitle)?->getPayload();
		self::assertSame(0x15C7, (int) unpack('n', substr((string) $payload, 2, 2))[1], 'the language is kept');

		$bmff->setUserDataAtom(BMFFFile::KeyTitle, null);
		self::assertSame([], BMFFFile::fromString($bmff->toBinary())->getUserDataAtoms());
	}

	public function testAnItunesTagRoundTripsAndBuildsTheChainItNeeds(): void
	{
		$bytes = $this->movieWithUserData('');
		$bmff = BMFFFile::fromString($bytes);
		self::assertSame([], $bmff->getItunesTags());

		$bmff->setItunesTag(BMFFFile::KeyTitle, 'Built From Nothing');
		$out = $bmff->toBinary();
		$round = BMFFFile::fromString($out);
		self::assertSame(['mdir'], [substr((string) $round->getBox('moov')?->getChild('udta')?->getChild('meta')?->getChild('hdlr')?->getPayload(), 8, 4)]);
		self::assertSame([BMFFFile::KeyTitle => 'Built From Nothing'], $round->getItunesTags());

		$round->setItunesTag(BMFFFile::KeyTitle, 'Replaced');
		self::assertSame(['\xA9nam' => 'Replaced'][BMFFFile::KeyTitle] ?? 'Replaced', BMFFFile::fromString($round->toBinary())->getItunesTags()[BMFFFile::KeyTitle]);

		$round->setItunesTag(BMFFFile::KeyTitle, null);
		self::assertSame([], BMFFFile::fromString($round->toBinary())->getItunesTags());
	}

	public function testTheConventionFollowsTheFileAndThenTheBrand(): void
	{
		// A file already using text atoms keeps using them.
		$atoms = BMFFFile::fromString($this->movieWithUserData($this->atom(BMFFFile::KeyTitle, 'Old')));
		$atoms->setUserDataValue(BMFFFile::KeyTitle, 'New');
		self::assertSame(['\xA9nam' => 'New'][BMFFFile::KeyTitle] ?? 'New', $atoms->getUserDataAtoms()[BMFFFile::KeyTitle]);
		self::assertSame([], $atoms->getItunesTags());

		// An MP4 with neither gets the iTunes list.
		$mp4 = BMFFFile::fromString($this->movieWithUserData(''));
		$mp4->setUserDataValue(BMFFFile::KeyTitle, 'Tagged');
		self::assertSame('Tagged', $mp4->getItunesTags()[BMFFFile::KeyTitle]);
		self::assertSame([], $mp4->getUserDataAtoms());

		// A QuickTime movie with neither gets a text atom.
		$mov = BMFFFile::fromString($this->ftyp('qt  ', 'qt  ') . $this->box('mdat', 'x')
			. $this->box('moov', $this->box('udta', '')));
		$mov->setUserDataValue(BMFFFile::KeyTitle, 'Atomised');
		self::assertSame('Atomised', $mov->getUserDataAtoms()[BMFFFile::KeyTitle]);
		self::assertSame([], $mov->getItunesTags());
	}

	public function testRemovingAValueClearsBothConventions(): void
	{
		$bmff = BMFFFile::fromString($this->movieWithUserData(
			$this->atom(BMFFFile::KeyTitle, 'From an atom')
			. $this->fullBox('meta', $this->box('ilst', $this->ilstKey(BMFFFile::KeyTitle, 'From a tag'))),
		));
		self::assertSame('From a tag', $bmff->getUserDataValue(BMFFFile::KeyTitle), 'the iTunes list is read first');

		$bmff->setUserDataValue(BMFFFile::KeyTitle, null);
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertNull($round->getUserDataValue(BMFFFile::KeyTitle));
		self::assertSame([], $round->getUserDataAtoms());
		self::assertSame([], $round->getItunesTags());
	}

	public function testAMovieWithoutAUserDataBoxGetsOne(): void
	{
		$bytes = $this->ftyp('isom', 'isom') . $this->box('mdat', 'x')
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)));
		$bmff = BMFFFile::fromString($bytes);
		self::assertSame([], $bmff->getUserDataAtoms());

		$bmff->setUserDataAtom(BMFFFile::KeyTitle, 'Fresh');
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertSame('Fresh', $round->getUserDataAtoms()[BMFFFile::KeyTitle]);
		self::assertNotNull($round->getBox('moov')?->getChild('udta'));
	}

	public function testUserDataOnAFileWithoutAMovieIsRefused(): void
	{
		$this->expectException(\RuntimeException::class);
		BMFFFile::fromString($this->heif())->setUserDataValue(BMFFFile::KeyTitle, 'nowhere to put this');
	}

	//
	// ─── Resizing the movie without moving the media ─────────────────────────
	//

	public function testAMovieBeforeTheMediaAbsorbsAShrinkIntoItsFreeBox(): void
	{
		$bytes = $this->faststart($this->atom(BMFFFile::KeyTitle, 'A long enough title'), 4);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setUserDataValue(BMFFFile::KeyTitle, null);
		$out = $bmff->toBinary();

		self::assertNull(BMFFFile::fromString($out)->getUserDataValue(BMFFFile::KeyTitle));
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'), 'the media does not move');
		self::assertSame(strlen($bytes), strlen($out), 'the free box took up the slack');
	}

	public function testAMovieBeforeTheMediaAbsorbsAGrowthWhenThereIsRoom(): void
	{
		$bytes = $this->faststart($this->atom(BMFFFile::KeyTitle, 'Short'), 64);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setUserDataValue(BMFFFile::KeyTitle, 'A considerably longer title than before');
		$out = $bmff->toBinary();

		self::assertSame('A considerably longer title than before', BMFFFile::fromString($out)->getUserDataValue(BMFFFile::KeyTitle));
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
		self::assertSame(strlen($bytes), strlen($out));
	}

	public function testAGrowthWithNoRoomIsRefusedAndTheFileIsLeftIntact(): void
	{
		$bytes = $this->faststart($this->atom(BMFFFile::KeyTitle, 'Short'), 0);
		$bmff = BMFFFile::fromString($bytes);
		try {
			$bmff->setUserDataValue(BMFFFile::KeyTitle, 'A considerably longer title than before');
			self::fail('growing the movie past the free box must be refused');
		} catch (\RuntimeException $e) {
		}
		self::assertSame($bytes, $bmff->toBinary(), 'the refused edit is undone, not half-applied');
		self::assertSame('Short', $bmff->getUserDataValue(BMFFFile::KeyTitle));
	}

	public function testAGrowthWithNoFreeBoxAtAllIsRefused(): void
	{
		$bytes = $this->ftyp('isom', 'isom') . $this->box('moov', $this->box('udta', $this->atom(BMFFFile::KeyTitle, 'S')))
			. $this->box('mdat', str_repeat("\x11", 16));
		$bmff = BMFFFile::fromString($bytes);
		$this->expectException(\RuntimeException::class);
		$bmff->setUserDataValue(BMFFFile::KeyTitle, 'A much longer title');
	}

	//
	// ─── Carriers the format does not have ───────────────────────────────────
	//

	public function testIptcIsRefusedRatherThanDropped(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		self::assertNull($bmff->getIPTC());
		self::assertFalse($bmff->hasIPTC());
		$bmff->setIPTC(null);
		$this->expectException(\RuntimeException::class);
		$bmff->setIPTC(new IPTC());
	}

	//
	// ─── The ICC colour property ─────────────────────────────────────────────
	//

	public function testAnIccProfileIsReadFromTheColourProperty(): void
	{
		$profile = str_repeat('P', 64);
		$bmff = BMFFFile::fromString($this->heifWithProperties([
			$this->box('ispe', str_repeat("\0", 12)),
			$this->colr(BMFFFile::ColourTypeProfile, $profile),
		]));
		self::assertSame($profile, $bmff->getICCProfile());
		self::assertTrue($bmff->hasICCProfile());

		// The restricted form is a profile too.
		$restricted = BMFFFile::fromString($this->heifWithProperties([$this->colr(BMFFFile::ColourTypeRestrictedProfile, 'rp')]));
		self::assertSame('rp', $restricted->getICCProfile());
	}

	public function testAColourBoxThatOnlyNamesAColourSpaceIsNotAProfile(): void
	{
		// `nclx` states coefficients rather than carrying a profile: the real shape libheif writes.
		$bmff = BMFFFile::fromString($this->heifWithProperties([$this->colr('nclx', "\x00\x01\x00\x0d\x00\x06\x80")]));
		self::assertNull($bmff->getICCProfile());
		self::assertFalse($bmff->hasICCProfile());
		self::assertNull(BMFFFile::fromString($this->heif())->getICCProfile(), 'and a file with no properties has none');
	}

	public function testAProfileOfTheSameLengthIsWrittenInPlace(): void
	{
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$bmff = BMFFFile::fromString($bytes);
		$before = $bmff->getItems()[0]->getOffset();

		$bmff->setICCProfile(str_repeat('Q', 64));
		$out = $bmff->toBinary();
		self::assertSame(strlen($bytes), strlen($out), 'nothing changes length');
		self::assertSame($before, BMFFFile::fromString($out)->getItems()[0]->getOffset(), 'so nothing moves');
		self::assertSame(str_repeat('Q', 64), BMFFFile::fromString($out)->getICCProfile());
	}

	public function testAddingAProfileShiftsTheItemLocationsToMatch(): void
	{
		$bytes = $this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))]);
		$bmff = BMFFFile::fromString($bytes);
		$data = $bmff->getItemData($bmff->getItems()[0]);
		$before = $bmff->getItems()[0]->getOffset();

		$profile = str_repeat('P', 200);
		$bmff->setICCProfile($profile);
		$out = $bmff->toBinary();
		$round = BMFFFile::fromString($out);

		self::assertSame($profile, $round->getICCProfile());
		self::assertGreaterThan($before, $round->getItems()[0]->getOffset(), 'the media moved down');
		self::assertSame($data, $round->getItemData($round->getItems()[0]), 'and iloc was corrected to match');
		self::assertSame(strlen($bytes) + 8 + 4 + 200 + 1, strlen($out), 'the colr box plus one association byte');
	}

	public function testReplacingAProfileWithADifferentLengthShiftsTheItems(): void
	{
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$bmff = BMFFFile::fromString($bytes);
		$data = $bmff->getItemData($bmff->getItems()[0]);

		$bmff->setICCProfile(str_repeat('Q', 200));   // longer, so meta grows and the media moves
		$round = BMFFFile::fromString($bmff->toBinary());

		self::assertSame(str_repeat('Q', 200), $round->getICCProfile());
		self::assertSame($data, $round->getItemData($round->getItems()[0]), 'iloc was corrected');
		self::assertSame(strlen($bytes) + 136, strlen($bmff->toBinary()), 'exactly the profile difference');
	}

	public function testRemovingAProfileWithNoAssociationBox(): void
	{
		// An ipco holding the profile but no ipma to repair.
		$data = 'item bytes';
		$ftyp = $this->ftyp();
		$iprp = $this->box('iprp', $this->box('ipco', $this->colr(BMFFFile::ColourTypeProfile, 'gone')));
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
			$iprp,
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		self::assertSame('gone', $bmff->getICCProfile());
		$bmff->setICCProfile(null);
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertNull($round->getICCProfile());
		self::assertSame($data, $round->getItemData($round->getItems()[0]));
	}

	public function testAFileWithNoItemLocationsNeedsNoCorrection(): void
	{
		$bytes = $this->ftyp() . $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->fullBox('pitm', pack('n', 1)),
			$this->box('iprp', $this->box('ipco', '')),
		);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile('a profile');
		self::assertSame('a profile', BMFFFile::fromString($bmff->toBinary())->getICCProfile());
	}

	//
	// ─── Trading with the padding property, so nothing moves ─────────────────
	//

	public function testAShorterProfileKeepsItsSlotAndPadsTheRemainder(): void
	{
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$bmff = BMFFFile::fromString($bytes);
		$data = $bmff->getItemData($bmff->getItems()[0]);

		$bmff->setICCProfile(str_repeat('Q', 20));   // frees 44 bytes, plenty for a free box
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the file is the same length');
		$round = BMFFFile::fromString($out);
		self::assertSame(str_repeat('Q', 20), $round->getICCProfile());
		self::assertSame($data, $round->getItemData($round->getItems()[0]), 'the media never moved');
		$ipco = $round->getBox('meta')?->getChild('iprp')?->getChild('ipco');
		self::assertSame(['colr', 'free'], array_map(fn ($p) => $p->getType(), $ipco?->getChildren() ?? []));
	}

	public function testAShorterProfileWithTooLittleFreedCannotKeepItsSlot(): void
	{
		// Freeing four bytes leaves nowhere to put a padding property, so the container resizes.
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile(str_repeat('Q', 60));
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes) - 4, strlen($out), 'the container shrank instead');
		$round = BMFFFile::fromString($out);
		self::assertSame(str_repeat('Q', 60), $round->getICCProfile());
		self::assertSame(['colr'], array_map(fn ($p) => $p->getType(), $round->getBox('meta')?->getChild('iprp')?->getChild('ipco')?->getChildren() ?? []));
	}

	public function testAProfileGrowsBackIntoThePaddingItLeft(): void
	{
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$shrunk = BMFFFile::fromString($bytes);
		$shrunk->setICCProfile(str_repeat('Q', 20));
		$withPadding = $shrunk->toBinary();

		$grown = BMFFFile::fromString($withPadding);
		$grown->setICCProfile(str_repeat('R', 50));   // takes 30 bytes back from the padding
		$out = $grown->toBinary();

		self::assertSame(strlen($withPadding), strlen($out), 'still the same length');
		self::assertSame(str_repeat('R', 50), BMFFFile::fromString($out)->getICCProfile());

		// Growing by exactly the padding's whole length removes it outright.
		$exact = BMFFFile::fromString($withPadding);
		$exact->setICCProfile(str_repeat('S', 20 + 36 + 8));
		$out = $exact->toBinary();
		self::assertSame(strlen($withPadding), strlen($out));
		self::assertSame(['colr'], array_map(fn ($p) => $p->getType(), BMFFFile::fromString($out)->getBox('meta')?->getChild('iprp')?->getChild('ipco')?->getChildren() ?? []));
	}

	public function testShrinkingAgainJustGrowsThePaddingThatIsAlreadyThere(): void
	{
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$first = BMFFFile::fromString($bytes);
		$first->setICCProfile(str_repeat('Q', 40));
		$withPadding = $first->toBinary();

		$second = BMFFFile::fromString($withPadding);
		$second->setICCProfile(str_repeat('R', 10));   // no minimum this time: the padding just grows
		$out = $second->toBinary();

		self::assertSame(strlen($withPadding), strlen($out));
		$round = BMFFFile::fromString($out);
		self::assertSame(str_repeat('R', 10), $round->getICCProfile());
		self::assertSame(['colr', 'free'], array_map(fn ($p) => $p->getType(), $round->getBox('meta')?->getChild('iprp')?->getChild('ipco')?->getChildren() ?? []));

		// Even a two-byte shortfall works once there is padding to put it in.
		$third = BMFFFile::fromString($out);
		$third->setICCProfile(str_repeat('S', 8));
		self::assertSame(strlen($out), strlen($third->toBinary()));
	}

	public function testAProfileTooBigForThePaddingResizesInstead(): void
	{
		$bytes = $this->heifWithProperties([$this->colr(BMFFFile::ColourTypeProfile, str_repeat('P', 64))]);
		$shrunk = BMFFFile::fromString($bytes);
		$shrunk->setICCProfile(str_repeat('Q', 20));
		$withPadding = $shrunk->toBinary();

		$grown = BMFFFile::fromString($withPadding);
		$grown->setICCProfile(str_repeat('R', 400));
		$out = $grown->toBinary();
		self::assertGreaterThan(strlen($withPadding), strlen($out), 'the container had to grow');
		self::assertSame(str_repeat('R', 400), BMFFFile::fromString($out)->getICCProfile());
	}

	public function testAProfileIsAddedIntoExistingPaddingWithoutResizing(): void
	{
		// A container that already carries padding can take a profile out of it.
		$bytes = $this->heifWithProperties([
			$this->box('ispe', str_repeat("\0", 12)),
			$this->box('free', str_repeat("\0", 60)),
		]);
		$bmff = BMFFFile::fromString($bytes);
		$data = $bmff->getItemData($bmff->getItems()[0]);

		$bmff->setICCProfile(str_repeat('P', 40));
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the padding paid for it');
		$round = BMFFFile::fromString($out);
		self::assertSame(str_repeat('P', 40), $round->getICCProfile());
		self::assertSame($data, $round->getItemData($round->getItems()[0]));
		self::assertSame(
			['ispe', 'colr', 'free'],
			array_map(fn ($p) => $p->getType(), $round->getBox('meta')?->getChild('iprp')?->getChild('ipco')?->getChildren() ?? []),
			'the new property goes before the padding, so the padding stays last',
		);
		// ispe keeps index 1 and the new colr takes index 2.
		self::assertStringContainsString(chr(1) . chr(2), (string) $round->getBox('meta')?->getChild('iprp')?->getChild('ipma')?->getPayload());
	}

	public function testPaddingThatIsNotLastIsNotTradedWith(): void
	{
		// A free box in the middle holds an index the associations count past, so it is left
		// alone and the container resizes instead.
		$bytes = $this->heifWithProperties([
			$this->box('free', str_repeat("\0", 60)),
			$this->box('ispe', str_repeat("\0", 12)),
		]);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile(str_repeat('P', 40));
		$out = $bmff->toBinary();

		self::assertGreaterThan(strlen($bytes), strlen($out), 'the middle padding was not touched');
		self::assertSame(str_repeat('P', 40), BMFFFile::fromString($out)->getICCProfile());
	}

	public function testAddingIntoPaddingStillNeedsAPrimaryItem(): void
	{
		$bytes = $this->ftyp() . $this->meta(
			$this->box('iprp', $this->box('ipco', $this->box('free', str_repeat("\0", 60)))),
		);
		$bmff = BMFFFile::fromString($bytes);
		try {
			$bmff->setICCProfile(str_repeat('P', 40));
			self::fail('there is nothing to associate the profile with');
		} catch (\RuntimeException $e) {
		}
		self::assertSame($bytes, $bmff->toBinary(), 'the refused write is undone');
	}

	public function testRemovingAProfileWhenThereIsNoAssociationBox(): void
	{
		$data = 'item bytes';
		$ftyp = $this->ftyp();
		$iprp = $this->box('iprp', $this->box('ipco', $this->colr(BMFFFile::ColourTypeProfile, 'gone')));
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
			$iprp,
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile(null);
		$out = $bmff->toBinary();
		self::assertSame(strlen($bytes), strlen($out), 'nothing to disassociate, so nothing changes length');
		self::assertNull(BMFFFile::fromString($out)->getICCProfile());
		self::assertSame($data, BMFFFile::fromString($out)->getItemData(BMFFFile::fromString($out)->getItems()[0]));
	}

	public function testRemovingAProfileThatIsNotThereWithNoProperties(): void
	{
		$bytes = $this->heif();
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile(null);
		self::assertSame($bytes, $bmff->toBinary());
	}

	public function testAddingAProfileAssociatesItNonEssentially(): void
	{
		$bmff = BMFFFile::fromString($this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))]));
		$bmff->setICCProfile('profile');
		$ipma = BMFFFile::fromString($bmff->toBinary())->getBox('meta')?->getChild('iprp')?->getChild('ipma');
		self::assertNotNull($ipma);

		// version 0, flags 0, one entry for item 1 with ispe (1) and the new colr (2).
		self::assertSame(
			bin2hex(chr(0) . "\x00\x00\x00" . pack('N', 1) . pack('n', 1) . chr(2) . chr(1) . chr(2)),
			bin2hex($ipma->getPayload()),
		);
	}

	public function testRemovingAProfileKeepsEveryPropertyIndex(): void
	{
		$bytes = $this->heifWithProperties(
			[$this->box('ispe', str_repeat("\0", 12)), $this->colr(BMFFFile::ColourTypeProfile, 'gone'), $this->box('pixi', 'xx')],
			$this->ipma(1, [[true, 1], [false, 2], [false, 3]]),
		);
		$bmff = BMFFFile::fromString($bytes);
		$data = $bmff->getItemData($bmff->getItems()[0]);

		$bmff->setICCProfile(null);
		$out = $bmff->toBinary();
		$round = BMFFFile::fromString($out);

		self::assertNull($round->getICCProfile());
		self::assertSame($data, $round->getItemData($round->getItems()[0]), 'the item still resolves');
		self::assertSame(strlen($bytes), strlen($out), 'and the file is the same length, so nothing moved');

		// The profile's slot becomes padding, so `pixi` keeps index 3 and nothing renumbers.
		$ipco = $round->getBox('meta')?->getChild('iprp')?->getChild('ipco');
		self::assertSame(['ispe', 'free', 'pixi'], array_map(fn ($p) => $p->getType(), $ipco?->getChildren() ?? []));
		$ipma = $round->getBox('meta')?->getChild('iprp')?->getChild('ipma');
		self::assertSame(
			bin2hex(chr(0) . "\x00\x00\x00" . pack('N', 1) . pack('n', 1) . chr(2) . chr(0x81) . chr(3)),
			bin2hex((string) $ipma?->getPayload()),
			'only the association naming the profile is gone',
		);
	}

	public function testRemovingAProfileThatIsNotThereChangesNothing(): void
	{
		$bytes = $this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))]);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile(null);
		self::assertSame($bytes, $bmff->toBinary());
	}

	public function testWideAssociationFormsAreReadAndWritten(): void
	{
		// version 1 widens the item id to 32 bits, flags bit 0 widens the index to 15.
		$bytes = $this->heifWithProperties(
			[$this->box('ispe', str_repeat("\0", 12))],
			$this->ipma(1, [[true, 1]], 1, 1),
		);
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile('profile');
		$ipma = BMFFFile::fromString($bmff->toBinary())->getBox('meta')?->getChild('iprp')?->getChild('ipma');
		self::assertSame(
			bin2hex(chr(1) . "\x00\x00\x01" . pack('N', 1) . pack('N', 1) . chr(2) . pack('n', 0x8001) . pack('n', 2)),
			bin2hex((string) $ipma?->getPayload()),
		);
	}

	public function testAProfileIsRefusedWhenTheFileIsNotAStill(): void
	{
		// A movie's stco and fragment offsets address the file too, and are not rewritten.
		// The properties are present, so the refusal really is about the movie box.
		$bytes = $this->ftyp('isom', 'isom')
			. $this->meta(
				$this->iinf($this->infe(1, 'hvc1')),
				$this->iloc([[1, 200, 0, 5]]),
				$this->fullBox('pitm', pack('n', 1)),
				$this->box('iprp', $this->box('ipco', $this->box('ispe', str_repeat("\0", 12))) . $this->ipma(1, [[false, 1]])),
			)
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8))) . $this->box('mdat', 'media');
		$bmff = BMFFFile::fromString($bytes);
		self::assertNotNull($bmff->getBox('meta')?->getChild('iprp'), 'the properties are there to write into');

		try {
			$bmff->setICCProfile('profile');
			self::fail('a movie must be refused');
		} catch (\RuntimeException $e) {
		}
		self::assertSame($bytes, $bmff->toBinary(), 'the refused edit is undone');
	}

	public function testAProfileIsRefusedWithNoMetaBoxAtAll(): void
	{
		$bmff = BMFFFile::fromString($this->ftyp());
		$this->expectException(\RuntimeException::class);
		$bmff->setICCProfile('profile');
	}

	public function testAProfileIsRefusedWithNoPrimaryItem(): void
	{
		// A meta box with properties but nothing to associate the profile with.
		$bytes = $this->ftyp() . $this->meta($this->box('iprp', $this->box('ipco', '')));
		$bmff = BMFFFile::fromString($bytes);
		try {
			$bmff->setICCProfile('profile');
			self::fail('a file with no primary item must be refused');
		} catch (\RuntimeException $e) {
		}
		self::assertSame($bytes, $bmff->toBinary(), 'the refused edit is undone, not half-applied');
	}

	public function testThePropertyTreeIsBuiltWhenTheFileHasNone(): void
	{
		// A still with a primary item but no iprp at all: both it and ipco are created.
		$data = 'item bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $base) => $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		$bmff->setICCProfile('a profile');
		$round = BMFFFile::fromString($bmff->toBinary());

		self::assertSame('a profile', $round->getICCProfile());
		self::assertSame($data, $round->getItemData($round->getItems()[0]), 'the item still resolves');
		$iprp = $round->getBox('meta')?->getChild('iprp');
		self::assertNotNull($iprp?->getChild('ipco'));
		self::assertNotNull($iprp?->getChild('ipma'), 'the association box is created too');
	}

	public function testAnItemInIdatIsNotShifted(): void
	{
		// An idat item's offset is relative to that box, so a resize must leave it alone.
		$ftyp = $this->ftyp();
		$build = fn () => $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->box('idat', 'XXhello'),
			$this->fullBox('iloc', chr(0x44) . chr(0x40) . pack('n', 1)
				. pack('n', 1) . pack('n', 1) . pack('n', 0) . pack('N', 0) . pack('n', 1) . pack('N', 2) . pack('N', 5), 1),
			$this->fullBox('pitm', pack('n', 1)),
			$this->box('iprp', $this->box('ipco', '') . $this->ipma(1, [])),
		);
		$bmff = BMFFFile::fromString($ftyp . $build());
		$bmff->setICCProfile('a profile');
		$round = BMFFFile::fromString($bmff->toBinary());

		self::assertSame('a profile', $round->getICCProfile());
		self::assertSame('hello', $round->getItemData($round->getItems()[0]), 'the idat item is untouched');
	}

	public function testAShiftThatWouldNotFitIsRefused(): void
	{
		// A one-byte base offset cannot hold the address after a 200-byte profile is added.
		$ftyp = $this->ftyp();
		$build = fn () => $this->meta(
			$this->iinf($this->infe(1, 'hvc1')),
			$this->fullBox('iloc', chr(0x44) . chr(0x10) . pack('n', 1)
				. pack('n', 1) . pack('n', 0) . chr(250) . pack('n', 1) . pack('N', 0) . pack('N', 4)),
			$this->fullBox('pitm', pack('n', 1)),
			$this->box('iprp', $this->box('ipco', '') . $this->ipma(1, [])),
		);
		$bytes = $ftyp . $build() . $this->box('mdat', 'data');
		$bmff = BMFFFile::fromString($bytes);
		try {
			$bmff->setICCProfile(str_repeat('P', 200));
			self::fail('a location that cannot record the move must be refused');
		} catch (\RuntimeException $e) {
		}
		self::assertSame($bytes, $bmff->toBinary(), 'the refused edit is undone');
	}

	public function testAMultiExtentItemCannotBeShifted(): void
	{
		$item = new BMFFItem(1, 'hvc1');
		$item->setLocation(BMFFItem::FileConstruction, 0, 4, 4, 100, 10, 8, 4, 12, 4, false);
		self::assertFalse($item->canShift(8), 'a split item has no single location to move');
	}

	public function testAssociationsForOtherItemsAreLeftAlone(): void
	{
		$properties = [$this->box('ispe', str_repeat("\0", 12))];
		$ipma = $this->box('ipma', chr(0) . "\x00\x00\x00" . pack('N', 2)
			. pack('n', 7) . chr(1) . chr(1)          // a different item comes first
			. pack('n', 1) . chr(1) . chr(1));
		$bmff = BMFFFile::fromString($this->heifWithProperties($properties, $ipma));
		$bmff->setICCProfile('p');

		[, , $entries] = (function (BMFFFile $b) {
			$read = new ReflectionMethod($b, 'readAssociations');
			$read->setAccessible(true);
			$ipma = $b->getBox('meta')?->getChild('iprp')?->getChild('ipma');
			return $read->invoke($b, (string) $ipma?->getPayload());
		})(BMFFFile::fromString($bmff->toBinary()));

		self::assertSame(7, $entries[0][0]);
		self::assertCount(1, $entries[0][1], 'the other item gains nothing');
		self::assertCount(2, $entries[1][1], 'the primary item gains the colour property');
	}

	public function testAnItemWithNoAssociationEntryGainsOne(): void
	{
		// An ipma that names a different item entirely: the primary item needs a new entry.
		$ipma = $this->box('ipma', chr(0) . "\x00\x00\x00" . pack('N', 1) . pack('n', 9) . chr(1) . chr(1));
		$bmff = BMFFFile::fromString($this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))], $ipma));
		$bmff->setICCProfile('p');
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertSame('p', $round->getICCProfile());
		self::assertStringContainsString(pack('n', 1) . chr(1) . chr(2), (string) $round->getBox('meta')?->getChild('iprp')?->getChild('ipma')?->getPayload());
	}

	public function testAMalformedAssociationBoxReadsAsEmpty(): void
	{
		$bmff = BMFFFile::fromString($this->heifWithProperties([$this->box('ispe', str_repeat("\0", 12))], $this->box('ipma', 'tiny')));
		$bmff->setICCProfile('p');
		self::assertSame('p', BMFFFile::fromString($bmff->toBinary())->getICCProfile());
	}

	public function testAnIndexTooWideForTheShortFormIsRefused(): void
	{
		// 127 properties already, so the new colour property would be index 128.
		$properties = array_fill(0, 127, $this->box('ispe', str_repeat("\0", 12)));
		$bmff = BMFFFile::fromString($this->heifWithProperties($properties, $this->ipma(1, [[false, 1]])));
		$this->expectException(\RuntimeException::class);
		$bmff->setICCProfile('p');
	}

	//
	// ─── Boxes, privacy, streaming ───────────────────────────────────────────
	//

	public function testBoxAccess(): void
	{
		$bmff = BMFFFile::fromString($this->movie());
		self::assertSame(['ftyp', 'mdat', 'moov'], array_map(fn ($b) => $b->getType(), $bmff->getBoxes()));
		self::assertNotNull($bmff->getBox('moov'));
		self::assertNull($bmff->getBox('trak'), 'getBox looks only at the top level');

		$bmff->setBoxes([new BMFFFileBox('ftyp', 'isom' . pack('N', 0) . 'isom')]);
		self::assertCount(1, $bmff->getBoxes());
		self::assertSame([], $bmff->getItems(), 'replacing the boxes forgets the items');
	}

	public function testScrubbingRemovesIdentifyingUserData(): void
	{
		$bmff = BMFFFile::fromString($this->movieWithUserData(
			$this->atom(BMFFFile::KeyArtist, 'A Director')
			. $this->atom(BMFFFile::KeyLocation, '+51.5074-000.1278/')
			. $this->atom(BMFFFile::KeyDate, '2026-09-21')
			. $this->atom(BMFFFile::KeyEncoder, 'Some Encoder 1.0')
			. $this->atom(BMFFFile::KeyTitle, 'A Clip'),
		));

		self::assertSame(1, $bmff->clearPrivateData(PrivacyCategory::Location));
		self::assertNull($bmff->getUserDataValue(BMFFFile::KeyLocation), 'the GPS atom is the location');
		self::assertSame('A Director', $bmff->getUserDataValue(BMFFFile::KeyArtist), 'another category is untouched');

		self::assertSame(1, $bmff->clearPrivateData(PrivacyCategory::Author));
		self::assertNull($bmff->getUserDataValue(BMFFFile::KeyArtist));
		self::assertSame('2026-09-21', $bmff->getUserDataValue(BMFFFile::KeyDate));

		self::assertGreaterThan(0, $bmff->clearPrivateData());
		self::assertSame([], $bmff->getUserDataAtoms());
		self::assertSame(0, $bmff->clearPrivateData(), 'a scrub is idempotent');
	}

	public function testScrubbingReachesTheCarriers(): void
	{
		$bmff = BMFFFile::fromString($this->heif());
		$bmff->setEXIF($this->exif());
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'creator', 'A Director');
		$bmff->setXMP($xmp);

		self::assertGreaterThan(0, $bmff->clearPrivateData());
		self::assertNull($bmff->getEXIF()?->getValueByName('Artist'));
		self::assertNull($bmff->getXMP()?->getProperty(XMP::NS_DC, 'creator'));
	}

	public function testStreamingOut(): void
	{
		$bytes = $this->heif();
		$target = fopen('php://temp', 'r+b');
		$written = BMFFFile::fromString($bytes)->streamTo($target);
		rewind($target);
		self::assertSame($bytes, (string) stream_get_contents($target));
		self::assertSame(strlen($bytes), $written);
		fclose($target);
	}

	//
	// ─── Table forms other writers emit ──────────────────────────────────────
	//
	// The default fixtures are shaped the way libheif writes a HEIC: version 0 tables with
	// the item address in the `iloc` base offset.  The format allows more than that, and the
	// readers and writers here claim to handle it, so these prove the claim.

	/**
	 * A still whose `iloc` has **no base offset field**, so the item address rides the extent
	 * offset instead — the layout writers other than libheif produce.
	 */
	private function heifExtentAddressed(): string
	{
		$data = 'picture bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $offset): string => $this->meta(
			$this->iinf($this->infe(1, 'hvc1', 'image')),
			// offset_size 4, length_size 4, base_offset_size 0.
			$this->fullBox('iloc', chr(0x44) . chr(0x00) . pack('n', 1)
				. pack('n', 1) . pack('n', 0) . pack('n', 1) . pack('N', $offset) . pack('N', strlen($data))),
			$this->fullBox('pitm', pack('n', 1)),
		);
		return $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);
	}

	/**
	 * A still using the wide table forms: a version 2 `iloc` (32-bit item ids, a construction
	 * method and an extent index), a version 1 `iinf` (32-bit entry count), a version 1 `pitm`
	 * (32-bit primary id), and a version 1 `iref` (32-bit reference ids).
	 */
	private function heifWideTables(): string
	{
		$data = 'picture bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $base): string => $this->meta(
			$this->fullBox('iinf', pack('N', 1) . $this->infe(1, 'hvc1', 'image'), 1),
			// version 2: offset_size 4, length_size 4, base_offset_size 4, index_size 0.
			$this->fullBox('iloc', chr(0x44) . chr(0x40) . pack('N', 1)
				. pack('N', 1) . pack('n', 0) . pack('n', 0) . pack('N', $base)
				. pack('n', 1) . pack('N', 0) . pack('N', strlen($data)), 2),
			$this->fullBox('pitm', pack('N', 1), 1),
			$this->box('iref', chr(1) . "\x00\x00\x00"),
		);
		return $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);
	}

	/** The picture item, whatever position it holds once other items are added. */
	private function pictureItem(BMFFFile $bmff): BMFFItem
	{
		foreach ($bmff->getItems() as $item) {
			if ($item->getType() === 'hvc1') {
				return $item;
			}
		}
		self::fail('the fixture has no picture item');
	}

	public function testAnItemAddressedByItsExtentOffsetShiftsCorrectly(): void
	{
		$bmff = BMFFFile::fromString($this->heifExtentAddressed());
		self::assertSame('picture bytes', $bmff->getItemData($this->pictureItem($bmff)));

		// Creating an item grows `meta`, so every location must move by exactly that much —
		// here through the extent offset, since the table has no base offset to carry it.
		$bmff->setEXIF($this->exif());
		$round = BMFFFile::fromString($bmff->toBinary());
		self::assertSame('picture bytes', $round->getItemData($this->pictureItem($round)), 'the picture still reads');
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
	}

	public function testTheWideTableFormsAreReadAndAppendedTo(): void
	{
		$bmff = BMFFFile::fromString($this->heifWideTables());
		self::assertSame('picture bytes', $bmff->getItemData($this->pictureItem($bmff)));

		$bmff->setEXIF($this->exif());
		$bmff->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
		$round = BMFFFile::fromString($bmff->toBinary());

		self::assertSame('picture bytes', $round->getItemData($this->pictureItem($round)), 'the picture still reads');
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertStringContainsString('xmpmeta', (string) $round->getXmpText());

		// The appended entries keep the table in the version and widths it already used.
		$iloc = (string) $round->getBox(BMFFFile::MetaBox)?->getChild(BMFFFile::ItemLocationBox)?->getPayload();
		self::assertSame(2, ord($iloc[0]), 'the `iloc` stays version 2');
		self::assertSame(3, (int) unpack('N', substr($iloc, 6, 4))[1], 'three located items');
		$iinf = (string) $round->getBox(BMFFFile::MetaBox)?->getChild(BMFFFile::ItemInfoBox)?->getPayload();
		self::assertSame(1, ord($iinf[0]), 'the `iinf` stays version 1');
		self::assertSame(3, (int) unpack('N', substr($iinf, 4, 4))[1], 'three described items');
	}

	public function testAWideIrefGetsWideReferences(): void
	{
		$bmff = BMFFFile::fromString($this->heifWideTables());
		$bmff->setEXIF($this->exif());
		$round = BMFFFile::fromString($bmff->toBinary());

		$iref = $round->getBox(BMFFFile::MetaBox)?->getChild(BMFFFile::ItemReferenceBox);
		self::assertNotNull($iref);
		$cdsc = $iref->getChildren()[0] ?? null;
		self::assertNotNull($cdsc, 'the new item says which picture it describes');
		// Version 1 ids are 32-bit: from item 2, one reference, to item 1.
		self::assertSame(
			bin2hex(pack('N', 2) . pack('n', 1) . pack('N', 1)),
			bin2hex((string) $cdsc->getPayload()),
		);
	}

	public function testTheSmallestSufficientFreeRunTakesTheBox(): void
	{
		// Two runs can hold the 60-byte box: 208 bytes with 148 to spare, and 68 with 8.
		// Best fit takes the tighter one and leaves the larger run whole for a larger write.
		$bytes = $this->ftyp('isom', 'isom', 'mp41')
			. $this->box('mdat', str_repeat("\x11", 32))
			. $this->box('free', str_repeat("\0", 200))
			. $this->box('free', str_repeat("\0", 60))
			. $this->box('moov', $this->box('mvhd', str_repeat("\0", 8)));
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setXmpText(str_repeat('x', 36));
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the padding paid for it');
		self::assertSame(
			['ftyp', 'mdat', 'free', 'uuid', 'free', 'moov'],
			array_map(fn ($b) => $b->getType(), BMFFFile::fromString($out)->getBoxes()),
			'the second, tighter run took the box and the larger one is untouched',
		);
		self::assertSame(208, strlen(BMFFFile::fromString($out)->getBoxes()[2]->toBinary()), 'the larger run is whole');
		self::assertSame(str_repeat('x', 36), BMFFFile::fromString($out)->getXmpText());
	}

	public function testAUserDataValueOfTheSameLengthResizesNothing(): void
	{
		$bytes = $this->movieWithUserData($this->atom(BMFFFile::KeyTitle, 'AAAA'));
		$bmff = BMFFFile::fromString($bytes);
		$bmff->setUserDataValue(BMFFFile::KeyTitle, 'BBBB');
		$out = $bmff->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'a same-length value changes no box length');
		self::assertSame(strpos($bytes, 'mdat'), strpos($out, 'mdat'));
		self::assertSame('BBBB', BMFFFile::fromString($out)->getUserDataValue(BMFFFile::KeyTitle));
	}

	public function testRemovingAProfileThatIsNotTheFirstPropertyKeepsTheRest(): void
	{
		$bytes = $this->heifWithProperties([
			$this->fullBox('ispe', pack('NN', 6, 4)),
			$this->colr(BMFFFile::ColourTypeProfile, 'gone'),
		]);
		$bmff = BMFFFile::fromString($bytes);
		self::assertSame('gone', $bmff->getICCProfile());

		$bmff->setICCProfile(null);
		$out = $bmff->toBinary();
		$round = BMFFFile::fromString($out);

		self::assertNull($round->getICCProfile());
		self::assertSame(strlen($bytes), strlen($out), 'padding replaced the profile, so nothing moved');
		self::assertSame('item bytes', $round->getItemData($round->getItems()[0]), 'the picture still reads');
		// The property that came first keeps its index, so no association had to be renumbered.
		$ipco = $round->getBox(BMFFFile::MetaBox)?->getChild('iprp')?->getChild('ipco');
		self::assertSame(['ispe', 'free'], array_map(fn ($p) => $p->getType(), $ipco?->getChildren() ?? []));
	}

	public function testAnItemInfoEntryWithNoPayloadReadsAsAbsent(): void
	{
		$data = 'picture bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $base): string => $this->meta(
			$this->iinf($this->infe(1, 'hvc1', 'image'), $this->box('infe', '')),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$items = BMFFFile::fromString($bytes)->getItems();
		self::assertCount(1, $items, 'the empty entry is skipped, the real one is not');
		self::assertSame('hvc1', $items[0]->getType());
	}

	public function testAnItemAddedToATruncatedItemInfoTableGetsAValidOne(): void
	{
		// An `iinf` too short to hold its own version and entry count has no count to bump;
		// the new table states the one entry it now describes rather than staying broken.
		$data = 'picture bytes';
		$ftyp = $this->ftyp();
		$build = fn (int $base): string => $this->meta(
			$this->box('iinf', ''),
			$this->iloc([[1, $base, 0, strlen($data)]]),
			$this->fullBox('pitm', pack('n', 1)),
		);
		$bytes = $ftyp . $build(strlen($ftyp) + strlen($build(0)) + 8) . $this->box('mdat', $data);

		$bmff = BMFFFile::fromString($bytes);
		self::assertSame([], $bmff->getItems(), 'nothing is described yet');

		$bmff->setEXIF($this->exif());
		$round = BMFFFile::fromString($bmff->toBinary());

		$iinf = (string) $round->getBox(BMFFFile::MetaBox)?->getChild(BMFFFile::ItemInfoBox)?->getPayload();
		self::assertSame(0, ord($iinf[0]), 'a fresh version 0 table');
		self::assertSame(1, (int) unpack('n', substr($iinf, 4, 2))[1], 'naming its one entry');
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
	}
}
