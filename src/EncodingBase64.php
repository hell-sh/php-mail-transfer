<?php
namespace Email;
class EncodingBase64 extends EncodingWord
{
	static function getName(): string
	{
		return "base64";
	}

	static function getToken(): string
	{
		return "B";
	}

	static function encode(string $in): string
	{
		return base64_encode($in);
	}

	static function decode(string $in): string
	{
		return base64_decode($in);
	}

	static function decodeWord(string $in): string
	{
		return base64_decode($in);
	}
}
