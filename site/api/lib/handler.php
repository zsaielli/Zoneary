<?php
/**
 * The endpoint's decision-making, separated from the HTTP plumbing so it can be
 * exercised directly by tools/test_contact.php with a fake transport.
 *
 * Everything a client is told comes from here, and it is deliberately dull:
 * a validation message a visitor can act on, or one generic failure. SMTP
 * detail, configuration, stack traces and the contents of exceptions never
 * cross this boundary.
 */

declare(strict_types=1);

// Library file: only ever reached through contact.php, which defines this.
if (!defined('ZONEARY_CONTACT')) {
    http_response_code(404);
    exit;
}

final class ContactHandler
{
    public const GENERIC_FAILURE = 'Something went wrong on our end and the message was not sent. Please try again in a moment, or email info@zoneary.com directly.';
    public const SUCCESS         = 'Thanks - we have your details.';

    private ContactConfig $config;
    private ContactTransport $transport;
    private ContactRateLimit $limiter;

    public function __construct(ContactConfig $config, ContactTransport $transport, ContactRateLimit $limiter)
    {
        $this->config    = $config;
        $this->transport = $transport;
        $this->limiter   = $limiter;
    }

    /**
     * @param array<string,mixed> $body   the parsed request body
     * @param array<string,mixed> $server the $_SERVER superglobal (or a stand-in)
     * @return array{status:int, payload:array<string,mixed>, log?:string}
     */
    public function handle(array $body, array $server, ?int $nowMs = null): array
    {
        $nowMs = $nowMs ?? (int) round(microtime(true) * 1000);
        $now   = intdiv($nowMs, 1000);

        $check = ContactValidator::check($body, $nowMs);

        if ($check['ok'] !== true) {
            // A honeypot or timing rejection is answered as if it succeeded, so
            // an automated submitter gets no signal to tune against. Nothing is
            // sent and nothing is recorded beyond the rate-limit counter.
            if (!empty($check['silent'])) {
                $this->limiter->attempt($this->identity($server), $now);
                return [
                    'status'  => 200,
                    'payload' => ['ok' => true, 'message' => self::SUCCESS],
                    'log'     => 'rejected quietly: ' . (string) ($check['reason'] ?? ''),
                ];
            }

            return [
                'status'  => 400,
                'payload' => ['ok' => false, 'error' => (string) $check['error']],
                'log'     => 'invalid: ' . (string) ($check['reason'] ?? ''),
            ];
        }

        $verdict = $this->limiter->attempt($this->identity($server), $now);
        if ($verdict['allowed'] !== true) {
            $retry = (int) ($verdict['retry_after'] ?? 600);
            return [
                'status'  => 429,
                'payload' => [
                    'ok'          => false,
                    'error'       => 'That is a few more submissions than we expected from one place. Please try again shortly, or email info@zoneary.com.',
                    'retry_after' => $retry,
                ],
                'log' => 'rate limited: ' . (string) ($verdict['reason'] ?? ''),
            ];
        }

        /** @var array<string,string> $fields */
        $fields = $check['fields'];

        try {
            $message = ContactMessage::build($this->config, $fields, $this->metadata($server, $now), $now);
            $this->transport->send($message);
        } catch (Throwable $e) {
            // The exception text can name the SMTP host or quote a server reply.
            // It stays here; the client gets the generic failure.
            return [
                'status'  => 502,
                'payload' => ['ok' => false, 'error' => self::GENERIC_FAILURE],
                'log'     => 'send failed: ' . $e->getMessage(),
            ];
        }

        return [
            'status'  => 200,
            'payload' => ['ok' => true, 'message' => self::SUCCESS],
        ];
    }

    /**
     * Context that helps identify abuse, and nothing more. No cookies, no
     * headers that could carry credentials, no referrer chain.
     *
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    public function metadata(array $server, int $now): array
    {
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (ContactValidator::length($ua) > 200) {
            $ua = substr($ua, 0, 200);
        }

        return [
            'Submitted' => gmdate('Y-m-d H:i:s', $now) . ' UTC',
            'IP'        => $this->clientIp($server),
            'Agent'     => $ua !== '' ? ContactValidator::clean($ua) : '-',
        ];
    }

    /**
     * The client address.
     *
     * REMOTE_ADDR only. X-Forwarded-For is client-settable and this endpoint
     * sits directly behind the origin web server, so honouring it would let
     * anyone mint a fresh rate-limit identity per request.
     *
     * @param array<string,mixed> $server
     */
    public function clientIp(array $server): string
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? 'unknown' : $ip;
    }

    /** @param array<string,mixed> $server */
    private function identity(array $server): string
    {
        return $this->clientIp($server);
    }
}
