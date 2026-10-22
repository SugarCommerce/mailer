# Mailer

Transactional email for self-hosted PHP. One `Message`, several transports: Postmark, Resend, Amazon SES, Cloudflare Email, SMTP, plus log and in-memory for development. Zero dependencies.

## Why

`mail()` gives you no delivery signal, no message id, and no way to know a message bounced. PHPMailer gives you SMTP and little else. Symfony Mailer is excellent and drags in eight packages plus an event dispatcher.

This sits in the middle: build a message once, swap the transport in configuration, and get back a result that tells you whether a retry is worth attempting.

Prefer an API transport where one exists. Shared hosts routinely block outbound 25, 465 and 587 — it is the single most common cause of "email doesn't work" on a self-hosted install — and SMTP needs five to eight round trips before a message is accepted where an HTTPS call needs one.

## Features

- ✉️ **One message, any transport** — switching provider is one line of configuration.
- 🔒 **Header-injection safe** — a newline in an address, subject or header value is rejected, not stripped.
- 🧱 **Real MIME** — `alternative` inside `related` inside `mixed`, so inline images resolve and attachments stay attachments.
- 🌍 **RFC 2047 headers** — non-ASCII subjects and sender names fold on character boundaries, not byte boundaries.
- 📄 **Automatic plain-text part** — derived from the HTML when you don't supply one, keeping link targets.
- 🔁 **Retryable failures flagged** — a 5xx is worth queueing again, a rejected address never will be.
- 🧪 **Memory and log transports** — assert against what was sent, or read it on disk, decoded, with `LogReader`.
- 🛟 **Staging redirect** — divert every message to one inbox so a restored production database can't mail real customers.

## Requirements

PHP 8.5+, `ext-curl`, `ext-json`

## Installation

```bash
composer require sugarcommerce/mailer
```

## Usage

```php
use SugarCommerce\Mailer\{Address, Mailer, Message};
use SugarCommerce\Mailer\Transports\Postmark;

$mailer = new Mailer(
    new Postmark( getenv( 'POSTMARK_TOKEN' ) ),
    from: new Address( 'billing@example.com', 'Example' ),
);

$result = $mailer->send(
    Message::to( 'customer@example.com' )
        ->subject( 'Your receipt' )
        ->html( $html )
);

if ( $result->failed_send() ) {
    log_failure( $result->error, retry: $result->retryable );
}
```

### Messages

```php
Message::to( 'a@example.com' )              // or an array, or an Address
    ->add_to( 'b@example.com', 'Beth' )
    ->cc( 'accounts@example.com' )
    ->bcc( 'archive@example.com' )
    ->from( 'billing@example.com', 'Example' )
    ->reply_to( 'help@example.com' )
    ->subject( 'Your receipt' )
    ->html( $html )
    ->text( $plain )                        // derived from HTML when omitted
    ->attach( Attachment::from_path( '/tmp/invoice.pdf' ) )
    ->header( 'X-Entity-Ref-ID', $order_id )
    ->meta( 'order_id', (string) $order_id ) // echoed back on provider webhooks
    ->tag( 'receipt' );
```

`problems()` returns everything missing before it can be sent — the `Mailer` calls it for you and fails without touching the network.

### Results

Never throws. A bounce or a bad API key is a normal outcome for outbound mail, not an exception:

```php
$result->sent
$result->failed_send()
$result->message_id   // store it — it's what ties a later bounce webhook back
$result->error
$result->status
$result->retryable    // 429, 5xx and transport failures only
```

### Attachments

```php
Attachment::from_path( '/tmp/invoice.pdf' );
new Attachment( 'invoice.pdf', $bytes, 'application/pdf' );
new Attachment( 'logo.png', $bytes, 'image/png', 'logo' );  // inline: <img src="cid:logo">
```

Held in memory rather than by path, because an API transport needs the bytes anyway — and because a path that was valid when the message was built may not be when a queue worker finally sends it.

### Transports

```php
new Transports\Postmark( $token, stream: 'outbound' );
new Transports\Resend( $api_key );
new Transports\Ses( $access_key, $secret_key, region: 'eu-west-1' );
new Transports\Cloudflare( $api_token, $account_id );
new Transports\Smtp( 'smtp.example.com', 587, $user, $pass );
new Transports\Smtp( 'smtp.example.com', 465, $user, $pass, implicit_tls: true );
new Transports\Smtp( '127.0.0.1', 1025, require_tls: false );    // a local catcher like Mailpit only
new Transports\Log( '/tmp/mail.log' );
new Transports\Memory();
```

| Transport | Notes |
|---|---|
| Postmark | Transactional-only by policy, which is why its deliverability is what it is. Keep marketing on a separate stream. |
| Resend | Tag values must be alphanumeric with dashes and underscores; anything else is sanitised for you. |
| SES | SigV4 signed directly — no AWS SDK. Sends raw MIME, so attachments and headers pass through unchanged. A new account is sandboxed until you ask for production access. |
| Cloudflare | Cloudflare Email Service, public beta on Workers Paid. Fine for notifications; for receipts and licence keys, prefer a provider with a longer track record until it reaches GA. |
| SMTP | The universal fallback. STARTTLS on 587, implicit TLS on 465, `AUTH PLAIN`/`LOGIN`. TLS 1.2+ with the certificate verified. A server that does not offer STARTTLS is refused (since 1.3.0) rather than sent the password in the clear; pass `require_tls: false` for a local catcher. |
| Log | Writes the full serialised message to a file, created readable by its owner only. Development. |
| Memory | Collects messages for assertions. Tests. |

### Reading the log back

```php
use SugarCommerce\Mailer\Transports\LogReader;

foreach ( ( new LogReader( '/tmp/mail.log' ) )->messages( 20 ) as $message ) {
    $message['subject'];  // decoded from RFC 2047
    $message['to'];
    $message['html'];     // quoted-printable undone, so links are whole again
    $message['text'];
}
```

The `html` is the message as sent, customer-typed content and all: show it in a sandboxed iframe and escape every header, never echo it into an admin page. Newest first. For a development outbox page: the log is exact, and nobody wants to read `=3D` through every attribute. Only the end of a large log is read (`max_bytes`, 4 MiB by default), starting at a whole entry, and attachments are never taken for the body.

### Testing your own code

```php
$transport = new Transports\Memory();
$mailer    = new Mailer( $transport, from: new Address( 'billing@example.com' ) );

$mailer->send( $message );

$this->assertTrue( $transport->sent_to( 'customer@example.com' ) );
$this->assertStringContainsString( 'receipt', $transport->last()->get_subject() );
```

A suite wired to a real provider eventually emails a real customer, usually from a fixture that looked synthetic.

### Staging safety

```php
new Mailer( $transport, from: $from, redirect: 'dev@example.local' );
```

Every message goes to that address instead, with the original recipients preserved in the subject and an `X-Original-To` header. The usual way a staging site emails a production customer list is that nobody set this.

## Serialising by hand

```php
use SugarCommerce\Mailer\Mime;

$raw = Mime::build( $message );
```

Headers and body, CRLF-delimited, ready for `SendRawEmail` or a queue. Bcc is deliberately absent from the output — those addresses belong in the envelope only, and writing them shows every blind recipient to every other one.

## Testing

```bash
composer install
composer test
```

70 tests. The MIME layer is asserted structurally — part nesting, boundary uniqueness, encoded-word round trips through `mb_decode_mimeheader`, dot-stuffing, and the absence of a single bare LF anywhere in the output.

## License

GPL-2.0-or-later
