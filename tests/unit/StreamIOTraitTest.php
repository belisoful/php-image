<?php

use Belisoful\Image\Meta\EXIF;
use Belisoful\Image\Meta\IPTC;
use Belisoful\Image\Meta\IPTCTags;
use Belisoful\Image\Meta\PictureInfo;
use Belisoful\Image\Meta\PrintIM;
use Belisoful\Image\Meta\XMP;
use Belisoful\Image\TIFF\TIFFDataType;
use Belisoful\Image\TIFF\TIFFDocument;
use Belisoful\Image\JPEGImage;
use Belisoful\Image\PhotoshopIRB;
use Belisoful\Image\PhotoshopResource;
use Belisoful\Image\TIFFImage;
use Psr\Http\Message\StreamInterface;

class StreamIOTraitTest extends PHPUnit\Framework\TestCase
{
	private function jpegBytes(): string
	{
		$im = imagecreatetruecolor(12, 9);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	private function sampleExif(): EXIF
	{
		$exif = new EXIF();
		$exif->setValueByName('Make', 'PradoCam');
		$exif->getExifIfd(true)->setTagValues(33434, TIFFDataType::URational, [[1, 250]]);
		return $exif;
	}

	public function testJpegFromStreamVariants()
	{
		$bytes = $this->jpegBytes();

		// A foreign PSR-7 stream and a raw PHP resource parse alike.
		$fromTStream = JPEGImage::fromStream(new TestPsr7Stream($bytes));
		$fromBinary = JPEGImage::fromStream(new TestPsr7Stream($bytes));
		$resource = fopen('php://memory', 'w+b');
		fwrite($resource, $bytes);
		$fromResource = JPEGImage::fromStream($resource);
		fclose($resource);

		foreach ([$fromTStream, $fromBinary, $fromResource] as $jpeg) {
			self::assertSame(12, $jpeg->getWidth());
			self::assertSame(9, $jpeg->getHeight());
		}
	}

	public function testJpegWriteToStreamAndResource()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$expected = $jpeg->toBinary();

		$stream = new TestPsr7Stream('');
		self::assertSame(strlen($expected), $jpeg->writeTo($stream));
		$stream->rewind();
		self::assertSame(bin2hex($expected), bin2hex($stream->getContents()));

		$resource = fopen('php://memory', 'w+b');
		self::assertSame(strlen($expected), $jpeg->writeTo($resource));
		rewind($resource);
		self::assertSame(bin2hex($expected), bin2hex(stream_get_contents($resource)));
		fclose($resource);
	}

	public function testTiffRoundTripThroughStreams()
	{
		$exif = $this->sampleExif();
		$tiff = TIFFImage::fromString($exif->getTiff()->toBinary());

		$stream = new TestPsr7Stream();
		$tiff->writeTo($stream);
		$stream->rewind();
		$reparsed = TIFFImage::fromStream($stream);
		self::assertSame('PradoCam', $reparsed->getEXIF()->getMake());
	}

	public function testTiffDocumentWindowedStream()
	{
		// The document reads from the stream's current position, so positioning it
		// inside a larger buffer scopes the parse.
		$tiffBytes = $this->sampleExif()->getTiff()->toBinary();
		$buffer = 'JUNKPREFIX--' . $tiffBytes . '--TRAILING';
		$window = new TestPsr7Stream($buffer);
		$window->seek(12);

		$document = TIFFDocument::fromStream($window);
		self::assertSame('PradoCam', $document->getIfd(0)->getTagValue(271));
	}

	public function testExifFromStreamAutoDetectsForm()
	{
		$exif = $this->sampleExif();

		$segment = EXIF::fromStream(new TestPsr7Stream($exif->toBinary()));   // Exif signature
		self::assertSame('PradoCam', $segment->getMake());

		$bare = EXIF::fromStream(new TestPsr7Stream($exif->getTiff()->toBinary()));   // bare TIFF
		self::assertSame('PradoCam', $bare->getMake());

		$written = new TestPsr7Stream('');
		$exif->writeTo($written);
		$written->rewind();
		self::assertSame('PradoCam', EXIF::fromStream($written)->getMake());
	}

