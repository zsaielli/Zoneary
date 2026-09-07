<?php
/**
 * Server-side validation for the contact / early-access form.
 *
 * This is the only validation that matters. The browser does its own checks so
 * people get fast feedback, but nothing here trusts them: every field is
 * re-checked, re-trimmed and length-capped on the server, and the product
 * choice is matched against an allowlist rather than accepted as text.
 */

declare(strict_types=1);

// Library file: only ever reached through contact.php, which defines this.
if (!defined('ZONEARY_CONTACT')) {
    http_response_code(404);
    exit;
}

final class ContactValidator
{
    /** Product interest is a closed set. Anything else is a malformed request. */
    public const PRODUCTS = [
        'Watchtower',
        'PulseGrid',
        'Sentinel',
        'The whole ecosystem',
    ];

    /** Character (not byte) limits, per field. */
    public const LIMITS = [
        'name'         => 120,
        'email'        => 254,
        'organization' => 160,
        'product'      => 40,
        'count'        => 120,
        'platform'     => 160,
        'notes'        => 4000,
    ];

    /** Fields the endpoint understands. Anything else is rejected outright. */
    public const KNOWN_FIELDS = [
        'name', 'email', 'organization', 'product', 'count', 'platform', 'notes',
        // abuse controls; never used in the message body
        'company_website', 'ts',
    ];

    /** The honeypot: present in the markup, hidden from people, empty when honest. */
    public const HONEYPOT_FIELD = 'company_website';

    /** A person cannot meaningfully complete this form faster than this. */
    public const MIN_FILL_SECONDS = 3;

    /**
     * Validate and normalize a raw submission.
     *
     * @param array<string,mixed> $raw   the untrusted request body
     * @param int                 $nowMs current time in milliseconds
     * @return array{ok:bool, fields?:array<string,string>, error?:string, reason?:string, silent?:bool}
     *         `reason` is for the server's own eyes; `error` is safe to show.
     *         `silent` marks a rejection that should look like success to a bot.
     */
    public static function check(array $raw, int $nowMs): array
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::KNOWN_FIELDS, true)) {
                return self::fail('That submission could not be read.', 'unexpected field: ' . (string) $key);
            }
        }

        foreach ($raw as $key => $value) {
            if (!is_string($value)) {
                return self::fail('That submission could not be read.', 'non-string field: ' . (string) $key);
            }
        }

        // ---- honeypot -------------------------------------------------------
        // A filled honeypot is answered with the success message a bot expects,
        // so the operator learns nothing from the response. Nothing is sent.
        if (self::clean((string) ($raw[self::HONEYPOT_FIELD] ?? '')) !== '') {
            return self::silent('honeypot filled');
        }

        // ---- fill time ------------------------------------------------------
        // Advisory only: a missing timestamp is fine (the form works without
        // JavaScript), but an implausibly fast one is not.
        $ts = (string) ($raw['ts'] ?? '');
        if ($ts !== '' && ctype_digit($ts)) {
            $elapsed = ($nowMs - (int) $ts) / 1000;
            if ($elapsed >= 0 && $elapsed < self::MIN_FILL_SECONDS) {
                return self::silent('submitted after ' . $elapsed . 's');
            }
        }

        $fields = [];
        foreach (['name', 'email', 'organization', 'product', 'count', 'platform', 'notes'] as $key) {
            $fields[$key] = self::clean((string) ($raw[$key] ?? ''));
        }
        // Everything except Notes is a header-adjacent single-line value.
        foreach (['name', 'email', 'organization', 'product', 'count', 'platform'] as $key) {
            $fields[$key] = self::singleLine($fields[$key]);
        }

        // ---- required -------------------------------------------------------
        if ($fields['name'] === '') {
            return self::fail('Please add your name.', 'name missing');
        }
        if ($fields['email'] === '') {
            return self::fail('Please add your email address.', 'email missing');
        }
        if ($fields['product'] === '') {
            return self::fail('Please choose which product you are interested in.', 'product missing');
        }

        // ---- lengths --------------------------------------------------------
        foreach (self::LIMITS as $key => $max) {
            if (self::length($fields[$key]) > $max) {
                return self::fail(
                    self::LABELS[$key] . ' is longer than we can accept (' . $max . ' characters).',
                    $key . ' too long'
                );
            }
        }

        // ---- email ----------------------------------------------------------
        if (!self::isEmail($fields['email'])) {
            return self::fail('That email address does not look right.', 'email malformed');
        }

        // ---- product allowlist ----------------------------------------------
        if (!in_array($fields['product'], self::PRODUCTS, true)) {
            return self::fail('Please choose which product you are interested in.', 'product not in allowlist');
        }

        return ['ok' => true, 'fields' => $fields];
    }

    /** Field names as a visitor would recognise them, for error messages. */
    private const LABELS = [
        'name'         => 'Your name',
        'email'        => 'Your email address',
        'organization' => 'Organization',
        'product'      => 'Product interest',
        'count'        => 'Approx. sites / devices',
        'platform'     => 'Current platform',
        'notes'        => 'Notes',
    ];

    /**
     * Normalize one submitted value.
     *
     * Control characters go first. A CR or LF that survives into a header is a
     * header-injection primitive, so they are removed here rather than escaped
     * later - the message builder then has nothing dangerous left to handle.
     */
    public static function clean(string $value): string
    {
        // drop anything that is not valid UTF-8 rather than pass it on
        if (!self::isUtf8($value)) {
            $value = (string) preg_replace('/[\x80-\xFF]/', '', $value);
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // C0 controls and DEL, keeping only newline
        $value = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $value);
        // zero-width and BiDi-override characters: invisible, and only ever abuse
        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $value);
        // collapse runs of spaces and blank lines
        $value = (string) preg_replace('/[ \t]+/', ' ', $value);
        $value = (string) preg_replace('/\n{3,}/', "\n\n", $value);
        return trim($value);
    }

    /** Flatten a value to a single line. */
    public static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public static function isUtf8(string $value): bool
    {
        return $value === '' || preg_match('//u', $value) === 1;
    }

    public static function length(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? strlen($value) : $count;
    }

    /**
     * Email syntax.
     *
     * filter_var handles the grammar; the extra checks refuse the shapes that
     * are syntactically arguable but in practice only appear in header
     * injection attempts, and the dotted-domain rule rejects user@localhost.
     */
    public static function isEmail(string $email): bool
    {
        if ($email === '' || self::length($email) > self::LIMITS['email']) {
            return false;
        }
        if (preg_match('/[\r\n\t<>,;"\\\\]/', $email) === 1) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $parts = explode('@', $email);
        if (count($parts) !== 2 || $parts[0] === '' || strpos($parts[1], '.') === false) {
            return false;
        }
        return substr($parts[1], -1) !== '.';
    }

    /** @return array{ok:false, error:string, reason:string} */
    private static function fail(string $error, string $reason): array
    {
        return ['ok' => false, 'error' => $error, 'reason' => $reason];
    }

    /** @return array{ok:false, error:string, reason:string, silent:true} */
    private static function silent(string $reason): array
    {
        return [
            'ok'     => false,
            'error'  => 'Thanks - we have your details.',
            'reason' => $reason,
            'silent' => true,
        ];
    }
}
