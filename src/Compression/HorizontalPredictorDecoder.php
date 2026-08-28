<?php

/**
 * HorizontalPredictorDecoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * HorizontalPredictorDecoder class.
 *
 * The incremental decoder half of the horizontal predictor: it reverses TIFF horizontal
 * differencing (Predictor 2) over complete rows as they arrive.  See
 * {@see HorizontalPredictorCodec} for the row-buffering behavior and {@see HorizontalPredictor}
 * for the transform.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class HorizontalPredictorDecoder extends HorizontalPredictorCodec
{
	/**
	 * Accumulates complete rows, undoing the differencing.
	 * @param string $rows The differenced row bytes.
	 * @return string The raw sample bytes.
	 */
	protected function transform(string $rows): string
	{
		return HorizontalPredictor::decode($rows, $this->_columns, $this->_samples);
	}
}
