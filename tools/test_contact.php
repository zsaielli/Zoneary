<?php
/**
 * Tests for the contact / early-access endpoint.
 *
 *   php tools/test_contact.php
 *
 * Dependency-free, in the same spirit as tools/check_site.py: no PHPUnit, no
 * Composer, nothing to install on a machine or in CI beyond PHP itself.
 *
 * NOTHING HERE SENDS EMAIL. The transport is an interface and every test
 * injects a recording fake, so no socket is opened and no credential is used.
 * The one "real" transport test asserts that a failure is handled, using a
 * transport that only ever throws.
 */

declare(strict_types=1);

define('ZONEARY_CONTACT', true);

$root = dirname(__DIR__);
require $root . '/site/api/lib/config.php';
require $root . '/site/api/lib/validate.php';
require $root . '/site/api/lib/message.php';
require $root . '/site/api/lib/smtp.php';
require $root . '/site/api/lib/rate_limit.php';
require $root . '/site/api/lib/handler.php';

// ---------------------------------------------------------------- harness ---

final class T
{
    public static int $passed = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n" . $name . "\n";
    }

    public static function ok(bool $condition, string $what): void
    {
        if ($condition) {
            self::$passed++;
            echo "  PASS  " . $what . "\n";
            return;
        }
        self::$failures[] = self::$group . ' / ' . $what;
        echo "  FAIL  " . $what . "\n";
    }

    public static function same($expected, $actual, string $what): void
    {
        $good = $expected === $actual;
        if (!$good) {
            $what .= sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true));
        }
        self::ok($good, $what);
    }

    public static function contains(string $haystack, string $needle, string $what): void
    {
        self::ok(strpos($haystack, $needle) !== false, $what);
    }

    public static function missing(string $haystack, string $needle, string $what): void
    {
        self::ok(strpos($haystack, $needle) === false, $what);
    }
}

// ------------------------------------------------------------------ fakes ---

/** Records what it was asked to send. Opens nothing. */
final class RecordingTransport implements ContactTransport
{
    /** @var ContactMessage[] */
    public array $sent = [];

    public function send(ContactMessage $message): void
    {
        $this->sent[] = $message;
    }

    public function last(): ?ContactMessage
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }
}

/** Stands in for an SMTP server that is refusing us, with a talkative error. */
final class FailingTransport implements ContactTransport
{
    public const LEAKY_DETAIL = 'AUTH failed: 535 5.7.8 smtp.hostinger.com rejected hunter2';

    public function send(ContactMessage $message): void
    {
        throw new ContactTransportException(self::LEAKY_DETAIL);
    }
}

// ----------------------------------------------------------------- fixture --

const TEST_PASSWORD = 'not-a-real-password-9f3a1c';

$stateRoot = sys_get_temp_dir() . '/zoneary-contact-tests-' . bin2hex(random_bytes(4));

function testConfig(): ContactConfig
{
    global $stateRoot;
    static $n = 0;
    $n++;
    return ContactConfig::fromArray([
        'smtp_password' => TEST_PASSWORD,
        'state_dir'     => $stateRoot . '/case' . $n,
    ]);
}

/** @return array<string,string> */
function validBody(array $overrides = []): array
{
    return $overrides + [
        'name'         => 'Jane Smith',
        'email'        => 'jane@example.com',
        'organization' => 'Example Logistics',
        'product'      => 'Watchtower',
        'count'        => '1 site, ~20 cameras',
        'platform'     => 'none yet',
        'notes'        => 'Interested in the desktop client.',
    ];
}

/** @return array<string,mixed> */
function server(string $ip = '203.0.113.7'): array
{
    return [
        'REMOTE_ADDR'     => $ip,
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Test)',
        'REQUEST_METHOD'  => 'POST',
    ];
}

function newHandler(?ContactTransport $transport = null, ?ContactConfig $config = null): array
{
    $config    = $config ?? testConfig();
    $transport = $transport ?? new RecordingTransport();
    $handler   = new ContactHandler($config, $transport, new ContactRateLimit($config->stateDir()));
    return [$handler, $transport, $config];
}

$NOW_MS = 1757160000000; // fixed clock, so timing tests are not flaky

