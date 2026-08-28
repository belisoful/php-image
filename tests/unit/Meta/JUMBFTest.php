<?php

use Belisoful\Image\Meta\JUMBF\JUMBFBox;
use Belisoful\Image\Meta\JUMBF\JUMBFDescription;
use Belisoful\Image\JPEGImage;

class JUMBFTest extends PHPUnit\Framework\TestCase
{
	private function jpegBytes(): string
	{
		$im = imagecreatetruecolor(12, 9);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testDescriptionRoundTripAndUuids()
	{
		// The reserved content-type UUID pattern: type bytes then the fixed suffix.
		self::assertSame("Exif\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71", JUMBFDescription::ExifUuid);
		self::assertSame(JUMBFDescription::XmlUuid, JUMBFDescription::typeUuid('xml '));
		self::assertSame(JUMBFDescription::JsonUuid, JUMBFDescription::typeUuid('json'));

		$description = new JUMBFDescription(JUMBFDescription::ExifUuid, 'exif-annotation');
		// Label present and requestable, per the spec's 3.H flag group.
		self::assertSame(0x03, $description->getToggles());

		$reparsed = JUMBFDescription::parse($description->toBinary());
		self::assertNotFalse($reparsed);
		self::assertSame(JUMBFDescription::ExifUuid, $reparsed->getUuid());
		self::assertSame('Exif', $reparsed->getUuidType());
		self::assertSame('exif-annotation', $reparsed->getLabel());
		self::assertNull($reparsed->getId());
		self::assertNull($reparsed->getSignature());

		// Optional id and signature toggle on and round-trip.
		$description->setId(42);
		$description->setSignature(str_repeat("\xC3", 32));
		self::assertSame(0x0F, $description->getToggles());
		$full = JUMBFDescription::parse($description->toBinary());
		self::assertSame(42, $full->getId());
		self::assertSame(str_repeat("\xC3", 32), $full->getSignature());

		$description->setLabel(null);
		self::assertNull(JUMBFDescription::parse($description->toBinary())->getLabel());
		self::assertFalse(JUMBFDescription::parse('too short'));

		$description->setUuid(JUMBFDescription::JsonUuid);
		self::assertSame('json', $description->getUuidType());
		$description->setToggles(JUMBFDescription::RequestableToggle);
		self::assertSame(JUMBFDescription::RequestableToggle, $description->getToggles());
		self::assertNull((new JUMBFDescription(str_repeat("\x11", 16)))->getUuidType());
	}

	public function testBoxMutators()
	{
		$box = new JUMBFBox(JUMBFBox::SuperBox);
		self::assertSame([], $box->getChildren());
		$box->addChild(new JUMBFBox(JUMBFBox::DescriptionBox, (new JUMBFDescription(JUMBFDescription::XmlUuid, 'l'))->toBinary()));
		$content = new JUMBFBox(JUMBFBox::CborBox, 'raw');
		$content->setType(JUMBFBox::XmlBox);
		$box->addChild($content);
		self::assertCount(2, $box->getChildren());
		self::assertSame(JUMBFBox::XmlBox, $box->getContentType());
		self::assertSame('raw', JUMBFBox::parse($box->toBinary())->getContentData());
	}

	public function testBoxStructureRoundTrip()
	{
		$box = JUMBFBox::xml('exif-annotation', '<rdf:RDF>annotation</rdf:RDF>');
		$bytes = $box->toBinary();

		// LBox/TBox framing: 'jumb' superbox whose first child is the 'jumd' description.
		self::assertSame(strlen($bytes), unpack('N', substr($bytes, 0, 4))[1]);
		self::assertSame(JUMBFBox::SuperBox, substr($bytes, 4, 4));
		self::assertSame(JUMBFBox::DescriptionBox, substr($bytes, 12, 4));

		$reparsed = JUMBFBox::parse($bytes);
		self::assertNotFalse($reparsed);
		self::assertTrue($reparsed->getIsSuperBox());
		self::assertSame('exif-annotation', $reparsed->getLabel());
		self::assertSame(JUMBFBox::XmlBox, $reparsed->getContentType());
		self::assertSame('<rdf:RDF>annotation</rdf:RDF>', $reparsed->getContentData());
		self::assertCount(1, $reparsed->getContentBoxes());
		self::assertSame(bin2hex($bytes), bin2hex($reparsed->toBinary()));
	}

	public function testJsonAndExifAnnotationBuilders()
	{
		$json = JUMBFBox::json('ld', '{"@context":"x"}');
		self::assertSame(JUMBFBox::JsonBox, $json->getContentType());
		self::assertSame(JUMBFDescription::JsonUuid, $json->getDescription()->getUuid());

		$exif = JUMBFBox::exifAnnotation('exif-note', '{"a":1}', JUMBFBox::JsonBox);
		self::assertSame(JUMBFDescription::ExifUuid, $exif->getDescription()->getUuid());
		self::assertSame('{"a":1}', $exif->getContentData());
	}

	public function testNestedSuperBoxesAndSequences()
	{
		$inner = JUMBFBox::xml('inner', '<a/>');
		$outer = JUMBFBox::superBox(new JUMBFDescription(JUMBFDescription::typeUuid('jumb'), 'outer'), [$inner]);

		$reparsed = JUMBFBox::parse($outer->toBinary());
		self::assertSame('outer', $reparsed->getLabel());
		$nested = $reparsed->getContentBoxes()[0];
		self::assertTrue($nested->getIsSuperBox());
		self::assertSame('inner', $nested->getLabel());
		self::assertSame('<a/>', $nested->getContentData());

		// Several boxes in sequence parse as a list.
		$sequence = $inner->toBinary() . JUMBFBox::json('two', '{}')->toBinary();
		$boxes = JUMBFBox::parseBoxes($sequence);
		self::assertCount(2, $boxes);
		self::assertSame(['inner', 'two'], array_map(fn ($b) => $b->getLabel(), $boxes));
	}

	public function testMalformedBoxesAreTolerated()
	{
		self::assertSame([], JUMBFBox::parseBoxes('tiny'));
		self::assertFalse(JUMBFBox::parse(''));
		// A length running past the data stops the walk rather than throwing.
		self::assertSame([], JUMBFBox::parseBoxes(pack('N', 9999) . 'jumb' . 'short'));
		// A zero length means "to the end of the data".
		$open = JUMBFBox::parseBoxes(pack('N', 0) . 'json' . '{"a":1}');
		self::assertCount(1, $open);
		self::assertSame('{"a":1}', $open[0]->getPayload());
	}

	public function testExtendedLengthBoxes()
	{
		// LBox == 1 moves the length into the 64-bit XLBox that follows the type.
		$payload = '{"big":true}';
		$bytes = pack('N', 1) . JUMBFBox::JsonBox . pack('NN', 0, 16 + strlen($payload)) . $payload;

		$boxes = JUMBFBox::parseBoxes($bytes);
		self::assertCount(1, $boxes);
		self::assertSame(JUMBFBox::JsonBox, $boxes[0]->getType());
		self::assertSame($payload, $boxes[0]->getPayload());

		// A box announcing the extended length without room for it stops the walk.
		self::assertSame([], JUMBFBox::parseBoxes(pack('N', 1) . JUMBFBox::JsonBox . pack('N', 0)));
		// And an extended length running past the data is rejected like a plain one.
		self::assertSame([], JUMBFBox::parseBoxes(pack('N', 1) . JUMBFBox::JsonBox . pack('NN', 0, 9999) . $payload));
	}

	public function testDescriptionlessSuperBoxAndPlainBoxData()
	{
		// A superbox whose children carry no 'jumd' has no description to report.
		$box = new JUMBFBox(JUMBFBox::SuperBox, '', [new JUMBFBox(JUMBFBox::XmlBox, '<a/>')]);
		self::assertNull($box->getDescription());
		self::assertNull($box->getLabel());
		self::assertSame('<a/>', $box->getContentData());
		self::assertNull(JUMBFBox::parse($box->toBinary())->getLabel());

		// A content box outside a superbox answers with its own payload.
		$plain = new JUMBFBox(JUMBFBox::XmlBox, '<b/>');
		self::assertFalse($plain->getIsSuperBox());
		self::assertSame('<b/>', $plain->getContentData());

		// A superbox with no children at all has no description either.
		self::assertNull((new JUMBFBox(JUMBFBox::SuperBox))->getDescription());
	}

	public function testDescriptionTogglesFollowTheOptionalFields()
	{
		// A label, id, or signature set on a description raises its toggle; clearing one
		// lowers it, and the packed payload follows.
		$description = new JUMBFDescription(JUMBFDescription::XmlUuid);
		self::assertSame(JUMBFDescription::RequestableToggle, $description->getToggles());

		$description->setLabel('added-later');
		self::assertSame(
			JUMBFDescription::RequestableToggle | JUMBFDescription::LabelToggle,
			$description->getToggles(),
		);
		self::assertSame('added-later', JUMBFDescription::parse($description->toBinary())->getLabel());

		$description->setId(7);
		$description->setSignature(str_repeat("\x5A", 32));
		self::assertSame(0x0F, $description->getToggles());

		$description->setId(null);
		self::assertSame(0x0B, $description->getToggles());
		$description->setSignature(null);
		self::assertSame(0x03, $description->getToggles());

		$reparsed = JUMBFDescription::parse($description->toBinary());
		self::assertSame('added-later', $reparsed->getLabel());
		self::assertNull($reparsed->getId());
		self::assertNull($reparsed->getSignature());
	}

	public function testStreamIo()
	{
		$box = JUMBFBox::xml('streamed', '<x/>');
		$stream = new TestPsr7Stream('');
		$box->writeTo($stream);
		$stream->rewind();
		self::assertSame('streamed', JUMBFBox::fromStream($stream)->getLabel());
	}

	public function testJpegApp11RoundTrip()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		self::assertSame([], $jpeg->getJumbfBoxes());

		$jpeg->setJumbfBoxes([
			JUMBFBox::exifAnnotation('exif-annotation', '<rdf:RDF>first</rdf:RDF>'),
			JUMBFBox::json('second-label', '{"n":2}'),
		]);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		$boxes = $reparsed->getJumbfBoxes();
		self::assertCount(2, $boxes);
		self::assertSame('exif-annotation', $boxes[0]->getLabel());
		self::assertSame('<rdf:RDF>first</rdf:RDF>', $boxes[0]->getContentData());
		self::assertSame('{"n":2}', $boxes[1]->getContentData());
		self::assertSame('{"n":2}', $reparsed->getJumbfBox('second-label')->getContentData());
		self::assertNull($reparsed->getJumbfBox('no-such-label'));

		// The segments carry the 'JP' identifier and are true APP11 markers.
		$found = false;
		foreach ($reparsed->getSegments() as $segment) {
			if ($segment['marker'] === JPEGImage::APP11) {
				$found = true;
			}
		}
		self::assertTrue($found);

		$reparsed->setJumbfBoxes([]);
		self::assertSame([], JPEGImage::fromString($reparsed->toBinary())->getJumbfBoxes());
	}

