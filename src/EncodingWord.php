<?php
namespace Email;
abstract class EncodingWord extends Encoding
{
	abstract static function getToken() : string;

	abstract static function decodeWord(string $in): string;

	static function getAll(): array
	{
		return [
			EncodingQuotedPrintable::class,
			EncodingBase64::class,
		];
	}
}
