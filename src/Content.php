<?php
namespace Email;
abstract class Content extends Section
{
	function getEffectiveHeaders(): array
	{
		return $this->headers;
	}
}
