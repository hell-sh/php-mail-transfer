<?php
namespace Email;
abstract class EncodingTransient extends Encoding
{
	static function encode(string $in): string
	{
		return $in;
	}

	static function decode(string $in): string
	{
		return $in;
	}
}
