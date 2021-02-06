<?php
namespace Email;
class Session extends Connection
{
	const DEFAULT_READ_TIMEOUT = 3;

	/**
	 * @var float $last_command
	 */
	var $last_command;
	/**
	 * @var string $helo_domain
	 */
	var $helo_domain = "";
	/**
	 * @var bool $starting_tls
	 */
	var $starting_tls = false;
	/**
	 * @var string $mail_from
	 */
	var $mail_from = "";
	/**
	 * @var string $rcpt_to
	 */
	var $rcpt_to = "";
	/**
	 * @var string|null $data
	 */
	var $data = null;

	function __construct($stream, int $read_timeout = Session::DEFAULT_READ_TIMEOUT, ?callable $log_line_function = Connection::LOGFUNC_NONE)
	{
		stream_set_blocking($stream, false);
		$this->stream = $stream;
		socket_getpeername(socket_import_stream($this->stream), $addr, $port);
		if(strpos($addr, ":") !== false)
		{
			$addr = "[$addr]";
		}
		parent::__construct($addr.":".$port, $read_timeout, $log_line_function);
		$this->last_command = $this->constructed_at;
		$this->initStream();
		$this->log(self::LOGPREFIX_BIDIR, "Connection established");
	}
}
