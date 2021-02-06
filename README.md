# php-mail-transfer

A library allowing you to easily write custom email servers and clients in PHP.

> `composer require hell-sh/mail-transfer:dev-senpai`

## Work In Progress!

The only reason I'm publishing this so early is so that I can build a custom server using "dev-senpai".

### Some of the things that have yet to be done

- Multipart Content
  - Attachments
- Deal with multiple header values
  - Deal with multiple `DKIM-Signature`s (only 1 has to pass for DMARC)
- DKIM Subdomain Considerations
- DNSBL: https://www.spamhaus.org/faq/section/DNSBL%20Usage

## Examples

### Server

```PHP
<?php
require "vendor/autoload.php";
(new Email\Server(
    __DIR__."/fullchain.pem", __DIR__."/privkey.pem",
    Email\Server::BIND_ADDR_ALL, Email\Server::BIND_PORT_DEFAULT,
    Email\Session::DEFAULT_TIMEOUT, Email\Connection::LOGFUNC_ECHO
))->onEmailReceived(function(Email\Email $email, bool $sender_authenticated)
    {
        $subject = ($email->getSubject() ?: "(no subject)");
        $authentication_state = ($sender_authenticated ? "Authenticated" : "Unauthenticated");
        echo "Received \"$subject\" from {$email->getSender()} ($authentication_state)".PHP_EOL;
    })
  ->loop();
```

### Client

```PHP
<?php
require "vendor/autoload.php";
Email\Email::basic(
    new Email\Address("Sender <sender@localhost>"),
    new Email\Address("recipient@localhost"),
    /* Subject: */ "Saying hello to the world",
    new Email\ContentTextPlain("Hello, world!")
)->sign(new Email\DkimKey("php", "file://".__DIR__."/dkim-private.pem"))
 ->sendToRecipient(Email\Client::DEFAULT_TIMEOUT, Email\Connection::LOGFUNC_ECHO);
Asyncore\Asyncore::loop();
```

#### Setup

*So you can properly send emails.*

First, make sure you're on a public machine with forward-confirmed reverse DNS records for IPv4 & IPv6 (or to put it more generally, any IP that your machine may use for outgoing connection). If this is not the case, there might be all sorts of subtle issues.

Next, use openssl via the terminal to generate an RSA keypair for DKIM:

```BASH
openssl genrsa -out dkim-private.pem 2048
openssl rsa -in dkim-private.pem -out dkim-public.pem -pubout -outform PEM
```

Finally, set up the DNS records:

| Type | Name | Content |
| --- | --- | --- |
| TXT | `@` | `v=spf1 a:<your hostname> -all` |
| TXT | `<selector>._domainkey.@` | `v=DKIM1; k=rsa; g=*; s=email; h=sha256; t=s; p=<base64-encoded public key>` |
| TXT | `_dmarc.@` | `v=DMARC1; p=<policy>; pct=100` |

- `selector` is also the first parameter to `new Email\DkimKey` and basically is the name of the DKIM key.
- `base64-encoded public key` means the actual content of the `dkim-public.pem` in a single line and without the BEGIN & END KEY wrapping.
- `policy` is what to do when an email is sent using this domain without being authenticated:
    - `reject`: Deny it
    - `quarantine`: Put it in spam
    - `none`: Allow it
