<?php
namespace Email;
class Client extends Connection
{
	const DEFAULT_CONNECT_TIMEOUT = 2;
	const DEFAULT_READ_TIMEOUT = 10;

	function __construct(string $address, int $port = 25, int $connect_timeout = Client::DEFAULT_CONNECT_TIMEOUT, int $read_timeout = Client::DEFAULT_READ_TIMEOUT, ?callable $log_line_function = Connection::LOGFUNC_NONE)
	{
		parent::__construct("$address:$port", $read_timeout, $log_line_function);
		$this->stream = @fsockopen($this->remote_name, $port, $errno, $errstr, $connect_timeout);
		if(!$this->stream)
		{
			throw new ExceptionConnectionNotEstablished("Failed to connect to {$this->remote_name}: $errstr ($errno)");
		}
		$this->log(self::LOGPREFIX_BIDIR, "Connection established");
		stream_set_blocking($this->stream, true);
		$this->initStream();
		$response = "";
		$this->readResponse(function($response_) use (&$response)
		{
			$response = $response_;
		}, function(Fail $fail) use (&$response)
		{
			$response = $fail->__toString();
		});
		stream_set_blocking($this->stream, false);
		if(substr($response, 0, 3) != "220")
		{
			$this->close();
			throw new ExceptionConnectionNotEstablished($response);
		}
	}

	private function readResponse(callable $callback, ?callable $on_fail): void
	{
		$deadline = microtime(true) + $this->read_timeout;
		$response = "";
		$this->startLoop(function() use ($callback, $on_fail, $deadline, &$line, &$response): void
		{
			while(true)
			{
				if($line = $this->readLine())
				{
					if(strlen($line) > 4 + 2)
					{
						$response .= substr($line, 4);
					}
					if(substr($line, 3, 1) == "-")
					{
						$line = "";
					}
					else
					{
						$response = rtrim(substr($line, 0, 3)." ".$response);
						$this->log(Connection::LOGPREFIX_RIGHT, $response);
						$this->endLoop();
						if($callback !== null)
						{
							$callback($response);
						}
						return;
					}
				}
				else if(microtime(true) > $deadline)
				{
					$this->endLoop();
					$this->fail($on_fail, Fail::TIMEOUT);
					return;
				}
			}
		});
	}

	private function populateCapabilities(string $response): void
	{
		$this->capabilities = [];
		$i = strpos($response, "\r\n");
		if($i === false)
		{
			return;
		}
		$response = substr($response, $i + 2);
		foreach(explode("\r\n", $response) as $line)
		{
			$arr = explode(" ", $line);
			$this->capabilities[strtoupper($arr[0])] = ($arr[1] ?? "");
		}
	}

	private function identify_smtp(callable $callback, ?callable $on_fail): void
	{
		$this->writeLine("HELO ".Machine::getHostname())->readResponse(function(string $response) use ($callback, $on_fail): void
		{
			if(substr($response, 0, 3) != "250")
			{
				$this->fail($on_fail, Fail::UNEXPECTED_RESPONSE, $response);
				return;
			}
			$this->populateCapabilities($response);
			$callback();
		}, $on_fail);
	}

	function identify(callable $callback, ?callable $on_fail = null): void
	{
		if($this->protocol == self::PROTOCOL_SMTP)
		{
			$this->identify_smtp($callback, $on_fail);
		}
		else
		{
			$this->writeLine("EHLO ".Machine::getHostname())->readResponse(function(string $response) use ($callback, $on_fail): void
			{
				if(substr($response, 0, 3) != "250")
				{
					if($this->protocol != self::PROTOCOL_TBD)
					{
						$this->fail($on_fail,Fail::UNEXPECTED_RESPONSE, $response);
						return;
					}
					$this->protocol = self::PROTOCOL_SMTP;
					$this->identify_smtp($callback, $on_fail);
					return;
				}
				$this->protocol = self::PROTOCOL_ESMTP;
				$this->populateCapabilities($response);
				$callback();
			}, $on_fail);
		}
	}

	function enableEncryption(callable $callback, ?callable $on_fail = null): void
	{
		if($this->protocol == Connection::PROTOCOL_ESMTP && !array_key_exists("STARTTLS", $this->capabilities))
		{
			$callback();
			return;
		}
		$this->writeLine("STARTTLS")->readResponse(function(string $response) use ($callback, $on_fail): void
		{
			if(substr($response, 0, 3) != "220")
			{
				$this->fail($on_fail, Fail::UNEXPECTED_RESPONSE, $response);
				return;
			}
			$this->startLoop(function() use ($callback, $on_fail): void
			{
				$ret = stream_socket_enable_crypto($this->stream,true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
				if($ret === 0)
				{
					return;
				}
				$this->endLoop();
				if($ret !== true)
				{
					$this->fail($on_fail, Fail::STARTTLS_FAILED, "stream_socket_enable_crypto returned ".strval($ret));
					return;
				}
				$crypto_data = stream_get_meta_data($this->stream)["crypto"];
				$this->log(Connection::LOGPREFIX_BIDIR, "Agreed on ".$crypto_data["protocol"]." using cipher ".$crypto_data["cipher_name"]);
				$callback();
			});
		}, $on_fail);
	}

	function smartHandshake(callable $callback, ?callable $on_fail = null): void
	{
		$this->identify(function() use ($callback, $on_fail)
		{
			$this->enableEncryption(function() use ($callback, $on_fail)
			{
				$this->identify($callback, $on_fail);
			}, $callback);
		}, $on_fail);
	}

	function sendEmail(Email $email, ?callable $callback = null, ?callable $on_fail = null): void
	{
		$this->writeLine("MAIL FROM:<".$email->getSender()->address.">")
			 ->readResponse(function(string $response) use ($email, $callback, $on_fail): void
			{
				if(substr($response, 0, 3) != "250")
				{
					$this->fail($on_fail, Fail::UNEXPECTED_RESPONSE, $response);
					return;
				}
				$this->writeLine("RCPT TO:<".$email->getRecipient()->address.">")
					->readResponse(function(string $response) use ($email, $callback, $on_fail): void
					{
						if(substr($response, 0, 3) != "250")
						{
							$this->fail($on_fail, Fail::UNEXPECTED_RESPONSE, $response);
							return;
						}
						$this->writeLine("DATA")
							->readResponse(function(string $response) use ($email, $callback, $on_fail): void
							{
								if(substr($response, 0, 3) != "354")
								{
									$this->fail($on_fail, Fail::UNEXPECTED_RESPONSE, $response);
									return;
								}
								$this->writeRaw($email->getSmtpData());
								$this->writeLine(".")->readResponse(function(string $response) use ($callback, $on_fail): void
								{
									$code = substr($response, 0, 3);
									if($code != "250")
									{
										if($code == "421" || $code == "451")
										{
											$this->fail($on_fail, Fail::RATE_LIMITED);
										}
										else
										{
											$this->fail($on_fail, Fail::UNEXPECTED_RESPONSE, $response);
										}
										return;
									}
									if(is_callable($callback))
									{
										$callback();
									}
								}, $on_fail);
							}, $on_fail);
					}, $on_fail);
			}, $on_fail);
	}
}
