<?php

use Belisoful\Image\ConformableInterface;
use Belisoful\Image\ConformanceCategory;
use Belisoful\Image\ImageFile;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\PNGImage;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFFImage;

/**
 * `validate()` reports where metadata departs from its specification and changes nothing;
 * `conform()` corrects it, by category, and only when asked.  A file that is merely read
 * and saved comes back as it was, departures included.
 */
class ConformanceTest extends PHPUnit\Framework\TestCase
{
	private function gd(): \GdImage
	{
		$image = imagecreatetruecolor(16, 12);
		imagefilledrectangle($image, 0, 0, 15, 11, imagecolorallocate($image, 10, 120, 200));
		return $image;
	}

	private function jpegBytes(): string
	{
		ob_start();
		imagejpeg($this->gd());
		return (string) ob_get_clean();
	}

	private function pngBytes(): string
	{
		ob_start();
		imagepng($this->gd());
		return (string) ob_get_clean();
	}

	/** An EXIF holding one field in each of its three directories. */
	private function exif(): EXIF
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', 'Own');
		$exif->getExifIfd(true)->setTagValues(33434, TIFFDataType::URational, [[1, 250]]);
		$exif->getGpsIfd(true)->setTagValues(1, TIFFDataType::Ascii, "N\0");
		return $exif;
	}

	public function testTheCategories()
	{
		self::assertSame(ConformanceCategory::Type | ConformanceCategory::Required | ConformanceCategory::Structure, ConformanceCategory::Safe);
		self::assertSame(0, ConformanceCategory::Safe & ConformanceCategory::Invented, 'a value nobody recorded is written only when asked for');
		self::assertSame(0, ConformanceCategory::Safe & ConformanceCategory::Advisory);
		self::assertSame(ConformanceCategory::Invented, ConformanceCategory::All & ConformanceCategory::Invented);
		foreach ([new EXIF(), XMP::blank(), new IPTC(), new PhotoshopIRB(), JPEGImage::fromString($this->jpegBytes())] as $carrier) {
			self::assertInstanceOf(ConformableInterface::class, $carrier);
		}
	}

	// ---- IPTC ----

	public function testIptcValidateReportsAndChangesNothing()
	{
		$block = "\x1C\x02\x78\x00\x07caption";
		$iptc = IPTC::parse($block);

		$all = $iptc->validate();
		self::assertSame(
			[IPTCTags::EnvelopeRecordVersion, IPTCTags::FileFormat, IPTCTags::FileVersion, IPTCTags::ServiceIdentifier, IPTCTags::DateSent, IPTCTags::EnvelopeNumber, IPTCTags::ApplicationRecordVersion],
			array_keys($all),
		);
		self::assertSame('required and missing', $all[IPTCTags::DateSent]);
		self::assertSame(
			[IPTCTags::EnvelopeRecordVersion, IPTCTags::FileFormat, IPTCTags::FileVersion, IPTCTags::ApplicationRecordVersion],
			array_keys($iptc->validate(ConformanceCategory::Required)),
		);
		self::assertSame(
			[IPTCTags::ServiceIdentifier, IPTCTags::DateSent, IPTCTags::EnvelopeNumber],
			array_keys($iptc->validate(ConformanceCategory::Invented)),
		);
		self::assertSame([], $iptc->validate(ConformanceCategory::Type | ConformanceCategory::Structure | ConformanceCategory::Advisory));

		// Asking is not changing: the set is still the bytes it was read from.
		self::assertSame([IPTCTags::CaptionAbstract], $iptc->getKeys());
		self::assertSame(bin2hex($block), bin2hex($iptc->toBinary(false)));
	}

	public function testIptcConformByCategory()
	{
		$block = "\x1C\x02\x78\x00\x07caption";

		// What IIM itself gives the value of, and nothing invented.
		$iptc = IPTC::parse($block);
		self::assertSame(4, $iptc->conform());
		self::assertSame(4, $iptc[IPTCTags::EnvelopeRecordVersion]);
		self::assertSame(1, $iptc[IPTCTags::FileFormat]);
		self::assertSame(4, $iptc[IPTCTags::ApplicationRecordVersion]);
		self::assertFalse($iptc->contains(IPTCTags::DateSent));
		self::assertSame([], $iptc->validate(ConformanceCategory::Safe));
		self::assertSame(0, $iptc->conform(), 'nothing is corrected twice');

		// The envelope's sender, date and number only when asked for.
		self::assertSame(3, $iptc->conform(ConformanceCategory::Invented));
		self::assertSame(IPTC::DefaultServiceIdentifier, $iptc[IPTCTags::ServiceIdentifier]);
		self::assertSame(IPTC::formatIPTCDate(), $iptc[IPTCTags::DateSent]);
		self::assertSame(IPTC::computeEnvelopeNumber(IPTC::DefaultServiceIdentifier, $iptc[IPTCTags::DateSent]), $iptc[IPTCTags::EnvelopeNumber]);
		self::assertSame([], $iptc->validate());

		// A record version standing where its record holds nothing is structure.
		$stray = new IPTC('');
		$stray[IPTCTags::NewsPhotoVersion] = 4;
		self::assertSame([IPTCTags::NewsPhotoVersion => 'a record version with no dataset in its record'], $stray->validate(ConformanceCategory::Structure));
		self::assertSame(1, $stray->conform(ConformanceCategory::Structure));
		self::assertFalse($stray->contains(IPTCTags::NewsPhotoVersion));

		// A set built from nothing is completed when it is written, as one being sent.
		$new = new IPTC();
		$new[IPTCTags::CaptionAbstract] = 'caption';
		self::assertNotSame([], $new->validate());
		$new->toBinary(false);
		self::assertSame([], $new->validate());
	}

	// ---- XMP ----

	private function xmpInTheWrongForms(): XMP
	{
		return XMP::parse('<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/">'
			. '<dc:subject>alpha</dc:subject>'
			. '<dc:title>A title</dc:title>'
			. '<dc:creator><rdf:Bag><rdf:li>One</rdf:li><rdf:li>Two</rdf:li></rdf:Bag></dc:creator>'
			. '<xmpMM:DerivedFrom><rdf:Bag><rdf:li>not a structure</rdf:li></rdf:Bag></xmpMM:DerivedFrom>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>');
	}

	public function testXmpValidateReportsTheFormOfEachProperty()
	{
		$xmp = $this->xmpInTheWrongForms();
		$before = $xmp->toPacketText(false);

		self::assertSame(
			[
				'dc:subject' => 'expected an rdf:Bag array',
				'dc:title' => 'expected an rdf:Alt array',
				'dc:creator' => 'expected an rdf:Seq array, found rdf:Bag',
				'xmpMM:DerivedFrom' => 'expected a structure',
			],
			$xmp->validate(),
		);
		self::assertSame($xmp->validate(), $xmp->validate(ConformanceCategory::Type));
		self::assertSame([], $xmp->validate(ConformanceCategory::All & ~ConformanceCategory::Type));
		self::assertSame($before, $xmp->toPacketText(false));
		self::assertSame([], XMP::blank()->validate());
	}

	public function testXmpConformRewritesWhatHasOneRightForm()
	{
		$xmp = $this->xmpInTheWrongForms();
		self::assertSame(0, $xmp->conform(ConformanceCategory::Required | ConformanceCategory::Invented | ConformanceCategory::Structure));

		self::assertSame(3, $xmp->conform());
		self::assertSame('Bag', $xmp->getArrayType(XMP::NS_DC, 'subject'));
		self::assertSame(['alpha'], $xmp->getProperty(XMP::NS_DC, 'subject'));
		self::assertSame('A title', $xmp->getLangAltValue(XMP::NS_DC, 'title'));
		self::assertSame('Seq', $xmp->getArrayType(XMP::NS_DC, 'creator'));
		self::assertSame(['One', 'Two'], $xmp->getProperty(XMP::NS_DC, 'creator'));

		// A collection where a structure belongs has no one right reading, and stays reported.
		self::assertSame(['xmpMM:DerivedFrom' => 'expected a structure'], $xmp->validate());
		self::assertSame(0, $xmp->conform());

		// What was written is what is read.
		self::assertSame(['xmpMM:DerivedFrom' => 'expected a structure'], XMP::parse($xmp->toPacketText(false))->validate());
	}

	// ---- EXIF ----

	public function testExifOfACompressedImage()
	{
		// Only IFD0: Exif asks how the chroma is sited.
		$exif = new EXIF();
		$exif->setValueByName('Make', 'Own');
		self::assertSame(['IFD0:YCbCrPositioning' => 'required and missing'], $exif->validate());
		self::assertSame([], $exif->validate(ConformanceCategory::All & ~ConformanceCategory::Required));

		// With the Exif and GPS directories, what each of them must state.
		$exif = $this->exif();
		$before = bin2hex($exif->toBinary());
		self::assertSame(
			['IFD0:YCbCrPositioning', 'ExifIFD:ExifVersion', 'ExifIFD:ComponentsConfiguration', 'ExifIFD:ColorSpace', 'ExifIFD:PixelXDimension', 'ExifIFD:PixelYDimension', 'GPS:GPSVersionID'],
			array_keys($exif->validate()),
		);
		self::assertSame($before, bin2hex($exif->toBinary()), 'asking is not changing');

		// Everything but the pixel dimensions, which are the image's to state.
		self::assertSame(0, $exif->conform(ConformanceCategory::Type | ConformanceCategory::Invented));
		self::assertSame(5, $exif->conform());
		self::assertSame([1], $exif->getIfd0()->getTag(0x0213)->getValues());
		self::assertSame('0232', $exif->getExifIfd()->getTag(0x9000)->getValues());
		self::assertSame("\x01\x02\x03\x00", $exif->getExifIfd()->getTag(0x9101)->getValues());
		self::assertSame([0xFFFF], $exif->getExifIfd()->getTag(0xA001)->getValues());
		self::assertSame([2, 3, 0, 0], $exif->getGpsIfd()->getTag(0)->getValues());
		self::assertSame(['ExifIFD:PixelXDimension', 'ExifIFD:PixelYDimension'], array_keys($exif->validate()));
		self::assertSame(0, $exif->conform());

		// A field of the UTF-8 type came with Exif 3.0, and the version says so.
		$modern = $this->exif();
		$modern->getIfd0()->setTagValues(270, TIFFDataType::Utf8, "caf\u{E9}\0");
		$modern->conform();
		self::assertSame('0300', $modern->getExifIfd()->getTag(0x9000)->getValues());
	}

	public function testExifOfATiffFileAndOfAnyOtherContainer()
	{
		// A TIFF file's own directory: its resolution, and the versions where it has the directories.
		$tiff = TIFFImage::fromImage($this->gd());
		$exif = $tiff->getEXIF();
		$exif->getIfd0()->removeTag(282);
		$exif->getIfd0()->removeTag(283);
		self::assertSame(['IFD0:XResolution', 'IFD0:YResolution'], array_keys($exif->validate()));
		$exif->getExifIfd(true)->setTagValues(33434, TIFFDataType::URational, [[1, 250]]);
		$exif->getGpsIfd(true)->setTagValues(1, TIFFDataType::Ascii, "N\0");
		self::assertSame(
			['IFD0:XResolution', 'IFD0:YResolution', 'ExifIFD:ExifVersion', 'ExifIFD:FlashpixVersion', 'ExifIFD:ColorSpace', 'GPS:GPSVersionID'],
			array_keys($exif->validate()),
		);
		self::assertSame(6, $exif->conform());
		self::assertSame([[72, 1]], $exif->getIfd0()->getTag(282)->getValues());
		self::assertSame('0100', $exif->getExifIfd()->getTag(0xA000)->getValues());
		self::assertSame([], $exif->validate());

		// A TIFF of tiles is a TIFF file all the same.
		$tiled = new EXIF();
		$tiled->setSignature('');
		$tiled->getIfd0()->setTagValues(324, TIFFDataType::ULong, [8]);
		self::assertSame(['IFD0:XResolution', 'IFD0:YResolution'], array_keys($tiled->validate()));

		// The block another container carries has nothing the specification requires of it.
		$block = $this->exif();
		$block->setSignature('');
		self::assertSame([], $block->validate());
		self::assertSame(0, $block->conform());
	}

	public function testTheIptcTagOfATiffIsLong()
	{
		$block = "\x1C\x02\x78\x00\x07caption" . "\x1C\x02\x05\x00\x04name";
		$exif = $this->exif();
		$exif->getIfd0()->setTagValues(EXIF::IptcTag, TIFFDataType::Undefined, $block);

		self::assertSame(['IFD0:IPTC-NAA' => 'expected type LONG'], $exif->validate(ConformanceCategory::Type));
		self::assertSame(0, $exif->conform(ConformanceCategory::Required & 0));
		self::assertSame(1, $exif->conform(ConformanceCategory::Type));
		self::assertSame(TIFFDataType::ULong, $exif->getIfd0()->getTag(EXIF::IptcTag)->getType());
		self::assertSame(['name', 'caption'], [$exif->getIPTC()[IPTCTags::ObjectName], $exif->getIPTC()[IPTCTags::CaptionAbstract]]);
		self::assertSame([IPTCTags::CaptionAbstract, IPTCTags::ObjectName], $exif->getIPTC()->getKeys(), 'the datasets are not touched');
		self::assertSame([], $exif->validate(ConformanceCategory::Type));
		self::assertSame(0, $exif->conform(ConformanceCategory::Type));
	}

	// ---- the Photoshop resource block ----

	public function testAResourceBlockAnswersForTheIptcItHolds()
	{
		$irb = new PhotoshopIRB();
		self::assertSame([], $irb->validate());
		self::assertSame(0, $irb->conform(ConformanceCategory::All));

		$irb->setResource(new PhotoshopResource(PhotoshopResource::IptcNaa, "\x1C\x02\x78\x00\x07caption"));
		self::assertCount(7, $irb->validate());
		self::assertSame(4, $irb->conform());
		self::assertSame(4, $irb->getIPTC()[IPTCTags::ApplicationRecordVersion]);
		self::assertCount(3, $irb->validate());
		self::assertSame(0, $irb->conform());
	}

	// ---- the containers ----

	public function testAContainerReportsAndCorrectsEveryCarrierItHolds()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		self::assertSame([], $jpeg->validate(), 'a file with no metadata has none out of line');
		self::assertSame(0, $jpeg->conform(ConformanceCategory::All));

		$jpeg->setEXIF($this->exif());
		$jpeg->setXMP($this->xmpInTheWrongForms());
		$block = "\x1C\x02\x78\x00\x07caption";
		$jpeg->setIPTC(IPTC::parse($block));
		$file = $jpeg->toBinary();

		// Read and written, it is the file it was: nothing is corrected unasked.
		$read = JPEGImage::fromString($file);
		self::assertSame(bin2hex($file), bin2hex($read->toBinary()));

		$problems = $read->validate();
		self::assertSame('required and missing', $problems['EXIF ExifIFD:ColorSpace']);
		self::assertSame('required and missing', $problems['EXIF ExifIFD:PixelXDimension']);
		self::assertSame('expected an rdf:Bag array', $problems['XMP dc:subject']);
		self::assertSame('required and missing', $problems['IPTC ' . IPTCTags::ApplicationRecordVersion]);
		self::assertSame('required and missing', $problems['IPTC ' . IPTCTags::DateSent]);
		self::assertCount(7 + 4 + 7, $problems);
		self::assertSame(bin2hex($file), bin2hex($read->toBinary()), 'asking is not changing');

		// Five EXIF fields and the two pixel dimensions, three XMP properties, four IPTC datasets.
		self::assertSame(5 + 2 + 3 + 4, $read->conform());
		self::assertSame(0, $read->conform());
		$conformed = JPEGImage::fromString($read->toBinary());
		self::assertSame([16], $conformed->getEXIF()->getExifIfd()->getTag(0xA002)->getValues());
		self::assertSame([12], $conformed->getEXIF()->getExifIfd()->getTag(0xA003)->getValues());
		self::assertSame(
			['XMP xmpMM:DerivedFrom', 'IPTC ' . IPTCTags::ServiceIdentifier, 'IPTC ' . IPTCTags::DateSent, 'IPTC ' . IPTCTags::EnvelopeNumber],
			array_keys($conformed->validate()),
		);
		self::assertSame(['XMP xmpMM:DerivedFrom'], array_keys($conformed->validate(ConformanceCategory::Safe)));

		// And the envelope, when it is asked for.
		self::assertSame(3, $conformed->conform(ConformanceCategory::Invented));
		self::assertSame(['XMP xmpMM:DerivedFrom'], array_keys(JPEGImage::fromString($conformed->toBinary())->validate()));
	}

	public function testATiffAndAPng()
	{
		// A TIFF another writer left without a resolution, its IPTC tag UNDEFINED.
		$tiff = TIFFImage::fromImage($this->gd());
		$ifd0 = $tiff->getEXIF()->getIfd0();
		$ifd0->removeTag(282);
		$ifd0->removeTag(283);
		$ifd0->setTagValues(EXIF::IptcTag, TIFFDataType::Undefined, "\x1C\x02\x00\x00\x02\x00\x04" . "\x1C\x02\x78\x00\x07caption");
		$read = TIFFImage::fromString($tiff->toBinary());

		self::assertSame(
			['EXIF IFD0:IPTC-NAA', 'EXIF IFD0:XResolution', 'EXIF IFD0:YResolution', 'IPTC 1#000', 'IPTC 1#020', 'IPTC 1#022', 'IPTC 1#030', 'IPTC 1#070', 'IPTC 1#040'],
			array_keys($read->validate()),
		);
		self::assertSame(3, $read->conform(ConformanceCategory::Type | ConformanceCategory::Required) - 3, 'three in the directory, three in the IPTC');
		$conformed = TIFFImage::fromString($read->toBinary());
		self::assertSame(TIFFDataType::ULong, $conformed->getEXIF()->getIfd0()->getTag(EXIF::IptcTag)->getType());
		self::assertSame('caption', $conformed->getIPTC()[IPTCTags::CaptionAbstract]);
		self::assertSame([], $conformed->validate(ConformanceCategory::Safe));

		// The Exif block of a PNG has nothing required of it; its XMP is held to its schemas.
		$png = PNGImage::fromString($this->pngBytes());
		$png->setEXIF($this->exif());
		$png->setXMP($this->xmpInTheWrongForms());
		$read = PNGImage::fromString($png->toBinary());
		self::assertSame(['XMP dc:subject', 'XMP dc:title', 'XMP dc:creator', 'XMP xmpMM:DerivedFrom'], array_keys($read->validate()));
		self::assertSame(3, $read->conform());
		self::assertSame(['XMP xmpMM:DerivedFrom'], array_keys(PNGImage::fromString($read->toBinary())->validate()));
	}

	public function testAnExtendedXmpPacketNotNamedByItsDigestIsReported()
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Extended');
		$text = $xmp->toPacketText(false);
		$build = function (string $name) use ($text): string {
			$main = XMP::blank();
			$main->setProperty(XMP::NS_NOTE, 'HasExtendedXMP', $name);
			$segment = fn (string $payload) => "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
			$jpeg = $this->jpegBytes();
			return substr($jpeg, 0, 2)
				. $segment(JPEGImage::XMP_IDENTIFIER . $main->toPacketText(false))
				. $segment(JPEGImage::XMP_EXTENSION_IDENTIFIER . $name . pack('N', strlen($text)) . pack('N', 0) . $text)
				. substr($jpeg, 2);
		};

		// Named by its digest, in either case of the letters: nothing to say.
		self::assertSame([], JPEGImage::fromString($build(strtoupper(md5($text))))->validate());
		self::assertSame([], JPEGImage::fromString($build(md5($text)))->validate());

		// Named by something else, it is read all the same, and reported as advice.
		$jpeg = JPEGImage::fromString($build(str_repeat('A', 32)));
		self::assertSame(['Extended'], $jpeg->getXMP()->getProperty(XMP::NS_DC, 'title'));
		self::assertSame(['XMP xmpNote:HasExtendedXMP' => 'the extended packet is not named by its MD5 digest'], $jpeg->validate());
		self::assertSame([], $jpeg->validate(ConformanceCategory::Safe | ConformanceCategory::Invented));
		self::assertSame(0, $jpeg->conform(ConformanceCategory::All), 'advice is not something to correct');
	}

	// ---- an independent reading of the same specifications ----

	/**
	 * Runs ExifTool's own validation over a file, where ExifTool is installed.
	 * @param ImageFile $image
	 * @param string $extension
	 * @return string[] Its warnings.
	 */
	private function exiftoolWarnings(ImageFile $image, string $extension): array
	{
		$exiftool = trim((string) shell_exec('command -v exiftool 2>/dev/null'));
		if ($exiftool === '') {
			self::markTestSkipped('ExifTool is not installed.');
		}
		$path = tempnam(sys_get_temp_dir(), 'conform') . '.' . $extension;
		$image->save($path);
		try {
			$output = (string) shell_exec(escapeshellarg($exiftool) . ' -validate -warning -error -a -s3 ' . escapeshellarg($path) . ' 2>&1');
		} finally {
			unlink($path);
			@unlink(substr($path, 0, -strlen($extension) - 1));
		}
		$lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
		self::assertNotSame([], $lines, 'ExifTool said nothing at all');
		return array_values(array_filter($lines, fn ($line) => $line !== 'OK' && !preg_match('/^\d+ Warnings?/', $line)));
	}

	public function testExifToolFindsNothingWrongWithAConformedFile()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$jpeg->setEXIF($this->exif());
		$block = "\x1C\x02\x78\x00\x07caption";
		$jpeg->setIPTC(IPTC::parse($block));
		self::assertNotSame([], $this->exiftoolWarnings($jpeg, 'jpg'), 'ExifTool reads the same specification');
		$jpeg->conform();
		self::assertSame([], $this->exiftoolWarnings($jpeg, 'jpg'));

		$tiff = TIFFImage::fromImage($this->gd());
		$tiff->getEXIF()->getExifIfd(true)->setTagValues(33434, TIFFDataType::URational, [[1, 250]]);
		$iptc = new IPTC();
		$iptc[IPTCTags::CaptionAbstract] = 'caption';
		$tiff->setIPTC($iptc);
		$tiff->getEXIF()->getIfd0()->removeTag(282);
		self::assertNotSame([], $this->exiftoolWarnings($tiff, 'tif'));
		$tiff->conform();
		self::assertSame([], $this->exiftoolWarnings($tiff, 'tif'));
	}
}
