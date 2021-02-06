<?php
namespace Email;
abstract class Section
{
	/**
	 * @var array $headers
	 */
	var $headers;

	function __construct(array $headers)
	{
		$this->headers = $headers;
	}

	abstract function getEffectiveHeaders(): array;

	static function normaliseHeaderCasing(string $key): string
	{
		$words = explode("-", $key);
		foreach($words as &$word)
		{
			$word = strtoupper($word);
			switch($word)
			{
				case "DKIM":
				case "ID":
				case "MIME":
				case "SPF":
				case "X":
					continue 2;
			}
			$word = substr($word, 0, 1).strtolower(substr($word, 1));
		}
		return join("-", $words);
	}

	function setHeader(string $key, string $value): self
	{
		$this->headers[self::normaliseHeaderCasing($key)] = $value;
		return $this;
	}

	function hasHeader(string $key): bool
	{
		return array_key_exists(self::normaliseHeaderCasing($key), $this->getEffectiveHeaders());
	}

	function getHeader(string $key): string
	{
		return $this->getEffectiveHeaders()[self::normaliseHeaderCasing($key)] ?? "";
	}

	abstract function getBody(): string;

	function getData(): string
	{
		$data = "";
		foreach($this->getEffectiveHeaders() as $key => $value)
		{
			$data .= "$key: $value\r\n";
		}
		$data .= "\r\n".$this->getBody();
		return $data;
	}
}
