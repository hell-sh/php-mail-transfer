<?php /** @noinspection PhpUnused */
namespace Email;
use DateTime;
class Email extends Section
{
	const SEND_OK = 0;
	const SEND_TEMP_FAIL = 1;
	const SEND_PERM_FAIL = 2;

	/**
	 * @var Content|null $content
	 */
	var $content;

	function __construct(array $headers, ?Content $content = null)
	{
		parent::__construct($headers);
		$this->content = $content;
	}

	static function init(Content $content, array $headers = []): Email
	{
		return new Email([
			"Date" => date(DATE_RFC2822),
			"MIME-Version" => "1.0"
		] + $headers, $content);
	}

	static function basic(Address $sender, Address $recipient, string $subject, Content $content): Email
	{
		return Email::init($content, [
			"From" => $sender->__toString(),
			"To" => $recipient->__toString(),
			"Subject" => $subject
		]);
	}

	function getEffectiveHeaders(): array
	{
		if($this->content instanceof Content)
		{
			return $this->content->headers + $this->headers;
		}
		return $this->headers;
	}

	function getDate(): int
	{
		$dt = DateTime::createFromFormat(DATE_RFC2822, $this->getHeader(""));
		if($dt instanceof DateTime)
		{
			return $dt->getTimestamp();
		}
		return 0;
	}

	function getSender(): Address
	{
		return new Address($this->getHeader("From"));
	}

	function getRecipient(): Address
	{
		return new Address($this->getHeader("To"));
	}

	function setSubject(string $subject): self
	{
		return $this->setHeader("Subject", $subject);
	}

	function getSubject(): string
	{
		return $this->getHeader("Subject");
	}

	function getCanonicalizedHeader(string $canonicalization, string $key): string
	{
		if($canonicalization == "simple")
		{
			return self::normaliseHeaderCasing($key).": ".$this->getHeader($key);
		}
		return strtolower($key).":".trim(preg_replace("/\s+/", " ", $this->getHeader($key)));
	}

	function getCanonicalizedHeaders(string $canonicalization, array $headers): string
	{
		$data = "";
		foreach($headers as $header)
		{
			if($this->hasHeader($header))
			{
				$data .= $this->getCanonicalizedHeader($canonicalization, $header)."\r\n";
			}
		}
		$data .= $this->getCanonicalizedHeader($canonicalization, "DKIM-Signature");
		return $data;
	}

	function getCanonicalizedBody(string $canonicalization): string
	{
		$body = $this->content->getBody();
		if(substr($body, -2) == "\r\n")
		{
			while(substr($body, -4) == "\r\n\r\n")
			{
				$body = substr($body, 0, -2);
			}
		}
		else
		{
			$body .= "\r\n";
		}
		if($canonicalization == "relaxed")
		{
			$body = preg_replace("/\s+/", " ", $body);
		}
		return $body;
	}

	function sign(DkimKey $key, array $headers_to_sign = ["From", "To", "Subject"]): self
	{
		$time = time();
		$domain = $this->getSender()->getDomain();
		$this->headers["DKIM-Signature"] =
			"v=1; a=rsa-sha256; q=dns/txt; s=".$key->selector."; t=$time; c=relaxed/simple; ".
			"h=".join(":", $headers_to_sign)."; d=$domain; ".
			"bh=".base64_encode(pack("H*", hash("sha256", $this->getCanonicalizedBody("simple"))))."; ".
			"b=";
		$this->headers["DKIM-Signature"] .= $key->sign($this->getCanonicalizedHeaders("relaxed", $headers_to_sign), OPENSSL_ALGO_SHA256);
		return $this;
	}

	static function parseKeyValuePairs(string $in, string $kv_separator = "=", string $pair_separator = ";"): array
	{
		$out = [];
		foreach(explode($pair_separator, $in) as $kv_pair)
		{
			$kv_pair = explode($kv_separator, $kv_pair, 2);
			$out[trim($kv_pair[0])] = trim($kv_pair[1] ?? "");
		}
		return $out;
	}

	function getDkimData(): array
	{
		return self::parseKeyValuePairs($this->getHeader("DKIM-Signature"));
	}

