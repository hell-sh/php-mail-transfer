<?php /** @noinspection PhpUnused */
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

	abstract function getAllHeaders(): array;

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
		$search = strtolower($key).":";
		foreach($this->headers as &$header)
		{
			if(strtolower(substr($header, 0, strlen($search))) == $search)
			{
				$header = self::normaliseHeaderCasing($key).": ".$value;
				return $this;
			}
		}
		array_push($this->headers, self::normaliseHeaderCasing($key).": ".$value);
		return $this;
	}

	function hasHeader(string $key): bool
	{
		$search = strtolower($key).":";
		foreach($this->headers as $header)
		{
			if(substr($header, 0, strlen($search)) == $search)
			{
				return true;
			}
		}
		return false;
	}

	function getFirstHeaderValue(string $key): ?string
	{
		$search = strtolower($key).":";
		foreach($this->headers as $header)
		{
			if(strtolower(substr($header, 0, strlen($search))) == $search)
			{
				return trim(substr($header, strlen($search)));
			}
		}
		return null;
	}

	function getHeaderValues(string $key): array
	{
		$search = strtolower($key).":";
		$values = [];
		foreach($this->headers as $header)
		{
			if(strtolower(substr($header, 0, strlen($search))) == $search)
			{
				array_push($values, trim(substr($header, strlen($search))));
			}
		}
		return $values;
	}

	abstract function getBody(): string;

	function getData(): string
	{
		return join("\r\n", $this->getAllHeaders())."\r\n\r\n".$this->getBody();
	}
}
