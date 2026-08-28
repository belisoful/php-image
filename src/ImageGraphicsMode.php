<?php

/**
 * ImageGraphicsMode class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image;

/**
 * ImageGraphicsMode class.
 *
 * Enumerates the graphics libraries {@see ImageGraphics} converts raster data through:
 * `GD` (ext-gd, `\GdImage`, {@see ImageGraphicsGD}) and `Imagick` (ext-imagick,
 * `\Imagick`, {@see ImageGraphicsImagick}).  A null mode in the {@see ImageGraphics}
 * methods selects the {@see ImageGraphics::getDefaultMode() default}, which prefers GD
 * and falls back to Imagick.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class ImageGraphicsMode
{
	public const GD = 'GD';
	public const Imagick = 'Imagick';
}
