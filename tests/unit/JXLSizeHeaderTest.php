<?php

use Belisoful\Image\JXLSizeHeader;

/**
 * The bit-packed `SizeHeader` a JPEG XL codestream opens with.  Two kinds of case are
 * covered: headers built here field by field, which pin the grammar down, and the leading
 * bytes of files actually written by `cjxl`, which pin it to reality.
 */
class JXLSizeHeaderTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Packs fields into a codestream the way JPEG XL does: least-significant bit first
	 * within each byte, and a field's first bit is its low bit.
	 * @param array<int, array{0: int, 1: int}> $fields Each [value, bit width].
	 */
	private function codestream(array $fields): string
	{
		$bits = '';
		foreach ($fields as [$value, $width]) {
			for ($i = 0; $i < $width; $i++) {
				$bits .= (string) (($value >> $i) & 1);
			}
		}
		$bytes = '';
		foreach (str_split(str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '0'), 8) as $chunk) {
			$bytes .= chr((int) bindec(strrev($chunk)));
		}
		return JXLSizeHeader::Signature . $bytes;
	}

	public function testTheCompactHeightFormIsEighthsMinusOne(): void
	{
		// small=1, height=(5+1)*8=48, ratio=3 (4:3) so the width is derived as 64.
		$header = JXLSizeHeader::fromCodestream($this->codestream([[1, 1], [5, 5], [3, 3]]));
		self::assertInstanceOf(JXLSizeHeader::class, $header);
		self::assertTrue($header->getIsSmall());
		self::assertSame(48, $header->getHeight());
		self::assertSame(64, $header->getWidth());
		self::assertSame(3, $header->getAspectRatio());
	}

	public function testTheFullHeightFormStoresOneLessThanTheHeight(): void
	{
		// small=0, selector 0 (9 bits), height 1, ratio 1 (1:1).
		$header = JXLSizeHeader::fromCodestream($this->codestream([[0, 1], [0, 2], [0, 9], [1, 3]]));
		self::assertInstanceOf(JXLSizeHeader::class, $header);
		self::assertFalse($header->getIsSmall());
		self::assertSame(1, $header->getHeight());
		self::assertSame(1, $header->getWidth());
	}

	/** @dataProvider selectorProvider */
	public function testEverySelectorWidthIsRead(int $selector, int $width, int $height): void
	{
		$header = JXLSizeHeader::fromCodestream(
			$this->codestream([[0, 1], [$selector, 2], [$height - 1, $width], [1, 3]]),
		);
		self::assertSame($height, $header?->getHeight());
		self::assertSame($height, $header?->getWidth(), 'a 1:1 ratio makes the width the height');
	}

	public static function selectorProvider(): array
	{
		return [
			'9 bits' => [0, 9, 512],
			'13 bits' => [1, 13, 8192],
			'18 bits' => [2, 18, 262144],
			'30 bits' => [3, 30, 1048576],
		];
	}

	/** @dataProvider ratioProvider */
	public function testEveryAspectRatioDerivesTheWidth(int $code, int $height, int $expected): void
	{
		$header = JXLSizeHeader::fromCodestream($this->codestream([[0, 1], [0, 2], [$height - 1, 9], [$code, 3]]));
		self::assertSame($height, $header?->getHeight());
		self::assertSame($expected, $header?->getWidth());
		self::assertSame($code, $header?->getAspectRatio());
	}

	public static function ratioProvider(): array
	{
		return [
			'1:1' => [1, 100, 100],
			'12:10' => [2, 100, 120],
			'4:3' => [3, 48, 64],
			'3:2' => [4, 200, 300],
			'16:9' => [5, 90, 160],
			'5:4' => [6, 80, 100],
			'2:1' => [7, 50, 100],
		];
	}

	public function testAZeroRatioStoresTheWidthInstead(): void
	{
		// Both dimensions in full form: 13 wide, 7 high — no ratio can produce that.
		$header = JXLSizeHeader::fromCodestream(
			$this->codestream([[0, 1], [0, 2], [12, 9], [0, 3], [0, 2], [6, 9]]),
		);
		self::assertSame(13, $header?->getHeight());
		self::assertSame(7, $header?->getWidth());
		self::assertSame(0, $header?->getAspectRatio());
	}

	public function testAZeroRatioInTheCompactFormStoresBothAsEighths(): void
	{
		$header = JXLSizeHeader::fromCodestream($this->codestream([[1, 1], [2, 5], [0, 3], [4, 5]]));
		self::assertTrue($header?->getIsSmall());
		self::assertSame(24, $header?->getHeight());
		self::assertSame(40, $header?->getWidth());
	}

	/**
	 * The leading bytes of files written by `cjxl`, kept as the regression vectors that tie
	 * this decoder to real encoder output rather than only to itself.
	 * @dataProvider encoderProvider
	 * @param string $hex
	 * @param int $width
	 * @param int $height
	 */
	public function testRealEncoderOutputIsRead(string $hex, int $width, int $height): void
	{
		$header = JXLSizeHeader::fromCodestream((string) hex2bin($hex));
		self::assertSame($width, $header?->getWidth());
		self::assertSame($height, $header?->getHeight());
	}

	public static function encoderProvider(): array
	{
		return [
			'64x48 (small, 4:3)' => ['ff0acb060013880200', 64, 48],
			'1200x1000 (12:10)' => ['ff0a3a1f1a00138802', 1200, 1000],
			'16x9 (16:9)' => ['ff0a40d00100138802', 16, 9],
			'3x5 (stored width)' => ['ff0a2000040c001388', 3, 5],
			'65535x8 (wide field)' => ['ff0a3800fdff190013', 65535, 8],
			'40x40 (small, 1:1)' => ['ff0a49060013880200', 40, 40],
			'100x9000 (tall)' => ['ff0a3c19018c190013', 100, 9000],
		];
	}

	public function testSomethingThatIsNotACodestreamIsRefused(): void
	{
		self::assertNull(JXLSizeHeader::fromCodestream(''));
		self::assertNull(JXLSizeHeader::fromCodestream('not a codestream'));
		self::assertNull(JXLSizeHeader::fromCodestream("\x00\x00" . str_repeat("\x00", 8)));
	}

	public function testATruncatedHeaderIsRefusedRatherThanGuessed(): void
	{
		self::assertNull(JXLSizeHeader::fromCodestream(JXLSizeHeader::Signature), 'no bits at all');
		// A full-form height whose 30-bit field is cut short.
		self::assertNull(JXLSizeHeader::fromCodestream(JXLSizeHeader::Signature . "\x06"));
		// A compact height whose ratio bits are missing.
		self::assertNull(JXLSizeHeader::fromCodestream(JXLSizeHeader::Signature . "\x0b"));
		// A zero ratio whose stored width is missing.
		self::assertNull(JXLSizeHeader::fromCodestream(JXLSizeHeader::Signature . "\x30\x00"));
	}

	public function testTheRatioTableIsTheSpecTable(): void
	{
		self::assertSame(
			[1 => [1, 1], 2 => [12, 10], 3 => [4, 3], 4 => [3, 2], 5 => [16, 9], 6 => [5, 4], 7 => [2, 1]],
			JXLSizeHeader::AspectRatios,
		);
	}
}