	static function getTxtRecords(string $query): array
	{
		$records = dns_get_record($query, DNS_TXT);
		$txts = [];
		foreach($records as $record)
		{
			array_push($txts, $record["txt"]);
		}
		return $txts;
	}

	/** @noinspection PhpMissingReturnTypeInspection */
	private static function getDkimPublicKey(string $selector, string $domain, string $hash_algo)
	{
		$reject_reasons = [];
		foreach(self::getTxtRecords("$selector._domainkey.$domain") as $txt)
		{
			$dkim_data = self::parseKeyValuePairs($txt);
			if(!array_key_exists("p", $dkim_data))
			{
				array_push($reject_reasons, "p missing");
				continue;
			}
			if(array_key_exists("v", $dkim_data) && $dkim_data["v"] != "DKIM1")
			{
				array_push($reject_reasons, "version unsupported");
				continue;
			}
			if(array_key_exists("k", $dkim_data) && $dkim_data["k"] != "rsa")
			{
				array_push($reject_reasons, "k unsupported");
				continue;
			}
			if(!in_array($dkim_data["s"] ?? "*", ["*", "email"]))
			{
				array_push($reject_reasons, "s mismatch");
				continue;
			}
			if(array_key_exists("h", $dkim_data) && !in_array($hash_algo, explode(":", $dkim_data["h"])))
			{
				array_push($reject_reasons, "h mismatch");
				continue;
			}
			$pub = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\r\n".$dkim_data["p"]."\r\n-----END PUBLIC KEY-----\r\n");
			if($pub === false)
			{
				array_push($reject_reasons, "invalid format");
				continue;
			}
			return $pub;
		}
		return $reject_reasons;
	}

	function verifyDkimSignature(): string
	{
		$dkim_data = $this->getDkimData();
		foreach(["a", "b", "bh", "d", "h", "s"] as $tag)
		{
			if(!array_key_exists($tag, $dkim_data))
			{
				return $tag." missing";
			}
		}
		if($dkim_data["v"] != "1")
		{
			return "version unsupported";
		}
		if(array_key_exists("q", $dkim_data) && !in_array("dns/txt", explode(":", $dkim_data["q"])))
		{
			return "no supported query mechanism";
		}
		// TODO: Allow subdomains unless domainkey has t=s (note that t is colon-separated)
		if($dkim_data["d"] != $this->getSender()->getDomain())
		{
			return "domain mismatch";
		}
		if(array_key_exists("t", $dkim_data) && $this->hasHeader("Date") && $dkim_data["t"] < $this->getDate())
		{
			return "signature predates email";
		}
		if(array_key_exists("x", $dkim_data) && $dkim_data["x"] <= time())
		{
			return "expired";
		}
		if($dkim_data["a"] != "rsa-sha1" && $dkim_data["a"] != "rsa-sha256")
		{
			return "algorithm unsupported";
		}
		$canonicalizations = ["simple", "simple"];
		if(array_key_exists("c", $dkim_data))
		{
			$i = strpos($dkim_data["c"], "/");
			if($i === false)
			{
				$canonicalizations[0] = $dkim_data["c"];
			}
			else
			{
				$canonicalizations[0] = substr($dkim_data["c"], 0, $i);
				$canonicalizations[1] = substr($dkim_data["c"], $i + 1);
			}
			foreach($canonicalizations as $canonicalization)
			{
				if(!in_array($canonicalization, ["relaxed", "simple"]))
				{
					return "$canonicalization canonicalization unsupported";
				}
			}
		}
		$body = $this->getCanonicalizedBody($canonicalizations[1]);
		if(array_key_exists("l", $dkim_data))
		{
			$body = substr($body, 0, intval($dkim_data["l"]));
		}
		$hash_algo = substr($dkim_data["a"], 4);
		$body_hash = base64_encode(pack("H*", hash($hash_algo, $body)));
		if($dkim_data["bh"] != $body_hash)
		{
			return "bh is not ".$body_hash;
		}
		$pub = self::getDkimPublicKey($dkim_data["s"], $dkim_data["d"], $hash_algo);
		if(is_array($pub))
		{
			if(count($pub))
			{
				return "no matching public key (".join(", ", $pub).")";
			}
			else
			{
				return "no public key found";
			}
		}
		$og_value = $this->headers["DKIM-Signature"];
		$this->headers["DKIM-Signature"] = substr($this->headers["DKIM-Signature"], 0, strpos($this->headers["DKIM-Signature"], "b=".$dkim_data["b"]) + 2);
		$signed_data = $this->getCanonicalizedHeaders($canonicalizations[0], explode(":", $dkim_data["h"]));
		$this->headers["DKIM-Signature"] = $og_value;
		$verify_res = openssl_verify($signed_data, base64_decode($dkim_data["b"]), $pub, $dkim_data["a"] == "rsa-sha256" ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);
		if(PHP_MAJOR_VERSION < 8)
		{
			openssl_pkey_free($pub);
		}
		if($verify_res !== 1)
		{
			return "signature mismatch";
		}
		return "pass";
	}

