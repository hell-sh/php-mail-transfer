<?php
namespace Email;
class EncodingQuotedPrintable extends EncodingWord
{
	static function getName(): string
	{
		return "quoted-printable";
	}

	static function getToken(): string
	{
		return "Q";
	}

	private static function ensureSpace(string &$out, string &$line, int $length_limit)
	{
		if(strlen($line) > $length_limit)
		{
			$out .= substr($line, 0, $length_limit)."=\r\n";
			$line = substr($line, $length_limit);
		}
	}

	static function encode(string $in): string
	{
		$out = "";
		$line = "";
		foreach(str_split($in) as $c)
		{
			$b = ord($c);
			if($b == 10 || $b == 13)
			{
				$out .= $line.$c;
				$line = "";
			}
			else if(($b >= 33 && $b <= 60) || ($b >= 62 && $b <= 126) || $b == 9 || $b == 32)
			{
				self::ensureSpace($out, $line, 75);
				$line .= $c;
			}
			else
			{
				self::ensureSpace($out, $line, 72);
				$line .= "=".sprintf("%02X", $b);
			}
		}
		return $out.$line;
	}

	static function decode(string $in): string
	{
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
			$hex = substr($in, $j + 1, 2);
			if(strlen($hex) != 2)
			{
				$out .= $hex;
				break;
			}
			if($hex != "\r\n")
			{
				$out .= chr(hexdec($hex));
			}
			$i = $j + 3;
		}
		while(true);
		$out .= substr($in, $i);
		return $out;
	}

	static function decodeWord(string $in): string
	{
		return str_replace("_", " ", self::decode($in));
	}
}
