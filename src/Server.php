<?php /** @noinspection PhpUnused */
namespace Email;
use Asyncore\Asyncore;
use SPFLib\
{Check\Environment, Check\Result, Checker};
use SplObjectStorage;
class Server
{
	const BIND_ADDR_ALL = ["0.0.0.0", "::"];
	const BIND_ADDR_ALL_IP4 = ["0.0.0.0"];
	const BIND_ADDR_ALL_IP6 = ["::"];
	const BIND_ADDR_LOCAL = ["127.0.0.1", "::1"];

	const BIND_PORT_DEFAULT = [25, 587];

	const METHOD_PASSES_REQUIRED = 1;

	/**
	 * @var array $streams
	 */
	var $streams;
	/**
	 * @var int $session_read_timeout
	 */
	var $session_read_timeout;
	/**
	 * @var callable|null $session_log_line_function
	 */
	var $session_log_line_function;
	/**
	 * @var SplObjectStorage $clients
	 */
	var $clients;
	/**
	 * @var callable|null $on_session_start
	 */
	var $on_session_start;
	/**
	 * @var bool $supports_encryption
	 */
	var $supports_encryption;
	/**
	 * @var bool $require_encryption
	 */
	var $require_encryption = false;
	/**
	 * @var callable|null $on_email_received
	 */
	var $on_email_received = null;
	/**
	 * @var callable|null $on_email_rejected
	 */
	var $on_email_rejected = null;

