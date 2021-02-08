<?php
namespace Email;
abstract class EncodingWord extends Encoding
{
	abstract static function getToken() : string;

	static function decodeWord(string $in): string
	{
		return static::decode($in);
	}

	static function getAll(): array
	{
		return [
			EncodingQuotedPrintable::class,
			EncodingBase64::class,
		];
	}
}
