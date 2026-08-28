<?php

/**
 * TestIOHelper class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

use Psr\Http\Message\StreamInterface;

/**
 * TestIOHelper builds and inspects PHP stream resources for unit tests.  Resources and streams are a universal aspect of PHP, so any test — not just
 * the IO layer's — can lean on these factories instead of re-opening `php://temp` by hand.
 *
 * The factories cover the common shapes:
 *
 * | Method                       | Produces                                              |
 * |------------------------------|-------------------------------------------------------|
 * | {@see memoryResource()}      | a `php://memory` resource                             |
 * | {@see tempResource()}        | a `php://temp` resource                               |
 * | {@see dataResource()}        | a `php://temp` resource seeded with bytes and rewound |
 * | {@see fileResource()}        | a file resource                                       |
 * | {@see pipeResource()}        | a non-seekable, read-only pipe carrying given bytes   |
 *
 * {@see contents()} reads a stream or resource in full (rewinding first when seekable),
 * {@see closeAny()} closes either a pipe or a plain resource, and {@see tempFile()} /
 * {@see removeTempFiles()} manage scratch files so a test's tearDown can clean up in one
 * call.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TestIOHelper
{
	/** @var array<int, string> Scratch file paths created by {@see tempFile()}. */
	private static array $_tempFiles = [];

	/** @var array<int, true> Resource ids of pipes opened by {@see pipeResource()}. */
	private static array $_pipes = [];

	// -------------------------------------------------------------------------
	// Raw resource factories
	// -------------------------------------------------------------------------

	/**
	 * Opens a php://memory resource.
	 * @param string $mode The fopen mode. Default 'r+b'.
	 * @return resource The open resource.
	 */
	public static function memoryResource(string $mode = 'r+b')
	{
		return fopen('php://memory', $mode);
	}

	/**
	 * Opens a php://temp resource.
	 * @param string $mode The fopen mode. Default 'r+b'.
	 * @return resource The open resource.
	 */
	public static function tempResource(string $mode = 'r+b')
	{
		return fopen('php://temp', $mode);
	}

	/**
	 * Opens a php://temp resource seeded with bytes and rewound to the start.
	 * @param string $data The initial contents.
	 * @param string $mode The fopen mode. Default 'r+b'.
	 * @return resource The seeded, rewound resource.
	 */
	public static function dataResource(string $data, string $mode = 'r+b')
	{
		$resource = fopen('php://temp', $mode);
		if ($data !== '') {
			fwrite($resource, $data);
			rewind($resource);
		}
		return $resource;
	}

	/**
	 * Opens a file resource.
	 * @param string $path The file path.
	 * @param string $mode The fopen mode. Default 'rb'.
	 * @return resource The open resource.
	 */
	public static function fileResource(string $path, string $mode = 'rb')
	{
		return fopen($path, $mode);
	}

	/**
	 * Opens a non-seekable, read-only pipe carrying the given bytes (via {@see popen()}),
	 * for exercising non-seekable stream paths.
	 * @param string $data The bytes the pipe yields.
	 * @return resource The open pipe resource (close with {@see closeAny()}).
	 */
	public static function pipeResource(string $data)
	{
		$path = static::tempFile($data, 'iopipe');
		$command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('readfile(' . var_export($path, true) . ');');
		$pipe = popen($command, 'r');
		if (is_resource($pipe)) {
			self::$_pipes[(int) $pipe] = true;
		}
		return $pipe;
	}

	// -------------------------------------------------------------------------
	// Inspection & cleanup
	// -------------------------------------------------------------------------

	/**
	 * Reads a stream or raw resource in full, rewinding first when it is seekable.
	 * @param resource|StreamInterface $streamOrResource The stream or resource.
	 * @return string The full contents.
	 */
	public static function contents($streamOrResource): string
	{
		if ($streamOrResource instanceof StreamInterface) {
			if ($streamOrResource->isSeekable()) {
				$streamOrResource->seek(0);
			}
			return $streamOrResource->getContents();
		}
		$meta = stream_get_meta_data($streamOrResource);
		if (!empty($meta['seekable'])) {
			rewind($streamOrResource);
		}
		return (string) stream_get_contents($streamOrResource);
	}

	/**
	 * Closes a resource, using {@see pclose()} for a pipe and {@see fclose()} otherwise.
	 * @param resource $resource The resource to close.
	 */
	public static function closeAny($resource): void
	{
		if (!is_resource($resource)) {
			return;
		}
		$id = (int) $resource;
		if (isset(self::$_pipes[$id])) {
			unset(self::$_pipes[$id]);
			@pclose($resource);
			return;
		}
		fclose($resource);
	}

	/**
	 * Creates a scratch file (registered for {@see removeTempFiles()}), optionally seeded.
	 * @param string $contents The initial contents. Default '' (empty file).
	 * @param string $prefix The temp-name prefix. Default 'iotest'.
	 * @return string The file path.
	 */
	public static function tempFile(string $contents = '', string $prefix = 'iotest'): string
	{
		$path = tempnam(sys_get_temp_dir(), $prefix);
		if ($contents !== '') {
			file_put_contents($path, $contents);
		}
		self::$_tempFiles[] = $path;
		return $path;
	}

	/**
	 * Deletes every scratch file created by {@see tempFile()} and clears the registry.
	 */
	public static function removeTempFiles(): void
	{
		foreach (self::$_tempFiles as $path) {
			if (is_file($path)) {
				@unlink($path);
			}
		}
		self::$_tempFiles = [];
	}
}
