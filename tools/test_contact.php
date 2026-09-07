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

/**
 * Assert one page's styles.css reference carries the current content version.
 *
 * @return int 1 if the page links styles.css, 0 if it does not
 */
function assertStamped(string $file, string $root, string $wantVersion): int
{
    $text = file_get_contents($file);
    $name = str_replace('\\', '/', substr($file, strlen($root) + 1));
    preg_match_all('/href="([^"]*styles\.css[^"]*)"/', $text, $m);
    if (!$m[1]) {
        return 0;
    }
    foreach ($m[1] as $ref) {
        T::contains($ref, '?v=' . $wantVersion, "$name: styles.css is stamped with the current content version");
    }
    return 1;
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
T::contains($msg->header('Subject'), 'Zoneary enquiry - Watchtower', 'subject names the product');
T::missing($msg->header('Subject'), 'early access', 'the subject does not presume a waitlist signup');

$optional = ContactValidator::check(validBody(['organization' => '', 'count' => '', 'platform' => '', 'notes' => '']), $NOW_MS);
T::contains(ContactMessage::body($optional['fields']), 'Organization: -', 'an omitted optional field renders as a dash');

// ============================================================================
T::group('2. Required fields are enforced server-side');

foreach (['name' => 'Please add your name.',
          'email' => 'Please add your email address.',
          'product' => 'Please choose what your message is about.'] as $field => $expected) {
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

// Both trap names are live: the current one, and the previous one so a visitor
// on a cached copy of the old page is handled the same way rather than getting
// a confusing rejection.
foreach (ContactValidator::HONEYPOT_FIELDS as $trap) {
    [$h, $t] = newHandler();
    $r = $h->handle(validBody([$trap => 'http://spam.test']), server(), $NOW_MS);
    T::same(200, $r['status'], "a filled '$trap' gets the same 200 a person would");
    T::same(true, $r['payload']['ok'], "a filled '$trap' is told it succeeded");
    T::same(0, count($t->sent), "a filled '$trap' sends nothing");
    T::contains($r['log'], 'honeypot', "the '$trap' rejection is recorded server-side");
}
T::ok(in_array('homepage_url', ContactValidator::HONEYPOT_FIELDS, true), 'the renamed trap is server-detectable');
T::ok(in_array('company_website', ContactValidator::HONEYPOT_FIELDS, true), 'the previous trap name still detects');
T::same('homepage_url', ContactValidator::HONEYPOT_FIELD, 'the form posts the renamed trap');
foreach (ContactValidator::HONEYPOT_FIELDS as $trap) {
    T::ok(in_array($trap, ContactValidator::KNOWN_FIELDS, true), "'$trap' is an accepted field, not a hard rejection");
}

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
foreach (ContactValidator::HONEYPOT_FIELDS as $trap) {
    T::missing($t->last()->toString(), $trap, "the trap name '$trap' never appears in the message");
}

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
T::group('7b. The acceptance boundary: nothing after the final 250 can fail a send');

// A scripted SMTP server at the wire level. The real command sequencing,
// expect() gating, dot-stuffing and acceptance ordering all run; only the
// socket is replaced, deterministically. Nothing contacts Hostinger and the
// only credential in play is the fake TEST_PASSWORD.
final class ScriptedSmtp extends SmtpTransport
{
    /** @var string[] replies the server will hand back, in order */
    private array $script;
    private int $at = 0;
    /** @var string[] everything the client put on the wire */
    public array $written = [];
    /** Server hangs up the moment it has accepted the message. */
    public bool $dropAfterAccept = false;
    private bool $accepted = false;

    public function __construct(ContactConfig $c, array $script, bool $dropAfterAccept = false)
    {
        parent::__construct($c);
        $this->script = $script;
        $this->dropAfterAccept = $dropAfterAccept;
    }

    public function send(ContactMessage $message): void
    {
        $this->runConversation($message);
    }

    protected function write(string $data): void
    {
        $this->written[] = $data;
        // A server that has hung up makes the next write fail - which is
        // exactly the condition that used to sink an accepted message.
        if ($this->accepted && $this->dropAfterAccept) {
            throw new ContactTransportException('write to the SMTP server failed');
        }
    }

    protected function read(): string
    {
        if ($this->at >= count($this->script)) {
            throw new ContactTransportException('the SMTP connection closed early');
        }
        $reply = $this->script[$this->at++];
        // the last scripted reply is the post-DATA acceptance
        if ($this->at === count($this->script) && strncmp($reply, '250', 3) === 0) {
            $this->accepted = true;
        }
        return $reply;
    }
}

$ACCEPTING_SCRIPT = [
    "220 smtp.test ESMTP ready\r\n",
    "250-smtp.test\r\n250 AUTH LOGIN\r\n",
    "334 VXNlcm5hbWU6\r\n",
    "334 UGFzc3dvcmQ6\r\n",
    "235 2.7.0 Authentication succeeded\r\n",
    "250 2.1.0 Sender OK\r\n",
    "250 2.1.5 Recipient OK\r\n",
    "354 Start mail input\r\n",
    "250 2.0.0 Ok: queued as ABC123\r\n",
];

$cfg = testConfig();
$fields = ContactValidator::check(validBody(), $NOW_MS)['fields'];
$msg = ContactMessage::build($cfg, $fields, [], intdiv($NOW_MS, 1000));

// 1. The happy path still completes.
$threw = null;
try {
    (new ScriptedSmtp($cfg, $ACCEPTING_SCRIPT, false))->send($msg);
} catch (Throwable $e) {
    $threw = $e;
}
T::same(null, $threw === null ? null : $threw->getMessage(), 'a full accepting conversation succeeds');

// 2. THE REGRESSION: the server accepts, then hangs up before QUIT is written.
//    Previously the QUIT write threw and the visitor was told the send failed
//    for a message the server had already taken responsibility for.
$threw = null;
try {
    (new ScriptedSmtp($cfg, $ACCEPTING_SCRIPT, true))->send($msg);
} catch (Throwable $e) {
    $threw = $e;
}
T::same(null, $threw === null ? null : $threw->getMessage(),
    'a socket closed straight after the final 250 is still a successful send');

// 3. And through the handler, the client sees success rather than a 502.
$closingTransport = new ScriptedSmtp(testConfig(), $ACCEPTING_SCRIPT, true);
$cfg3 = testConfig();
$h = new ContactHandler($cfg3, $closingTransport, new ContactRateLimit($cfg3->stateDir()));
$r = $h->handle(validBody(), server('198.51.100.77'), $NOW_MS);
T::same(200, $r['status'], 'the endpoint returns 200 when the server hangs up after accepting');
T::same(true, $r['payload']['ok'], 'the visitor is not told to submit again');

// 4. Failures BEFORE acceptance must still fail. The boundary moved, not vanished.
$refusals = [
    'AUTH refused'   => [4, "535 5.7.8 Authentication credentials invalid\r\n"],
    'MAIL FROM'      => [5, "550 5.7.1 Sender rejected\r\n"],
    'RCPT TO'        => [6, "550 5.1.1 No such recipient\r\n"],
    'DATA'           => [7, "554 5.5.1 Command rejected\r\n"],
    'body rejected'  => [8, "552 5.3.4 Message too big\r\n"],
];
foreach ($refusals as $label => [$index, $reply]) {
    $script = $ACCEPTING_SCRIPT;
    $script[$index] = $reply;
    $script = array_slice($script, 0, $index + 1);
    $threw = false;
    try {
        (new ScriptedSmtp($cfg, $script, false))->send($msg);
    } catch (ContactTransportException $e) {
        $threw = true;
    }
    T::same(true, $threw, "a refusal at $label is still a failure");
}

// 5. And a pre-acceptance refusal reaches the client as a safe 502.
$script = $ACCEPTING_SCRIPT;
$script[4] = "535 5.7.8 Authentication credentials invalid\r\n";
$script = array_slice($script, 0, 5);
$cfg4 = testConfig();
$h = new ContactHandler($cfg4, new ScriptedSmtp($cfg4, $script, false),
                        new ContactRateLimit($cfg4->stateDir()));
$r = $h->handle(validBody(), server('198.51.100.88'), $NOW_MS);
T::same(502, $r['status'], 'an authentication refusal is reported as a failure, not a success');
T::same(ContactHandler::GENERIC_FAILURE, $r['payload']['error'], 'and the visitor gets the generic message');
T::missing(json_encode($r['payload']), TEST_PASSWORD, 'an auth refusal does not echo the credential');
T::missing(json_encode($r['payload']), '535', 'an auth refusal does not echo the SMTP code');

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
T::contains($page, 'Send to Zoneary', 'the submit button carries the neutral label');
T::missing($page, '>Join early access</button>', 'the waitlist-only button label is gone');

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
$successBlock = substr($page, (int) strpos($page, 'if (r.data && r.data.ok)'), 900);
T::contains($successBlock, 'form.reset()', 'the reset happens inside the success branch');

// ---- the success transition must not depend on the stylesheet -------------
// A stale styles.css left the button reading "Sending…" under a form that
// would not disappear, with the success panel stacked below it.
T::contains($successBlock, 'busy(false)', 'the success branch restores the button state');
T::ok(
    strpos($successBlock, 'busy(false)') < strpos($successBlock, 'form.hidden = true'),
    'the button is restored before the form is hidden'
);
T::contains($successBlock, "form.style.display = 'none'", 'the form is hidden inline, not only by attribute');
T::contains($successBlock, 'form.hidden = true', 'the hidden attribute is still set for semantics');
T::contains($successBlock, 'done.hidden = false', 'the success panel is revealed');
T::contains($successBlock, "done.style.display = 'block'", 'the success panel is shown inline too');

// ---- the honeypot conceals itself without the stylesheet -------------------
preg_match('/<div[^>]*class="ea-trap"[^>]*>(.*?)<\/div>/s', $page, $trap);
T::ok(!empty($trap), 'the honeypot wrapper is present');
$wrapper = $trap ? substr($trap[0], 0, strpos($trap[0], '>') + 1) : '';
$inner   = $trap[1] ?? '';
T::contains($wrapper, 'style=', 'the honeypot wrapper carries an inline style');
$inlineCss = str_replace(' ', '', strtolower($wrapper));
T::contains($inlineCss, 'position:absolute', 'the inline style positions it absolutely');
T::ok((bool) preg_match('/left:-\d/', $inlineCss), 'the inline style moves it off-screen');
T::contains($wrapper, 'aria-hidden="true"', 'the wrapper is hidden from assistive technology');
T::contains($inner, 'tabindex="-1"', 'the honeypot input is not keyboard-focusable');
T::contains($inner, 'autocomplete="off"', 'the honeypot opts out of autofill');
T::missing($inner, 'type="hidden"', 'the honeypot is NOT type=hidden, which bots would skip');
T::contains($inner, 'name="' . ContactValidator::HONEYPOT_FIELD . '"', 'the markup posts the name the server traps');
T::ok(strpos($page, 'name="company_website"') === false, 'the autofill-prone old field name is gone from the markup');

// ---- every page's stylesheet reference is cache-busted ---------------------
// css/ is served with max-age=604800 while the HTML is not cached at all, so an
// unversioned reference means a deploy lands new markup on an old stylesheet.
$cssFile = $root . '/site/css/styles.css';
$wantVersion = substr(hash('sha256', str_replace("\r\n", "\n", file_get_contents($cssFile))), 0, 8);
$pagesWithCss = 0;
foreach (glob($root . '/site/*.html') ?: [] as $f) {
    $pagesWithCss += assertStamped($f, $root, $wantVersion);
}
foreach (glob($root . '/site/*/index.html') ?: [] as $f) {
    $pagesWithCss += assertStamped($f, $root, $wantVersion);
}
T::ok($pagesWithCss >= 10, "every page linking styles.css is stamped (found $pagesWithCss)");

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
T::group('15. One form for contact, early access and product interest');

// A general enquiry must be expressible - the page is reached from the Contact
// nav, not only from a product CTA.
T::ok(in_array('Something else', ContactValidator::PRODUCTS, true), "a general enquiry has an allowlisted value");
[$h, $t] = newHandler();
$r = $h->handle(validBody(['product' => 'Something else']), server(), $NOW_MS);
T::same(200, $r['status'], 'a general enquiry is accepted');
T::contains($t->last()->header('Subject'), 'Zoneary enquiry - Something else', 'its subject reads as an enquiry');

// Every product still routes, and none of them says "early access" in the subject.
foreach (ContactValidator::PRODUCTS as $product) {
    [$h, $t] = newHandler();
    $r = $h->handle(validBody(['product' => $product]), server(), $NOW_MS);
    T::same(200, $r['status'], "product '$product' is accepted");
    T::contains($t->last()->header('Subject'), 'Zoneary enquiry - ' . $product, "subject for '$product'");
    T::contains(ContactMessage::body(ContactValidator::check(validBody(['product' => $product]), $NOW_MS)['fields']),
        'Product interest: ' . $product, "the body still records product interest for '$product'");
}

// ---- contact routing across the site --------------------------------------
// The nav and footer "Contact" links, and the primary CTA buttons, must reach
// the form rather than the visitor's mail client.
$siteRoot = $root . '/site';
$pages = array_merge(glob($siteRoot . '/*.html') ?: [], glob($siteRoot . '/*/index.html') ?: []);
$footerContact = 0;
foreach ($pages as $f) {
    $text = file_get_contents($f);
    // the glob covers *.html and */index.html, so sentinel/demo.html is out of scope
    $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($f, strlen($siteRoot) + 1));
    // no "Contact" link may be a mailto any more
    T::ok(
        preg_match('/<a[^>]*href="mailto:[^"]*"[^>]*>\s*Contact\s*<\/a>/i', $text) !== 1,
        "$name: no mailto: link labelled Contact"
    );
    if (preg_match('/<a[^>]*href="([^"]*early-access\.html[^"]*)"[^>]*>\s*Contact\s*<\/a>/i', $text)) {
        $footerContact++;
    }
}
T::ok($footerContact >= 9, "every page routes Contact to the form (found $footerContact)");

// the homepage nav specifically - the link that started this
$home = file_get_contents($siteRoot . '/index.html');
T::ok(
    preg_match('/<a href="early-access\.html">Contact<\/a>/', $home) === 1,
    'the homepage nav Contact link points at the form'
);
T::missing($home, '<a href="mailto:info@zoneary.com">Contact</a>', 'the homepage nav mailto is gone');
T::missing($home, 'mailto:info@zoneary.com?subject=Zoneary%20sales%20enquiry', 'the homepage sales CTA no longer opens a mail client');

// informational mailto links are deliberately preserved
T::contains(file_get_contents($siteRoot . '/security.html'), 'mailto:info@zoneary.com?subject=Security%20report',
    'responsible-disclosure email is still a direct mailto');
T::contains(file_get_contents($siteRoot . '/privacy.html'), 'mailto:info@zoneary.com?subject=Privacy',
    'the privacy contact is still a direct mailto');
T::contains($page, 'mailto:info@zoneary.com?subject=Early%20access',
    'the form keeps an email fallback for when it cannot be used');

// the page reads as a contact destination, not only a waitlist
T::contains($page, 'Get in touch with Zoneary', 'the heading works for any enquiry');
T::missing($page, 'Be first, as the ecosystem rolls out.', 'the waitlist-only heading is gone');
T::contains($page, '<title>Contact | Zoneary</title>', 'the title reads as Contact');
T::contains($page, 'Something else', 'the form offers a general option');

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
