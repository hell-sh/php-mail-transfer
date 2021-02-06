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
	$str = "Test <nobody@hell.sh>";
	$address = new Address($str, null);
	Nose::assertEquals($address->__toString(), $str);
	Nose::assertEquals($address->name, "Test");
	Nose::assertEquals($address->address, "nobody@hell.sh");
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
		"Test" => str_repeat("a", 200)
	], new ContentTextPlain(str_repeat("a", 200)));
	$smtp_data = $email->getSmtpData(78);
	foreach(explode("\r\n", $smtp_data) as $line)
	{
		Nose::assertTrue(strlen($line) < 80);
	}
	$email_from_data = Email::fromSmtpData($smtp_data);
	Nose::assertEquals($email->getHeader("Test"), $email_from_data->getHeader("Test"));
	Nose::assertEquals($email->content->text, $email_from_data->content->text);
}

function testLookups()
{
	// With MX Record
	Nose::assertEquals(["mxresult.hell.sh"], (new Address("nobody@mxtest.hell.sh"))->getServers());
	// Without MX Record
	Nose::assertEquals(["trash-mail.com"], (new Address("nobody@trash-mail.com"))->getServers());
}
