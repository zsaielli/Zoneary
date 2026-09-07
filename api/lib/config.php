<?php
/**
 * Configuration for the Zoneary contact endpoint.
 *
 * The SMTP password is the only real secret here, and it never lives in this
 * repository. It is read from a file ABOVE the web root, which is created by
 * hand on the server and is therefore not touched by the git deployment and
 * not reachable over HTTP. See docs/contact-form.md.
 */

declare(strict_types=1);

// Library file: only ever reached through contact.php, which defines this.
if (!defined('ZONEARY_CONTACT')) {
    http_response_code(404);
    exit;
}

final class ContactConfig
{
    /** Fixed envelope. Never derived from user input. */
    public const SMTP_HOST   = 'smtp.hostinger.com';
    public const SMTP_PORT   = 465;
    public const SMTP_USER   = 'website@zoneary.com';
    public const FROM_EMAIL  = 'website@zoneary.com';
    public const FROM_NAME   = 'Zoneary Website';
    public const TO_EMAIL    = 'info@zoneary.com';

    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Candidate locations for the secrets file, most specific first.
     *
     * $baseDir is the directory of contact.php (…/public_html/api), so its
     * grandparent is the domain directory that contains public_html.
     *
     * @return string[]
     */
    public static function candidatePaths(string $baseDir): array
    {
        $paths = [];

        $override = getenv('ZONEARY_CONTACT_CONFIG');
        if (is_string($override) && $override !== '') {
            $paths[] = $override;
        }

        // …/domains/zoneary.com/zoneary-private/contact-config.php
        $paths[] = dirname($baseDir, 2) . '/zoneary-private/contact-config.php';

        // Same idea, resolved from the document root instead, for hosts whose
        // layout puts the endpoint somewhere unexpected.
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (is_string($docRoot) && $docRoot !== '') {
            $paths[] = dirname($docRoot) . '/zoneary-private/contact-config.php';
        }

        return array_values(array_unique($paths));
    }

    /**
     * @throws RuntimeException when no configuration file is present or it is
     *                          missing the SMTP password.
     */
    public static function load(string $baseDir): self
    {
        foreach (self::candidatePaths($baseDir) as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            /** @psalm-suppress UnresolvableInclude */
            $values = require $path;
            if (!is_array($values)) {
                throw new RuntimeException('configuration file did not return an array');
            }
            return self::fromArray($values, dirname($path));
        }

        throw new RuntimeException('no contact configuration file found');
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function fromArray(array $values, string $configDir = ''): self
    {
        $password = isset($values['smtp_password']) ? (string) $values['smtp_password'] : '';
        if ($password === '') {
            throw new RuntimeException('smtp_password is missing from the configuration');
        }

        $stateDir = isset($values['state_dir']) && $values['state_dir'] !== ''
            ? (string) $values['state_dir']
            : ($configDir !== '' ? $configDir . '/rate' : sys_get_temp_dir() . '/zoneary-rate');

        return new self([
            'smtp_host'     => (string) ($values['smtp_host'] ?? self::SMTP_HOST),
            'smtp_port'     => (int) ($values['smtp_port'] ?? self::SMTP_PORT),
            'smtp_user'     => (string) ($values['smtp_user'] ?? self::SMTP_USER),
            'smtp_password' => $password,
            'from_email'    => (string) ($values['from_email'] ?? self::FROM_EMAIL),
            'from_name'     => (string) ($values['from_name'] ?? self::FROM_NAME),
            'to_email'      => (string) ($values['to_email'] ?? self::TO_EMAIL),
            'state_dir'     => $stateDir,
        ]);
    }

    public function smtpHost(): string { return (string) $this->values['smtp_host']; }
    public function smtpPort(): int { return (int) $this->values['smtp_port']; }
    public function smtpUser(): string { return (string) $this->values['smtp_user']; }
    public function smtpPassword(): string { return (string) $this->values['smtp_password']; }
    public function fromEmail(): string { return (string) $this->values['from_email']; }
    public function fromName(): string { return (string) $this->values['from_name']; }
    public function toEmail(): string { return (string) $this->values['to_email']; }
    public function stateDir(): string { return (string) $this->values['state_dir']; }

    /**
     * Debug representation with the password removed.
     *
     * Anything that might end up in a log or an error page goes through here,
     * so the credential cannot be printed by accident.
     *
     * @return array<string,mixed>
     */
    public function redacted(): array
    {
        $out = $this->values;
        $out['smtp_password'] = '[redacted]';
        return $out;
    }

    public function __debugInfo(): array { return $this->redacted(); }
}
