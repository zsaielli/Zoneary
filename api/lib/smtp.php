<?php
/**
 * Minimal authenticated SMTP client for smtp.hostinger.com:465 (implicit TLS).
 *
 * Deliberately dependency-free: this repository ships no Composer step, and the
 * Hostinger deployment is a plain git checkout into public_html, so a mailer
 * library would have to be vendored into the site tree. What is needed here is
 * a few hundred bytes of protocol - EHLO, AUTH LOGIN, MAIL, RCPT, DATA, QUIT.
 *
 * The transport is an interface so tests can substitute a fake. No test in this
 * repository opens a socket or authenticates anywhere.
 */

declare(strict_types=1);

// Library file: only ever reached through contact.php, which defines this.
if (!defined('ZONEARY_CONTACT')) {
    http_response_code(404);
    exit;
}

class ContactTransportException extends RuntimeException
{
}

interface ContactTransport
{
    /**
     * Deliver a built message.
     *
     * @throws ContactTransportException on any failure to hand off the message.
     */
    public function send(ContactMessage $message): void;
}

class SmtpTransport implements ContactTransport
{
    protected ContactConfig $config;
    protected int $timeout;
    /** @var resource|null */
    protected $socket = null;

    public function __construct(ContactConfig $config, int $timeout = 15)
    {
        $this->config  = $config;
        $this->timeout = $timeout;
    }

    public function send(ContactMessage $message): void
    {
        if (!extension_loaded('openssl')) {
            throw new ContactTransportException('openssl is not available; cannot open an implicit-TLS connection');
        }

        try {
            $this->connect();
            $this->runConversation($message);
        } finally {
            $this->close();
        }
    }

    /**
     * The SMTP conversation itself, on an already-open connection.
     *
     * Separated from send() so the protocol can be exercised against a scripted
     * server without opening a socket - see tools/test_contact.php. The
     * acceptance boundary below is the reason this is worth testing directly.
     */
    protected function runConversation(ContactMessage $message): void
    {
        $this->expect($this->read(), 220, 'greeting');

        $this->command('EHLO ' . $this->heloName(), 250, 'EHLO');

        // AUTH LOGIN: the two base64 blobs are the credential. They are
        // never logged, never echoed, and the command strings are not
        // included in any exception message.
        $this->command('AUTH LOGIN', 334, 'AUTH');
        $this->commandQuiet(base64_encode($this->config->smtpUser()), 334, 'AUTH username');
        $this->commandQuiet(base64_encode($this->config->smtpPassword()), 235, 'AUTH password');

        $this->command('MAIL FROM:<' . $message->envelopeSender() . '>', 250, 'MAIL FROM');
        $this->command('RCPT TO:<' . $message->envelopeRecipient() . '>', 250, 'RCPT TO');
        $this->command('DATA', 354, 'DATA');

        $this->write($this->dotStuff($message->toString()) . "\r\n.\r\n");
        $this->expect($this->read(), 250, 'message body');

        // ---- the message is ACCEPTED from here on ---------------------------
        // That final 250 is the acceptance point: the server has taken
        // responsibility for the message. Nothing after it may turn a
        // successful send into a client-visible failure.
        //
        // QUIT is still sent, because closing without it is an abrupt session
        // end that a mail server may hold against the sender. But a server that
        // has already accepted the message is entitled to close the socket
        // immediately, and then this write fails. Swallowing that is the
        // difference between "delivered" and telling the visitor nothing was
        // sent - which would invite a duplicate submission.
        $this->quitQuietly();
    }

    /**
     * Say goodbye, and never let saying goodbye fail the send.
     *
     * Called only after the message has been accepted. Any error here is about
     * the teardown of a connection whose work is already done.
     */
    protected function quitQuietly(): void
    {
        try {
            $this->write("QUIT\r\n");
        } catch (Throwable $e) {
            // Deliberately ignored: the message was accepted before this ran.
        }
    }

    protected function connect(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
            ],
        ]);

        $endpoint = 'ssl://' . $this->config->smtpHost() . ':' . $this->config->smtpPort();
        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new ContactTransportException(sprintf('connection to %s failed (%d)', $endpoint, (int) $errno));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    /**
     * The name given in EHLO. A literal is used rather than the request's Host
     * header so that a spoofed Host cannot reach the SMTP conversation.
     */
    private function heloName(): string
    {
        $at = strrpos($this->config->fromEmail(), '@');
        return $at === false ? 'zoneary.com' : substr($this->config->fromEmail(), $at + 1);
    }

    protected function command(string $line, int $expected, string $label): string
    {
        $this->write($line . "\r\n");
        return $this->expect($this->read(), $expected, $label);
    }

    /**
     * Same as command(), but the failure never quotes the line that was sent -
     * used for the AUTH exchange so a credential cannot reach an exception,
     * a log file or an error page.
     */
    protected function commandQuiet(string $line, int $expected, string $label): void
    {
        $this->write($line . "\r\n");
        $response = $this->read();
        if ((int) substr($response, 0, 3) !== $expected) {
            throw new ContactTransportException($label . ' was rejected by the server');
        }
    }

    protected function write(string $data): void
    {
        if ($this->socket === null) {
            throw new ContactTransportException('not connected');
        }
        if (@fwrite($this->socket, $data) === false) {
            throw new ContactTransportException('write to the SMTP server failed');
        }
    }

    /** Read one (possibly multi-line) SMTP reply. */
    protected function read(): string
    {
        if ($this->socket === null) {
            throw new ContactTransportException('not connected');
        }

        $reply = '';
        while (true) {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $info = stream_get_meta_data($this->socket);
                throw new ContactTransportException(
                    !empty($info['timed_out']) ? 'the SMTP server timed out' : 'the SMTP connection closed early'
                );
            }
            $reply .= $line;
            // a continuation line is "250-text"; the final one is "250 text"
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $reply;
    }

    protected function expect(string $response, int $expected, string $label): string
    {
        if ((int) substr($response, 0, 3) !== $expected) {
            throw new ContactTransportException(sprintf(
                '%s failed: %s',
                $label,
                ContactMessage::headerValue(substr($response, 0, 200))
            ));
        }
        return $response;
    }

    /** RFC 5321 transparency: a line that begins with "." gets a second one. */
    public function dotStuff(string $data): string
    {
        $data = str_replace("\r\n.", "\r\n..", $data);
        return strncmp($data, '.', 1) === 0 ? '.' . $data : $data;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
