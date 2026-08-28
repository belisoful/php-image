<?php

/**
 * StreamCodec interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * StreamCodec interface.
 *
 * An incremental byte-stream codec context, modeled on PHP's own {@see deflate_init()}/
 * {@see deflate_add()} (and {@see inflate_init()}/{@see inflate_add()}): a factory hands
 * back a fresh context, {@see add()} pushes each input chunk and returns whatever output
 * is ready, and {@see finish()} flushes the trailing state.  A context is single-use and
 * single-direction; {@see CompressorInterface::compress()}/{@see CompressorInterface::decompress()}
 * are the whole-string convenience over the same engine (`add($all) . finish()`).
 *
 * A context holds only the bounded state its algorithm needs — a carry buffer, a
 * dictionary, a partial byte — so it transforms a stream of any size in constant memory.
 * That makes it self-contained enough to drive from a native PHP {@see \php_user_filter}:
 * the filter's `filter()` loop feeds each bucket to {@see add()} and appends {@see finish()}
 * on close.
 *
 * ```php
 * $enc = LZWCompressor::encoder();
 * $out  = $enc->add($chunkA);
 * $out .= $enc->add($chunkB);
 * $out .= $enc->finish();
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
interface StreamCodec
{
	/**
	 * Pushes a chunk of input and returns the output produced so far.  Bounded state is
	 * carried to the next call, so a field split across chunks is handled correctly.
	 * @param string $data The input chunk (may be '').
	 * @return string The output produced from this chunk (may be '').
	 */
	public function add(string $data): string;

	/**
	 * Flushes any pending state and returns the final output.  After this the context is
	 * spent; further {@see add()} calls are not defined.
	 * @return string The final output (may be '').
	 */
	public function finish(): string;
}
