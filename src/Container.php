<?php /** @noinspection PhpUnused */
namespace Email;
abstract class Container
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

	function getAllHeaderKeys(): array
	{
		$headers = $this->getAllHeaders();
		foreach($headers as &$header)
		{
			$header = self::normaliseHeaderCasing(substr($header, 0, strpos($header, ":")));
		}
		return $headers;
	}

	function getAllHeaderKeyValuePairs(): array
	{
		$headers = [];
		foreach($this->getAllHeaders() as $header)
		{
			$header = explode(":", $header, 2);
			$headers[self::normaliseHeaderCasing($header[0])] = $header[1];
		}
		return $headers;
	}

	function addHeader(string $key, string $value): self
	{
		array_unshift($this->headers, self::normaliseHeaderCasing($key).": ".$value);
		return $this;
	}

	function setHeader(string $key, string $value): self
	{
		$search = strtolower($key).":";
		$found = false;
		foreach($this->headers as $i => &$header)
		{
			if(strtolower(substr($header, 0, strlen($search))) == $search)
			{
				if($found)
				{
					unset($this->headers[$i]);
				}
				else
				{
					$header = self::normaliseHeaderCasing($key).": ".$value;
					$found = true;
				}
			}
		}
		if(!$found)
		{
			return $this->addHeader($key, $value);
		}
		return $this;
	}

	function removeHeader(string $key): self
	{
		$search = strtolower($key).":";
		foreach($this->headers as $i => $header)
		{
			if(strtolower(substr($header, 0, strlen($search))) == $search)
			{
				unset($this->headers[$i]);
			}
		}
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

	static function decodeHeaderValue(string $value): string
	{
		if(substr($value, 0, 2) == "=?" && substr($value, -2) == "?=")
		{
			$arr = explode("?", substr($value, 2, -2), 3);
			if($arr[1] == "Q")
			{
				return EncodingQuotedPrintable::decodeWord($arr[2]);
			}
			else if($arr[1] == "B")
			{
				return EncodingBase64::decodeWord($arr[2]);
			}
		}
		return $value;
	}

	abstract function getBody(): string;

	function getData(): string
	{
		return join("\r\n", $this->getAllHeaders())."\r\n\r\n".$this->getBody();
	}

	function getSmtpData(int $line_length = 78): string
	{
		$data = "";
		foreach($this->getAllHeaders() as $header)
		{
			$safe_line = "";
			$line = "";
			$cr = false;
			foreach(str_split($header) as $c)
			{
				if($c == "\r")
				{
					$cr = true;
					continue;
				}
				if($cr)
				{
					$cr = false;
					if($c == "\n")
					{
						$data .= $safe_line.$line."\r\n";
						$safe_line = "";
						$line = "";
						continue;
					}
				}
				if($c == " " || $c == "\t")
				{
					$safe_line .= $line;
					$line = "";
				}
				$line .= $c;
				if($safe_line && strlen($safe_line) + strlen($line) >= $line_length)
				{
					$data .= $safe_line."\r\n";
					$safe_line = "";
				}
			}
			$data .= $safe_line.$line."\r\n";
		}
		$data .= "\r\n";
		$body_rows = explode("\r\n", $this->getBody());
		if(count($body_rows) > 1 || (count($body_rows) == 1 && $body_rows[0] !== ""))
		{
			foreach ($body_rows as $row)
			{
				if (strlen($row) == 0)
				{
					$data .= "\r\n";
				}
				else
				{
					$i = 0;
					while (strlen($row) > $i)
					{
						$line = substr($row, $i, $line_length);
						if (substr($line, 0, 1) == ".")
						{
							$data .= ".";
						}
						$data .= $line;
						$data .= "\r\n";
						$i += $line_length;
					}
				}
			}
		}
		return $data;
	}
}