	public function testJpegApp11SplitsAndReassemblesLargeBoxes()
	{
		// A box larger than one 64 KB segment must fragment and rejoin byte-perfectly.
		$payload = str_repeat('<t>x</t>', 20000);   // 160 KB
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$jpeg->setJumbfBoxes([JUMBFBox::xml('big', $payload)]);
		$bytes = $jpeg->toBinary();

		$segments = 0;
		foreach (JPEGImage::fromString($bytes)->getSegments() as $segment) {
			if ($segment['marker'] === JPEGImage::APP11) {
				$segments++;
			}
		}
		self::assertSame(1, $segments);   // one recorded 'jumbf' segment kind

		$reparsed = JPEGImage::fromString($bytes);
		$boxes = $reparsed->getJumbfBoxes();
		self::assertCount(1, $boxes);
		self::assertSame('big', $boxes[0]->getLabel());
		self::assertSame(strlen($payload), strlen((string) $boxes[0]->getContentData()));
		self::assertSame(md5($payload), md5((string) $boxes[0]->getContentData()));

		// The composed file really did use several APP11 markers.
		self::assertGreaterThan(2, substr_count($bytes, "\xFF\xEB"));
	}

	public function testJpegKeepsOtherMetadataAlongsideJumbf()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$exif = new Belisoful\Image\Meta\EXIF();
		$exif->setValueByName('Make', 'BoxCam');
		$jpeg->setEXIF($exif);
		$jpeg->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
		$jpeg->setJumbfBoxes([JUMBFBox::xml('note', '<n/>')]);

