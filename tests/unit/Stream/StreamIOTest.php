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
}
