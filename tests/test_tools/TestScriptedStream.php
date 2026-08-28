<?php

/**
 * TestScriptedStream class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

use Psr\Http\Message\StreamInterface;

/**
 * TestScriptedStream is a minimal PSR-7 stream whose read() and write() results are
 * scripted, for exercising decorator edge paths a real resource stream cannot reach:
 * short reads, a read that returns '' before EOF, a write that accepts a partial
 * count or nothing, and an unknown size.
 */
class TestScriptedStream implements StreamInterface
{
	/** @var array<int, string> The chunks successive read() calls return ('' simulates a stall). */
	public array $reads = [];

	/** @var array<int, int> The byte counts successive write() calls report; -1 means "accept all". */
	public array $writes = [];

	/** @var bool The value eof() reports once the read script is exhausted. */
	public bool $eofWhenDrained = true;

	/** @var ?int The size getSize() reports. */
	public ?int $size = null;

	/** @var bool Whether the stream reports seekable. */
	public bool $seekable = false;

	/** @var int The position tell() reports; reads and seeks advance it. */
	public int $pos = 0;

	/** @var string Every byte accepted by write(), concatenated. */
	public string $written = '';

	public function __construct(array $reads = [], array $writes = [])
	{
		$this->reads = $reads;
		$this->writes = $writes;
	}

	public function __toString(): string
	{
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
		return $this->size;
	}

	public function tell(): int
	{
		return $this->pos;
	}

	public function eof(): bool
	{
		return $this->reads === [] && $this->eofWhenDrained;
	}

	public function isSeekable(): bool
	{
		return $this->seekable;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		if (!$this->seekable) {
			throw new \RuntimeException('Not seekable');
		}
		$this->pos = ($whence === SEEK_CUR) ? $this->pos + $offset : $offset;
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
		$count = $this->writes === [] ? -1 : array_shift($this->writes);
		if ($count === -1 || $count > strlen($string)) {
			$count = strlen($string);
		}
		$this->written .= substr($string, 0, $count);
		$this->pos += $count;
		return $count;
	}

	public function isReadable(): bool
	{
		return true;
	}

	public function read(int $length): string
	{
		if ($this->reads === []) {
			return '';
		}
		$chunk = substr(array_shift($this->reads), 0, $length);
		$this->pos += strlen($chunk);
		return $chunk;
	}

	public function getContents(): string
	{
		$contents = '';
		while (!$this->eof()) {
			$chunk = $this->read(PHP_INT_MAX);
			if ($chunk === '') {
				break;
			}
			$contents .= $chunk;
		}
		return $contents;
	}

	public function getMetadata(?string $key = null): mixed
	{
		return $key === null ? [] : null;
	}
}
