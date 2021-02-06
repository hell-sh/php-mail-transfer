<?php
namespace Email;
class EncodingQuotedPrintable extends Encoding
{
	static function getName(): string
	{
		return "quoted-printable";
	}

	static function encode(string $in): string
	{
		$out = "";
		foreach(str_split($in) as $c)
		{
			$b = ord($c);
			if(($b < 32 || $b > 126 || $b == 61) && $b != 9)
			{
				$out .= "=".sprintf("%02X", $b);
			}
			else
			{
				$out .= $c;
			}
		}
		return $out;
	}

	static function decode(string $in): string
	{
		$in = str_replace(["\r", "\n"], "", $in);
		$i = $j = 0;
		$out = "";
		do
		{
			$j = strpos($in, "=", $i);
			if($j === false)
			{
				break;
			}
			$out .= substr($in, $i, $j - $i);
			$out .= chr(hexdec(substr($in, $j + 1, 2)));
			$i = $j + 3;
		}
		while(true);
		$out .= substr($in, $i);
		return $out;
	}

	static function dkim_encode(string $in): string
	{
		$out = "";
		foreach(str_split($in) as $c)
		{
			$b = ord($c);
			if ((0x21 <= $b && $b <= 0x3A)
				|| $b == 0x3C
				|| (0x3E <= $b && $b <= 0x7E /*&& $b != 0x7C*/))
			{
				$out .= $c;
			}
			else
			{
				$out .= "=".sprintf("%02X", $b);
			}
		}
		return $out;
	}
}
