<?php /** @noinspection PhpUnused */
namespace Email;
use Asyncore\
{Asyncore, Condition, Loop};
use BadFunctionCallException;
use LogicException;
use RuntimeException;
class Connection
{
	const LOGFUNC_NONE = null;
	const LOGFUNC_ECHO = '\Email\Connection::impl_LOGFUNC_ECHO';

	const LOGPREFIX_LEFT = "<--";
	const LOGPREFIX_RIGHT = "-->";
	const LOGPREFIX_BIDIR = "<->";
	const LOGPREFIX_INFO = "(i)";
	const LOGPREFIX_FAIL = "/!\\";

	const ONFAIL_IGNORE = null;
	const ONFAIL_THROW = '\Email\Connection::impl_ONFAIL_THROW';

	const PROTOCOL_TBD = 0;
	const PROTOCOL_SMTP = 1;
	const PROTOCOL_ESMTP = 2;

	/**
	 * @var float $constructed_at
	 */
	var $constructed_at;
	/**
	 * @var string $remote_name
	 */
	var $remote_name;
	/**
	 * @var int $read_timeout
	 */
	var $read_timeout;
	/**
	 * @var callable|null $log_line_function
	 */
	var $log_line_function;
	var $stream;
	/**
	 * @var Condition $open_condition
	 */
	var $open_condition;
	/**
	 * @var Loop $loop
	 */
	private $loop;
	/**
	 * @var callable|null
	 */
	var $default_fail_handler = self::ONFAIL_IGNORE;
	/**
	 * @var string $line_buffer
	 */
	private $line_buffer = "";
	/**
	 * @var int $protocol
	 */
	var $protocol = self::PROTOCOL_TBD;
	/**
	 * @var array $capabilities
	 */
	var $capabilities = [];

	function __construct(string $remote_name, int $read_read_timeout, ?callable $log_line_function = Connection::LOGFUNC_NONE)
	{
		$this->constructed_at = microtime(true);
		$this->remote_name = $remote_name;
		$this->read_timeout = $read_read_timeout;
		$this->log_line_function = $log_line_function;
	}

	function __clone()
	{
		throw new LogicException("You can't clone a Connection");
	}

	function __destruct()
	{
		$this->close();
	}

	function initStream(): void
	{
		stream_set_timeout($this->stream, $this->read_timeout);
		$this->open_condition = Asyncore::condition(function(): bool
		{
			return $this->isOpen();
		});
		$this->open_condition->onFalse(function()
		{
			$this->log(self::LOGPREFIX_BIDIR, "Connection closed");
		});
	}

	function isOpen(): bool
	{
		return $this->stream && !feof($this->stream);
	}

	function close(): void
	{
		if(is_resource($this->stream))
		{
			if($this instanceof Client)
			{
				fwrite($this->stream, "QUIT\r\n");
			}
			@fclose($this->stream);
			$this->stream = false;
		}
	}

	function getRemoteAddress(): string
	{
		return explode(":", $this->remote_name, 2)[0];
	}

	function log(string $prefix, string $message): void
	{
		if(is_callable($this->log_line_function))
		{
			foreach(explode("\r\n", $message) as $line)
			{
				($this->log_line_function)($this, $prefix." ".$line);
			}
		}
	}

	function defaultLogLineFormat(string $line): string
	{
		return "[".number_format(microtime(true) - $this->constructed_at, 3)."s] {$this->remote_name} $line";
	}

	static function impl_LOGFUNC_ECHO(?Connection $con, string $line): void
	{
		echo ($con instanceof Connection ? $con->defaultLogLineFormat($line) : $line).PHP_EOL;
	}

	function fail(?callable $on_fail, int $fail_type, string $fail_extra = ""): void
	{
		$fail = new Fail($fail_type, $fail_extra);
		$this->log(self::LOGPREFIX_FAIL, $fail->__toString());
		if(!is_callable($on_fail))
		{
			$on_fail = $this->default_fail_handler;
		}
		if(is_callable($on_fail))
		{
			$on_fail($fail);
		}
	}

	static function impl_ONFAIL_THROW(Fail $fail): void
	{
		throw new RuntimeException($fail->__toString());
	}

	function readLine(): string
	{
		while(true)
		{
			$c = fgetc($this->stream);
			if($c === false)
			{
				break;
			}
			$this->line_buffer .= $c;
			if(substr($this->line_buffer, -2) == "\r\n")
			{
				$line = $this->line_buffer;
				$this->line_buffer = "";
				return $line;
			}
		}
		return "";
	}

	protected function startLoop(callable $func): void
	{
		if($this->loop)
		{
			throw new BadFunctionCallException("A loop is already active");
		}
		$this->loop = $this->open_condition->add($func);
		if(stream_get_meta_data($this->stream)["blocked"])
		{
			while($this->loop !== null)
			{
				($this->loop->function)();
			}
		}
	}

	protected function endLoop(): void
	{
		$this->loop->remove();
		$this->loop = null;
	}

	function writeRaw(string $data): void
	{
		$this->log(self::LOGPREFIX_LEFT, $data);
		fwrite($this->stream, $data);
	}

	function writeLine(string $line): self
	{
		$this->log(self::LOGPREFIX_LEFT, $line);
		fwrite($this->stream, $line."\r\n");
		$this->flush();
		return $this;
	}

	function flush(): void
	{
		fflush($this->stream);
	}

	function isEncrypted(): bool
	{
		return array_key_exists("crypto", stream_get_meta_data($this->stream));
	}
}