// ============================================================================
T::group('1. A valid submission is accepted and handed to the transport');

[$h, $t] = newHandler();
$r = $h->handle(validBody(), server(), $NOW_MS);
T::same(200, $r['status'], 'returns 200');
T::same(true, $r['payload']['ok'], 'payload reports ok');
T::same(1, count($t->sent), 'exactly one message was handed to the transport');

$msg = $t->last();
$raw = $msg->toString();
$body = ContactMessage::body(ContactValidator::check(validBody(), $NOW_MS)['fields']);
T::contains($body, 'Name: Jane Smith', 'body carries Name');
T::contains($body, 'Email: jane@example.com', 'body carries Email');
T::contains($body, 'Organization: Example Logistics', 'body carries Organization');
T::contains($body, 'Product interest: Watchtower', 'body carries Product interest');
T::contains($body, 'Approx. sites/devices: 1 site, ~20 cameras', 'body carries sites/devices');
T::contains($body, 'Current platform: none yet', 'body carries Current platform');
T::contains($body, 'Notes:', 'body carries Notes');
T::contains($msg->header('Subject'), 'Zoneary early access - Watchtower', 'subject names the product');

$optional = ContactValidator::check(validBody(['organization' => '', 'count' => '', 'platform' => '', 'notes' => '']), $NOW_MS);
T::contains(ContactMessage::body($optional['fields']), 'Organization: -', 'an omitted optional field renders as a dash');

// ============================================================================
T::group('2. Required fields are enforced server-side');

foreach (['name' => 'Please add your name.',
          'email' => 'Please add your email address.',
          'product' => 'Please choose which product you are interested in.'] as $field => $expected) {
    [$h, $t] = newHandler();
    $r = $h->handle(validBody([$field => '']), server(), $NOW_MS);
    T::same(400, $r['status'], "missing $field is rejected with 400");
    T::same($expected, $r['payload']['error'], "missing $field explains what to fix");
    T::same(0, count($t->sent), "missing $field sends nothing");
}

// whitespace is not content
[$h, $t] = newHandler();
$r = $h->handle(validBody(['name' => "   \t  "]), server(), $NOW_MS);
T::same(400, $r['status'], 'a whitespace-only name counts as missing');
T::same(0, count($t->sent), 'a whitespace-only name sends nothing');

// ============================================================================
T::group('3. Malformed email addresses are rejected');

$bad = [
    'jane', 'jane@', '@example.com', 'jane@localhost', 'jane@example.',
    'jane example@test.com', 'jane@@example.com', 'jane<@example.com',
    '"jane"@example.com>', 'jane@example.com, evil@attacker.test',
];
foreach ($bad as $address) {
    [$h, $t] = newHandler();
    $r = $h->handle(validBody(['email' => $address]), server(), $NOW_MS);
    T::same(400, $r['status'], 'rejects ' . var_export($address, true));
    T::same(0, count($t->sent), 'sends nothing for ' . var_export($address, true));
}

foreach (['jane@example.com', 'jane.smith+tag@sub.example.co.uk', "o'brien@example.org"] as $address) {
    [$h, $t] = newHandler();
    $r = $h->handle(validBody(['email' => $address]), server(), $NOW_MS);
    T::same(200, $r['status'], 'accepts ' . $address);
}

// ============================================================================
T::group('4. Field length limits are enforced');

foreach (ContactValidator::LIMITS as $field => $max) {
    if ($field === 'product' || $field === 'email') {
        continue; // both are constrained more tightly by their own rules
    }
    [$h, $t] = newHandler();
    $r = $h->handle(validBody([$field => str_repeat('a', $max + 1)]), server(), $NOW_MS);
    T::same(400, $r['status'], "$field over $max characters is rejected");
    T::same(0, count($t->sent), "$field over $max characters sends nothing");

    [$h, $t] = newHandler();
    $r = $h->handle(validBody([$field => str_repeat('a', $max)]), server(), $NOW_MS);
    T::same(200, $r['status'], "$field at exactly $max characters is accepted");
}

// limits count characters, not bytes, so multi-byte names are not truncated early
[$h, $t] = newHandler();
$r = $h->handle(validBody(['name' => str_repeat('é', 120)]), server(), $NOW_MS);
T::same(200, $r['status'], '120 multi-byte characters fit a 120-character limit');