	function getBody(): string
	{
		return $this->content->getBody();
	}

	function getSmtpData(int $line_length = 78): string
	{
		$data = "";
		foreach($this->getEffectiveHeaders() as $key => $value)
		{
			$safe_line = "";
			$line = "";
			$cr = false;
			foreach(str_split("$key: $value") as $c)
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
				if(strlen($safe_line) + strlen($line) >= $line_length)
				{
					$data .= $safe_line."\r\n";
					$safe_line = "";
				}
			}
			$data .= $safe_line.$line."\r\n";
		}
		$data .= "\r\n";
		foreach(explode("\r\n", $this->content->getBody()) as $row)
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

	function sendToRecipient(int $timeout = Client::DEFAULT_TIMEOUT, ?callable $log_line_function = Connection::LOGFUNC_NONE): int
	{
		$status = self::SEND_PERM_FAIL;
		$con = $this->getRecipient()->createConnection($timeout, $log_line_function);
		if($con instanceof Connection)
		{
			$con->smartHandshake(function() use (&$status, $con)
			{
				$con->sendEmail($this, function() use (&$status)
				{
					$status = self::SEND_OK;
				}, function(Fail $fail) use (&$status)
				{
					if($fail->type == Fail::RATE_LIMITED
						|| ($fail->type == FAIL::UNEXPECTED_RESPONSE && substr($fail->extra, 0, 1) == "4"))
					{
						$status = self::SEND_TEMP_FAIL;
					}
				});
			});
		}
		return $status;
	}

	static function fromData(string $data): Email
	{
		$header_i = strpos($data, "\r\n\r\n");
		if($header_i === false)
		{
			$header_i = strlen($data);
		}
		return Email::fromData2(
			substr($data, 0, $header_i),
			substr($data, $header_i + 4)
		);
	}

	static function fromSmtpData(string $data): Email
	{
		$header_i = strpos($data, "\r\n\r\n");
		if($header_i === false)
		{
			$header_i = strlen($data);
		}
		return Email::fromData2(
			str_replace(["\r\n ", "\r\n\t"], [" ", "\t"], substr($data, 0, $header_i)),
			substr($data, $header_i + 4)
		);
	}

	private static function fromData2(string $headers, string $body): Email
	{
		$email = new Email(self::headersFromData($headers));
		$encoding = null;
		if($email->getHeader("Content-Transfer-Encoding"))
		{
			$encoding = Encoding::fromName($email->getHeader("Content-Transfer-Encoding"));
		}
		$encoding = $encoding ?? EncodingSevenbit::class;
		$email->content = new ContentText(
			call_user_func($encoding.'::decode', $body),
			$email->getHeader("Content-Type") ?: "text/plain"
		);
		return $email;
	}

	static function headersFromData(string $data): array
	{
		$headers = [];
		foreach(explode("\r\n", $data) as $line)
		{
			if(empty($line))
			{
				continue;
			}
			$arr = explode(": ", $line, 2);
			$headers[$arr[0]] = $arr[1] ?? "";
		}
		return $headers;
	}
}
