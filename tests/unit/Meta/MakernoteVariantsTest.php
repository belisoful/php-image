<?php

use Belisoful\Image\Meta\Makernote\CanonMakernote;
use Belisoful\Image\Meta\Makernote\KonicaMinoltaMakernote;
use Belisoful\Image\Meta\Makernote\Makernote;
use Belisoful\Image\TIFF\TIFFDataType;

/**
 * Exercises every maker variant the registry knows: header signatures, forced byte
 * orders, note-relative offsets, missing next-IFD pointers, and the nested sub-IFD.
 */
class MakernoteVariantsTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Packs an IFD: entries as [tag, type, count, valueFieldBytes(4)].
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

	public function testOlympusEpsonAgfaSharedGroup()
	{
		// Same tag group through three different vendor headers; inline-only IFDs.
		$ifd = $this->packIfd([[0x0204, TIFFDataType::URational, 1, '']], true);   // needs offset: use inline short instead
		$ifd = $this->packIfd([[0x0201, TIFFDataType::UShort, 1, pack('n', 2)]], true);   // Quality
		$cases = [
			['OLYMPUS OPTICAL CO.,LTD', "OLYMP\x00\x01\x00" . $ifd, 'Olympus', 'Olympus'],
			['SEIKO EPSON CORP.', "EPSON\x00\x01\x00" . $ifd, 'Epson', 'Olympus'],
			['AGFA', "AGFA \x00\x01\x00" . $ifd, 'Agfa', 'Olympus'],
		];
		foreach ($cases as [$make, $note, $maker, $group]) {
			$parsed = Makernote::fromNote($note, $make);
			self::assertNotNull($parsed, $maker);
			self::assertSame($maker, $parsed->getMaker());
			self::assertSame($group, $parsed->getTagGroup());
			self::assertTrue($parsed->getIsDecoded());
			self::assertSame(2, $parsed->getIfd()->getTagValue(0x0201));
			self::assertNotNull($parsed->getTagText(0x0201));
		}
	}

	public function testKyoceraLocalOffsetsNoNextPointer()
	{
		// Kyocera: 22-byte header, IFD with note-relative value offsets, no next pointer.
		$header = 'KYOCERA            ' . "\x00\x00\x00";
		$text = "SerialXYZ\0";
		$ifdStart = 22;
		$dataPos = $ifdStart + 2 + 12;   // note-relative: count + one entry, no next pointer
		$ifd = $this->packIfd(
			[[0x0001, TIFFDataType::Ascii, strlen($text), pack('N', $dataPos)]],
			true,
			false,
		);
		$parsed = Makernote::fromNote($header . $ifd . $text, 'KYOCERA');
		self::assertNotNull($parsed);
		self::assertSame('Kyocera', $parsed->getMaker());
		self::assertSame('SerialXYZ', $parsed->getIfd()->getTag(0x0001)->getValue());

		self::assertNotNull(Makernote::fromNote($header . $ifd . $text, 'CONTAX'));
	}

	public function testPentaxBothTypes()
	{
		$ifd = $this->packIfd([[0x0008, TIFFDataType::UShort, 1, pack('n', 1)]], true);
		$type2 = Makernote::fromNote("AOC\x00\x4D\x4D" . $ifd, 'PENTAX Corporation');
		self::assertSame('Pentax Type 2', $type2->getVariant());
		self::assertSame('Casio Type 2', $type2->getTagGroup());
		self::assertSame(1, $type2->getIfd()->getTagValue(0x0008));

		$type1 = Makernote::fromNote($ifd, 'Asahi Optical');
		self::assertSame('Pentax Type 1', $type1->getVariant());
		self::assertSame('Pentax', $type1->getTagGroup());
	}

	public function testPanasonicWithAndWithoutIfd()
	{
		$ifd = $this->packIfd([[0x0001, TIFFDataType::UShort, 1, pack('n', 2)]], true, false);
		$parsed = Makernote::fromNote("Panasonic\x00\x00\x00" . $ifd, 'Panasonic DMC');
		self::assertSame('Panasonic', $parsed->getMaker());
		self::assertSame(2, $parsed->getIfd()->getTagValue(0x0001));
		self::assertSame('2', $parsed->getTagText(0x0001));   // Quality Mode is Numeric

		$empty = Makernote::fromNote('MKED', 'Panasonic DMC');
		self::assertNotNull($empty);
		self::assertSame('Panasonic Empty Makernote', $empty->getVariant());
		self::assertFalse($empty->getIsDecoded());
	}

	public function testNikonType1()
	{
		$ifd = $this->packIfd([[0x0003, TIFFDataType::UShort, 1, pack('n', 1)]], true);
		$parsed = Makernote::fromNote("Nikon\x00\x01\x00" . $ifd, 'NIKON');
		self::assertSame('Nikon Type 1', $parsed->getVariant());
		self::assertSame('VGA (640x480) Basic', $parsed->getTagText(0x0003));
	}

	public function testCasioType1HeaderlessForcedBigEndian()
	{
		// Casio Type 1: no header, forced MM even inside a little-endian EXIF.
		$ifd = $this->packIfd([[0x0001, TIFFDataType::UShort, 1, pack('n', 1)]], true);
		$parsed = Makernote::fromNote($ifd, 'CASIO', '', 0, false);
		self::assertSame('Casio Type 1', $parsed->getVariant());
		self::assertSame('Single Shutter', $parsed->getTagText(0x0001));
	}

	public function testRicohIfdFormWithNestedCameraInfo()
	{
		// Ricoh: 'Ricoh' header, MM, IFD at 8; tag 0x2001 points at a signed camera-info
		// block holding its own IFD with local offsets and no next pointer.
		$camInfo = '[Ricoh Camera Info]' . "\x00"
			. $this->packIfd([[0x0001, TIFFDataType::UShort, 1, pack('n', 7)]], true, false);
		$notePrefix = "Ricoh\x00\x00\x00";
		$ifdStart = 8;
		$blockPos = $ifdStart + 2 + 12 + 4;   // after count + one entry + next pointer
		$ifd = $this->packIfd(
			[[0x2001, TIFFDataType::Undefined, strlen($camInfo), pack('N', $blockPos)]],
			true,
		);
		$note = $notePrefix . $ifd . $camInfo;
		$parsed = Makernote::fromNote($note, 'RICOH', $note, 0, true);
		self::assertNotNull($parsed);
		self::assertSame('Ricoh', $parsed->getVariant());
		self::assertArrayHasKey('RicohSubIFD', $parsed->getSubIfds());
		self::assertSame(7, $parsed->getSubIfds()['RicohSubIFD']->getTagValue(0x0001));
	}

	public function testMinoltaLegacySignaturesRecognizedUndecoded()
	{
		foreach (['KC', '+M+M+M+M', 'MINOL'] as $signature) {
			$parsed = Makernote::fromNote($signature . str_repeat("\x00", 16), 'KONICA MINOLTA');
			self::assertNotNull($parsed, $signature);
			self::assertFalse($parsed->getIsDecoded(), $signature);
			self::assertStringContainsString($signature, $parsed->getVariant());
		}
	}

	public function testMinoltaUndefinedBytesSettingsBranch()
	{
		// The camera-settings block stored as Undefined bytes (big-endian longs).
		$settings = array_fill(0, 6, 0);
		$settings[2] = 3;   // Exposure Mode: M
		$block = pack('N*', ...$settings);
		$ifd = $this->packIfd([[0x0001, TIFFDataType::Undefined, strlen($block), '']], true);
		// Inline impossible (24 bytes): rebuild with offset.
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd([[0x0001, TIFFDataType::Undefined, strlen($block), pack('N', $dataPos)]], true);
		$note = $ifd . $block;
		$parsed = Makernote::fromNote($note, 'MINOLTA', $note, 0, true);
		self::assertInstanceOf(KonicaMinoltaMakernote::class, $parsed);
		self::assertSame('M', $parsed->getCameraSettings()['Exposure Mode']);
	}

	public function testCanonCustomFunctionsDecode()
	{
		// Custom functions: element 0 is the byte count, then (function << 8) | value.
		$values = [8, (0x01 << 8) | 1, (0x02 << 8) | 2, (0x63 << 8) | 5];
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[CanonMakernote::CustomFunctionsTag, TIFFDataType::UShort, count($values), pack('N', $dataPos)]],
			true,
		);
		$note = $ifd . pack('n*', ...$values);
		$parsed = Makernote::fromNote($note, 'Canon', $note, 0, true);
		self::assertInstanceOf(CanonMakernote::class, $parsed);
		$functions = $parsed->getCustomFunctions();
		self::assertSame('On', $functions['Long Exposure Noise Reduction']);
		self::assertArrayHasKey('Custom Function 99', $functions);
		self::assertSame('5', $functions['Custom Function 99']);
	}

	public function testRicohNestedCameraInfoWithoutTheSurroundingTiff()
	{
		// The same note handed over on its own (no surrounding TIFF bytes): the nested
		// block is addressed within the note, so it still decodes.
		$camInfo = '[Ricoh Camera Info]' . "\x00"
			. $this->packIfd([[0x0001, TIFFDataType::UShort, 1, pack('n', 7)]], true, false);
		$blockPos = 8 + 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[0x2001, TIFFDataType::Undefined, strlen($camInfo), pack('N', $blockPos)]],
			true,
		);
		$note = "Ricoh\x00\x00\x00" . $ifd . $camInfo;

		$parsed = Makernote::fromNote($note, 'RICOH');
		self::assertNotNull($parsed);
		self::assertSame('Ricoh', $parsed->getVariant());
		self::assertArrayHasKey('RicohSubIFD', $parsed->getSubIfds());
		self::assertSame(7, $parsed->getSubIfds()['RicohSubIFD']->getTagValue(0x0001));
	}

	public function testRicohCameraInfoBlockWithoutItsSignature()
	{
		// Tag 0x2001 is there and points at a real block, but the block does not carry
		// the '[Ricoh Camera Info]' signature: nothing is decoded from it, and the rest
		// of the note is unaffected.
		$block = 'NOT THE CAMERA INFO' . "\x00"
			. $this->packIfd([[0x0001, TIFFDataType::UShort, 1, pack('n', 7)]], true, false);
		$blockPos = 8 + 2 + 24 + 4;
		$ifd = $this->packIfd([
			[0x0002, TIFFDataType::UShort, 1, pack('n', 5)],
			[0x2001, TIFFDataType::Undefined, strlen($block), pack('N', $blockPos)],
		], true);
		$note = "Ricoh\x00\x00\x00" . $ifd . $block;

		$parsed = Makernote::fromNote($note, 'RICOH', $note, 0, true);
		self::assertSame('Ricoh', $parsed->getVariant());
		self::assertSame([], $parsed->getSubIfds());
		self::assertSame(5, $parsed->getIfd()->getTagValue(0x0002));
	}

	public function testRicohWithoutTheCameraInfoBlock()
	{
		// The Ricoh variant declares a nested sub-IFD, but a note that has no 0x2001
		// tag simply has no sub-IFD; the rest of the note still decodes.
		$ifd = $this->packIfd([[0x0002, TIFFDataType::UShort, 1, pack('n', 5)]], true);
		$note = "Ricoh\x00\x00\x00" . $ifd;
		$parsed = Makernote::fromNote($note, 'RICOH', $note, 0, true);
		self::assertSame('Ricoh', $parsed->getVariant());
		self::assertSame([], $parsed->getSubIfds());
		self::assertSame(5, $parsed->getIfd()->getTagValue(0x0002));
	}

	public function testFujifilmNoteTruncatedBeforeItsIfdPointer()
	{
		// Fujifilm reads the IFD offset from bytes 8..11 of the note; a note that stops
		// at the signature is still recognized as Fujifilm but decodes to nothing.
		// The truncated unpack() raises a PHP warning the reader tolerates.
		set_error_handler(fn () => true, E_WARNING);
		try {
			$parsed = Makernote::fromNote('FUJIFILM', 'FUJIFILM FinePix');
		} finally {
			restore_error_handler();
		}
		self::assertNotNull($parsed);
		self::assertSame('Fujifilm', $parsed->getVariant());
		self::assertFalse($parsed->getIsDecoded());
		self::assertNull($parsed->getIfd());
	}

	public function testCanonSettingsBlocksBeyondTheirTables()
	{
		// Camera Settings 1 with the whole 35-word block: the plain numeric entries
		// render as themselves, and words past the table's end are left out.
		$settings = array_fill(0, 35, 0);
		$settings[0] = 70;      // size field: never displayed
		$settings[23] = 105;    // Maximum Focal Length of Lens (plain)
		$settings[24] = 24;     // Minimum Focal Length of Lens (plain)
		$settings[25] = 1;      // Focal Length Units per mm (plain)
		$settings[34] = 7;      // past the last defined index
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[CanonMakernote::CameraSettings1Tag, TIFFDataType::UShort, count($settings), pack('N', $dataPos)]],
			true,
		);
		$note = $ifd . pack('n*', ...$settings);
		$parsed = Makernote::fromNote($note, 'Canon', $note, 0, true);
		self::assertInstanceOf(CanonMakernote::class, $parsed);

		$decoded = $parsed->getCameraSettings();
		self::assertSame('105', $decoded['Maximum Focal Length of Lens']);
		self::assertSame('24', $decoded['Minimum Focal Length of Lens']);
		self::assertSame('1', $decoded['Focal Length Units per mm']);
		self::assertArrayNotHasKey('Number of Bytes in Tag', $decoded);

		// The same note carries no Custom Functions block.
		self::assertSame([], $parsed->getCustomFunctions());
	}

	public function testMinoltaSevenHiSettingsTagAndPlainValues()
	{
		// The 7Hi stores its camera settings in tag 0x0003 instead of 0x0001.
		$values = array_fill(0, 21, 0);
		$values[2] = 1;      // Exposure Mode: A
		$values[18] = 4;     // Interval Number (plain)
		$values[20] = 250;   // Focus Distance (plain)
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[KonicaMinoltaMakernote::CameraSettings7HiTag, TIFFDataType::ULong, count($values), pack('N', $dataPos)]],
			true,
		);
		$note = $ifd . pack('N*', ...$values);
		$parsed = Makernote::fromNote($note, 'KONICA MINOLTA', $note, 0, true);
		self::assertInstanceOf(KonicaMinoltaMakernote::class, $parsed);

		$settings = $parsed->getCameraSettings();
		self::assertSame('A', $settings['Exposure Mode']);
		self::assertSame('4', $settings['Interval Number']);
		self::assertSame('250', $settings['Focus Distance']);
	}

	public function testMinoltaWithoutAnySettingsBlock()
	{
		$ifd = $this->packIfd([[0x0040, TIFFDataType::UShort, 1, pack('n', 1)]], true);
		$parsed = Makernote::fromNote($ifd, 'MINOLTA', $ifd, 0, true);
		self::assertInstanceOf(KonicaMinoltaMakernote::class, $parsed);
		self::assertSame(1, $parsed->getIfd()->getTagValue(0x0040));
		self::assertSame([], $parsed->getCameraSettings());
	}

	public function testRegisterMakerClassOverride()
	{
		$custom = new class () extends Makernote {
		};
		try {
			Makernote::registerMakerClass('Sony', $custom::class);
			$ifd = $this->packIfd([[0x9001, TIFFDataType::UShort, 1, pack('n', 3)]], true, false);
			$parsed = Makernote::fromNote("SONY CAM \x00\x00\x00" . $ifd, 'SONY');
			self::assertInstanceOf($custom::class, $parsed);
			self::assertSame('Sony', $parsed->getMaker());
		} finally {
			Makernote::registerMakerClass('Sony', null);
		}
		// The default class is restored.
		$ifd = $this->packIfd([[0x9001, TIFFDataType::UShort, 1, pack('n', 3)]], true, false);
		self::assertSame(Makernote::class, get_class(Makernote::fromNote("SONY DSC \x00\x00\x00" . $ifd, 'SONY')));
	}

	public function testAccessorsAndUnknownTagNaming()
	{
		$ifd = $this->packIfd([
			[0x0001, TIFFDataType::UShort, 1, pack('n', 2)],
			[0xEEEE, TIFFDataType::UShort, 1, pack('n', 9)],   // unknown tag
		], true, false);
		$note = "Panasonic\x00\x00\x00" . $ifd;
		$parsed = Makernote::fromNote($note, 'Panasonic');
		self::assertSame($note, $parsed->getNote());
		self::assertSame([], $parsed->getWarnings());
		self::assertNull($parsed->getText());
		self::assertNull($parsed->getTagText(0x9999));
		$values = $parsed->getValues();
		self::assertArrayHasKey('Tag 0xEEEE', $values);
	}
}
