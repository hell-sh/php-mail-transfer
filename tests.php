<?php /** @noinspection PhpUnused PhpUnhandledExceptionInspection */
namespace Email;
require "vendor/autoload.php";
use Nose;
function testEncodingAndDecoding()
{
	Nose::assertEquals(EncodingQuotedPrintable::encode("Hêlló, wörld!"), "H=C3=AAll=C3=B3, w=C3=B6rld!");
	Nose::assertEquals(EncodingQuotedPrintable::decode("H=C3=AAll=C3=B3, w=C3=B6rld!"), "Hêlló, wörld!");
	Nose::assertEquals(EncodingBase64::encode("Hêlló, wörld!"), "SMOqbGzDsywgd8O2cmxkIQ==");
	Nose::assertEquals(EncodingBase64::decode("SMOqbGzDsywgd8O2cmxkIQ=="), "Hêlló, wörld!");
}

function testAddressParsing()
{
	// DO NOT SEND EMAILS TO php-mail-transfer@nirvana.admins.ws
	// This is a trap for spam crawlers!
	$str = "Test <php-mail-transfer@nirvana.admins.ws>";
	$address = new Address($str, null);
	Nose::assertEquals($address->__toString(), $str);
	Nose::assertEquals($address->name, "Test");
	Nose::assertEquals($address->address, "php-mail-transfer@nirvana.admins.ws");
	Nose::assertEquals((new Address("<php-mail-transfer@nirvana.admins.ws>"))->address, "php-mail-transfer@nirvana.admins.ws");
}

function testHeaderCasing()
{
	$headers = [
		"From",
		"To",
		"Date",
		"MIME-Version",
		"Content-Type",
		"Content-Transfer-Encoding",
		"Subject",
		"DKIM-Signature",
		"Message-ID",
		"In-Reply-To",
		"References",
	];
	foreach($headers as $header)
	{
		Nose::assertEquals(Email::normaliseHeaderCasing(strtolower($header)), $header);
	}
}

function testSmtpData()
{
	$email = new Email([
		"Test: ".str_repeat("a", 50)." ".str_repeat("a", 50)
	], new ContentTextPlain(str_repeat("a", 200)));
	$smtp_data = $email->getSmtpData(78);
	foreach(explode("\r\n", $smtp_data) as $line)
	{
		Nose::assertTrue(strlen($line) <= 80);
	}
	$email_from_data = Email::fromSmtpData($smtp_data);
	Nose::assertEquals($email->getFirstHeaderValue("Test"), $email_from_data->getFirstHeaderValue("Test"));
	Nose::assertEquals($email->content->text, $email_from_data->content->text);
}

function testUnfoldableHeader()
{
	$email = new Email([
		"Test" => str_repeat("a", 200)
	]);
	$smtp_data = $email->getSmtpData(78);
	$i = 0;
	foreach(explode("\r\n", $smtp_data) as $line)
	{
		if($line)
		{
			$i = 0;
		}
		else
		{
			Nose::assertTrue(++$i < 3);
		}
	}
}

function testDate()
{
	$email = new Email();
	$email->setDate(1337);
	Nose::assertEquals($email->getDate(), 1337);
}

function testLookups()
{
	// With MX Record
	Nose::assertEquals(["mxresult.hell.sh"], (new Address("nobody@mxtest.hell.sh"))->getServers());
	// Without MX Record
	Nose::assertEquals(["trash-mail.com"], (new Address("nobody@trash-mail.com"))->getServers());
}
