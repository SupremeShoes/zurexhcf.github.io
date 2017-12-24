<?php

namespace XF\Util;

class Json
{
	/**
	 * @param mixed $input
	 * @param bool $standardizeLineEndings If true, line endings are standardized on \n (including within JSON values)
	 * @param int|null $options Options to pass to json_encode
	 *
	 * @return string
	 */
	public static function jsonEncodePretty($input, $standardizeLineEndings = true, $options = null)
	{
		if ($options === null)
		{
			$options = JSON_UNESCAPED_SLASHES;
		}
		$options |= JSON_PRETTY_PRINT;

		$output = json_encode($input, $options);

		// PHP 5.4 outputs line breaks in empty arrays
		$output = preg_replace('#\[\n\s*\]#', '[]', $output);

		if ($standardizeLineEndings)
		{
			$output = str_replace("\r", '', $output);
			$output = preg_replace('#(?<!\\\\)\\\\r#', '', $output);
		}

		return $output;
	}

	protected static $jsonErrors = [
		JSON_ERROR_NONE => 'No error',
		JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
		JSON_ERROR_STATE_MISMATCH => 'State mismatch (invalid or malformed JSON)',
		JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
		JSON_ERROR_SYNTAX => 'Syntax error',
		JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded'
	];

	/**
	 * Returns the error string of the last json_encode() or json_decode() call
	 *
	 * @link http://php.net/manual/en/function.json-last-error-msg.php
	 * @return bool|string|null
	 */
	public static function jsonLastErrorMsg()
	{
		if (!function_exists('json_last_error_msg'))
		{
			$errors = self::$jsonErrors;
			$error = json_last_error();
			return isset($errors[$error]) ? $errors[$error] : false;
		}
		else
		{
			return json_last_error_msg();
		}
	}
}