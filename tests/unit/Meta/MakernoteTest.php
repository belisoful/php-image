<?php

use Belisoful\Image\Meta\Makernote\CanonMakernote;
use Belisoful\Image\Meta\Makernote\KonicaMinoltaMakernote;
use Belisoful\Image\Meta\Makernote\Makernote;
use Belisoful\Image\Meta\Makernote\MakernoteTags;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\TIFF\TIFFIfd;

class MakernoteTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Packs an IFD: entries as [tag, type, count, valueFieldBytes(4)], big/little endian.
	 * @param array $entries
	 * @param bool $bigEndian
	 * @param bool $nextPointer
	 */
	private function packIfd(array $entries, bool $bigEndian, bool $nextPointer = true): string
	{
		$n = $bigEndian ? 'n' : 'v';
		$out = pack($n, count($entries));
		foreach ($entries as [$tag, $type, $count, $field]) {
			$out .= pack($n, $tag) . pack($n, $type) . pack($bigEndian ? 'N' : 'V', $count) . str_pad($field, 4, "\0");
		}
		return $nextPointer ? $out . "\0\0\0\0" : $out;
	}

	/**
	 * Builds an EXIF whose makernote (with absolute in-TIFF offsets) survives via the
	 * two-pass pin: compose with a placeholder, find the note's offset, rebuild the note
	 * against that offset, recompose pinned.
	 * @param string $make
	 * @param callable $noteBuilder
	 */
	private function exifWithNote(string $make, callable $noteBuilder): EXIF
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', $make);
		$placeholder = $noteBuilder(0);
		$exif->getExifIfd(true)->setTagValues(EXIF::MakerNoteTag, TIFFDataType::Undefined, $placeholder);

		$first = EXIF::fromSegment($exif->toBinary());
		$offset = $first->getExifIfd()->getTag(EXIF::MakerNoteTag)->getOffset();
		$note = $noteBuilder($offset);
		self::assertSame(strlen($placeholder), strlen($note), 'note builder must be length-stable');
		$first->getExifIfd()->getTag(EXIF::MakerNoteTag)->setValues($note);

		return EXIF::fromSegment($first->toBinary());
	}

	public function testCanonSettingsDecode()
	{
		$exif = $this->exifWithNote('Canon', function (int $base) {
			// Camera Settings 1: size word + 5 shorts (macro=1, timer=30, quality=3, flash=4)
			$settings = [12, 1, 30, 3, 4, 0];
			$dataPos = $base + 2 + 12 + 4;   // count + one entry + next pointer
			$ifd = $this->packIfd(
				[[CanonMakernote::CameraSettings1Tag, TIFFDataType::UShort, count($settings), pack('N', $dataPos)]],
				true,
			);
			return $ifd . pack('n*', ...$settings);
		});

		$note = $exif->getMakernote();
		self::assertInstanceOf(CanonMakernote::class, $note);
		self::assertSame('Canon', $note->getMaker());
		self::assertTrue($note->getIsDecoded());
		$settings = $note->getCameraSettings();
		self::assertSame('Macro', $settings['Macro Mode']);
		self::assertSame('3 seconds', $settings['Self Timer Length']);
		self::assertSame('Fine', $settings['Quality']);
		self::assertSame('Slow Synchro', $settings['Flash Mode']);
	}

	public function testSonyInlineNote()
	{
		$exif = $this->exifWithNote('Sony', function (int $base) {
			// Signature + IFD at 12 with one inline short, no next-IFD pointer.
			return "SONY DSC \x00\x00\x00" . $this->packIfd(
				[[0x9001, TIFFDataType::UShort, 1, pack('n', 7)]],
				true,
				false,
			);
		});
		$note = $exif->getMakernote();
		self::assertNotNull($note);
		self::assertSame('Sony', $note->getMaker());
		self::assertSame(7, $note->getIfd()->getTagValue(0x9001));
	}

	public function testFujifilmRelativeOffsetsAndNikonFallback()
	{
		// A Fujifilm-signed note on a Nikon body (Coolpix 775): II, note-relative offsets.
		$exif = $this->exifWithNote('NIKON', function (int $base) {
			$version = "0130";
			$ifd = $this->packIfd(
				[[0x0000, TIFFDataType::Undefined, 4, $version]],
				false,
			);
			return 'FUJIFILM' . pack('V', 12) . $ifd;
		});
		$note = $exif->getMakernote();
		self::assertNotNull($note);
		self::assertSame('Fujifilm', $note->getMaker());
		self::assertSame('0130', $note->getIfd()->getTag(0x0000)->getValues());
	}

	public function testNikonType3EmbeddedTiff()
	{
		$exif = $this->exifWithNote('NIKON CORPORATION', function (int $base) {
			$inner = new TIFFDocument();
			$inner->setIsBigEndian(false);
			$ifd = new TIFFIfd();
			$ifd->setTagValues(0x0001, TIFFDataType::Undefined, "0210");
			$ifd->setTagValues(0x0002, TIFFDataType::UShort, [0, 400]);   // ISO
			$inner->addIfd($ifd);
			return "Nikon\x00\x02\x10\x00\x00" . $inner->toBinary();
		});
		$note = $exif->getMakernote();
		self::assertNotNull($note);
		self::assertSame('Nikon Type 3', $note->getVariant());
		self::assertSame([0, 400], $note->getIfd()->getTag(0x0002)->getValues());
		self::assertArrayHasKey('ISO Speed Used', $note->getValues());
	}

	public function testRicohTextNote()
	{
		$note = Makernote::fromNote('Rv1.00;Rg1.0;', 'RICOH DC-3Z');
		self::assertNotNull($note);
		self::assertSame('Ricoh', $note->getMaker());
		self::assertTrue($note->getIsDecoded());
		self::assertSame('Rv1.00;Rg1.0;', $note->getText());
		self::assertNull($note->getIfd());
	}

	public function testMinoltaUndecodableRecognized()
	{
		$note = Makernote::fromNote("MLY0123456789", 'Minolta DiMAGE');
		self::assertNotNull($note);
		self::assertSame('Konica Minolta', $note->getMaker());
		self::assertInstanceOf(KonicaMinoltaMakernote::class, $note);
		self::assertFalse($note->getIsDecoded());
	}

	public function testMinoltaCameraSettingsDecode()
	{
		$exif = $this->exifWithNote('MINOLTA CO.,LTD', function (int $base) {
			$settings = array_fill(0, 8, 0);
			$settings[2] = 1;   // Exposure Mode: A
			$settings[4] = 3;   // White Balance: Tungsten
			$dataPos = $base + 2 + 12 + 4;
			$ifd = $this->packIfd(
				[[KonicaMinoltaMakernote::CameraSettingsTag, TIFFDataType::ULong, count($settings), pack('N', $dataPos)]],
				true,
			);
			return $ifd . pack('N*', ...$settings);
		});
		$note = $exif->getMakernote();
		self::assertInstanceOf(KonicaMinoltaMakernote::class, $note);
		$settings = $note->getCameraSettings();
		self::assertSame('A', $settings['Exposure Mode']);
		self::assertSame('Tungsten', $settings['White Balance']);
	}

	public function testCasioThumbnailExtraction()
	{
		$jpeg = "\x00\xD8\xFF\xE0" . str_repeat("\x11", 12);   // corrupt SOI first byte: gets repaired
		$fixed = "\xFF" . substr($jpeg, 1);
		$exif = $this->exifWithNote('CASIO COMPUTER CO.,LTD.', function (int $base) use ($jpeg) {
			$dataPos = $base + 6 + 2 + 12 + 4;
			$ifd = $this->packIfd(
				[[0x2000, TIFFDataType::Undefined, strlen($jpeg), pack('N', $dataPos)]],
				true,
			);
			return "QVC\x00\x00\x00" . $ifd . $jpeg;
		});
		$note = $exif->getMakernote();
		self::assertNotNull($note);
		self::assertSame('Casio Type 2', $note->getVariant());
		self::assertSame($fixed, $note->getThumbnail());
	}

	public function testCasioThumbnailStoredAsByteValues()
	{
		// The same preview written with the byte type rather than as opaque bytes: the
		// values arrive as an integer array and still assemble into the JPEG.
		$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x22", 12);
		$dataPos = 6 + 2 + 12 + 4;   // header + count + one entry + next pointer
		$ifd = $this->packIfd(
			[[0x0004, TIFFDataType::UByte, strlen($jpeg), pack('N', $dataPos)]],
			true,
		);
		$note = "QVC\x00\x00\x00" . $ifd . $jpeg;

		$parsed = Makernote::fromNote($note, 'CASIO COMPUTER CO.,LTD.', $note, 0, true);
		self::assertSame('Casio Type 2', $parsed->getVariant());
		self::assertIsArray($parsed->getIfd()->getTag(0x0004)->getValues());
		self::assertSame($jpeg, $parsed->getThumbnail());
	}

	public function testUnrecognizedMakeReturnsNull()
	{
		self::assertNull(Makernote::fromNote('whatever bytes', 'Unknown Camera Co'));
	}

	public function testFromExifWithoutAMakernote()
	{
		// No EXIF sub-IFD at all, and an EXIF sub-IFD without the makernote tag.
		self::assertNull(Makernote::fromExif(new EXIF()));

		$exif = new EXIF();
		$exif->getExifIfd(true)->setTagValues(34855, TIFFDataType::UShort, [200]);
		self::assertNull(Makernote::fromExif($exif));
	}

	public function testNikonType3WithABrokenEmbeddedTiff()
	{
		// The Nikon Type 3 signature promises a TIFF header that is not there: the note
		// stays recognized and reports the failure instead of throwing.
		$note = Makernote::fromNote("Nikon\x00\x02\x10\x00\x00" . 'GARBAGE-NOT-A-TIFF', 'NIKON CORPORATION');
		self::assertNotNull($note);
		self::assertSame('Nikon Type 3', $note->getVariant());
		self::assertFalse($note->getIsDecoded());
		self::assertNull($note->getIfd());
		self::assertCount(1, $note->getWarnings());
		self::assertStringContainsString('embedded TIFF header did not parse', $note->getWarnings()[0]);
	}

	public function testUndecodedNoteHasNoValuesOrThumbnail()
	{
		// The Ricoh plain-text form has no IFD, so neither the tag map nor the
		// thumbnail lookup has anything to work with.
		$text = Makernote::fromNote('Rv1.00;Rg1.0;', 'RICOH DC-3Z');
		self::assertSame([], $text->getValues());
		self::assertNull($text->getThumbnail());

		// A decoded maker that defines no thumbnail tags at all reports none either.
		$ifd = $this->packIfd([[0x0001, TIFFDataType::UShort, 1, pack('n', 2)]], true, false);
		$panasonic = Makernote::fromNote("Panasonic\x00\x00\x00" . $ifd, 'Panasonic DMC');
		self::assertNotNull($panasonic->getIfd());
		self::assertNull($panasonic->getThumbnail());
	}

	public function testThumbnailTagTooShortToBeAJpeg()
	{
		// Casio carries its preview in tag 0x0004 or 0x2000: a two-byte value is not a
		// JPEG and no repair is attempted on it.
		$note = "QVC\x00\x00\x00" . $this->packIfd(
			[[0x0004, TIFFDataType::Undefined, 2, "\x01\x02"]],
			true,
		);
		$parsed = Makernote::fromNote($note, 'CASIO COMPUTER CO.,LTD.', $note, 0, true);
		self::assertSame('Casio Type 2', $parsed->getVariant());
		self::assertSame("\x01\x02", $parsed->getIfd()->getTag(0x0004)->getValues());
		self::assertNull($parsed->getThumbnail());
	}

	public function testThumbnailTagHoldingSomethingOtherThanAJpeg()
	{
		// Casio's first preview tag holds four bytes that are no JPEG and cannot be
		// repaired into one, so the search moves on to the second tag.
		$jpeg = "\xFF\xD8\xFF\xE1" . str_repeat("\x33", 12);
		$notJpeg = "\x89PNG";
		$entries = 2;
		$dataPos = 6 + 2 + $entries * 12 + 4;
		$ifd = $this->packIfd([
			[0x0004, TIFFDataType::Undefined, strlen($notJpeg), $notJpeg],
			[0x2000, TIFFDataType::Undefined, strlen($jpeg), pack('N', $dataPos)],
		], true);
		$note = "QVC\x00\x00\x00" . $ifd . $jpeg;

		$parsed = Makernote::fromNote($note, 'CASIO COMPUTER CO.,LTD.', $note, 0, true);
		self::assertSame($notJpeg, $parsed->getIfd()->getTag(0x0004)->getValues());
		self::assertSame($jpeg, $parsed->getThumbnail());
	}

	public function testTagKnowledge()
	{
		self::assertSame(13, count(MakernoteTags::Headers));
		self::assertSame(13, count(MakernoteTags::Definitions));
		self::assertSame('Casio Preview Thumbnail', MakernoteTags::nameOf('Casio Type 2', 0x2000));
		self::assertNotNull(MakernoteTags::Definitions['Canon'] ?? null);
		self::assertSame(['Nikon Type 3', 2], MakernoteTags::findByName('ISO Speed Used', 'Nikon Type 3'));
		// The makernote knowledge base still resolves the base EXIF groups' API shape.
		self::assertNull(MakernoteTags::findByName('NoSuchTag'));
	}
}
