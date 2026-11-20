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

	// ---- save(): the file is replaced whole or left as it was ----

	/**
	 * Runs a test against a file in a directory of its own, removed afterwards.
	 * @param callable(string, string): void $test Given the file's path and its directory.
	 */
	private function withSavedFile(callable $test): void
	{
		$dir = sys_get_temp_dir() . '/save-' . bin2hex(random_bytes(6));
		mkdir($dir);
		$path = $dir . '/picture.jpg';
		file_put_contents($path, $this->jpegBytes());
		try {
			$test($path, $dir);
		} finally {
			foreach (array_diff((array) scandir($dir), ['.', '..']) as $name) {
				@unlink($dir . '/' . $name);
			}
			@rmdir($dir);
		}
	}

	private function editedJpeg(string $path): JPEGImage
	{
		$jpeg = JPEGImage::fromFile($path);
		$jpeg->setEXIF($this->sampleExif());
		return $jpeg;
	}

	public function testSaveReplacesTheFileAndKeepsItsPermissions()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			chmod($path, 0o600);
			$before = fileinode($path);
			$jpeg = $this->editedJpeg($path);

			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($path));
			clearstatcache();
			// A new file took the old one's name, with its permissions, and nothing else
			// is left beside it.
			self::assertNotSame($before, fileinode($path));
			self::assertSame(0o600, fileperms($path) & 0o777);
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($path)));
			self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
		});
	}

	public function testAWriteThatStopsShortLeavesTheOriginalAsItWas()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$original = (string) file_get_contents($path);
			$jpeg = TFullDiskJpeg::fromString($original);
			$jpeg->setEXIF($this->sampleExif());
			try {
				$jpeg->save($path);
				self::fail('a short write was reported as a save');
			} catch (\RuntimeException $e) {
				self::assertStringContainsString('cannot be written', $e->getMessage());
			}
			self::assertSame(bin2hex($original), bin2hex((string) file_get_contents($path)));
			self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
		});
	}

	public function testSaveFollowsALinkToTheFileItNames()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$link = $dir . '/link.jpg';
			symlink($path, $link);
			$jpeg = $this->editedJpeg($path);
			$jpeg->save($link);
			clearstatcache();
			self::assertTrue(is_link($link));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($path)));
		});
	}

	public function testSaveThroughALinkToNothingWritesTheFileItNames()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$link = $dir . '/link.jpg';
			symlink($dir . '/absent.jpg', $link);
			$jpeg = $this->editedJpeg($path);
			$jpeg->save($link);
			clearstatcache();
			self::assertTrue(is_link($link));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($dir . '/absent.jpg')));
		});
	}

	/**
	 * Asserts that a save is refused as one that cannot replace the file, and that the
	 * file and its directory are as they were.
	 * @param JPEGImage $jpeg
	 * @param string $path
	 * @param string $dir
	 * @param string $why
	 */
	private function assertNotReplaced(JPEGImage $jpeg, string $path, string $dir, string $why): void
	{
		$original = (string) file_get_contents($path);
		$before = fileinode($path);
		try {
			$jpeg->save($path);
			self::fail('a file that could not be replaced was reported as saved');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString($why, $e->getMessage());
			self::assertStringContainsString('writeTo()', $e->getMessage());
		}
		clearstatcache();
		self::assertSame($before, fileinode($path));   // the same file, never written over in place
		self::assertSame(bin2hex($original), bin2hex((string) file_get_contents($path)));
		self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
	}

	public function testSaveThrowsWhenTheRenameIsRefusedAndLeavesTheFileAsItWas()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$jpeg = TNoRenameJpeg::fromString((string) file_get_contents($path));
			$jpeg->setEXIF($this->sampleExif());
			$this->assertNotReplaced($jpeg, $path, $dir, 'renamed over it');
		});
	}

	public function testSaveThrowsWhenTheDirectoryTakesNoTemporaryFile()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			// The file itself can be written, which is what a write in place would have
			// used; its directory takes no new file, so there is nothing to rename.
			chmod($dir, 0o555);
			try {
				if (@touch($dir . '/probe')) {
					self::markTestSkipped('this user writes to a read-only directory');
				}
				$this->assertNotReplaced($this->editedJpeg($path), $path, $dir, 'temporary file');
			} finally {
				chmod($dir, 0o755);
			}
		});
	}

	public function testSaveIntoADirectoryThatDoesNotExistThrows()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			try {
				$this->editedJpeg($path)->save($dir . '/absent/picture.jpg');
				self::fail('a file was saved into a directory that does not exist');
			} catch (\RuntimeException $e) {
				self::assertStringContainsString('temporary file', $e->getMessage());
			}
			self::assertFalse(is_dir($dir . '/absent'));
		});
	}

	public function testSaveToANewFileWritesIt()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$jpeg = $this->editedJpeg($path);
			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($dir . '/copy.jpg'));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($dir . '/copy.jpg')));
			self::assertSame(['copy.jpg', 'picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
		});
	}

	public function testSaveOverAFileWhoseNameIsAsLongAsANameMayBe()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			// The temporary file is not named after the target, so there is room for it.
			$long = $dir . '/' . str_repeat('n', 251) . '.jpg';
			copy($path, $long);
			$jpeg = $this->editedJpeg($long);
			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($long));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($long)));
		});
	}

	public function testSaveOverSomethingThatIsNotAFileIsNotARename()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$jpeg = $this->editedJpeg($path);
			// A directory is not a file to replace: the plain write is tried and refuses it.
			try {
				$jpeg->save($dir);
				self::fail('a directory was written over');
			} catch (\RuntimeException $e) {
				self::assertTrue(is_dir($dir));
				self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
			}
			// A stream wrapper has no rename to rely on and is written directly.
			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save('php://memory'));
		});
	}
}

/**
 * A JPEG on a disk that fills half-way through a write.
 */
class TFullDiskJpeg extends JPEGImage
{
	protected function writeHandle($handle, string $bytes): false|int
	{
		return fwrite($handle, substr($bytes, 0, intdiv(strlen($bytes), 2)));
	}
}

/**
 * A JPEG whose temporary file is gone by the time it is to be renamed, as when the
 * directory refuses the rename.
 */
class TNoRenameJpeg extends JPEGImage
{
	protected function writeHandle($handle, string $bytes): false|int
	{
		$written = fwrite($handle, $bytes);
		unlink(stream_get_meta_data($handle)['uri']);
		return $written;
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