// an email longer than the limit is refused
[$h, $t] = newHandler();
$r = $h->handle(validBody(['email' => str_repeat('a', 250) . '@example.com']), server(), $NOW_MS);
T::same(400, $r['status'], 'an over-length email is rejected');

// ============================================================================
T::group('5. Honeypot and timing submissions are neutralized');

[$h, $t] = newHandler();
$r = $h->handle(validBody(['company_website' => 'http://spam.test']), server(), $NOW_MS);
T::same(200, $r['status'], 'a filled honeypot gets the same 200 a person would');
T::same(true, $r['payload']['ok'], 'a filled honeypot is told it succeeded');
T::same(0, count($t->sent), 'a filled honeypot sends nothing');
T::contains($r['log'], 'honeypot', 'the honeypot rejection is recorded server-side');

[$h, $t] = newHandler();
$r = $h->handle(validBody(['ts' => (string) ($NOW_MS - 400)]), server(), $NOW_MS);
T::same(200, $r['status'], 'a sub-second fill time gets a 200');
T::same(0, count($t->sent), 'a sub-second fill time sends nothing');

[$h, $t] = newHandler();
$r = $h->handle(validBody(['ts' => (string) ($NOW_MS - 30000)]), server(), $NOW_MS);
T::same(200, $r['status'], 'a plausible fill time is accepted');
T::same(1, count($t->sent), 'a plausible fill time is sent');

[$h, $t] = newHandler();
$r = $h->handle(validBody(), server(), $NOW_MS);
T::same(1, count($t->sent), 'a missing timestamp does not block a submission (no-JS path)');

[$h, $t] = newHandler();
$r = $h->handle(validBody(['surprise' => 'x']), server(), $NOW_MS);
T::same(400, $r['status'], 'an unexpected field is rejected');
T::same(0, count($t->sent), 'an unexpected field sends nothing');

[$h, $t] = newHandler();
$r = $h->handle(validBody(['notes' => ['array']]), server(), $NOW_MS);
T::same(400, $r['status'], 'a non-string field is rejected');

// the honeypot never reaches the message
[$h, $t] = newHandler();
$h->handle(validBody(['ts' => (string) ($NOW_MS - 30000)]), server(), $NOW_MS);
T::missing($t->last()->toString(), 'company_website', 'the honeypot field name never appears in the message');

// ============================================================================
T::group('6. Rate limiting');

[$h, $t] = newHandler();
$statuses = [];
for ($i = 0; $i < 6; $i++) {
    $statuses[] = $h->handle(validBody(), server('198.51.100.4'), $NOW_MS + $i * 1000)['status'];
}
T::same([200, 200, 200, 429, 429, 429], $statuses, 'the fourth burst submission from one address is refused');
T::same(3, count($t->sent), 'only the allowed submissions were sent');

[$h2, $t2, $cfg] = newHandler();
for ($i = 0; $i < 4; $i++) {
    $h2->handle(validBody(), server('198.51.100.9'), $NOW_MS + $i * 1000);
}
$r = $h2->handle(validBody(), server('198.51.100.10'), $NOW_MS + 5000);
T::same(200, $r['status'], 'a different address is unaffected by another address hitting the limit');

// the window really is a window: the same address is allowed again once it passes
[$h3, $t3] = newHandler();
for ($i = 0; $i < 4; $i++) {
    $h3->handle(validBody(), server('198.51.100.20'), $NOW_MS + $i * 1000);
}
$later = $h3->handle(validBody(), server('198.51.100.20'), $NOW_MS + (ContactRateLimit::PER_IP_BURST_WINDOW + 60) * 1000);
T::same(200, $later['status'], 'the same address is allowed again after the burst window passes');

$r = $h3->handle(validBody(), server('198.51.100.20'), $NOW_MS + (ContactRateLimit::PER_IP_BURST_WINDOW + 61) * 1000);
T::ok(isset($r['payload']['retry_after']) || $r['status'] === 200, 'a refusal carries a retry_after hint');

