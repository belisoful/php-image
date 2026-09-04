<?php

use Belisoful\Image\Stream\SourceRange;

/**
 * Unit tests for {@see \Belisoful\Image\Stream\SourceRange}, a deferred `[offset, length]`
 * window into a still-open source stream that copies straight to a target.
 */
class SourceRangeTest extends PHPUnit\Framework\TestCase
{
	public function testWriteToCopiesTheWindowToATarget()
	{
		$source = TestIOHelper::dataResource('HEADER' . 'PAYLOAD' . 'TRAILER');
		$range = new SourceRange($source, 6, 7);
		self::assertSame(7, $range->getLength());

		$target = TestIOHelper::memoryResource();
		self::assertSame(7, $range->writeTo($target));
		rewind($target);
		self::assertSame('PAYLOAD', stream_get_contents($target));
		fclose($source);
		fclose($target);
	}

	public function testReadMaterializesTheWindow()
	{
		$source = TestIOHelper::dataResource('....abcXYZ....');
		$range = new SourceRange($source, 7, 3);
		self::assertSame('XYZ', $range->read());
		fclose($source);
	}
}
