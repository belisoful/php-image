<?php

/**
 * PackBitsEncoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * PackBitsEncoder class.
 *
 * The incremental encoder half of the PackBits run-length codec (see
 * {@see PackBitsCompressor} for the format).  It is a {@see StreamCodec}: feed input with
 * {@see add()} and emit the trailing packet with {@see finish()}.  The state carried
 * between chunks is bounded — a partial literal or repeat run, at most 128 bytes — so it
 * encodes a stream of any size in constant memory.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class PackBitsEncoder implements StreamCodec
{
	/** @var string The pending literal run being built. */
	private string $_lit = '';

	/** @var int The byte value of the pending repeat run, or -1 when none. */
	private int $_runByte = -1;

	/** @var int The length of the pending repeat run. */
	private int $_runLen = 0;

	/**
	 * Feeds a chunk through the byte-by-byte run/literal state machine.
	 * @param string $data The raw chunk.
	 * @return string The PackBits packets completed by this chunk.
	 */
	public function add(string $data): string
	{
		$out = '';
		$lit = $this->_lit;
		$runByte = $this->_runByte;
		$runLen = $this->_runLen;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$ch = $data[$i];
			$c = ord($ch);
			if ($runLen > 0) {
				if ($c === $runByte && $runLen < 128) {
					$runLen++;
					continue;
				}
				$out .= chr(257 - $runLen) . chr($runByte);
				$runLen = 0;
				$runByte = -1;
			}
			$litLen = strlen($lit);
			if ($litLen > 0 && $c === ord($lit[$litLen - 1])) {
				if ($litLen > 1) {
					$out .= chr($litLen - 2) . substr($lit, 0, -1); // emit the literal, pull its last byte into a run
				}
				$lit = '';
				$runByte = $c;
				$runLen = 2;
				continue;
			}
			if ($litLen === 128) {                                  // literal full and this byte starts no run; flush before appending, so a run straddling the boundary still coalesces
				$out .= chr($litLen - 1) . $lit;
				$lit = '';
			}
			$lit .= $ch;
		}
		$this->_lit = $lit;
		$this->_runByte = $runByte;
		$this->_runLen = $runLen;
		return $out;
	}

	/**
	 * Emits the final pending packet.
	 * @return string The final bytes.
	 */
	public function finish(): string
	{
		if ($this->_runLen > 0) {
			return chr(257 - $this->_runLen) . chr($this->_runByte);
		}
		if ($this->_lit !== '') {
			return chr(strlen($this->_lit) - 1) . $this->_lit;
		}
		return '';
	}
}