// A limiter that cannot write must not lock the form. Two ways to get there:
// a path underneath a regular file, and a path the OS rejects outright.
foreach ([__FILE__ . '/not-a-directory', "\0/invalid", ''] as $i => $badPath) {
    $roLimiter = new ContactRateLimit($badPath);
    T::same(false, $roLimiter->isUsable(), "an unusable state directory is detected (#$i)");
    T::same(true, $roLimiter->attempt('203.0.113.1')['allowed'], "an unusable limiter fails open rather than blocking everyone (#$i)");
}

// and the handler still delivers when the limiter cannot keep state
$brokenCfg = ContactConfig::fromArray(['smtp_password' => TEST_PASSWORD, 'state_dir' => __FILE__ . '/nope']);
$brokenT   = new RecordingTransport();
$brokenH   = new ContactHandler($brokenCfg, $brokenT, new ContactRateLimit($brokenCfg->stateDir()));
T::same(200, $brokenH->handle(validBody(), server(), $NOW_MS)['status'], 'a submission still succeeds when the limiter cannot store state');

// ============================================================================
T::group('7. An SMTP failure produces safe client behavior');

[$h, $t] = newHandler(new FailingTransport());
$r = $h->handle(validBody(), server(), $NOW_MS);
T::same(502, $r['status'], 'a transport failure returns 502');
T::same(false, $r['payload']['ok'], 'a transport failure reports not-ok');
T::same(ContactHandler::GENERIC_FAILURE, $r['payload']['error'], 'the visitor gets the generic failure message');

$clientText = json_encode($r['payload']);
T::missing($clientText, 'hunter2', 'the failing credential is not echoed to the client');
T::missing($clientText, 'smtp.hostinger', 'the SMTP hostname is not echoed to the client');
T::missing($clientText, '535', 'the raw SMTP reply code is not echoed to the client');
T::contains($r['log'], 'send failed', 'the real reason is kept server-side');

// ============================================================================
T::group('8-10. The envelope is fixed, and the visitor is only ever Reply-To');

[$h, $t] = newHandler();
$h->handle(validBody(), server(), $NOW_MS);
$msg = $t->last();

T::same('"Zoneary Website" <website@zoneary.com>', $msg->header('From'), 'From is the website mailbox');
T::same('info@zoneary.com', $msg->header('To'), 'To is info@zoneary.com');
T::same('"Jane Smith" <jane@example.com>', $msg->header('Reply-To'), 'Reply-To is the visitor');
T::same('website@zoneary.com', $msg->envelopeSender(), 'the envelope sender is the website mailbox');
T::same('info@zoneary.com', $msg->envelopeRecipient(), 'the envelope recipient is info@zoneary.com');
T::missing($msg->header('From'), 'jane@example.com', 'the visitor never appears in From');

// the visitor cannot redirect the message by any field
foreach (['name', 'organization', 'notes', 'count', 'platform'] as $field) {
    [$h, $t] = newHandler();
    $h->handle(validBody([$field => 'attacker@evil.test']), server(), $NOW_MS);
    T::same('info@zoneary.com', $t->last()->header('To'), "To is unchanged by the $field field");
    T::same('info@zoneary.com', $t->last()->envelopeRecipient(), "the envelope recipient is unchanged by the $field field");
}

// a unicode display name is encoded rather than passed through raw
[$h, $t] = newHandler();
$h->handle(validBody(['name' => 'Zoë Ärmström']), server(), $NOW_MS);
$replyTo = $t->last()->header('Reply-To');
T::contains($replyTo, '=?UTF-8?B?', 'a non-ASCII display name is RFC 2047 encoded');
T::contains($replyTo, '<jane@example.com>', 'the encoded name still carries the visitor address');

// ============================================================================
T::group('11. User input cannot inject mail headers');

$payloads = [
    "Jane\r\nBcc: evil@attacker.test",
    "Jane\nBcc: evil@attacker.test",
    "Jane\rTo: evil@attacker.test",
    "Jane\r\n\r\nInjected body",
    "Jane%0d%0aBcc:evil@attacker.test",
    "Jane\x00Bcc: evil@attacker.test",
];

