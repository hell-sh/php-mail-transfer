<?php
namespace Email;
abstract class Content extends Section
{
	/**
	 * @var array $headers
	 */
	var $headers;

	function __construct(array $headers)
	{
		$this->headers = $headers;
	}

	function getEffectiveHeaders(): array
	{
		return $this->headers;
	}
}
