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
		foreach(explode("\r\n", $this->getBody()) as $row)
		{
			$i = 0;
			while(strlen($row) > $i)
			{
				$line = substr($row, $i, $line_length);
				if(substr($line, 0, 1) == ".")
				{
					$data .= ".";
				}
				$data .= $line;
				$data .= "\r\n";
				$i += $line_length;
			}
		}
		return $data;
	}
}