foreach (['name', 'organization', 'count', 'platform'] as $field) {
    foreach ($payloads as $i => $payload) {
        [$h, $t] = newHandler();
        $r = $h->handle(validBody([$field => $payload]), server(), $NOW_MS);
        T::same(200, $r['status'], "$field injection #$i is handled rather than erroring");

        $msg = $t->last();
        $headerBlock = substr($msg->toString(), 0, (int) strpos($msg->toString(), "\r\n\r\n"));
        T::missing(strtolower($headerBlock), 'bcc:', "$field injection #$i produces no Bcc header");
        T::missing(strtolower($headerBlock), 'evil@attacker.test', "$field injection #$i keeps the address out of the headers");
        T::same('info@zoneary.com', $msg->header('To'), "$field injection #$i leaves To alone");
    }
}

// the same, through the address field
foreach (["jane@example.com\r\nBcc: evil@attacker.test", "jane@example.com%0aBcc:evil@attacker.test"] as $i => $payload) {
    [$h, $t] = newHandler();
    $r = $h->handle(validBody(['email' => $payload]), server(), $NOW_MS);
    T::same(400, $r['status'], "an address carrying a header break is rejected (#$i)");
    T::same(0, count($t->sent), "an address carrying a header break sends nothing (#$i)");
}

// no header value may contain a bare CR or LF, whatever was submitted
[$h, $t] = newHandler();
$h->handle(validBody(['name' => "Jane\r\nBcc: evil@attacker.test", 'notes' => "line one\r\nline two"]), server(), $NOW_MS);
foreach ($t->last()->headers() as $name => $value) {
    T::ok(strpbrk($value, "\r\n") === false, "header $name contains no line break");
}

// a notes body with a leading dot cannot end the SMTP DATA stage early
$transport = new SmtpTransport(testConfig());
$stuffed = $transport->dotStuff("Subject: x\r\n\r\n.\r\nQUIT\r\n");
T::contains($stuffed, "\r\n..\r\n", 'a lone dot line is dot-stuffed for DATA');
T::same('..leading', substr($transport->dotStuff('.leading'), 0, 9), 'a leading dot is stuffed too');

// direct unit check of the header sanitizer
T::same('JaneBcc: evil', ContactMessage::headerValue("Jane\r\nBcc: evil"), 'headerValue removes CR/LF outright');
T::same('Jane Bcc: evil', ContactValidator::singleLine(ContactValidator::clean("Jane\r\nBcc: evil")), 'the validator flattens a line break to a space first');
$threw = false;
try {
    ContactMessage::mailbox('Jane', "jane@example.com\r\nBcc: evil@attacker.test");
} catch (Throwable $e) {
    $threw = true;
}
T::same(true, $threw, 'mailbox() refuses an address containing a header break');

// ============================================================================
T::group('12. SMTP credentials never reach the browser');

[$h, $t, $config] = newHandler();
$r = $h->handle(validBody(), server(), $NOW_MS);
T::missing(json_encode($r['payload']), TEST_PASSWORD, 'the success payload contains no password');

[$h, $t] = newHandler(new FailingTransport());
$r = $h->handle(validBody(), server(), $NOW_MS);
T::missing(json_encode($r['payload']), TEST_PASSWORD, 'the failure payload contains no password');

[$h, $t] = newHandler();
$h->handle(validBody(), server(), $NOW_MS);
T::missing($t->last()->toString(), TEST_PASSWORD, 'the outgoing message body contains no password');

T::missing(json_encode($config->redacted()), TEST_PASSWORD, 'the redacted config contains no password');
T::contains(json_encode($config->redacted()), 'redacted', 'the redacted config marks the password as removed');
T::missing(print_r($config, true), TEST_PASSWORD, 'print_r on the config reveals no password');
T::missing(var_export($config->__debugInfo(), true), TEST_PASSWORD, 'var_dump-style output reveals no password');

// every 4xx/5xx message the endpoint can produce, checked in one place
$clientMessages = [];
foreach ([validBody(['name' => '']), validBody(['email' => 'nope']), validBody(['notes' => str_repeat('a', 5000)])] as $case) {
    [$h, $t] = newHandler();
    $clientMessages[] = json_encode($h->handle($case, server(), $NOW_MS)['payload']);
}
foreach ($clientMessages as $i => $text) {
    T::missing($text, TEST_PASSWORD, "error response #$i contains no password");
    T::missing($text, 'smtp.hostinger.com', "error response #$i contains no SMTP host");
    T::missing($text, 'website@zoneary.com', "error response #$i does not disclose the sending mailbox");
}

