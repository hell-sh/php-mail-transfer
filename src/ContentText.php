<?php
namespace Email;
class ContentText extends Content
{
	/**
	 * @var string $text
	 */
	var $text;
	/**
	 * @var string $encoding
	 */
	var $encoding;

	function __construct(string $text, string $content_type, string $encoding = EncodingQuotedPrintable::class)
	{
		parent::__construct([
			"Content-Type: $content_type",
			"Content-Transfer-Encoding: ". call_user_func($encoding.'::getName')
		]);
		$this->text = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text));
		$this->encoding = $encoding;
	}

	function getBody(): string
	{
		return call_user_func($this->encoding.'::encode', $this->text);
	}
}
