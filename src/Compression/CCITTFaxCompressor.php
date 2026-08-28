<?php

/**
 * CCITTFaxCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

use Belisoful\Image\Util\BitReader;
use Belisoful\Image\Util\BitWriter;

/**
 * CCITTFaxCompressor class.
 *
 * The CCITT bilevel fax codings of TIFF's black-and-white images: Modified Huffman
 * (TIFF Compression 2, one-dimensional with byte-aligned rows), Group 3 (T.4, TIFF
 * Compression 3, one-dimensional rows separated by EOL codes), Group 3 two-dimensional
 * (the T.4 extension, whose EOL carries a 1D/2D tag bit), and Group 4 (T.6 MMR, TIFF
 * Compression 4, the two-dimensional coding of every fax-style TIFF).
 *
 * The uncompressed form is packed rows: one bit per pixel most-significant-bit first,
 * a set bit meaning **black**, each row padded to a whole byte.  {@see compress()}
 * encodes rows in any of the four codings; {@see decompress()} decodes all four.  The row width comes from the constructor; the row count
 * from the data (or the `$rows` limit on decode).
 *
 * ```php
 * $codec = new CCITTFaxCompressor(1728, CCITTFaxCompressor::Group4);
 * $encoded = $codec->compress($packedRows);
 * $rows = $codec->decompress($encoded);
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class CCITTFaxCompressor implements CompressorInterface
{
	/** Modified Huffman: 1D runs, rows byte-aligned, no EOL (TIFF Compression 2). */
	public const ModifiedHuffman = 1;

	/** Group 3 (T.4): 1D runs with an EOL before every row (TIFF Compression 3). */
	public const Group3 = 2;

	/** Group 3 two-dimensional (T.4 2D): each row's EOL carries a 1D/2D tag bit. */
	public const Group3TwoD = 3;

	/** Group 4 (T.6 MMR): fully two-dimensional, EOFB terminated (TIFF Compression 4). */
	public const Group4 = 4;

	/** @var array<int, array{0: int, 1: int}> The white run codes: run => [bit length, code]. */
	protected const WhiteCodes = [
		0 => [8, 0x35], 1 => [6, 0x07], 2 => [4, 0x07], 3 => [4, 0x08], 4 => [4, 0x0B],
		5 => [4, 0x0C], 6 => [4, 0x0E], 7 => [4, 0x0F], 8 => [5, 0x13], 9 => [5, 0x14],
		10 => [5, 0x07], 11 => [5, 0x08], 12 => [6, 0x08], 13 => [6, 0x03], 14 => [6, 0x34],
		15 => [6, 0x35], 16 => [6, 0x2A], 17 => [6, 0x2B], 18 => [7, 0x27], 19 => [7, 0x0C],
		20 => [7, 0x08], 21 => [7, 0x17], 22 => [7, 0x03], 23 => [7, 0x04], 24 => [7, 0x28],
		25 => [7, 0x2B], 26 => [7, 0x13], 27 => [7, 0x24], 28 => [7, 0x18], 29 => [8, 0x02],
		30 => [8, 0x03], 31 => [8, 0x1A], 32 => [8, 0x1B], 33 => [8, 0x12], 34 => [8, 0x13],
		35 => [8, 0x14], 36 => [8, 0x15], 37 => [8, 0x16], 38 => [8, 0x17], 39 => [8, 0x28],
		40 => [8, 0x29], 41 => [8, 0x2A], 42 => [8, 0x2B], 43 => [8, 0x2C], 44 => [8, 0x2D],
		45 => [8, 0x04], 46 => [8, 0x05], 47 => [8, 0x0A], 48 => [8, 0x0B], 49 => [8, 0x52],
		50 => [8, 0x53], 51 => [8, 0x54], 52 => [8, 0x55], 53 => [8, 0x24], 54 => [8, 0x25],
		55 => [8, 0x58], 56 => [8, 0x59], 57 => [8, 0x5A], 58 => [8, 0x5B], 59 => [8, 0x4A],
		60 => [8, 0x4B], 61 => [8, 0x32], 62 => [8, 0x33], 63 => [8, 0x34],
		64 => [5, 0x1B], 128 => [5, 0x12], 192 => [6, 0x17], 256 => [7, 0x37],
		320 => [8, 0x36], 384 => [8, 0x37], 448 => [8, 0x64], 512 => [8, 0x65],
		576 => [8, 0x68], 640 => [8, 0x67], 704 => [9, 0xCC], 768 => [9, 0xCD],
		832 => [9, 0xD2], 896 => [9, 0xD3], 960 => [9, 0xD4], 1024 => [9, 0xD5],
		1088 => [9, 0xD6], 1152 => [9, 0xD7], 1216 => [9, 0xD8], 1280 => [9, 0xD9],
		1344 => [9, 0xDA], 1408 => [9, 0xDB], 1472 => [9, 0x98], 1536 => [9, 0x99],
		1600 => [9, 0x9A], 1664 => [6, 0x18], 1728 => [9, 0x9B],
	];

	/** @var array<int, array{0: int, 1: int}> The black run codes: run => [bit length, code]. */
	protected const BlackCodes = [
		0 => [10, 0x37], 1 => [3, 0x02], 2 => [2, 0x03], 3 => [2, 0x02], 4 => [3, 0x03],
		5 => [4, 0x03], 6 => [4, 0x02], 7 => [5, 0x03], 8 => [6, 0x05], 9 => [6, 0x04],
		10 => [7, 0x04], 11 => [7, 0x05], 12 => [7, 0x07], 13 => [8, 0x04], 14 => [8, 0x07],
		15 => [9, 0x18], 16 => [10, 0x17], 17 => [10, 0x18], 18 => [10, 0x08], 19 => [11, 0x67],
		20 => [11, 0x68], 21 => [11, 0x6C], 22 => [11, 0x37], 23 => [11, 0x28], 24 => [11, 0x17],
		25 => [11, 0x18], 26 => [12, 0xCA], 27 => [12, 0xCB], 28 => [12, 0xCC], 29 => [12, 0xCD],
		30 => [12, 0x68], 31 => [12, 0x69], 32 => [12, 0x6A], 33 => [12, 0x6B], 34 => [12, 0xD2],
		35 => [12, 0xD3], 36 => [12, 0xD4], 37 => [12, 0xD5], 38 => [12, 0xD6], 39 => [12, 0xD7],
		40 => [12, 0x6C], 41 => [12, 0x6D], 42 => [12, 0xDA], 43 => [12, 0xDB], 44 => [12, 0x54],
		45 => [12, 0x55], 46 => [12, 0x56], 47 => [12, 0x57], 48 => [12, 0x64], 49 => [12, 0x65],
		50 => [12, 0x52], 51 => [12, 0x53], 52 => [12, 0x24], 53 => [12, 0x37], 54 => [12, 0x38],
		55 => [12, 0x27], 56 => [12, 0x28], 57 => [12, 0x58], 58 => [12, 0x59], 59 => [12, 0x2B],
		60 => [12, 0x2C], 61 => [12, 0x5A], 62 => [12, 0x66], 63 => [12, 0x67],
		64 => [10, 0x0F], 128 => [12, 0xC8], 192 => [12, 0xC9], 256 => [12, 0x5B],
		320 => [12, 0x33], 384 => [12, 0x34], 448 => [12, 0x35], 512 => [13, 0x6C],
		576 => [13, 0x6D], 640 => [13, 0x4A], 704 => [13, 0x4B], 768 => [13, 0x4C],
		832 => [13, 0x4D], 896 => [13, 0x72], 960 => [13, 0x73], 1024 => [13, 0x74],
		1088 => [13, 0x75], 1152 => [13, 0x76], 1216 => [13, 0x77], 1280 => [13, 0x52],
		1344 => [13, 0x53], 1408 => [13, 0x54], 1472 => [13, 0x55], 1536 => [13, 0x5A],
		1600 => [13, 0x5B], 1664 => [13, 0x64], 1728 => [13, 0x65],
	];

	/** @var array<int, array{0: int, 1: int}> The extended make-up codes shared by both colors. */
	protected const ExtendedCodes = [
		1792 => [11, 0x08], 1856 => [11, 0x0C], 1920 => [11, 0x0D], 1984 => [12, 0x12],
		2048 => [12, 0x13], 2112 => [12, 0x14], 2176 => [12, 0x15], 2240 => [12, 0x16],
		2304 => [12, 0x17], 2368 => [12, 0x1C], 2432 => [12, 0x1D], 2496 => [12, 0x1E],
		2560 => [12, 0x1F],
	];

	/** @var ?array<int, array<string, int>> The lazily built decode maps ("len:code" => run) per color. */
	private static ?array $_decodeMaps = null;

	/** @var int The row width in pixels. */
	private int $_columns;

	/** @var int The coding mode (a class constant). */
	private int $_mode;

	/**
	 * Constructs the codec.
	 * @param int $columns The row width in pixels. Default 1728.
	 * @param int $mode The coding mode. Default {@see Group4}.
	 * @throws \InvalidArgumentException When the mode is unknown or the width invalid.
	 */
	public function __construct(int $columns = 1728, int $mode = self::Group4)
	{
		if ($columns < 1) {
			throw new \InvalidArgumentException(sprintf('CCITT fax row width \'%s\' must be a positive pixel count.', $columns));
		}
		if ($mode < self::ModifiedHuffman || $mode > self::Group4) {
			throw new \InvalidArgumentException(sprintf('\'%s\' is not a CCITT fax coding mode.', $mode));
		}
		$this->_columns = $columns;
		$this->_mode = $mode;
	}

	/**
	 * Returns the row width in pixels.
	 * @return int The columns.
	 */
	public function getColumns(): int
	{
		return $this->_columns;
	}

	/**
	 * Returns the coding mode.
	 * @return int A class constant.
	 */
	public function getMode(): int
	{
		return $this->_mode;
	}

	/**
	 * Returns the packed bytes per row.
	 * @return int The row stride.
	 */
	public function getRowBytes(): int
	{
		return intdiv($this->_columns + 7, 8);
	}

	/**
	 * Encodes packed bilevel rows with the given geometry ({@see CompressorInterface} form).
	 * @param string $data The packed rows (MSB-first, set bit = black, byte-padded rows).
	 * @param int $columns The row width in pixels. Default 1728.
	 * @param int $mode The coding mode. Default {@see Group4}.
	 * @return string The encoded bytes.
	 */
	public static function compress(string $data, int $columns = 1728, int $mode = self::Group4): string
	{
		return (new self($columns, $mode))->encode($data);
	}

	/**
	 * Decodes encoded rows with the given geometry ({@see CompressorInterface} form).
	 * @param string $data The encoded bytes.
	 * @param int $columns The row width in pixels. Default 1728.
	 * @param int $mode The coding mode. Default {@see Group4}.
	 * @param ?int $rows The row limit, or null for all rows.
	 * @return string The packed rows (MSB-first, set bit = black, byte-padded rows).
	 */
	public static function decompress(string $data, int $columns = 1728, int $mode = self::Group4, ?int $rows = null): string
	{
		return (new self($columns, $mode))->decode($data, $rows);
	}

	/**
	 * Encodes packed bilevel rows.
	 * @param string $data The packed rows (MSB-first, set bit = black, byte-padded rows).
	 * @throws \UnexpectedValueException When the data is not whole rows.
	 * @return string The encoded bytes.
	 */
	public function encode(string $data): string
	{
		$rowBytes = $this->getRowBytes();
		if ($rowBytes === 0 || strlen($data) % $rowBytes !== 0) {
			throw new \UnexpectedValueException(sprintf('CCITT fax data of %s bytes is not whole rows of %s bytes.', strlen($data), $rowBytes));
		}
		$rows = intdiv(strlen($data), $rowBytes);
		$writer = new BitWriter();
		$reference = [];   // imaginary all-white reference line
		for ($row = 0; $row < $rows; $row++) {
			$changes = $this->rowChanges($data, $row * $rowBytes);
			switch ($this->_mode) {
				case self::ModifiedHuffman:
					$this->encodeRow1D($writer, $changes);
					$writer->align();
					break;
				case self::Group3:
					$this->writeEol($writer);
					$this->encodeRow1D($writer, $changes);
					break;
				case self::Group3TwoD:
					// T.4 two-dimensional: the EOL carries a tag bit — 1 for a
					// one-dimensional row, 0 for a row coded against its predecessor.
					$this->writeEol($writer);
					$writer->writeBits($row === 0 ? 1 : 0, 1);
					if ($row === 0) {
						$this->encodeRow1D($writer, $changes);
					} else {
						$this->encodeRow2D($writer, $changes, $reference);
					}
					$reference = $changes;
					break;
				case self::Group4:
					$this->encodeRow2D($writer, $changes, $reference);
					$reference = $changes;
					break;
			}
		}
		if ($this->_mode === self::Group3 || $this->_mode === self::Group3TwoD) {
			$this->writeEol($writer);
		} elseif ($this->_mode === self::Group4) {
			$this->writeEol($writer);   // EOFB = two EOLs
			$this->writeEol($writer);
		}
		$writer->align();
		$writer->flush();
		return $writer->getBytes();
	}

	/**
	 * Decodes encoded rows back to packed bilevel rows.
	 * @param string $data The encoded bytes.
	 * @param ?int $rows The row limit, or null for all rows.
	 * @return string The packed rows (MSB-first, set bit = black, byte-padded rows).
	 */
	public function decode(string $data, ?int $rows = null): string
	{
		$maxRows = $rows ?? PHP_INT_MAX;
		$reader = new BitReader($data);
		$out = '';
		$reference = [];
		$rows = 0;
		$twoDRow = false;
		while ($rows < $maxRows) {
			if ($this->_mode === self::Group3 || $this->_mode === self::Group3TwoD) {
				if (!$this->skipToEol($reader)) {
					break;
				}
				if ($this->_mode === self::Group3TwoD) {
					$tag = $reader->readBits(1);
					if ($tag === false) {
						break;
					}
					$twoDRow = $tag === 0;   // tag bit: 1 = 1D row, 0 = 2D row
				}
			}
			$changes = $this->_mode === self::Group4 || ($this->_mode === self::Group3TwoD && $twoDRow)
				? $this->decodeRow2D($reader, $reference)
				: $this->decodeRow1D($reader);
			if ($changes === null) {
				break;
			}
			$out .= $this->packRow($changes);
			$reference = $changes;
			$rows++;
			if ($this->_mode === self::ModifiedHuffman) {
				$reader->align();
			}
		}
		return $out;
	}

	/**
	 * Returns the changing-element positions of a packed row (a transition list; the
	 * row starts white).
	 * @param string $data The packed rows.
	 * @param int $offset The row's byte offset.
	 * @return int[] The change positions, ascending.
	 */
	protected function rowChanges(string $data, int $offset): array
	{
		$changes = [];
		$color = 0;   // white
		for ($x = 0; $x < $this->_columns; $x++) {
			$bit = (ord($data[$offset + ($x >> 3)]) >> (7 - ($x & 7))) & 1;
			if ($bit !== $color) {
				$changes[] = $x;
				$color = $bit;
			}
		}
		return $changes;
	}

	/**
	 * Packs a change list back to row bytes.
	 * @param int[] $changes The change positions.
	 * @return string The packed row.
	 */
	protected function packRow(array $changes): string
	{
		$row = str_repeat("\0", $this->getRowBytes());
		$changes[] = $this->_columns;
		$black = false;
		$position = 0;
		foreach ($changes as $change) {
			if ($black) {
				for ($x = $position; $x < $change && $x < $this->_columns; $x++) {
					$row[$x >> 3] = chr(ord($row[$x >> 3]) | (0x80 >> ($x & 7)));
				}
			}
			$position = $change;
			$black = !$black;
		}
		return $row;
	}

	/**
	 * Emits a run length in one color, chaining make-up codes for long runs.
	 * @param BitWriter $writer The bit sink.
	 * @param int $run The run length.
	 * @param bool $white Whether the run is white.
	 */
	protected function writeRun(BitWriter $writer, int $run, bool $white): void
	{
		$table = $white ? self::WhiteCodes : self::BlackCodes;
		while ($run >= 64) {
			$makeup = min(2560, $run & ~63);
			for (; $makeup >= 64; $makeup -= 64) {
				if (isset(self::ExtendedCodes[$makeup]) || isset($table[$makeup])) {
					break;
				}
			}
			if ($run - $makeup < 0 || $makeup < 64) {
				break;
			}
			[$bits, $code] = self::ExtendedCodes[$makeup] ?? $table[$makeup];
			$writer->writeBits($code, $bits);
			$run -= $makeup;
		}
		[$bits, $code] = $table[$run];
		$writer->writeBits($code, $bits);
	}

	/**
	 * Encodes one row as alternating one-dimensional runs.
	 * @param BitWriter $writer The bit sink.
	 * @param int[] $changes The row's change positions.
	 */
	protected function encodeRow1D(BitWriter $writer, array $changes): void
	{
		$changes[] = $this->_columns;
		$position = 0;
		$white = true;
		foreach ($changes as $change) {
			$this->writeRun($writer, $change - $position, $white);
			$position = $change;
			$white = !$white;
			if ($position >= $this->_columns) {
				break;
			}
		}
	}

	/**
	 * Encodes one row two-dimensionally against its reference row (T.6).
	 * @param BitWriter $writer The bit sink.
	 * @param int[] $changes The coding row's change positions.
	 * @param int[] $reference The reference row's change positions.
	 */
	protected function encodeRow2D(BitWriter $writer, array $changes, array $reference): void
	{
		$a0 = -1;
		$white = true;
		$index = 0;
		while (true) {
			$a1 = $changes[$index] ?? $this->_columns;
			[$b1, $b2] = $this->findB($reference, $a0, $white);
			if ($b2 < $a1) {
				$writer->writeBits(0x1, 4);   // pass: 0001
				$a0 = $b2;
				continue;
			}
			$delta = $a1 - $b1;
			if ($delta >= -3 && $delta <= 3) {
				[$bits, $code] = match ($delta) {
					0 => [1, 0x1],       // V0: 1
					1 => [3, 0x3],       // VR1: 011
					2 => [6, 0x3],       // VR2: 000011
					3 => [7, 0x3],       // VR3: 0000011
					-1 => [3, 0x2],      // VL1: 010
					-2 => [6, 0x2],      // VL2: 000010
					-3 => [7, 0x2],      // VL3: 0000010
				};
				$writer->writeBits($code, $bits);
				$a0 = $a1;
				$white = !$white;
				$index++;
			} else {
				$a2 = $changes[$index + 1] ?? $this->_columns;
				$writer->writeBits(0x1, 3);   // horizontal: 001
				$start = $a0 < 0 ? 0 : $a0;
				$this->writeRun($writer, $a1 - $start, $white);
				$this->writeRun($writer, $a2 - $a1, !$white);
				$a0 = $a2;
				$index += 2;
			}
			if ($a0 >= $this->_columns) {
				break;
			}
		}
	}

	/**
	 * Finds the reference changing elements b1 and b2 for a coding position.
	 * @param int[] $reference The reference row's change positions.
	 * @param int $a0 The coding position (-1 at row start).
	 * @param bool $white Whether the coding color is white.
	 * @return array{0: int, 1: int} The [b1, b2] positions (the row width when absent).
	 */
	protected function findB(array $reference, int $a0, bool $white): array
	{
		// b1: first reference change right of a0 starting a run of the opposite color
		// of the coding color — an even-index change starts black, odd starts white.
		$wantParity = $white ? 0 : 1;
		$count = count($reference);
		for ($i = 0; $i < $count; $i++) {
			if ($reference[$i] > $a0 && ($i & 1) === $wantParity) {
				return [$reference[$i], $reference[$i + 1] ?? $this->_columns];
			}
		}
		return [$this->_columns, $this->_columns];
	}

	/**
	 * Builds the run decode maps once per process.
	 * @return array<int, array<string, int>> The white/black "len:code" => run maps.
	 */
	protected static function decodeMaps(): array
	{
		if (self::$_decodeMaps === null) {
			$maps = [[], []];
			foreach ([0 => self::WhiteCodes, 1 => self::BlackCodes] as $color => $table) {
				foreach ($table as $run => [$bits, $code]) {
					$maps[$color]["$bits:$code"] = $run;
				}
				foreach (self::ExtendedCodes as $run => [$bits, $code]) {
					$maps[$color]["$bits:$code"] = $run;
				}
			}
			self::$_decodeMaps = $maps;
		}
		return self::$_decodeMaps;
	}

	/**
	 * Decodes one full run (make-up chain plus terminating code) of a color.
	 * @param BitReader $reader The bit source.
	 * @param bool $white Whether the run color is white.
	 * @throws \UnexpectedValueException When the bits match no code word.
	 * @return ?int The run length, or null at end of data.
	 */
	protected function readRun(BitReader $reader, bool $white): ?int
	{
		$maps = self::decodeMaps();
		$map = $maps[$white ? 0 : 1];
		$total = 0;
		while (true) {
			$bits = 0;
			$code = 0;
			while (true) {
				$bit = $reader->readBits(1);
				if ($bit === false) {
					return null;
				}
				$code = ($code << 1) | $bit;
				$bits++;
				if (isset($map["$bits:$code"])) {
					break;
				}
				if ($bits > 14) {
					if ($code === 0) {
						return null;   // a zero-fill / padding region, not a code word
					}
					throw new \UnexpectedValueException(sprintf('CCITT fax data contains an invalid %s-bit code word %s.', $bits, sprintf('0x%X', $code)));
				}
			}
			$run = $map["$bits:$code"];
			$total += $run;
			if ($run < 64) {
				return $total;
			}
		}
	}

	/**
	 * Decodes one one-dimensional row.
	 * @param BitReader $reader The bit source.
	 * @return ?int[] The row's change positions, or null at end of data.
	 */
	protected function decodeRow1D(BitReader $reader): ?array
	{
		$changes = [];
		$position = 0;
		$white = true;
		$decoded = false;
		while ($position < $this->_columns) {
			$run = $this->readRun($reader, $white);
			if ($run === null) {
				return $decoded ? $changes : null;
			}
			$decoded = true;
			$position += $run;
			if ($position >= $this->_columns) {
				break;   // the row edge is an implied boundary, not a recorded change
			}
			$changes[] = $position;
			$white = !$white;
		}
		return $changes;
	}

	/**
	 * Decodes one two-dimensional row against its reference (T.6 / T.4 2D).
	 * @param BitReader $reader The bit source.
	 * @param int[] $reference The reference row's change positions.
	 * @return ?int[] The row's change positions, or null at end of data / EOFB.
	 */
	protected function decodeRow2D(BitReader $reader, array $reference): ?array
	{
		$changes = [];
		$a0 = -1;
		$white = true;
		while ($a0 < $this->_columns) {
			[$b1, $b2] = $this->findB($reference, $a0, $white);
			$mode = $this->readModeCode($reader);
			if ($mode === null) {
				return $changes === [] ? null : $changes;
			}
			switch ($mode[0]) {
				case 'EOL':
					return $changes === [] ? null : $changes;
				case 'P':
					$a0 = $b2;
					break;
				case 'H':
					$start = $a0 < 0 ? 0 : $a0;
					$run1 = $this->readRun($reader, $white);
					$run2 = $this->readRun($reader, !$white);
					if ($run1 === null || $run2 === null) {
						return $changes === [] ? null : $changes;
					}
					$a1 = $start + $run1;
					$a2 = $a1 + $run2;
					$changes[] = min($a1, $this->_columns);
					$changes[] = min($a2, $this->_columns);
					$a0 = $a2;
					break;
				case 'V':
					$a1 = $b1 + $mode[1];
					$changes[] = min(max($a1, 0), $this->_columns);
					$a0 = $a1;
					$white = !$white;
					break;
			}
		}
		while ($changes !== [] && end($changes) >= $this->_columns) {
			array_pop($changes);
		}
		return $changes;
	}

	/**
	 * Reads one 2D mode code (vertical, pass, horizontal, or EOL).
	 * @param BitReader $reader The bit source.
	 * @throws \UnexpectedValueException When the bits match no mode code.
	 * @return ?array The ['V', delta] | ['P'] | ['H'] | ['EOL'] tuple, or null at end of data.
	 */
	protected function readModeCode(BitReader $reader): ?array
	{
		$code = 0;
		for ($bits = 1; $bits <= 12; $bits++) {
			$bit = $reader->readBits(1);
			if ($bit === false) {
				return null;
			}
			$code = ($code << 1) | $bit;
			switch (true) {
				case $bits === 1 && $code === 0x1:
					return ['V', 0];
				case $bits === 3 && $code === 0x1:
					return ['H'];
				case $bits === 3 && $code === 0x3:
					return ['V', 1];
				case $bits === 3 && $code === 0x2:
					return ['V', -1];
				case $bits === 4 && $code === 0x1:
					return ['P'];
				case $bits === 6 && $code === 0x3:
					return ['V', 2];
				case $bits === 6 && $code === 0x2:
					return ['V', -2];
				case $bits === 7 && $code === 0x3:
					return ['V', 3];
				case $bits === 7 && $code === 0x2:
					return ['V', -3];
				case $bits === 12 && $code === 0x1:
					return ['EOL'];
			}
		}
		throw new \UnexpectedValueException(sprintf('CCITT fax data contains an invalid %s-bit code word %s.', 12, sprintf('0x%X', $code)));
	}

	/**
	 * Emits an EOL code (eleven zero bits and a one).
	 * @param BitWriter $writer The bit sink.
	 */
	protected function writeEol(BitWriter $writer): void
	{
		$writer->writeBits(0x1, 12);
	}

	/**
	 * Scans forward to just past the next EOL code, tolerating fill bits.
	 * @param BitReader $reader The bit source.
	 * @return bool Whether an EOL was found before the data ended.
	 */
	protected function skipToEol(BitReader $reader): bool
	{
		$zeros = 0;
		while (true) {
			$bit = $reader->readBits(1);
			if ($bit === false) {
				return false;
			}
			if ($bit === 0) {
				$zeros++;
			} elseif ($zeros >= 11) {
				return true;
			} else {
				$zeros = 0;
			}
		}
	}

}
