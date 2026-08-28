<?php

use Belisoful\Image\Stream\BinaryReader;

/**
 * Unit tests for {@see \Belisoful\Image\Stream\BinaryReader}, the offset-driven typed
 * reader the lazy metadata scan uses.  Every case runs against both a foreign PSR-7
 * stream and a raw resource, because the point of the reader is that a scan need not care
 * which it holds.
 */
class BinaryReaderTest extends PHPUnit\Framework\TestCase
{
	protected function tearDown(): void
	{
		TestIOHelper::removeTempFiles();
	}

	/**
	 * The same bytes as a foreign PSR-7 stream and as a raw resource.
	 * @param string $bytes
	 */
	public static function bothKinds(string $bytes = ''): array
	{
		return ['psr7' => new TestPsr7Stream($bytes), 'resource' => TestIOHelper::dataResource($bytes)];
	}

	public function testRejectsANonStreamSource()
	{
		self::expectException(\InvalidArgumentException::class);
		new BinaryReader('a string is not a stream');
	}

	public function testSeekTellAndSeekability()
	{
		foreach (self::bothKinds('abcdefgh') as $kind => $source) {
			$reader = new BinaryReader($source);
			self::assertTrue($reader->isSeekable(), $kind);
			self::assertSame(0, $reader->tell(), $kind);
			$reader->seek(3);
			self::assertSame(3, $reader->tell(), $kind);
			$reader->seek(2, SEEK_CUR);
			self::assertSame(5, $reader->tell(), $kind);
		}
	}

	public function testNonSeekableSourceReportsSo()
	{
		self::assertFalse((new BinaryReader(new TestNonSeekableStream('abc')))->isSeekable());
	}

	public function testSeekThrowsOnANonSeekableResource()
	{
		// A pipe is a non-seekable resource, so fseek() fails deterministically on every PHP
		// version.  (A past-end seek on php://memory used to fail too, but PHP 8.3 made it
		// succeed, so that path is not a reliable way to reach this guard.)
		$pipe = TestIOHelper::pipeResource('some bytes');
		$reader = new BinaryReader($pipe);
		self::assertFalse($reader->isSeekable());
		try {
			$reader->seek(2);
			self::fail('seek() must throw on a non-seekable resource.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('seek', $e->getMessage());
		}
		TestIOHelper::closeAny($pipe);
	}

	public function testReadBytesReadsExactly()
	{
		foreach (self::bothKinds('abcdefgh') as $kind => $source) {
			$reader = new BinaryReader($source);
			self::assertSame('abc', $reader->readBytes(3), $kind);
			self::assertSame('de', $reader->readBytes(2), $kind);
			self::assertSame('', $reader->readBytes(0), $kind, 'a non-positive length reads nothing');
		}
	}

	public function testReadBytesThrowsWhenTheSourceEndsEarly()
	{
		foreach (self::bothKinds('abc') as $kind => $source) {
			$reader = new BinaryReader($source);
			try {
				$reader->readBytes(10);
				self::fail("short read must throw ($kind)");
			} catch (\RuntimeException $e) {
				self::assertStringContainsString('Unexpected end of stream', $e->getMessage(), $kind);
			}
		}
	}

	public function testTypedReadsHonourTheByteOrder()
	{
		foreach (self::bothKinds("\x01\x02\x03\x04\x05\x06") as $kind => $source) {
			$reader = new BinaryReader($source);
			$reader->setBigEndian(true);
			self::assertSame(0x0102, $reader->readUInt16(), $kind);
			self::assertSame(0x03040506, $reader->readUInt32(), $kind);
		}
		foreach (self::bothKinds("\x01\x02\x03\x04\x05\x06") as $kind => $source) {
			$reader = new BinaryReader($source);
			$reader->setBigEndian(false);
			self::assertSame(0x0201, $reader->readUInt16(), $kind);
			self::assertSame(0x06050403, $reader->readUInt32(), $kind);
		}
	}
}
