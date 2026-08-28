<?php

use Belisoful\Image\Compression\LZWCompressor;
use Belisoful\Image\Compression\PackBitsCompressor;
use Belisoful\Image\Compression\StreamCodec;

/**
 * A minimal native PHP stream filter that drives a {@see StreamCodec} engine, proving the
 * engines are self-contained enough for a `php_user_filter` to use as its compressor —
 * the library no longer ships a filter of its own.
 */
class TCodecDrivingFilter extends \php_user_filter
{
	/** @var array<string, callable(): StreamCodec> Codec factories, keyed by filter name. */
	public static array $factories = [];

	/** @var ?StreamCodec The engine this filter drives. */
	private ?StreamCodec $codec = null;

	public function onCreate(): bool
	{
		$factory = self::$factories[$this->filtername] ?? null;
		if ($factory === null) {
			return false;
		}
		$this->codec = $factory();
		return true;
	}

	public function filter($in, $out, &$consumed, bool $closing): int
	{
		while ($bucket = stream_bucket_make_writeable($in)) {
			$consumed += $bucket->datalen;
			$data = $this->codec->add($bucket->data);
			if ($data !== '') {
				stream_bucket_append($out, stream_bucket_new($this->stream, $data));
			}
		}
		if ($closing) {
			$data = $this->codec->finish();
			if ($data !== '') {
				stream_bucket_append($out, stream_bucket_new($this->stream, $data));
			}
		}
		return PSFS_PASS_ON;
	}
}

/**
 * Unit tests for the incremental {@see StreamCodec} engines behind the LZW and PackBits
 * compressors: {@see LZWCompressor::encoder()}/{@see LZWCompressor::decoder()} and the
 * PackBits equivalents, modeled on PHP's {@see deflate_init()}/{@see inflate_init()}.
 */
class StreamCodecTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Drives a codec $chunk bytes at a time, so its incremental state is exercised across
	 * chunk boundaries.
	 * @param StreamCodec $codec
	 * @param string $data
	 * @param int $chunk
	 */
	private function drive(StreamCodec $codec, string $data, int $chunk): string
	{
		$out = '';
		if ($data !== '') {
			foreach (str_split($data, $chunk) as $piece) {
				$out .= $codec->add($piece);
			}
		}
		return $out . $codec->finish();
	}

	public function testWholeStringIsAddThenFinish()
	{
		$raw = 'AAAAABCDEEEEEE' . str_repeat('Z', 50);
		$enc = LZWCompressor::encoder();
		self::assertSame(LZWCompressor::compress($raw), $enc->add($raw) . $enc->finish());

		$pb = PackBitsCompressor::encoder();
		self::assertSame(PackBitsCompressor::compress($raw), $pb->add($raw) . $pb->finish());
	}

	public function testPackBitsStreamingMatchesWholeStringAcrossChunkSizes()
	{
		$inputs = [
			'',
			'A',
			'AABCC',
			str_repeat('Q', 300),
			'AAAAABCDEEEEEE' . str_repeat('Z', 200) . PseudoRandomBytes::bytes(500, 'codec-1'),
		];
		foreach ($inputs as $raw) {
			$expected = PackBitsCompressor::compress($raw);
			foreach ([1, 3, 7, 128] as $chunk) {
				$encoded = $this->drive(PackBitsCompressor::encoder(), $raw, $chunk);
				self::assertSame($expected, $encoded, "PackBits encode chunk={$chunk}");
				$decoded = $this->drive(PackBitsCompressor::decoder(), $encoded, $chunk);
				self::assertSame($raw, $decoded, "PackBits decode chunk={$chunk}");
			}
		}
	}

	public function testLZWStreamingMatchesWholeStringAcrossChunkSizes()
	{
		$inputs = [
			'',
			'A',
			'TOBEORNOTTOBEORTOBEORNOT',
			str_repeat('abcdefghij', 500),   // crosses 9->10->11->12 bit widths
			PseudoRandomBytes::bytes(8000, 'codec-2'),
		];
		foreach ($inputs as $raw) {
			$expected = LZWCompressor::compress($raw);
			foreach ([1, 5, 13, 64] as $chunk) {
				$encoded = $this->drive(LZWCompressor::encoder(), $raw, $chunk);
				self::assertSame($expected, $encoded, 'LZW encode chunk=' . $chunk . ' len=' . strlen($raw));
				$decoded = $this->drive(LZWCompressor::decoder(), $encoded, $chunk);
				self::assertSame($raw, $decoded, 'LZW decode chunk=' . $chunk . ' len=' . strlen($raw));
			}
		}
	}

	public function testLZWStreamingMatchesAcrossDictionaryClears()
	{
		// 32 KB of spread values forces 9->10->11->12 growth AND the 4096-code clear/reset in
		// both directions; the incremental engine must stay byte-identical through the reset.
		$raw = '';
		for ($i = 0; $i < 8192; $i++) {
			$raw .= pack('N', $i * 2654435761 & 0xFFFFFFFF);
		}
		$expected = LZWCompressor::compress($raw);
		self::assertSame($expected, $this->drive(LZWCompressor::encoder(), $raw, 4096));
		self::assertSame($raw, $this->drive(LZWCompressor::decoder(), $expected, 4096));
	}

	public function testLZWDecoderRejectsCorruptData()
	{
		// Clear (256), 'A' (65), then the out-of-range code 300, packed 9 bits MSB-first.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(65), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(300), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 32, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(\UnexpectedValueException::class);
		$this->drive(LZWCompressor::decoder(), $bytes, 64);
	}

	public function testLZWDecoderRejectsAnUndefinedFirstCode()
	{
		// Clear (256) then 258 as the very first data code: no dictionary entry can exist yet.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT) . str_pad(decbin(258), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 24, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(\UnexpectedValueException::class);
		$this->drive(LZWCompressor::decoder(), $bytes, 64);
	}

	public function testLZWDecoderIgnoresBytesAfterEndOfInformation()
	{
		// The trailing padding is long enough to arrive in a later chunk, after the chunk
		// carrying end-of-information has already finished the decode.
		$raw = 'hello lzw';
		$trailing = LZWCompressor::compress($raw) . str_repeat("\x00", 20000);
		self::assertSame($raw, $this->drive(LZWCompressor::decoder(), $trailing, 8192));
	}

	public function testLZWEncodePadsNothingOnAByteBoundary()
	{
		// Six distinct bytes emit six codes; with the leading clear and the closing
		// end-of-information that is eight 9-bit codes, exactly nine whole bytes, so the
		// closing flush has no partial byte to pad.
		$encoded = $this->drive(LZWCompressor::encoder(), 'ABCDEF', 64);
		self::assertSame(9, strlen($encoded));
		self::assertSame(LZWCompressor::compress('ABCDEF'), $encoded);
		self::assertSame('ABCDEF', $this->drive(LZWCompressor::decoder(), $encoded, 64));
	}

	public function testPackBitsDecoderDropsATruncatedTail()
	{
		// A literal header wanting six bytes with only two present is an incomplete packet: it
		// is carried, never completed, and discarded at finish(), while the packet before decodes.
		self::assertSame('XYZ', $this->drive(PackBitsCompressor::decoder(), chr(2) . 'XYZ' . chr(5) . 'AB', 64));
	}

	public function testPackBitsDecoderSkipsNoOpPackets()
	{
		// 128 is the PackBits no-op header: it carries nothing and the packets around it decode.
		self::assertSame('ABC', $this->drive(PackBitsCompressor::decoder(), chr(128) . chr(2) . 'ABC' . chr(128), 64));
	}

	public function testPackBitsRunStraddlingLiteralBoundary()
	{
		// 127 distinct bytes fill a literal to 128 with the first byte of a pair; the 128-byte
		// flush must not split the pair, so it still coalesces into a run (regression).
		$raw = '';
		for ($i = 0; $i < 127; $i++) {
			$raw .= chr($i);
		}
		$raw .= chr(200) . chr(200);
		$expected = PackBitsCompressor::compress($raw);
		foreach ([1, 64, 128, 8192] as $chunk) {
			self::assertSame($expected, $this->drive(PackBitsCompressor::encoder(), $raw, $chunk), "encode chunk={$chunk}");
			self::assertSame($raw, $this->drive(PackBitsCompressor::decoder(), $expected, $chunk), "decode chunk={$chunk}");
		}
	}

	//
	// ─── A native php_user_filter driving an engine ──────────────────────────
	//

	public function testANativeStreamFilterCanUseACodecEngine()
	{
		// The library ships no stream filter; this proves a user can build one on the engine.
		TCodecDrivingFilter::$factories = [
			'test.lzw.encode' => static fn () => LZWCompressor::encoder(),
			'test.lzw.decode' => static fn () => LZWCompressor::decoder(),
			'test.packbits.encode' => static fn () => PackBitsCompressor::encoder(),
		];
		if (!in_array('test.lzw.encode', stream_get_filters(), true)) {
			stream_filter_register('test.lzw.encode', TCodecDrivingFilter::class);
			stream_filter_register('test.lzw.decode', TCodecDrivingFilter::class);
			stream_filter_register('test.packbits.encode', TCodecDrivingFilter::class);
		}

		$raw = str_repeat('the quick brown fox ', 200) . PseudoRandomBytes::bytes(2000, 'codec-3');

		$h = fopen('php://temp', 'r+b');
		fwrite($h, $raw);
		rewind($h);
		stream_filter_append($h, 'test.lzw.encode', STREAM_FILTER_READ);
		$encoded = stream_get_contents($h);
		fclose($h);
		self::assertSame(LZWCompressor::compress($raw), $encoded, 'The filter output matches the whole-string codec.');

		$h = fopen('php://temp', 'r+b');
		fwrite($h, $encoded);
		rewind($h);
		stream_filter_append($h, 'test.lzw.decode', STREAM_FILTER_READ);
		self::assertSame($raw, stream_get_contents($h));
		fclose($h);

		// A write-mode filter flushes the tail when the stream closes.
		$path = tempnam(sys_get_temp_dir(), 'phpimagecodec');
		$h = fopen($path, 'wb');
		stream_filter_append($h, 'test.packbits.encode', STREAM_FILTER_WRITE);
		foreach (str_split($raw, 100) as $chunk) {
			fwrite($h, $chunk);
		}
		fclose($h);
		$written = (string) file_get_contents($path);
		@unlink($path);
		self::assertSame(PackBitsCompressor::compress($raw), $written, 'Write mode flushes on close.');
	}
}