		$reparsed = JPEGImage::fromString($jpeg->toBinary());
		self::assertSame('BoxCam', $reparsed->getEXIF()->getMake());
		self::assertNotNull($reparsed->getXmpText());
		self::assertSame('<n/>', $reparsed->getJumbfBoxes()[0]->getContentData());
		self::assertSame(12, $reparsed->getWidth());
	}

	public function testSuperBoxWithOnlyADescriptionHasNoContent()
	{
		// A superbox is allowed to carry a description and nothing else.  Asking such a
		// box for its content must answer null, not reach past the end of an empty list.
		$description = new JUMBFBox();
		$description->setType(JUMBFBox::DescriptionBox);
		$description->setPayload('json' . "\x03\x00" . "label\x00");
		$box = new JUMBFBox();
		$box->setType('jumb');
		$box->setChildren([$description]);

		self::assertTrue($box->getIsSuperBox());
		self::assertSame([], $box->getContentBoxes());
		self::assertNull($box->getContentType(), 'no content box, so no content type');
		self::assertNull($box->getContentData(), 'no content box, so no content data');

		// and it survives a round trip in that shape
		$bytes = $box->toBinary();
		$parsed = JUMBFBox::parse($bytes);
		self::assertNotFalse($parsed);
		self::assertNull($parsed->getContentType());
		self::assertNull($parsed->getContentData());
	}

	public function testConstructorDerivesTogglesFromTheOptionalArguments()
	{
		// Handed the optional fields but no toggles, the constructor derives the byte
		// from what it was given -- each field raising only its own bit.
		$id = new JUMBFDescription(JUMBFDescription::JsonUuid, null, null, 0x12345678);
		self::assertSame(
			JUMBFDescription::RequestableToggle | JUMBFDescription::IdToggle,
			$id->getToggles(),
		);

		$signature = new JUMBFDescription(JUMBFDescription::JsonUuid, null, null, null, str_repeat("\x7E", 32));
		self::assertSame(
			JUMBFDescription::RequestableToggle | JUMBFDescription::SignatureToggle,
			$signature->getToggles(),
		);

		// All three together, and the derived toggles really drive what is packed.
		$full = new JUMBFDescription(JUMBFDescription::XmlUuid, 'ctor-label', null, 0x12345678, str_repeat("\x7E", 32));
		self::assertSame(0x0F, $full->getToggles());

		$reparsed = JUMBFDescription::parse($full->toBinary());
		self::assertNotFalse($reparsed);
		self::assertSame('ctor-label', $reparsed->getLabel());
		self::assertSame(0x12345678, $reparsed->getId());
		self::assertSame(str_repeat("\x7E", 32), $reparsed->getSignature());

		// An explicit toggles byte is used as given, whatever the other arguments say.
		$explicit = new JUMBFDescription(JUMBFDescription::XmlUuid, 'quiet', 0x01, 9, str_repeat("\x01", 32));
		self::assertSame(0x01, $explicit->getToggles());
		self::assertSame(JUMBFDescription::XmlUuid . "\x01", $explicit->toBinary());
	}

	public function testUnterminatedLabelRunsToTheEndOfThePayload()
	{
		// A writer that left the label's NUL off: the label is everything that remains,
		// and the read position lands at the end, so the id its toggle promises has no
		// room left and is reported absent rather than read out of the label's bytes.
		$toggles = JUMBFDescription::RequestableToggle | JUMBFDescription::LabelToggle | JUMBFDescription::IdToggle;
		$payload = JUMBFDescription::XmlUuid . chr($toggles) . 'unterminated';

		$description = JUMBFDescription::parse($payload);
		self::assertNotFalse($description);
		self::assertSame('unterminated', $description->getLabel());
		self::assertNull($description->getId());
		self::assertNull($description->getSignature());

		// Composing it back supplies the terminator the payload was missing.
		self::assertSame(
			JUMBFDescription::XmlUuid . chr($toggles) . "unterminated\0",
			$description->toBinary(),
		);
	}

	public function testUnparsableDescriptionBoxLeavesTheBoxWithoutADescription()
	{
		// A 'jumd' child too short to hold the UUID and toggles is not a description:
		// the superbox reports none rather than a half-read one, and its content is
		// still readable.
		self::assertFalse(JUMBFDescription::parse('short'));

		$box = new JUMBFBox(JUMBFBox::SuperBox, '', [
			new JUMBFBox(JUMBFBox::DescriptionBox, 'short'),
			new JUMBFBox(JUMBFBox::XmlBox, '<a/>'),
		]);
		self::assertNull($box->getDescription());
		self::assertNull($box->getLabel());
		self::assertSame('<a/>', $box->getContentData());

		$parsed = JUMBFBox::parse($box->toBinary());
		self::assertNotFalse($parsed);
		self::assertNull($parsed->getDescription());
		self::assertSame('<a/>', $parsed->getContentData());
	}
}
