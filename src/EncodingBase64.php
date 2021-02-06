<?php
namespace Email;
class EncodingBase64 extends Encoding
{
	static function getName(): string
	{
		return "base64";
	}

	static function encode(string $in): string
	{
		return base64_encode($in);
	}

	static function decode(string $in): string
	{
		return base64_decode($in);
	}
}