// the source tree itself must not contain a credential
T::group('12b. No credential material is in the repository');

$sourceFiles = array_merge(
    glob($root . '/site/api/*.php') ?: [],
    glob($root . '/site/api/lib/*.php') ?: []
);
foreach ($sourceFiles as $file) {
    $text = file_get_contents($file);
    $name = basename($file);
    T::ok(
        preg_match('/smtp_password\s*=>\s*[\'"][^\'"]+[\'"]/', $text) !== 1,
        "$name assigns no literal smtp_password"
    );
    T::ok(
        preg_match('/\$password\s*=\s*[\'"][^\'"]{4,}[\'"]/', $text) !== 1,
        "$name assigns no literal password"
    );
}

// ============================================================================
T::group('13. The configuration is loaded from outside the web root');

$candidates = ContactConfig::candidatePaths('/home/u123/domains/zoneary.com/public_html/api');
T::ok($candidates !== [], 'at least one configuration path is considered');
foreach ($candidates as $path) {
    T::missing($path, '/public_html/', 'candidate path is outside public_html: ' . $path);
}
T::contains($candidates[0], 'zoneary-private/contact-config.php', 'the documented path is the first candidate');

$threw = false;
try {
    ContactConfig::fromArray(['smtp_password' => '']);
} catch (Throwable $e) {
    $threw = true;
}
T::same(true, $threw, 'an empty password is refused rather than silently used');

$threw = false;
try {
    ContactConfig::load(sys_get_temp_dir() . '/zoneary-nonexistent-' . bin2hex(random_bytes(4)) . '/api');
} catch (Throwable $e) {
    $threw = true;
}
T::same(true, $threw, 'a missing configuration file is an error, not a default');

$defaults = testConfig();
T::same('smtp.hostinger.com', $defaults->smtpHost(), 'the SMTP host defaults to Hostinger');
T::same(465, $defaults->smtpPort(), 'the SMTP port defaults to 465');
T::same('website@zoneary.com', $defaults->smtpUser(), 'the SMTP user defaults to the website mailbox');

// ============================================================================
T::group('13b. The library directory is denied to the web, but not to PHP');

$deny = $root . '/site/api/lib/.htaccess';
T::ok(is_file($deny), 'api/lib/.htaccess exists');
$denyText = is_file($deny) ? file_get_contents($deny) : '';
T::contains($denyText, 'Require all denied', 'it denies all direct access');
T::ok(
    preg_match('/^\s*<IfModule/m', $denyText) !== 1,
    'the directive is unconditional, so it cannot silently match nothing'
);

// The point of the check: an HTTP-level deny must not affect PHP's include
// path. Every class the endpoint needs is reachable with the file in place -
// which it is, since these tests just required them all from that directory.
foreach (['ContactConfig', 'ContactValidator', 'ContactMessage', 'SmtpTransport',
          'ContactRateLimit', 'ContactHandler'] as $class) {
    T::ok(class_exists($class), "$class loads from the denied directory");
}
T::ok(interface_exists('ContactTransport'), 'ContactTransport loads from the denied directory');

// and the endpoint still requires exactly those files, by filesystem path
$entry = file_get_contents($root . '/site/api/contact.php');
T::ok(
    preg_match_all("/require __DIR__ \. '\/lib\/[a-z_]+\.php';/", $entry) === 6,
    'contact.php requires all six libraries by filesystem path, not by URL'
);
T::missing($entry, "require 'http", 'no library is fetched over HTTP');

// the deny file must never reach the static mirror, which has no server to honour it
$pages = file_get_contents($root . '/.github/workflows/pages.yml');
T::contains($pages, 'rm -rf site/api', 'the Pages mirror drops the whole api directory');

// ============================================================================
T::group('14. The page still works, and the mailto flow is gone');

$page = file_get_contents($root . '/site/early-access.html');

