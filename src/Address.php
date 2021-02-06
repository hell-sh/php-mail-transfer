<?php
namespace Email;
class Address
{
	/**
	 * @var string $address
	 */
	var $address;
	/**
	 * @var string $name
	 */
	var $name = "";

	function __construct(string $address, ?string $name = null)
	{
		if($name === null)
		{
			$i = strpos($address, " <");
			if($i !== false)
			{
				$this->name = substr($address, 0, $i);
				$this->address = substr($address, $i + 2, -1);
				return;
			}
		}
		$this->address = $address;
		$this->name = "";
	}

	function __toString(): string
	{
		if($this->name)
		{
			return "{$this->name} <{$this->address}>";
		}
		return $this->address;
	}

	function getDomain(): string
	{
		return explode("@", $this->address)[1];
	}

	function getServers(): array
	{
		$domain = $this->getDomain();
		$servers = [];
		foreach(dns_get_record($domain, DNS_MX) as $record)
		{
			array_push($servers, $record["target"]);
		}
		if(count($servers) > 0)
		{
			return $servers;
		}
		return [$domain];
	}

	function createConnection(int $connect_timeout = Client::DEFAULT_CONNECT_TIMEOUT, int $read_timeout = Client::DEFAULT_READ_TIMEOUT, ?callable $log_line_function = Connection::LOGFUNC_NONE): ?Client
	{
		foreach($this->getServers() as $server)
		{
			try
			{
				return new Client($server, $connect_timeout, $read_timeout, $log_line_function);
			}
			catch(ExceptionConnectionNotEstablished $e)
			{
				if(is_callable($log_line_function))
				{
					$log_line_function($e->getMessage());
				}
			}
		}
		return null;
	}
}
