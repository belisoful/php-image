<?php

use Belisoful\Image\ImageGraphicsLibraryInterface;
use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\ImageChunk;
use Belisoful\Image\ImageGraphics;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\PNGImage;
use Belisoful\Image\PrivacyCategory;
use Belisoful\Image\TIFFImage;
use Belisoful\Image\RIFFChunkType;
use Belisoful\Image\WebPImage;
use Belisoful\Image\GIFImage;
use Belisoful\Image\GIF\GIFBlockType;
use Belisoful\Image\GIF\GIFExtension;
use Belisoful\Image\GIF\GIFFrame;

/**
 * Every supported container reads AND writes each metadata carrier its format defines: a
 * setter must survive a compose-and-reparse cycle rather than being accepted and dropped.
 */
class ContainerReadWriteTest extends PHPUnit\Framework\TestCase
{
	private function gdImage(int $w = 6, int $h = 4): \GdImage
	{
		$image = imagecreatetruecolor($w, $h);
		imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 10, 120, 200));
		return $image;
	}

	private function png(): string
	{
		ob_start();
		imagepng($this->gdImage());
		return (string) ob_get_clean();
	}

	private function webp(): string
	{
		if (!function_exists('imagewebp')) {
			self::markTestSkipped('GD lacks WebP support.');
		}
		ob_start();
		imagewebp($this->gdImage());
		return (string) ob_get_clean();
	}

	private function exif(string $artist = 'A. Photographer'): EXIF
	{
		$exif = new EXIF();
		$exif->setSignature('');
		$exif->setValueByName('Artist', $artist);
		return $exif;
	}

	private function iptc(string $title = 'A Title'): IPTC
	{
		$iptc = new IPTC();
		$iptc[IPTCTags::ObjectName] = $title;
		$iptc[IPTCTags::Keywords] = ['alpha', 'beta'];
		return $iptc;
	}

	private function xmp(): XMP
	{
		$xmp = XMP::blank();
		$xmp->setProperty(XMP::NS_DC, 'title', 'Container test');
		return $xmp;
	}

	//
	// ─── The regression this suite exists for ────────────────────────────────
	//

	public function testEveryContainerPersistsWhatItAccepts()
	{
		$profile = ICCProfileBuilder::sRgb();
		$cases = [
			'JPEG' => [JPEGImage::class, $this->jpeg()],
			'PNG' => [PNGImage::class, $this->png()],
			'TIFF' => [TIFFImage::class, TIFFImage::fromImage($this->gdImage())->toBinary()],
		];
		foreach ($cases as $name => [$class, $bytes]) {
			$file = $class::fromString($bytes);
			$file->setICCProfile($profile);
			$file->setIPTC($this->iptc("$name title"));

			$round = $class::fromString($file->toBinary());
			self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()), "$name ICC profile");
			self::assertSame("$name title", $round->getIPTC()[IPTCTags::ObjectName], "$name IPTC");
			self::assertTrue($round->hasICCProfile(), "$name hasICCProfile");
			self::assertTrue($round->hasIPTC(), "$name hasIPTC");
		}
	}

	private function jpeg(): string
	{
		ob_start();
		imagejpeg($this->gdImage());
		return (string) ob_get_clean();
	}

	//
	// ─── JPEG ────────────────────────────────────────────────────────────────
	//

	public function testJpegRasterRoundTripKeepsEveryCarrier()
	{
		$profile = ICCProfileBuilder::sRgb();
		$jpeg = JPEGImage::fromString($this->jpeg());
		$jpeg->setEXIF($this->exif());
		$jpeg->setIPTC($this->iptc('JPEG title'));
		$jpeg->setICCProfile($profile);
		$jpeg->setXMP($this->xmp());
		$jpeg->setComment('a comment');

		$loaded = JPEGImage::fromString($jpeg->toBinary());
		$image = $loaded->getImage();
		self::assertInstanceOf(\GdImage::class, $image);
		self::assertSame([6, 4], ImageGraphics::getSize($image));

		$loaded->setImage(ImageGraphics::resampled($image, 12, 8), 90);
		$round = JPEGImage::fromString($loaded->toBinary());

		self::assertSame([12, 8], [$round->getWidth(), $round->getHeight()]);
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertSame('JPEG title', $round->getIPTC()[IPTCTags::ObjectName]);
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertSame('Container test', $round->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
		// The comment lives as a raw segment rather than a parsed carrier: the frame swap
		// must keep those too.
		self::assertSame('a comment', $round->getComment());

		// And the result is a JPEG a decoder accepts.
		$size = getimagesizefromstring($round->toBinary());
		self::assertSame([12, 8], [$size[0], $size[1]]);
	}

	public function testJpegSetImageKeepsUnmodelledSegments()
	{
		// An APP14 Adobe segment is preserved but not modelled; replacing the pixels must
		// not drop it.
		$jpeg = JPEGImage::fromString($this->jpeg());
		$adobe = "Adobe\x00d\x80\x00\x00\x00\x00\x00";
		$segments = $jpeg->getSegments();
		$reflection = new ReflectionMethod(JPEGImage::class, 'addSegment');
		$reflection->invoke($jpeg, 0xEE, 'raw', $adobe);
		self::assertCount(count($segments) + 1, $jpeg->getSegments());

		$jpeg->setImage($jpeg->getImage(), 85);
		$markers = array_column($jpeg->getSegments(), 'marker');
		self::assertContains(0xEE, $markers);
		self::assertSame($adobe, $jpeg->getSegments()[array_search(0xEE, $markers, true)]['payload']);
	}

	public function testJpegFromImage()
	{
		$jpeg = JPEGImage::fromImage($this->gdImage(9, 7), 80);
		self::assertSame('JPEG', $jpeg->getFormat());
		self::assertSame([9, 7], [$jpeg->getWidth(), $jpeg->getHeight()]);
	}

	//
	// ─── PNG ─────────────────────────────────────────────────────────────────
	//

	public function testPngCarriersRoundTrip()
	{
		$profile = ICCProfileBuilder::sRgb();
		$png = PNGImage::fromString($this->png());
		$png->setICCProfile($profile);
		$png->setEXIF($this->exif());
		$png->setIPTC($this->iptc('PNG title'));
		$png->setXMP($this->xmp());

		$round = PNGImage::fromString($png->toBinary());
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertSame('PNG title', $round->getIPTC()[IPTCTags::ObjectName]);
		self::assertSame(['alpha', 'beta'], $round->getIPTC()[IPTCTags::Keywords]);
		self::assertSame('Container test', $round->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
		self::assertInstanceOf(PhotoshopIRB::class, $round->getPhotoshopIRB());

		// The chunk order the specification requires, and a file GD still reads.
		self::assertSame(
			['IHDR', 'iCCP', 'pHYs', 'eXIf', 'zTXt', 'iTXt', 'IDAT', 'IEND'],
			array_map(fn (ImageChunk $c): string => $c->getType(), $round->getChunks()),
		);
		self::assertInstanceOf(\GdImage::class, @imagecreatefromstring($round->toBinary()));
	}

	public function testPngCarriersAreRemovable()
	{
		$png = PNGImage::fromString($this->png());
		$png->setICCProfile(ICCProfileBuilder::sRgb());
		$png->setEXIF($this->exif());
		$png->setIPTC($this->iptc());
		$png->setXMP($this->xmp());

		$png->setICCProfile(null);
		$png->setEXIF(null);
		$png->setIPTC(null);
		$png->setXMP(null);

		$round = PNGImage::fromString($png->toBinary());
		self::assertNull($round->getICCProfile());
		self::assertNull($round->getEXIF());
		self::assertNull($round->getIPTC());
		self::assertNull($round->getXMP());
		self::assertNull($round->getPhotoshopIRB());
		self::assertFalse($round->hasICCProfile());
		self::assertFalse($round->hasIPTC());
		self::assertSame(
			['IHDR', 'pHYs', 'IDAT', 'IEND'],
			array_map(fn (ImageChunk $c): string => $c->getType(), $round->getChunks()),
		);
	}

	public function testPngChunkMutatorsKeepTheNormativeOrder()
	{
		$png = PNGImage::fromString($this->png());

		// Each of these belongs before IDAT, wherever it is set from.
		$png->setChunk(new ImageChunk('gAMA', 4, 0, pack('N', 45455)));
		$png->setChunk(new ImageChunk('sRGB', 1, 0, "\x00"));
		$png->addChunk(new ImageChunk('tEXt', 0, 0, "Comment\x00first"));
		$png->addChunk(new ImageChunk('tEXt', 0, 0, "Author\x00second"));
		// An unknown ancillary type is legal anywhere, so it lands before IEND.
		$png->setChunk(new ImageChunk('prVt', 2, 0, 'hi'));

		$types = array_map(fn (ImageChunk $c): string => $c->getType(), $png->getChunks());
		self::assertSame(['IHDR', 'gAMA', 'sRGB', 'pHYs', 'tEXt', 'tEXt', 'IDAT', 'prVt', 'IEND'], $types);

		// Replacing is in place; the second call must not append a duplicate.
		$png->setChunk(new ImageChunk('gAMA', 4, 0, pack('N', 100000)));
		self::assertSame(1, count(array_filter($png->getChunks(), fn ($c) => $c->getType() === 'gAMA')));
		self::assertSame(pack('N', 100000), $png->getChunk('gAMA')?->getData());

		self::assertTrue($png->removeChunk('tEXt'));
		self::assertFalse($png->removeChunk('tEXt'));
		self::assertNull($png->getChunk('tEXt'));

		// The composed file re-reads, so the ordering is really valid.
		self::assertInstanceOf(PNGImage::class, PNGImage::fromString($png->toBinary()));
	}

	public function testPngSetChunksWholesale()
	{
		$png = PNGImage::fromString($this->png());
		$chunks = $png->getChunks();
		$png->setChunks(array_reverse(array_reverse($chunks)));
		self::assertCount(count($chunks), $png->getChunks());

		self::expectException(\InvalidArgumentException::class);
		$png->setChunks(['not a chunk']);
	}

	public function testPngRasterRoundTripCarriesMetadata()
	{
		$profile = ICCProfileBuilder::sRgb();
		$png = PNGImage::fromString($this->png());
		$png->setICCProfile($profile);
		$png->setEXIF($this->exif());
		$png->setXMP($this->xmp());

		$image = $png->getImage();
		self::assertInstanceOf(\GdImage::class, $image);
		self::assertSame([6, 4], ImageGraphics::getSize($image));

		$png->setImage(ImageGraphics::resampled($image, 12, 8));
		$round = PNGImage::fromString($png->toBinary());
		self::assertSame(12, $round->getWidth());
		self::assertSame(8, $round->getHeight());
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertNotNull($round->getXMP());

		// Single-instance chunks must not double up when the encoder writes its own.
		$types = array_map(fn (ImageChunk $c): string => $c->getType(), $round->getChunks());
		self::assertSame(count($types), count(array_unique(array_diff($types, ['IDAT', 'tEXt', 'zTXt', 'iTXt']))) + count(array_intersect($types, ['IDAT', 'tEXt', 'zTXt', 'iTXt'])));
	}

	public function testPngFromImage()
	{
		$png = PNGImage::fromImage($this->gdImage(9, 7));
		self::assertSame('PNG', $png->getFormat());
		self::assertSame([9, 7], [$png->getWidth(), $png->getHeight()]);
		self::assertSame('IHDR', $png->getChunks()[0]->getType());
	}

	public function testPngReadsForeignRawProfileForms()
	{
		// A bare IIM block under the other keyword other writers use, and hexadecimal
		// broken across lines with stray whitespace, both decode.
		$png = PNGImage::fromString($this->png());
		$iim = $this->iptc('From a bare block')->toBinary(false);
		$text = "\niptc\n" . sprintf('%8d', strlen($iim)) . "\n" . chunk_split(bin2hex($iim), 40, "\n");
		$payload = PNGImage::IptcKeyword . "\x00" . $text;
		$png->addChunk(new ImageChunk('tEXt', strlen($payload), 0, $payload));

		$round = PNGImage::fromString($png->toBinary());
		self::assertSame('From a bare block', $round->getIPTC()[IPTCTags::ObjectName]);

		// Setting IPTC moves it into the 8BIM block and drops the bare one.
		$round->setIPTC($this->iptc('Now in the IRB'));
		$again = PNGImage::fromString($round->toBinary());
		self::assertSame('Now in the IRB', $again->getIPTC()[IPTCTags::ObjectName]);
		self::assertInstanceOf(PhotoshopIRB::class, $again->getPhotoshopIRB());
	}

	public function testPngIgnoresMalformedRawProfiles()
	{
		$png = PNGImage::fromString($this->png());
		foreach (['no newlines at all', "\nonly\ntwo", "\n8bim\n4\nnot hexadecimal!", "\n8bim\n1\nABC"] as $i => $text) {
			$payload = PNGImage::IrbKeyword . "\x00" . $text;
			$fresh = PNGImage::fromString($this->png());
			$fresh->addChunk(new ImageChunk('tEXt', strlen($payload), 0, $payload));
			self::assertNull($fresh->getPhotoshopIRB(), "case $i");
			self::assertNull($fresh->getIPTC(), "case $i");
		}
	}

	public function testPngRasterSettersRefuseAnImageTheirLibraryCannotWrite()
	{
		if (!extension_loaded('imagick')) {
			self::markTestSkipped('ext-imagick is not loaded.');
		}
		// An empty Imagick has no PNG bytes to give: the chunk model must not be replaced
		// with nothing, so both the setter and the factory refuse it.
		$png = PNGImage::fromString($this->png());
		$before = $png->toBinary();
		try {
			$png->setImage(new Imagick());
			self::fail('an unwritable image was accepted');
		} catch (\RuntimeException $e) {
		}
		self::assertSame(bin2hex($before), bin2hex($png->toBinary()));

		self::expectException(\RuntimeException::class);
		PNGImage::fromImage(new Imagick());
	}

	public function testPngDroppingIptcFromAFileThatHasNoneChangesNothing()
	{
		$png = PNGImage::fromString($this->png());
		$before = $png->toBinary();
		$png->setIPTC(null);
		self::assertNull($png->getIPTC());
		self::assertNull($png->getPhotoshopIRB());
		// No empty 8BIM block is minted just to hold the absence.
		self::assertSame(bin2hex($before), bin2hex($png->toBinary()));
	}

	public function testPngAppendsAChunkWhenTheEndMarkerIsMissing()
	{
		// A type outside the normative order belongs before IEND; with no IEND to sit
		// before, it goes last instead of being dropped.
		$png = PNGImage::fromString($this->png());
		self::assertTrue($png->removeChunk('IEND'));
		$png->addChunk(new ImageChunk('prVt', 2, 0, 'hi'));

		$types = array_map(fn (ImageChunk $c): string => $c->getType(), $png->getChunks());
		self::assertSame('prVt', end($types));
		self::assertSame('hi', $png->getChunk('prVt')?->getData());

		// Restoring the end marker puts it back after the private chunk, and the file reads.
		$png->addChunk(new ImageChunk('IEND', 0, 0, ''));
		$round = PNGImage::fromString($png->toBinary());
		self::assertSame(
			['IHDR', 'pHYs', 'IDAT', 'prVt', 'IEND'],
			array_map(fn (ImageChunk $c): string => $c->getType(), $round->getChunks()),
		);
	}

	public function testPngReadsACompressedITxtPacketAndSkipsMalformedOnes()
	{
		$packet = $this->xmp()->toPacketText();
		// iTXt: keyword, NUL, compression flag and method, language tag, translated
		// keyword, then the text — deflated when the flag is 1.
		$png = PNGImage::fromString($this->png());
		$payload = PNGImage::XmpKeyword . "\x00" . "\x01\x00" . "\x00" . "\x00" . gzcompress($packet);
		$png->setChunk(new ImageChunk('iTXt', strlen($payload), 0, $payload));
		self::assertSame($packet, $png->getXmpText());
		self::assertSame('Container test', $png->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));

		// A compression flag with data that is not deflated reads as absent, not as noise.
		$bad = PNGImage::XmpKeyword . "\x00" . "\x01\x00" . "\x00" . "\x00" . 'not deflated';
		$png->setChunk(new ImageChunk('iTXt', strlen($bad), 0, $bad));
		self::assertNull($png->getXmpText());

		// So do payloads missing the language tag or the translated keyword terminators.
		foreach (["\x00\x00", "\x00\x00en\x00"] as $i => $rest) {
			$fresh = PNGImage::fromString($this->png());
			$truncated = PNGImage::XmpKeyword . "\x00" . $rest;
			$fresh->setChunk(new ImageChunk('iTXt', strlen($truncated), 0, $truncated));
			self::assertNull($fresh->getXmpText(), "case $i");
			self::assertNull($fresh->getXMP(), "case $i");
		}

		// A malformed chunk does not hide a well-formed one written after it.
		$fresh = PNGImage::fromString($this->png());
		$truncated = PNGImage::XmpKeyword . "\x00" . "\x00\x00";
		$fresh->addChunk(new ImageChunk('iTXt', strlen($truncated), 0, $truncated));
		$fresh->addChunk(new ImageChunk('iTXt', strlen($payload), 0, $payload));
		self::assertSame($packet, $fresh->getXmpText());
	}

	public function testPngIgnoresAnUndecodableICCOrExifChunk()
	{
		$png = PNGImage::fromString($this->png());
		$png->setChunk(new ImageChunk('iCCP', 8, 0, "name\x00\x00garbage"));
		$png->setChunk(new ImageChunk('eXIf', 4, 0, 'junk'));
		self::assertNull($png->getICCProfile());
		self::assertNull($png->getEXIF());

		// A payload without the name terminator, and an empty one.
		$png->setChunk(new ImageChunk('iCCP', 4, 0, 'name'));
		self::assertNull($png->getICCProfile());
		$png->setChunk(new ImageChunk('eXIf', 0, 0, ''));
		self::assertNull($png->getEXIF());
	}

	public function testPngTextThatIsNotTheCarrierItClaimsReadsAsAbsent()
	{
		// The chunk is well formed and its text decodes; what it holds is not a packet.
		$png = PNGImage::fromString($this->png());
		$png->setXmpText('this is not an XMP packet');
		self::assertSame('this is not an XMP packet', $png->getXmpText());
		self::assertNull($png->getXMP());

		// The same for a raw profile whose hexadecimal decodes to bytes that are not IIM:
		// the profile is read, the record set is not, and the two must not be confused.
		$iim = $this->iptc('A real record set')->toBinary(false);
		foreach (['not IIM' => "\x00\x01\x02\x03", 'IIM' => $iim] as $label => $bytes) {
			$text = "\niptc\n" . sprintf('%8d', strlen($bytes)) . "\n" . chunk_split(bin2hex($bytes), 72, "\n");
			$payload = PNGImage::IptcKeyword . "\x00" . $text;
			$fresh = PNGImage::fromString($this->png());
			$fresh->addChunk(new ImageChunk('tEXt', strlen($payload), 0, $payload));
			if ($label === 'IIM') {
				self::assertSame('A real record set', $fresh->getIPTC()[IPTCTags::ObjectName]);
			} else {
				self::assertNull($fresh->getIPTC(), $label);
			}
		}
	}

	public function testPngUninflatableCompressedTextReadsAsAbsent()
	{
		// A zTXt chunk holds one compression-method byte and then deflated bytes; when
		// those do not inflate the carrier is absent, not partially read.
		$png = PNGImage::fromString($this->png());
		$broken = PNGImage::IrbKeyword . "\x00" . "\x00" . 'not deflated at all';
		$png->addChunk(new ImageChunk('zTXt', strlen($broken), 0, $broken));
		self::assertNull($png->getPhotoshopIRB());
		self::assertNull($png->getIPTC());
		self::assertFalse($png->hasIPTC());

		// The same keyword, properly deflated, reads — so it is the inflation that failed.
		$png->setIPTC($this->iptc('Deflated'));
		self::assertSame('Deflated', $png->getIPTC()[IPTCTags::ObjectName]);
	}

	public function testPngKeepsTheResourceBlockWhenOnlyItsIptcIsDropped()
	{
		// The 8BIM block carries more than IPTC; dropping the record set must redact it
		// out of the block, not throw the block (and the other resources) away.
		$png = PNGImage::fromString($this->png());
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://example.org/photo'));
		$irb->setIPTC($this->iptc('PNG title'));
		$png->setPhotoshopIRB($irb);

		$png->setIPTC(null);
		$round = PNGImage::fromString($png->toBinary());
		self::assertNull($round->getIPTC());
		self::assertFalse($round->hasIPTC());
		$kept = $round->getPhotoshopIRB();
		self::assertInstanceOf(PhotoshopIRB::class, $kept);
		self::assertSame('https://example.org/photo', $kept->getResource(PhotoshopResource::Url)?->getData());
		self::assertNull($kept->getResource(PhotoshopResource::IptcNaa));
	}

	public function testPngTextChunkWithoutAKeywordSeparatorScrubsAsDescription()
	{
		// A writer that omitted the keyword NUL leaves a chunk that is all text: it has no
		// keyword, so only the Description category reaches it.
		$png = PNGImage::fromString($this->png());
		$png->addChunk(new ImageChunk('tEXt', 10, 0, 'no keyword'));

		self::assertSame(0, $png->clearPrivateData(PrivacyCategory::Author));
		self::assertSame('no keyword', $png->getChunk('tEXt')?->getData());

		self::assertSame(1, $png->clearPrivateData(PrivacyCategory::Description));
		self::assertNull($png->getChunk('tEXt'));
	}

	//
	// ─── WebP ────────────────────────────────────────────────────────────────
	//

	public function testWebPCarriersRoundTrip()
	{
		$profile = ICCProfileBuilder::sRgb();
		$webp = WebPImage::fromString($this->webp());
		// A simple file has no VP8X; metadata must add one.
		self::assertNull($webp->getRIFF()?->getChunk(RIFFChunkType::Vp8Extended));

		$webp->setICCProfile($profile);
		$webp->setEXIF($this->exif());
		$webp->setXMP($this->xmp());

		$round = WebPImage::fromString($webp->toBinary());
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertSame('Container test', $round->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
		self::assertSame([6, 4], [$round->getWidth(), $round->getHeight()]);

		// The specification's chunk order, and every feature flag set.
		self::assertSame(
			['VP8X', 'ICCP', 'VP8 ', 'EXIF', 'XMP '],
			array_map(fn (ImageChunk $c): string => $c->getType(), (array) $round->getRIFF()?->getChunks()),
		);
		$flags = ord((string) $round->getRIFF()?->getChunk(RIFFChunkType::Vp8Extended)?->getData()[0]);
		self::assertSame(WebPImage::Vp8xICCFlag, $flags & WebPImage::Vp8xICCFlag);
		self::assertSame(WebPImage::Vp8xExifFlag, $flags & WebPImage::Vp8xExifFlag);
		self::assertSame(WebPImage::Vp8xXmpFlag, $flags & WebPImage::Vp8xXmpFlag);
	}

	public function testWebPCarriersAreRemovableAndClearTheirFlags()
	{
		$webp = WebPImage::fromString($this->webp());
		$webp->setICCProfile(ICCProfileBuilder::sRgb());
		$webp->setEXIF($this->exif());
		$webp->setXMP($this->xmp());

		$webp->setICCProfile(null);
		$webp->setEXIF(null);
		$webp->setXMP(null);

		$round = WebPImage::fromString($webp->toBinary());
		self::assertNull($round->getICCProfile());
		self::assertNull($round->getEXIF());
		self::assertNull($round->getXMP());
		self::assertSame(0, ord((string) $round->getRIFF()?->getChunk(RIFFChunkType::Vp8Extended)?->getData()[0]));
	}

	public function testWebPRefusesIptcRatherThanDroppingIt()
	{
		$webp = WebPImage::fromString($this->webp());
		self::assertNull($webp->getIPTC());
		self::assertFalse($webp->hasIPTC());
		$webp->setIPTC(null);   // a null is accepted: nothing to store

		self::expectException(\RuntimeException::class);
		$webp->setIPTC($this->iptc());
	}

	public function testWebPRasterRoundTripCarriesMetadata()
	{
		$profile = ICCProfileBuilder::sRgb();
		$webp = WebPImage::fromString($this->webp());
		$webp->setICCProfile($profile);
		$webp->setEXIF($this->exif());
		$webp->setXMP($this->xmp());

		$image = $webp->getImage();
		self::assertTrue(ImageGraphics::isImage($image));
		$webp->setImage(ImageGraphics::resampled($image, 12, 8), 90);

		$round = WebPImage::fromString($webp->toBinary());
		self::assertSame([12, 8], [$round->getWidth(), $round->getHeight()]);
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertNotNull($round->getXMP());
	}

	public function testWebPFromImage()
	{
		if (!ImageGraphics::supports(ImageGraphicsLibraryInterface::CapabilityWebP)) {
			self::markTestSkipped('the default graphics library cannot write WebP.');
		}
		$webp = WebPImage::fromImage($this->gdImage(9, 7));
		self::assertSame('WebP', $webp->getFormat());
		self::assertSame([9, 7], [$webp->getWidth(), $webp->getHeight()]);
	}

	public function testWebPIgnoresAnUndecodableExifChunk()
	{
		$webp = WebPImage::fromString($this->webp());
		$webp->setEXIF($this->exif());
		$webp->getRIFF()?->getChunk(RIFFChunkType::Exif)?->setData('not TIFF');
		self::assertNull($webp->getEXIF());

		$webp->getRIFF()?->getChunk(RIFFChunkType::Exif)?->setData('');
		self::assertNull($webp->getEXIF());
	}

	//
	// ─── GIF ─────────────────────────────────────────────────────────────────
	//

	private function gif(): string
	{
		ob_start();
		imagegif($this->gdImage());
		return (string) ob_get_clean();
	}

	public function testGifCarriersRoundTrip()
	{
		$profile = ICCProfileBuilder::sRgb();
		$gif = GIFImage::fromString($this->gif());
		$gif->setICCProfile($profile);
		$gif->setXMP($this->xmp());
		$gif->addComment('a comment');
		$gif->setLoopCount(0);

		$bytes = $gif->toBinary();
		$round = GIFImage::fromString($bytes);
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertSame('Container test', $round->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
		self::assertSame(['a comment'], $round->getComments());
		self::assertSame(0, $round->getLoopCount());
		self::assertSame([6, 4], [$round->getWidth(), $round->getHeight()]);
		self::assertSame(1, $round->getFrameCount());

		// The XMP packet comes back byte-identical: the magic trailer means no byte of it
		// was mistaken for a sub-block length.
		self::assertSame($this->xmp()->toPacketText(), $round->getXmpText());

		// And the file still decodes, so a reader that knows nothing of XMP walks past the
		// raw block correctly.
		self::assertInstanceOf(\GdImage::class, @imagecreatefromstring($bytes));

		// The metadata extensions sit before the first frame.
		$blocks = $round->getBlocks();
		$firstFrame = null;
		foreach ($blocks as $index => $block) {
			if ($block instanceof GIFFrame) {
				$firstFrame = $index;
				break;
			}
		}
		self::assertNotNull($firstFrame);
		self::assertGreaterThan(0, $firstFrame, 'the extensions precede the frame');
	}

	public function testGifCarriersAreRemovable()
	{
		$gif = GIFImage::fromString($this->gif());
		$gif->setICCProfile(ICCProfileBuilder::sRgb());
		$gif->setXMP($this->xmp());

		$gif->setICCProfile(null);
		$gif->setXMP(null);
		// Removing what is already absent is a no-op, not an error.
		$gif->setICCProfile(null);
		$gif->setXmpText(null);

		$round = GIFImage::fromString($gif->toBinary());
		self::assertNull($round->getICCProfile());
		self::assertNull($round->getXmpText());
		self::assertNull($round->getXMP());
		self::assertFalse($round->hasICCProfile());
	}

	public function testGifReplacingACarrierKeepsItsPlace()
	{
		$gif = GIFImage::fromString($this->gif());
		$gif->setXMP($this->xmp());
		$before = count($gif->getBlocks());
		$position = array_search(
			$gif->getApplicationExtension(GIFImage::XmpIdentity),
			$gif->getBlocks(),
			true,
		);

		$replacement = XMP::blank();
		$replacement->setProperty(XMP::NS_DC, 'title', 'Replaced');
		$gif->setXMP($replacement);

		self::assertCount($before, $gif->getBlocks());
		self::assertSame($position, array_search($gif->getApplicationExtension(GIFImage::XmpIdentity), $gif->getBlocks(), true));
		self::assertSame('Replaced', $gif->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
	}

	public function testGifWithNoFramesStillCarriesItsMetadata()
	{
		// A metadata extension goes before the first frame; with no frame to precede, it
		// still has to land in the block list rather than being dropped.
		$bytes = GIFImage::Signature89a . pack('vv', 4, 2) . chr(0) . "\x00\x00" . chr(GIFBlockType::Trailer);
		$gif = GIFImage::fromString($bytes);
		self::assertSame(0, $gif->getFrameCount());

		$gif->setXMP($this->xmp());
		$gif->setICCProfile(ICCProfileBuilder::sRgb());
		self::assertCount(2, $gif->getBlocks());

		$round = GIFImage::fromString($gif->toBinary());
		self::assertSame(0, $round->getFrameCount());
		self::assertSame('Container test', $round->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
		self::assertSame(bin2hex(ICCProfileBuilder::sRgb()), bin2hex((string) $round->getICCProfile()));
	}

	public function testGifRefusesIptcRatherThanDroppingIt()
	{
		$gif = GIFImage::fromString($this->gif());
		self::assertNull($gif->getIPTC());
		self::assertFalse($gif->hasIPTC());
		$gif->setIPTC(null);

		self::expectException(\RuntimeException::class);
		$gif->setIPTC($this->iptc());
	}

	public function testGifRasterRoundTripKeepsExtensions()
	{
		$profile = ICCProfileBuilder::sRgb();
		$gif = GIFImage::fromString($this->gif());
		$gif->setICCProfile($profile);
		$gif->setXMP($this->xmp());
		$gif->addComment('kept');
		$gif->setLoopCount(3);

		$image = $gif->getImage();
		self::assertTrue(ImageGraphics::isImage($image));

		$gif->setImage(ImageGraphics::resampled($image, 12, 8));
		$round = GIFImage::fromString($gif->toBinary());

		self::assertSame([12, 8], [$round->getWidth(), $round->getHeight()]);
		self::assertSame(1, $round->getFrameCount());
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()));
		self::assertNotNull($round->getXMP());
		self::assertSame(['kept'], $round->getComments());
		self::assertSame(3, $round->getLoopCount());
		self::assertInstanceOf(\GdImage::class, @imagecreatefromstring($round->toBinary()));
	}

	public function testGifSetImageCollapsesAnimationToOneFrame()
	{
		$gif = GIFImage::fromString($this->gif());
		$second = new GIFFrame();
		$second->setWidth(6);
		$second->setHeight(4);
		$second->setMinCodeSize(GIFFrame::minCodeSizeFor((string) $gif->getGlobalColorTable()));
		$second->setPixels(str_repeat("\x00", 24));
		$gif->addFrame($second);
		self::assertSame(2, $gif->getFrameCount());

		$gif->setImage($gif->getImage());
		self::assertSame(1, $gif->getFrameCount());
	}

	public function testGifReadsAnXmpBlockWrittenInOrdinarySubBlocks()
	{
		// A writer that framed the packet normally (no magic trailer) still reads, through
		// the ordinary sub-block path.
		$gif = GIFImage::fromString($this->gif());
		$packet = $this->xmp()->toPacketText();
		$gif->addExtension(GIFExtension::application(GIFImage::XmpIdentity, $packet));

		$round = GIFImage::fromString($gif->toBinary());
		self::assertSame($packet, $round->getXmpText());
		self::assertFalse($round->getApplicationExtension(GIFImage::XmpIdentity)?->getIsRaw());
	}

	public function testGifACarrierExtensionWithNoPayloadReadsAsAbsent()
	{
		// The identity block with nothing after it: the extension is in the file, the
		// carrier is not.  An empty packet or profile must read as absent rather than as
		// a zero-byte value a caller would then try to parse or embed.
		$gif = GIFImage::fromString($this->gif());
		$gif->addExtension(GIFExtension::application(GIFImage::ICCIdentity, ''));
		$gif->addExtension(GIFExtension::rawApplication(GIFImage::XmpIdentity, ''));

		// Both before and after a compose-and-reparse cycle, which keeps the two blocks.
		foreach (['authored' => $gif, 'reparsed' => GIFImage::fromString($gif->toBinary())] as $state => $subject) {
			self::assertNotNull($subject->getApplicationExtension(GIFImage::ICCIdentity), $state);
			self::assertSame('', $subject->getApplicationExtension(GIFImage::ICCIdentity)?->getApplicationData(), $state);
			self::assertSame('', $subject->getApplicationExtension(GIFImage::XmpIdentity)?->getApplicationData(), $state);

			self::assertNull($subject->getICCProfile(), $state);
			self::assertFalse($subject->hasICCProfile(), $state);
			self::assertNull($subject->getXmpText(), $state);
			self::assertNull($subject->getXMP(), $state);
		}
	}

	public function testGifHeaderAccessors()
	{
		$gif = GIFImage::fromString($this->gif());

		$gif->setColorResolution(5);
		self::assertSame(5, $gif->getColorResolution());
		$gif->setColorResolution(0xFF);   // masked to three bits
		self::assertSame(7, $gif->getColorResolution());

		self::assertFalse($gif->getGlobalSorted());
		$gif->setGlobalSorted(true);
		self::assertTrue($gif->getGlobalSorted());

		$gif->setBackgroundIndex(0x1FF);   // masked to a byte
		self::assertSame(0xFF, $gif->getBackgroundIndex());
		$gif->setAspectRatio(0x142);
		self::assertSame(0x42, $gif->getAspectRatio());

		// Every field survives a compose and reparse.
		$round = GIFImage::fromString($gif->toBinary());
		self::assertSame(7, $round->getColorResolution());
		self::assertTrue($round->getGlobalSorted());
		self::assertSame(0xFF, $round->getBackgroundIndex());
		self::assertSame(0x42, $round->getAspectRatio());
	}

	public function testGifBlockListAndExtensionAccessors()
	{
		$gif = GIFImage::fromString($this->gif());
		$blocks = $gif->getBlocks();
		$gif->setBlocks(array_values($blocks));
		self::assertCount(count($blocks), $gif->getBlocks());

		$extension = GIFExtension::comment('text');
		self::assertSame(GIFBlockType::CommentLabel, $extension->getLabel());
		$extension->setLabel(GIFBlockType::PlainTextLabel);
		self::assertSame(GIFBlockType::PlainTextLabel, $extension->getLabel());
		self::assertFalse($extension->getIsRaw());
		$extension->setIsRaw(true);
		self::assertTrue($extension->getIsRaw());

		// A frame exposes its sort flag and the sub-block framing of its pixel data.
		$frame = $gif->getFrame(0);
		self::assertNotNull($frame);
		self::assertIsBool($frame->getSorted());
		self::assertNotEmpty($frame->getDataSubBlocks());
	}

	//
	// ─── TIFF ────────────────────────────────────────────────────────────────
	//

	public function testTiffXmpAndIrbFacades()
	{
		$tiff = TIFFImage::fromImage($this->gdImage());
		self::assertNull($tiff->getXMP());
		self::assertNull($tiff->getXmpText());

		$tiff->setXMP($this->xmp());
		$irb = new PhotoshopIRB();
		$irb->setIPTC($this->iptc('In the IRB'));
		$tiff->setPhotoshopIRB($irb);

		$round = TIFFImage::fromString($tiff->toBinary());
		self::assertSame('Container test', $round->getXMP()?->getLangAltValue(XMP::NS_DC, 'title'));
		self::assertNotNull($round->getXmpText());
		self::assertSame('In the IRB', $round->getPhotoshopIRB()?->getIPTC()[IPTCTags::ObjectName]);

		// And both are removable.
		$round->setXMP(null);
		$round->setPhotoshopIRB(null);
		$bare = TIFFImage::fromString($round->toBinary());
		self::assertNull($bare->getXMP());
		self::assertNull($bare->getPhotoshopIRB());
	}

	public function testTiffXmpOnAnEmptyContainer()
	{
		// Setting null on a TIFF with no EXIF must not conjure one.
		$tiff = new TIFFImage();
		$tiff->setXmpText(null);
		$tiff->setPhotoshopIRB(null);
		self::assertNull($tiff->getXmpText());
		self::assertNull($tiff->getPhotoshopIRB());

		$tiff->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"></x:xmpmeta>');
		self::assertNotNull($tiff->getXmpText());

		// An image-resource block with no resources composes to nothing, so it reads back
		// as absent; one carrying a resource survives.
		$fresh = new TIFFImage();
		$fresh->setPhotoshopIRB(new PhotoshopIRB());
		self::assertNull($fresh->getPhotoshopIRB());

		$irb = new PhotoshopIRB();
		$irb->setIPTC($this->iptc('Present'));
		$fresh->setPhotoshopIRB($irb);
		self::assertSame('Present', $fresh->getPhotoshopIRB()?->getIPTC()[IPTCTags::ObjectName]);
	}
}
