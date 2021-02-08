# php-mail-transfer

A library allowing you to easily write custom email servers and clients in PHP.

> `composer require hell-sh/mail-transfer:dev-senpai`

## Work In Progress!

The only reason I'm publishing this so early is so that I can build a custom server using "dev-senpai".

### Some of the things that have yet to be done

- Multipart Content
  - Attachments
- DKIM Subdomain Considerations
- Server: Dynamic & static SIZE

## Examples

### Server

```PHP
<?php
require "vendor/autoload.php";
(new Email\Server(
    __DIR__."/fullchain.pem", __DIR__."/privkey.pem",
    Email\Server::BIND_ADDR_ALL, [25],
    Email\Session::DEFAULT_READ_TIMEOUT, Email\Connection::LOGFUNC_ECHO
))->onEmailReceived(function(Email\Email $email, Email\Session $sender)
    {
        $subject = ($email->getSubject() ?: "(no subject)");
        $classification = $email->getFirstHeaderValue("X-Classification");
        echo "Received \"$subject\" from {$email->getSender()} ($classification)".PHP_EOL;
    })
  ->loop();
```

#### Anti-Spam

While SPF, DKIM and DMARC are all great at preventing spoofing, the real problem is spam, and there is no 100% effective method to address it on the server, but blacklists are by far the most promising.

- If you want to go all-out on not only blocking spam but also teaching ISPs that turn a blind eye a lesson, [UCEPROTECT](https://www.uceprotect.net/) is the way to go: `$server->setBlocklists(["dnsbl-1.uceprotect.net", "dnsbl-2.uceprotect.net", "dnsbl-3.uceprotect.net"]);`
    - If that's too extreme for you, consider only using their Level 1 or 2 lists.
- If you want to be more pragmatic, [JustSpam.org](http://www.justspam.org/) seems good for blocking just spam: `$server->setBlocklists(["dnsbl.justspam.org"]);`

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
 ->sendToRecipient(
     Email\Client::DEFAULT_CONNECT_TIMEOUT,
     Email\Client::DEFAULT_READ_TIMEOUT,
     Email\Connection::LOGFUNC_ECHO
   );
Asyncore\Asyncore::loop();
```

#### Setup

*So you can properly send emails.*

First, make sure you're on a public machine with forward-confirmed reverse DNS records for IPv4 & IPv6 (or to put it more generally, any IP that your machine may use for outgoing connections). If this is not the case, there might be all sorts of subtle issues.

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

---

Some of the RFCs that have been referenced in the development of this software:

- [RFC 2045 - MIME Pt. 1](https://tools.ietf.org/html/rfc2045)
- [RFC 2047 - MIME Pt. 3](https://tools.ietf.org/html/rfc2047)
- [RFC 4871 - DKIM](https://tools.ietf.org/html/rfc4871)
- [RFC 5321 - ESMTP](https://tools.ietf.org/html/rfc5321)
- [RFC 5322 - Internet Message Format](https://tools.ietf.org/html/rfc5322)
- [RFC 7489 - DMARC](https://tools.ietf.org/html/rfc7489)

SPF is implemented by [mlocati/spf-lib](https://github.com/mlocati/spf-lib), published under the MIT license.
