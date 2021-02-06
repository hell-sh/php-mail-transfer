<?php
namespace Email;
abstract class Machine
{
	/**
	 * @var string|null $hostname
	 */
	static $hostname = null;

	static function getHostname(): string
	{
		if(self::$hostname === null)
		{
			self::$hostname = gethostbyaddr(file_get_contents("https://ip.apimon.de/"));
		}
		return self::$hostname;
	}
}
