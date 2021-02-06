<?php
namespace Email;
class DkimKey
{
	/**
	 * @var string $selector
	 */
	var $selector;
	/**
	 * @var resource $private_key
	 */
	var $private_key;

	function __construct(string $selector, $private_key)
	{
		$this->selector = $selector;
		if(!is_resource($private_key))
		{
			$private_key = openssl_pkey_get_private($private_key);
			if(!$private_key)
			{
				throw new ExceptionOpenssl("Failed to get private key");
			}
		}
		$this->private_key = $private_key;
	}

	function sign(string $data, int $algo): string
	{
		if(!openssl_sign($data, $sig, $this->private_key, $algo))
		{
			throw new ExceptionOpenssl("Failed to sign data");
		}
		return base64_encode($sig);
	}
}