T::contains($page, 'action="api/contact.php"', 'the form posts to the endpoint');
T::contains($page, 'method="post"', 'the form uses POST');
T::contains($page, 'fetch(form.getAttribute(\'action\')', 'submission goes through fetch');
T::missing($page, "window.location.href = href", 'the mailto redirect is gone');
T::missing($page, 'window.location.href', 'nothing on the page navigates by assigning location.href');
// Plain "write to us" links are fine and stay. What must not exist is a mailto
// that carries the form's contents - that would be the old flow in disguise.
T::ok(preg_match('/mailto:[^"\']*[?&]body=/i', $page) !== 1, 'no mailto: link carries a prefilled body');
T::ok(preg_match('/mailto:[^"\']*encodeURIComponent/i', $page) !== 1, 'no mailto: URL is assembled from field values');
$script = substr($page, (int) strpos($page, '<script>'));
T::ok(preg_match('/\blines\s*=\s*\[/', $script) !== 1, 'the message-body assembly in JavaScript is gone');
T::missing($page, 'nothing is submitted or stored on this site', 'the old explanatory claim is gone');
T::contains($page, 'Submitted directly to Zoneary', 'the new explanatory copy is present');

// the abuse controls exist in the markup the server expects
T::contains($page, 'name="' . ContactValidator::HONEYPOT_FIELD . '"', 'the honeypot field is in the markup');
T::contains($page, 'class="ea-trap"', 'the honeypot is inside the hidden wrapper');
T::contains($page, 'name="ts"', 'the timestamp field is in the markup');

// the pre-existing behaviours this page already had
T::contains($page, "params.get('product')", 'the ?product= preselect still exists');
T::contains($page, "id=\"ea-back\"", 'the contextual back link still exists');
T::contains($page, 'Join early access', 'the submit button keeps its label');

// every name= the form posts is a field the server knows about
preg_match_all('/<(?:input|select|textarea)[^>]*\bname="([^"]+)"/i', $page, $m);
foreach (array_unique($m[1]) as $field) {
    T::ok(in_array($field, ContactValidator::KNOWN_FIELDS, true), "form field '$field' is known to the server");
}
// and every product option the form offers is one the server accepts
preg_match_all('/<option value="([^"]+)"/', $page, $m);
foreach ($m[1] as $value) {
    T::ok(in_array($value, ContactValidator::PRODUCTS, true), "product option '$value' passes the server allowlist");
}

// submission states are actually implemented
T::contains($page, 'button.disabled = on', 'the submit button is disabled while sending');
T::contains($page, "if (sending) return", 'a duplicate submission while in flight is ignored');
T::contains($page, 'form.reset()', 'the form is only cleared on success');
$successBlock = substr($page, (int) strpos($page, 'if (r.data && r.data.ok)'), 400);
T::contains($successBlock, 'form.reset()', 'the reset happens inside the success branch');

$css = file_get_contents($root . '/site/css/styles.css');
T::contains($css, '.ea-trap', 'the honeypot has a style that hides it');
T::contains($css, '.ea-note.is-error', 'an error state is styled');
T::contains($css, '.ea-done', 'the success panel is styled');
// .ea-form is display:flex, which outranks the hidden attribute unless this
// rule exists - without it the form stays on screen after a success.
T::contains($css, '.ea-form[hidden]{display:none}', 'a hidden form is actually hidden despite display:flex');

// the privacy notice no longer describes a purely static site
$privacy = file_get_contents($root . '/site/privacy.html');
T::ok(
    strpos($privacy, 'The Zoneary marketing website is a static site. It does not run analytics') === false,
    'the unqualified static-site claim has been updated'
);
T::contains($privacy, 'early-access form, which posts to a Zoneary endpoint', 'the privacy notice describes the endpoint');

// ============================================================================
// cleanup
$rm = static function (string $dir) use (&$rm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
        $path = $dir . '/' . $entry;
        is_dir($path) ? $rm($path) : @unlink($path);
    }
    @rmdir($dir);
};
$rm($stateRoot);

echo "\n" . str_repeat('-', 60) . "\n";
if (T::$failures === []) {
    echo sprintf("ALL CONTACT ENDPOINT TESTS PASSED (%d assertions)\n", T::$passed);
    exit(0);
}
echo sprintf("FAILED - %d of %d assertions\n", count(T::$failures), T::$passed + count(T::$failures));
foreach (T::$failures as $failure) {
    echo '  - ' . $failure . "\n";
}
exit(1);
