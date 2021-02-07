<?php
namespace Email;
abstract class Content extends Section
{
	function getAllHeaders(): array
	{
		return $this->headers;
	}
}
