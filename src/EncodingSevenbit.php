<?php
namespace Email;
class EncodingSevenbit extends EncodingTransient
{
	static function getName(): string
	{
		return "7bit";
	}
}
