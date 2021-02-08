<?php
namespace Email;
abstract class Content extends Container
{
	function getAllHeaders(): array
	{
		return $this->headers;
	}
}
