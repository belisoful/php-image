<?php

/**
 * TestPsr7Stream class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

use Psr\Http\Message\StreamInterface;

/**
 * A minimal, string-backed PSR-7 stream for the tests.
 *
 * The library consumes {@see StreamInterface} but no longer implements one, so its tests
 * hand it a stream of foreign make.  This double stands in for any third-party PSR-7
 * implementation a caller might use, and {@see TestNonSeekableStream} and
 * {@see TestBoundedSeekStream} narrow it to the awkward cases the scan path must survive.
 */
class TestPsr7Stream implements StreamInterface
{
	/** @var string The backing bytes. */
	protected string $_data;

	/** @var int The read/write position. */
	protected int $_pos = 0;

	public function __construct(string $data = '')
	{
		$this->_data = $data;
	}

	public function __toString(): string
	{
		$this->_pos = 0;
		return $this->getContents();
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
		return strlen($this->_data);
	}

	public function tell(): int
	{
		return $this->_pos;
	}

	public function eof(): bool
	{
		return $this->_pos >= strlen($this->_data);
	}

	public function isSeekable(): bool
	{
		return true;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		$target = match ($whence) {
			SEEK_CUR => $this->_pos + $offset,
			SEEK_END => strlen($this->_data) + $offset,
			default => $offset,
		};
		if ($target < 0) {
			throw new \RuntimeException('Cannot seek before the start of the stream');
		}
		$this->_pos = $target;
	}

	public function rewind(): void
	{
		$this->seek(0);
	}

	public function isWritable(): bool
	{
		return true;
	}

	public function write(string $string): int
	{
		$this->_data = substr_replace($this->_data, $string, $this->_pos, strlen($string));
		$this->_pos += strlen($string);
		return strlen($string);
	}

	public function isReadable(): bool
	{
		return true;
	}

	public function read(int $length): string
	{
		if ($length < 1 || $this->eof()) {
			return '';
		}
		$chunk = substr($this->_data, $this->_pos, $length);
		$this->_pos += strlen($chunk);
		return $chunk;
	}

	public function getContents(): string
	{
		$rest = substr($this->_data, $this->_pos);
		$this->_pos = strlen($this->_data);
		return $rest;
	}

	public function getMetadata(?string $key = null): mixed
	{
		$meta = ['seekable' => $this->isSeekable(), 'mode' => 'r+b'];
		return $key === null ? $meta : ($meta[$key] ?? null);
	}
}

/**
 * A PSR-7 stream that reports itself unseekable and refuses every seek.
 */
class TestNonSeekableStream extends TestPsr7Stream
{
	public function isSeekable(): bool
	{
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new \RuntimeException('Cannot seek a non-seekable stream');
	}

	public function rewind(): void
	{
		throw new \RuntimeException('Cannot seek a non-seekable stream');
	}
}

/**
 * A seekable PSR-7 stream that refuses an absolute seek past its end, reporting the
 * failure the PSR-7 way — a \RuntimeException raised by the stream itself.
 */
class TestBoundedSeekStream extends TestPsr7Stream
{
	/** @var int The number of seeks refused so far. */
	public int $refusals = 0;

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		if ($whence === SEEK_SET && $offset > strlen($this->_data)) {
			$this->refusals++;
			throw new \RuntimeException("Cannot seek to $offset, past the end of the stream");
		}
		parent::seek($offset, $whence);
	}
}
