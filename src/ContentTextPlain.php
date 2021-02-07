<?php
namespace Email;
class ContentTextPlain extends ContentText
{
	function __construct(string $text, string $charset = "UTF-8", string $encoding = EncodingQuotedPrintable::class)
	{
		parent::__construct($text, "text/plain; charset=$charset", $encoding);
	}
}
