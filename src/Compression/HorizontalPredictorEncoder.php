<?php

/**
 * HorizontalPredictorEncoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/php-image
 * @license https://github.com/belisoful/php-image/blob/master/LICENSE
 */

namespace Belisoful\Image\Compression;

/**
 * HorizontalPredictorEncoder class.
 *
 * The incremental encoder half of the horizontal predictor: it applies TIFF horizontal
 * differencing (Predictor 2) to complete rows as they arrive.  See
 * {@see HorizontalPredictorCodec} for the row-buffering behavior and {@see HorizontalPredictor}
 * for the transform.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class HorizontalPredictorEncoder extends HorizontalPredictorCodec
{
	/**
	 * Differences complete rows.
	 * @param string $rows The row bytes.
	 * @return string The differenced bytes.
	 */
	protected function transform(string $rows): string
	{
		return HorizontalPredictor::encode($rows, $this->_columns, $this->_samples);
	}
}