	function __construct(?string $public_key_file = null, ?string $private_key_file = null, array $bind_addresses = self::BIND_ADDR_ALL, array $bind_ports = self::BIND_PORT_DEFAULT, int $session_read_timeout = Session::DEFAULT_READ_TIMEOUT, ?callable $session_log_line_function = Connection::LOGFUNC_NONE)
	{
		$this->streams = [];
		$this->supports_encryption = $public_key_file && $private_key_file;
		foreach($bind_addresses as $bind_address)
		{
			$address = "tcp://";
			if(strpos($bind_address, ":") !== false)
			{
				$address .= "[$bind_address]";
			}
			else
			{
				$address .= $bind_address;
			}
			$address .= ":";
			foreach($bind_ports as $bind_port)
			{
				if($this->supports_encryption)
				{
					$stream = stream_socket_server($address.$bind_port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
						"ssl" => [
							"verify_peer" => false,
							"allow_self_signed" => true,
							"local_cert" => $public_key_file,
							"local_pk" => $private_key_file,
							"ciphers" => "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384"
						]
					]));
				}
				else
				{
					$stream = stream_socket_server($address.$bind_port, $errno, $errstr);
				}
				if(!$stream)
				{
					throw new ExceptionConnectionNotEstablished("Failed to bind ".$address.$bind_port.": $errstr ($errno)");
				}
				array_push($this->streams, $stream);
			}
		}
		$this->session_read_timeout = $session_read_timeout;
		$this->session_log_line_function = $session_log_line_function;
		$this->clients = new SplObjectStorage();
	}

	function onSessionStart(callable $function): self
	{
		$this->on_session_start = $function;
		return $this;
	}

	function onEmailReceived(callable $function): self
	{
		$this->on_email_received = $function;
		return $this;
	}

	function onEmailRejected(callable $function): self
	{
		$this->on_email_rejected = $function;
		return $this;
	}

	function accept(): self
	{
		foreach($this->streams as $stream)
		{
			while(($client = @stream_socket_accept($stream, 0)) !== false)
			{
				$session = new Session($client, $this->session_read_timeout, $this->session_log_line_function);
				if(is_callable($this->on_session_start))
				{
					($this->on_session_start)($session);
				}
				$session->writeLine("220 ".Machine::getHostname());
				$this->clients->attach($session);
				$session->open_condition->onFalse(function() use ($session)
				{
					$this->clients->detach($session);
				});
			}
		}
		return $this;
	}

	function handle(): self
	{
		foreach($this->clients as $client)
		{
			/**
			 * @var Session $client
			 */
			if($client->starting_tls)
			{
				$ret = stream_socket_enable_crypto($client->stream, true, STREAM_CRYPTO_METHOD_ANY_SERVER);
				if($ret === 0)
				{
					continue;
				}
				if($ret !== true)
				{
					$client->log(Connection::LOGPREFIX_FAIL, "stream_socket_enable_crypto returned $ret");
					$client->close();
					continue;
				}
				$client->starting_tls = false;
				$crypto_data = stream_get_meta_data($client->stream)["crypto"];
				$client->log(Connection::LOGPREFIX_BIDIR, "Agreed on ".$crypto_data["protocol"]." using cipher ".$crypto_data["cipher_name"]);
			}
			$line = $client->readLine();
			if(!$line)
			{
				if(microtime(true) > $client->last_command + $client->read_timeout)
				{
					$client->log(Connection::LOGPREFIX_INFO, "Received no command within {$client->read_timeout} seconds");
					$client->close();
				}
				continue;
			}
			do
			{
				$client->last_command = microtime(true);
				$line = rtrim($line);
				$client->log(Connection::LOGPREFIX_RIGHT, $line);
				if($client->data === null)
				{
					$command = explode(" ", $line, 2);
					$command[0] = strtoupper($command[0]);
					switch($command[0])
					{
						default:
							$client->writeLine("500");
							break;
						case "HELO":
							$client->helo_domain = $command[1];
							$client->writeLine("250 ".Machine::getHostname());
							break;
						case "EHLO":
							$client->helo_domain = $command[1];
							$client->writeLine("250-".Machine::getHostname());
							if($this->supports_encryption)
							{
								$client->writeLine("250-STARTTLS");
							}
							$client->writeLine("250 SMTPUTF8");
							break;
						case "STARTTLS":
							if($this->supports_encryption)
							{
								$client->writeLine("220");
								$ret = stream_socket_enable_crypto($client->stream, true, STREAM_CRYPTO_METHOD_ANY_SERVER);
								if($ret === 0)
								{
									$client->starting_tls = true;
								}
								else if($ret !== true)
								{
									$client->log(Connection::LOGPREFIX_FAIL, "stream_socket_enable_crypto returned $ret");
									$client->close();
								}
							}
							else
							{
								$client->writeLine("502");
							}
							break;
						case "MAIL":
							if($this->require_encryption && !$client->isEncrypted())
							{
								$client->writeLine("503");
								break;
							}
							if(substr($command[1], 0, 6) != "FROM:<" || substr($command[1], -1) != ">")
							{
								$client->writeLine("501");
								break;
							}
							$client->mail_from = substr($command[1], 6, -1);
							$client->writeLine("250");
							break;
						case "RCPT":
							if(!$client->helo_domain)
							{
								$client->writeLine("503");
								break;
							}
							if(substr($command[1], 0, 4) != "TO:<" || substr($command[1], -1) != ">")
							{
								$client->writeLine("501");
								break;
							}
							$client->rcpt_to = substr($command[1], 4, -1);
							$client->writeLine("250");
							break;
						case "DATA":
							if(!$client->rcpt_to)
							{
								$client->writeLine("503");
								break;
							}
							$client->data = "";
							$client->writeLine("354");
							break;
						case "QUIT":
							$client->close();
							break 2;
					}
				}
				else if($line == ".")
				{
					$email = Email::fromSmtpData($client->data);
					$client->data = null;
					if($email->getSender()->address != $client->mail_from)
					{
						$client->writeLine("550 From mismatch");
						continue;
					}
					$client->mail_from = "";
					if($email->getRecipient()->address != $client->rcpt_to)
					{
						$client->writeLine("550 To mismatch");
						continue;
					}
					$client->rcpt_to = "";

					$methods_passed = 0;
					$uses_dmarc = false;
					$dmarc_policy_is_reject = false;
					$reject_on_pass_failure = false;
					foreach(Email::getTxtRecords("_dmarc.".$email->getSender()->getDomain()) as $txt)
					{
						if(substr($txt, 0, 9) == "v=DMARC1;")
						{
							$dmarc = Email::parseKeyValuePairs(substr($txt, 9));
							if(!array_key_exists("p", $dmarc))
							{
								continue;
							}
							if($dmarc["p"] != "none")
							{
								$uses_dmarc = true;
								if($dmarc["p"] == "reject")
								{
									$dmarc_policy_is_reject = true;
									$reject_on_pass_failure = true;
								}
							}
							break;
						}
					}

					if($email->hasHeader("DKIM-Signature"))
					{
						$dkim_result = $email->verifyDkimSignature();
					}
					else
					{
						$dkim_result = "not present";
					}
					if($dkim_result == "pass")
					{
						$methods_passed++;
					}

					if($methods_passed < self::METHOD_PASSES_REQUIRED)
					{
						/** @noinspection PhpUnhandledExceptionInspection */
						$spf_result = (new Checker())->check(new Environment($client->getRemoteAddress(), $client->helo_domain, $email->getSender()))->getCode();
						if($spf_result == Result::CODE_PASS
							|| ($uses_dmarc && ($spf_result == Result::CODE_NONE || $spf_result == Result::CODE_PASS)))
						{
							$methods_passed++;
						}
						if(!$uses_dmarc && $spf_result == Result::CODE_FAIL)
						{
							$reject_on_pass_failure = true;
						}
					}
					else
					{
						$spf_result = "not checked";
					}

					$authenticity = "DKIM=$dkim_result; SPF=$spf_result";
					if($methods_passed < self::METHOD_PASSES_REQUIRED && $reject_on_pass_failure)
					{
						$client->writeLine("550 Authentication failed ($authenticity) and DMARC ".($dmarc_policy_is_reject ? "policy is reject" : "is unused"));
						if(is_callable($this->on_email_rejected))
						{
							($this->on_email_rejected)($email, $client);
						}
						continue;
					}
					$email->setHeader("X-Authenticity", $authenticity);
					if(is_callable($this->on_email_received))
					{
						$client->log(Connection::LOGPREFIX_INFO, $authenticity);
						$client->writeLine("250");
						($this->on_email_received)($email, $methods_passed >= self::METHOD_PASSES_REQUIRED, $client);
					}
					else
					{
						$client->writeLine("550 No \"on email received\" function was defined, so I can't deliver your email, but I can tell you your authenticity: $authenticity");
					}
				}
				else
				{
					$client->data .= $line."\r\n";
				}
			}
			while($line = $client->readLine());
		}
		return $this;
	}

	function registerLoop(): self
	{
		Asyncore::add(function()
		{
			$this->accept()->handle();
		});
		return $this;
	}

	function loop(): void
	{
		$this->registerLoop();
		Asyncore::loop();
	}
}
