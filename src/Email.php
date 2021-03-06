<?php /** @noinspection PhpUnused */
namespace Email;
use DateTime;
class Email extends Container
{
	const SEND_OK = 0;
	const SEND_TEMP_FAIL = 1;
	const SEND_PERM_FAIL = 2;

	/**
	 * @var Content|null $content
	 */
	var $content;

	function __construct(array $headers = [], ?Content $content = null)
	{
		parent::__construct($headers);
		$this->content = $content;
	}

	static function init(Content $content, array $headers = []): Email
	{
		return new Email(array_merge([
			"Date: ".date(DATE_RFC2822),
			"MIME-Version: 1.0",
		], $headers), $content);
	}

	static function basic(Address $sender, Address $recipient, string $subject, Content $content): Email
	{
		return Email::init($content, [
			"From: ".$sender->__toString(),
			"To: ".$recipient->__toString(),
			"Subject: ".$subject,
		]);
	}

	function getAllHeaders(): array
	{
		if($this->content instanceof Content)
		{
			return array_merge($this->headers, $this->content->headers);
		}
		return $this->headers;
	}

	function setDate(int $timestamp): self
	{
		return $this->setHeader("Date", date(DATE_RFC2822, $timestamp));
	}

	function getDate(): int
	{
		$dt = DateTime::createFromFormat(DATE_RFC2822, $this->getFirstHeaderValue("Date"));
		if($dt instanceof DateTime)
		{
			return $dt->getTimestamp();
		}
		return 0;
	}

	function getSender(): ?Address
	{
		$from = $this->getFirstHeaderValue("From");
		if($from === null)
		{
			return null;
		}
		return new Address($from);
	}

	function getSenders(): array
	{
		return array_map('new Address', $this->getHeaderValues("From"));
	}

	function getRecipient(): ?Address
	{
		$to = $this->getFirstHeaderValue("To");
		if($to === null)
		{
			return null;
		}
		return new Address($to);
	}

	function getRecipients(): array
	{
		return array_map('new Address', $this->getHeaderValues("To"));
	}

	function setSubject(string $subject): self
	{
		return $this->setHeader("Subject", $subject);
	}

	function getSubject(): string
	{
		return self::decodeHeaderValue($this->getFirstHeaderValue("Subject"));
	}

	static function canonicalizeHeader(string $canonicalization, string $key, string $value): string
	{
		if($canonicalization == "simple")
		{
			return self::normaliseHeaderCasing($key).": ".$value;
		}
		return strtolower($key).":".trim(preg_replace("/\s+/", " ", $value));
	}

	function getCanonicalizedHeaders(string $canonicalization, array $signed_headers, string $partial_dkim_signature): string
	{
		$data = "";
		$header_count = [];
		foreach($signed_headers as $key)
		{
			if(!array_key_exists($key, $header_count))
			{
				$header_count[$key] = 0;
			}
			$value = @$this->getHeaderValues($key)[$header_count[$key]++];
			if($value !== null)
			{
				$data .= self::canonicalizeHeader($canonicalization, $key, $value)."\r\n";
			}
		}
		$data .= self::canonicalizeHeader($canonicalization, "DKIM-Signature", $partial_dkim_signature);
		return $data;
	}

	function getCanonicalizedBody(string $canonicalization): string
	{
		$body = $this->getBody();
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
			$body = preg_replace("/ +/", " ", $body);
		}
		return $body;
	}

	function sign(DkimKey $key, array $headers_to_sign = ["From", "To", "Subject"]): self
	{
		$time = time();
		$domain = $this->getSender()->getDomain();
		$dkim_signature =
			"v=1; a=rsa-sha256; q=dns/txt; s=".$key->selector."; t=$time; c=relaxed/simple; ".
			"h=".join(":", $headers_to_sign)."; d=$domain; ".
			"bh=".base64_encode(pack("H*", hash("sha256", $this->getCanonicalizedBody("simple"))))."; ".
			"b=";
		$this->addHeader("DKIM-Signature", $dkim_signature.$key->sign($this->getCanonicalizedHeaders("relaxed", $headers_to_sign,$dkim_signature), OPENSSL_ALGO_SHA256));
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

	function verifyDkimSignature(string $dkim_signature): string
	{
		$dkim_data = self::parseKeyValuePairs($dkim_signature);
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
		$signed_data = $this->getCanonicalizedHeaders($canonicalizations[0], explode(":", $dkim_data["h"]), substr($dkim_signature, 0, strpos($dkim_signature, "b=".$dkim_data["b"]) + 2));
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
		return $this->content instanceof Content ? $this->content->getBody() : "";
	}

	function sendToRecipient(int $connect_timeout = Client::DEFAULT_CONNECT_TIMEOUT, int $read_timeout = Client::DEFAULT_READ_TIMEOUT, ?callable $log_line_function = Connection::LOGFUNC_NONE): int
	{
		$status = self::SEND_PERM_FAIL;
		$con = $this->getRecipient()->createConnection($connect_timeout, $read_timeout, $log_line_function);
		if($con instanceof Connection)
		{
			$con->smartHandshake(function() use (&$status, $con)
			{
				$con->sendEmail($this, function() use (&$status, $con)
				{
					$con->close();
					$status = self::SEND_OK;
				}, function(Fail $fail) use (&$status, $con)
				{
					$con->close();
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
			substr($data, $header_i + 4, -2)
		);
	}

	private static function fromData2(string $headers, string $body): Email
	{
		$email = new Email(explode("\r\n", $headers));
		$encoding = $email->getFirstHeaderValue("Content-Transfer-Encoding");
		if($encoding !== null)
		{
			$encoding = Encoding::fromName($encoding);
		}
		if($encoding === null)
		{
			$encoding = EncodingSevenbit::class;
		}
		$email->content = new ContentText(
			call_user_func($encoding.'::decode', $body),
			$email->getFirstHeaderValue("Content-Type") ?? "text/plain",
			$encoding
		);
		foreach($email->content->getAllHeaderKeys() as $key)
		{
			$email->removeHeader($key);
		}
		return $email;
	}
}
