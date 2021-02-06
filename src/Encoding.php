<?php
namespace Email;
abstract class Encoding
{
	abstract static function getName(): string;

	abstract static function encode(string $in): string;

	abstract static function decode(string $in): string;

	static function getAll(): array
	{
		return [
			EncodingQuotedPrintable::class,
			EncodingBase64::class,
			EncodingEightbit::class,
			EncodingSevenbit::class,
		];
	}

	static function fromName(string $name): ?string
	{
		foreach(self::getAll() as $encoding)
		{
			if(call_user_func($encoding.'::getName') == $name)
			{
				return $encoding;
			}
		}
		return null;
	}
}