	public function testMetadataClassesStreamRoundTrips()
	{
		$irb = new PhotoshopIRB();
		$irb->setResource(new PhotoshopResource(PhotoshopResource::Url, 'https://stream.example'));
		$stream = new TestPsr7Stream('');
		$irb->writeTo($stream);
		$stream->rewind();
		$reIrb = PhotoshopIRB::fromStream($stream);
		self::assertSame('https://stream.example', $reIrb->getResource(PhotoshopResource::Url)->decodeText());

		$xmp = XMP::blank();
		$xmp->setTitle('Streamed');
		$stream = new TestPsr7Stream('');
		$xmp->writeTo($stream);
		$stream->rewind();
		self::assertSame('Streamed', XMP::fromStream($stream)->getTitle());

		$pim = new PrintIM();
		$pim->setEntry(0x0009, 7);
		$stream = new TestPsr7Stream('');
		$pim->writeTo($stream);
		$stream->rewind();
		self::assertSame(7, PrintIM::fromStream($stream)->getEntryValue(0x0009));

		$info = new PictureInfo();
		$info->setHeader('[picture info]');
		$info->setText("\r\nMode=Fine\r\n[end]");
		$stream = new TestPsr7Stream('');
		$info->writeTo($stream);
		$stream->rewind();
		self::assertSame(['Mode' => 'Fine'], PictureInfo::fromStream($stream)->getFields());

		$iptc = new IPTC();
		$iptc[IPTCTags::ObjectName] = 'Stream Title';
		$stream = new TestPsr7Stream('');
		$iptc->writeTo($stream);
		$stream->rewind();
		$reIptc = IPTC::parse($stream);
		self::assertSame('Stream Title', $reIptc[IPTCTags::ObjectName]);
	}

	public function testForeignPsr7StreamAsWriteTarget()
	{
		// Any PSR-7 stream serves as a write target.
		$exif = $this->sampleExif();
		$inner = new TestPsr7Stream();
		$binary = $inner;
		$exif->writeTo($binary);
		$inner->rewind();
		self::assertSame('PradoCam', EXIF::fromStream($inner)->getMake());
	}

	public function testWriteToHonorsPartialWritesAndStalls()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		$expected = $jpeg->toBinary();

		// A stream taking only a few bytes per call is written in as many calls as it
		// takes, and the bytes arrive in order.
		$trickle = new TChunkedWriteStream(5);
		self::assertSame(strlen($expected), $jpeg->writeTo($trickle));
		self::assertSame(bin2hex($expected), bin2hex($trickle->buffer));
		self::assertSame((int) ceil(strlen($expected) / 5), $trickle->writes);

		// A stream that stops accepting bytes raises rather than looping forever.
		$stalled = new TChunkedWriteStream(0);
		try {
			$jpeg->writeTo($stalled);
			self::fail('writeTo accepted a stream that never wrote');
		} catch (\RuntimeException $e) {
			self::assertInstanceOf(\RuntimeException::class, $e);
		}
	}

	public function testInvalidTargetsAndSources()
	{
		$jpeg = JPEGImage::fromString($this->jpegBytes());
		try {
			$jpeg->writeTo('a plain string is not a write target');
			self::fail('writeTo accepted a string target');
		} catch (\InvalidArgumentException $e) {
			self::assertInstanceOf(\InvalidArgumentException::class, $e);
		}

		self::expectException(\InvalidArgumentException::class);
		TIFFDocument::fromStream(12345);
	}
}

/**
 * A write target that accepts at most a fixed number of bytes per call, so the
 * partial-write loop of StreamIOTrait::writeTo() can be observed; a chunk of zero is a
 * stream that never accepts anything.
 */
class TChunkedWriteStream implements StreamInterface
{
	public string $buffer = '';

	public int $writes = 0;

	public function __construct(private int $chunk)
	{
	}

	public function write(string $string): int
	{
		$this->writes++;
		$bytes = substr($string, 0, $this->chunk);
		$this->buffer .= $bytes;
		return strlen($bytes);
	}

	public function __toString(): string
	{
		return $this->buffer;
	}

	public function close(): void
	{
	}

	public function detach()
	{
		return null;
	}

	public function getSize(): ?int
	{
		return strlen($this->buffer);
	}

	public function tell(): int
	{
		return strlen($this->buffer);
	}

	public function eof(): bool
	{
		return true;
	}

	public function isSeekable(): bool
	{
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new RuntimeException('not seekable');
	}

	public function rewind(): void
	{
		throw new RuntimeException('not seekable');
	}

	public function isWritable(): bool
	{
		return true;
	}

	public function isReadable(): bool
	{
		return false;
	}

	public function read(int $length): string
	{
		throw new RuntimeException('not readable');
	}

	public function getContents(): string
	{
		return $this->buffer;
	}

	public function getMetadata(?string $key = null)
	{
		return $key === null ? [] : null;
	}
}
