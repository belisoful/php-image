<?php

use Belisoful\Image\Stream\StreamIO;

/**
 * Unit tests for {@see \Belisoful\Image\Stream\StreamIO}, the static whole-string helpers
 * over a byte source that may be a PSR-7 stream, a raw resource, or (for reads) a string.
 */
class StreamIOTest extends PHPUnit\Framework\TestCase
{
	protected function tearDown(): void
	{
		TestIOHelper::removeTempFiles();
	}

	public function testReadAllAcceptsEveryForm()
	{
		self::assertSame('literal', StreamIO::readAll('literal'));
		self::assertSame('abcdef', StreamIO::readAll(new TestPsr7Stream('abcdef')));
		self::assertSame('abcdef', StreamIO::readAll(TestIOHelper::dataResource('abcdef')));
	}

	public function testReadAllReadsFromTheCurrentPosition()
	{
		$psr7 = new TestPsr7Stream('HEADERbody');
		$psr7->seek(6);
		self::assertSame('body', StreamIO::readAll($psr7), 'It drains from where the source is, not from 0.');
	}

	public function testReadAllRejectsAnythingElse()
	{
		self::expectException(\InvalidArgumentException::class);
		StreamIO::readAll([1, 2, 3]);
	}

	public function testWriteAllToBothKinds()
	{
		$psr7 = new TestPsr7Stream();
		self::assertSame(5, StreamIO::writeAll($psr7, 'hello'));
		$psr7->rewind();
		self::assertSame('hello', $psr7->getContents());

		$resource = TestIOHelper::memoryResource();
		self::assertSame(5, StreamIO::writeAll($resource, 'hello'));
		rewind($resource);
		self::assertSame('hello', stream_get_contents($resource));
		fclose($resource);
	}

	public function testWriteAllLoopsOverPartialWrites()
	{
		// A stream that accepts one byte at a time still receives the whole string.
		$scripted = new TestScriptedStream([], [1, 1, 1]);
		self::assertSame(3, StreamIO::writeAll($scripted, 'abc'));
		self::assertSame('abc', $scripted->written);
	}

	public function testWriteAllThrowsWhenTheTargetStopsAccepting()
	{
		self::expectException(\RuntimeException::class);
		StreamIO::writeAll(new TestScriptedStream([], [1, 0]), 'abc');
	}

	public function testWriteAllRejectsANonTarget()
	{
		self::expectException(\InvalidArgumentException::class);
		StreamIO::writeAll('not a target', 'abc');
	}

	public function testRewindReturnsASeekableSourceToTheStart()
	{
		$psr7 = new TestPsr7Stream('abcdef');
		$psr7->seek(4);
		StreamIO::rewind($psr7);
		self::assertSame(0, $psr7->tell());

		$resource = TestIOHelper::dataResource('abcdef');
		fseek($resource, 4);
		StreamIO::rewind($resource);
		self::assertSame(0, ftell($resource));
		fclose($resource);
	}

	public function testRewindLeavesANonSeekableSourceUntouched()
	{
		$nonseek = new TestNonSeekableStream('abc');
		$nonseek->read(1);   // advance so a rewind would be observable if it happened
		StreamIO::rewind($nonseek);   // must be a no-op, not throw
		self::assertSame('bc', $nonseek->getContents(), 'The position is unchanged.');

		StreamIO::rewind('a plain string');   // strings are left alone, no error
		self::assertTrue(true);
	}

	public function testTempReturnsARewoundResource()
	{
		$handle = StreamIO::temp('contents');
		self::assertIsResource($handle);
		self::assertSame(0, ftell($handle), 'positioned at the start');
		self::assertSame('contents', stream_get_contents($handle));
		fclose($handle);

		$empty = StreamIO::temp();
		self::assertSame('', stream_get_contents($empty));
		fclose($empty);
	}

	public function testCopyRangeTransfersAByteRangeBetweenResources()
	{
		$source = TestIOHelper::dataResource('HEADER' . 'PAYLOAD' . 'TRAILER');
		$target = TestIOHelper::memoryResource();
		self::assertSame(7, StreamIO::copyRange($source, 6, 7, $target));
		rewind($target);
		self::assertSame('PAYLOAD', stream_get_contents($target));
		fclose($source);
		fclose($target);
	}

	public function testCopyRangeCrossesChunkBoundaries()
	{
		$payload = str_repeat('abcdefghij', 500);   // 5000 bytes
		$source = TestIOHelper::dataResource('..' . $payload . '..');
		$target = new TestPsr7Stream();
		// A tiny chunk size forces many transfers; every byte must still arrive in order.
		self::assertSame(5000, StreamIO::copyRange($source, 2, 5000, $target, 7));
		$target->rewind();
		self::assertSame($payload, $target->getContents());
		fclose($source);
	}

	public function testCopyRangeReadsFromAPsr7StreamSource()
	{
		$source = new TestPsr7Stream('0123456789');
		$target = TestIOHelper::memoryResource();
		self::assertSame(4, StreamIO::copyRange($source, 3, 4, $target));
		rewind($target);
		self::assertSame('3456', stream_get_contents($target));
		fclose($target);
	}

	public function testCopyRangeRejectsANonSeekableStreamSource()
	{
		self::expectException(\InvalidArgumentException::class);
		StreamIO::copyRange(new TestNonSeekableStream('abcdef'), 0, 3, TestIOHelper::memoryResource());
	}

	public function testCopyRangeRejectsANegativeLength()
	{
		self::expectException(\InvalidArgumentException::class);
		StreamIO::copyRange(TestIOHelper::dataResource('abc'), 0, -1, TestIOHelper::memoryResource());
	}

	public function testCopyRangeRejectsANonPositiveChunkSize()
	{
		self::expectException(\InvalidArgumentException::class);
		StreamIO::copyRange(TestIOHelper::dataResource('abc'), 0, 3, TestIOHelper::memoryResource(), 0);
	}

	public function testCopyRangeRejectsANonStreamSource()
	{
		self::expectException(\InvalidArgumentException::class);
		StreamIO::copyRange('a plain string', 0, 3, TestIOHelper::memoryResource());
	}

	public function testCopyRangeThrowsWhenTheSourceEndsBeforeTheRange()
	{
		self::expectException(\RuntimeException::class);
		StreamIO::copyRange(TestIOHelper::dataResource('short'), 0, 999, TestIOHelper::memoryResource());
	}

	public function testCopyRangeThrowsWhenAResourceCannotSeek()
	{
		// A pipe is a readable but non-seekable resource, so fseek fails.
		$pipe = popen('printf abcdef', 'r');
		self::assertIsResource($pipe);
		try {
			StreamIO::copyRange($pipe, 2, 2, TestIOHelper::memoryResource());
			self::fail('A non-seekable resource should be rejected.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('cannot seek', $e->getMessage());
		} finally {
			pclose($pipe);
		}
	}
}
