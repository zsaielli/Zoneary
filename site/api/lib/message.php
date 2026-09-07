<?php
/**
 * Builds the plain-text email that the endpoint sends to info@zoneary.com.
 *
 * The envelope is fixed by configuration and never influenced by the visitor:
 * From is always the website mailbox, To is always the destination mailbox, and
 * the visitor's address appears only as Reply-To. Header values are re-checked
 * here even though the validator already stripped CR/LF, because this is the
 * layer where a stray newline would actually become a new header.
 */

declare(strict_types=1);

// Library file: only ever reached through contact.php, which defines this.
if (!defined('ZONEARY_CONTACT')) {
    http_response_code(404);
    exit;
}

final class ContactMessage
{
    /** @var array<string,string> */
    private array $headers;
    private string $body;
    private string $envelopeSender;
    private string $envelopeRecipient;

    /**
     * @param array<string,string> $headers
     */
    private function __construct(array $headers, string $body, string $sender, string $recipient)
    {
        $this->headers           = $headers;
        $this->body              = $body;
        $this->envelopeSender    = $sender;
        $this->envelopeRecipient = $recipient;
    }

    /**
     * @param array<string,string> $fields   validated fields
     * @param array<string,string> $metadata server-known context for abuse diagnosis
     */
    public static function build(
        ContactConfig $config,
        array $fields,
        array $metadata = [],
        ?int $now = null,
        string $messageIdSeed = ''
    ): self {
        $now = $now ?? time();

        $product = $fields['product'];
        // The subject interpolates the product, which is allowlisted, so there
        // is nothing attacker-controlled in it. Sanitised anyway, on principle.
        //
        // Deliberately not "early access": this form is the site's single
        // contact destination, so most of what arrives through it is a question
        // rather than a waitlist signup, and the subject has to read correctly
        // in the mailbox either way.
        $subject = self::headerValue('Zoneary enquiry - ' . $product);

        $replyTo = self::mailbox($fields['name'], $fields['email']);

        $domain = self::domainOf($config->fromEmail());
        $seed   = $messageIdSeed !== '' ? $messageIdSeed : bin2hex(random_bytes(12));

        $headers = [
            'Date'                      => gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID'                => '<' . $seed . '@' . $domain . '>',
            'From'                      => self::mailbox($config->fromName(), $config->fromEmail()),
            'To'                        => self::mailbox('', $config->toEmail()),
            'Reply-To'                  => $replyTo,
            'Subject'                   => $subject,
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
            'Auto-Submitted'            => 'auto-generated',
            'X-Zoneary-Form'            => 'early-access',
        ];

        return new self(
            $headers,
            self::body($fields, $metadata),
            $config->fromEmail(),
            $config->toEmail()
        );
    }

    /**
     * The readable message. One labelled line per field, Notes last because it
     * is the only one that can run to several lines.
     *
     * @param array<string,string> $fields
     * @param array<string,string> $metadata
     */
    public static function body(array $fields, array $metadata = []): string
    {
        $dash = '-';
        $lines = [
            'Name: ' . $fields['name'],
            'Email: ' . $fields['email'],
            'Organization: ' . ($fields['organization'] !== '' ? $fields['organization'] : $dash),
            'Product interest: ' . $fields['product'],
            'Approx. sites/devices: ' . ($fields['count'] !== '' ? $fields['count'] : $dash),
            'Current platform: ' . ($fields['platform'] !== '' ? $fields['platform'] : $dash),
            '',
            'Notes:',
            $fields['notes'] !== '' ? $fields['notes'] : $dash,
        ];

        if ($metadata !== []) {
            $lines[] = '';
            $lines[] = str_repeat('-', 48);
            foreach ($metadata as $label => $value) {
                $lines[] = $label . ': ' . ContactValidator::singleLine((string) $value);
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Format a display name + address pair.
     *
     * The display name is RFC 2047 encoded whenever it is not plain printable
     * ASCII, which also means a name can never contribute raw bytes to the
     * header. The address has already been validated; it is re-checked so this
     * method is safe to call from anywhere.
     */
    public static function mailbox(string $name, string $email): string
    {
        $email = self::headerValue($email);
        if (!ContactValidator::isEmail($email)) {
            throw new InvalidArgumentException('refusing to build a mailbox from an invalid address');
        }

        $name = self::headerValue($name);
        if ($name === '') {
            return $email;
        }

        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1 && strpbrk($name, '"\\,;:<>@[]') === false) {
            return '"' . $name . '" <' . $email . '>';
        }

        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    /**
     * Make a value safe to place in a header: no CR, no LF, no NUL, no leading
     * or trailing whitespace that could be read as folding.
     */
    public static function headerValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        return trim($value);
    }

    private static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? 'zoneary.com' : substr($email, $at + 1);
    }

    /** The complete RFC 5322 message, ready for SMTP DATA. */
    public function toString(): string
    {
        $out = '';
        foreach ($this->headers as $name => $value) {
            $out .= $name . ': ' . $value . "\r\n";
        }
        $out .= "\r\n" . chunk_split(base64_encode($this->body), 76, "\r\n");
        return $out;
    }

    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }

    public function header(string $name): string { return $this->headers[$name] ?? ''; }

    public function envelopeSender(): string { return $this->envelopeSender; }

    public function envelopeRecipient(): string { return $this->envelopeRecipient; }
}
