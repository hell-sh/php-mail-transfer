<?php
namespace Email;
class Fail
{
	const TIMEOUT = 0;
	const UNEXPECTED_RESPONSE = 1;
	const STARTTLS_FAILED = 2;
	const RATE_LIMITED = 3;

	/**
	 * @var int $type
	 */
	public $type;
	/**
	 * @var string $extra
	 */
	public $extra;

	function __construct(int $type, string $extra = "")
	{
		$this->type = $type;
		$this->extra = $extra;
	}

	static function typeString(int $fail_type): string
	{
		switch($fail_type)
		{
			case self::TIMEOUT:
				return "Read timed out";

			case self::UNEXPECTED_RESPONSE:
				return "Unexpected response";

			case self::STARTTLS_FAILED:
				return "Failed to negotiate TLS";

			case self::RATE_LIMITED:
				return "Remote has rate limited us";
		}
		return "Unknown failure";
	}

	function __toString(): string
	{
		$str = self::typeString($this->type);
		if($this->extra)
		{
			$str .= ": ".$this->extra;
		}
		return $str;
	}
}
