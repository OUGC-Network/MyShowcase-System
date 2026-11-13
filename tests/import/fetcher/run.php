<?php
/**
 * Unit tests of the external file fetcher (decision D53, section 5.8 and 5.11): the URL syntax rules, the address
 * classification, the literal forms of IPv4 addresses, the fetcher's hop rules with a fake resolver and a fake transport,
 * the acceptance checks of a downloaded file, and the cleanup of temporary files. No network, no cURL:
 *
 *     php tests/import/fetcher/run.php
 */

declare(strict_types=1);

error_reporting(E_ALL);

ini_set('memory_limit', '512M');

define('IN_MYBB', 1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyShowcase\\')) {
        $file = dirname(__DIR__, 3) . '/Upload/inc/plugins/myshowcase/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

use MyShowcase\Service\ExternalFileAcceptance;
use MyShowcase\Service\ExternalFileDownload;
use MyShowcase\Service\ExternalFileFetcher;
use MyShowcase\Service\ExternalFileResolver;
use MyShowcase\Service\ExternalFileTempArea;
use MyShowcase\Service\ExternalFileTransport;
use MyShowcase\Service\ExternalFileTransportResult;
use MyShowcase\Util\AddressClass;
use MyShowcase\Util\ExternalFileException;
use MyShowcase\Util\ExternalFileLimits;
use MyShowcase\Util\ExternalUrl;

$failures = 0;
$passes = 0;

function check(bool $condition, string $name): void
{
    global $failures, $passes;

    if ($condition) {
        ++$passes;
    } else {
        ++$failures;

        echo "FAIL {$name}\n";
    }
}

/** The reason code a callable fails with, or null when it succeeds. */
function reasonOf(callable $call): ?string
{
    try {
        $call();
    } catch (ExternalFileException $exception) {
        return $exception->reason;
    }

    return null;
}

// --- URL syntax (section 5.7) ------------------------------------------------------------------------------------------

check(ExternalUrl::parse('https://placehold.co/600x450.png?text=Gallery%20Entry')->host === 'placehold.co', 'a placehold.co URL is accepted');
check(ExternalUrl::parse('https://PLACEHOLD.co/a.png')->host === 'placehold.co', 'the host is lower-cased');
check(ExternalUrl::parse('http://example.com')->path === '/', 'an empty path is /');
check(ExternalUrl::parse('https://example.com/a?x=1')->path === '/a?x=1', 'the query stays in the path');
check(ExternalUrl::parse('https://example.com:443/a')->port === 443 && ExternalUrl::parse('http://example.com:80/a')->port === 80, 'ports 80 and 443 are accepted');
check(ExternalUrl::parse('http://example.com/a')->port === 80 && ExternalUrl::parse('https://example.com/a')->port === 443, 'the default port follows the scheme');

$refused = [
    'file:///etc/passwd' => 'scheme',
    'ftp://placehold.co/x.png' => 'scheme',
    'gopher://placehold.co/' => 'scheme',
    'data:image/png;base64,AAAA' => 'scheme',
    'javascript:alert(1)' => 'scheme',
    'http:placehold.co/x' => 'scheme',
    'placehold.co/x.png' => 'scheme',
    'https://placehold.co:8443/x.png' => 'port',
    'http://placehold.co:22/' => 'port',
    'https://placehold.co:/x.png' => 'port',
    'http://placehold.co@127.0.0.1:8080/x.png' => 'userinfo',
    'https://user:pass@example.com/a' => 'userinfo',
    'https://example.com/a#frag' => 'fragment',
    'https://example.com/a b' => 'characters',
    "https://example.com/a\r\nX-Injected: 1" => 'characters',
    "https://example.com/a\0b" => 'characters',
    'https://example.com/a\\b' => 'characters',
    'https://exa%mple.com/' => 'host',
    'https://' => 'host',
    'https://[::1' => 'host',
    'https://exa_mple.com/' => 'host',
    'https://é.example/' => 'characters',
    'https://a:b:c/' => 'host',
    'https://' . str_repeat('a', 2100) . '.example/' => 'length',
];

foreach ($refused as $text => $reason) {
    check(reasonOf(fn() => ExternalUrl::parse($text)) === $reason, "a URL with the wrong shape is refused: {$reason} ({$text})");
}

check(reasonOf(fn() => ExternalUrl::parse(str_repeat('https://example.com/', 200))) === 'length', 'a URL of more than 2048 characters is refused');
check(strlen('https://example.com/' . str_repeat('a', 2000)) <= 2048 && reasonOf(fn() => ExternalUrl::parse('https://example.com/' . str_repeat('a', 2000))) === null, 'a URL of 2048 characters is accepted');

// Redirects keep the scheme and the port.
$base = ExternalUrl::parse('https://placehold.co/a.png');
check($base->redirectTo('/b.png')->path === '/b.png' && $base->redirectTo('/b.png')->host === 'placehold.co', 'a path redirect stays on the host');
check($base->redirectTo('https://other.example/c.png')->host === 'other.example', 'an absolute redirect to https is followed');
check($base->redirectTo('//other.example/c.png')->scheme === 'https', 'a network-path redirect keeps the scheme');
check(reasonOf(fn() => $base->redirectTo('http://placehold.co/a.png')) === 'scheme', 'a redirect to http from https is refused');
check(reasonOf(fn() => $base->redirectTo('https://placehold.co:8443/a.png')) === 'port', 'a redirect to another port is refused');
check(reasonOf(fn() => $base->redirectTo('file:///etc/passwd')) === 'scheme', 'a redirect to a file is refused');

// --- Literal addresses (section 5.8.2) ---------------------------------------------------------------------------------

$literals = [
    'http://2130706433/x.png' => '127.0.0.1',
    'http://0x7f000001/x.png' => '127.0.0.1',
    'http://0177.0.0.1/x.png' => '127.0.0.1',
    'http://127.1/x.png' => '127.0.0.1',
    'http://0x7f.1/x.png' => '127.0.0.1',
    'http://0/x.png' => '0.0.0.0',
    'http://169.254.169.254/latest/meta-data/' => '169.254.169.254',
    'http://8.8.8.8/x' => '8.8.8.8',
];

foreach ($literals as $text => $address) {
    check(ExternalUrl::parse($text)->literalAddress() === $address, "a literal host reads as {$address} ({$text})");
}

check(ExternalUrl::parse('http://[::1]:80/x')->literalAddress() === '::1', 'an IPv6 literal reads as the address');
check(ExternalUrl::parse('http://[2606:4700::1111]/x')->literalAddress() === '2606:4700::1111', 'a public IPv6 literal reads as the address');
check(ExternalUrl::parse('https://placehold.co/x')->literalAddress() === null, 'a name is not a literal address');
check(reasonOf(fn() => ExternalUrl::parse('http://1.2.3.4.5/x')->literalAddress()) === 'host', 'five numeric parts are not an address');
check(reasonOf(fn() => ExternalUrl::parse('http://256.1.1.1/x')->literalAddress()) === 'host', 'a part above 255 is not an address');
check(reasonOf(fn() => ExternalUrl::parse('http://0189.1.1.1/x')->literalAddress()) === 'host', 'an octal part with an 8 is not an address');

// --- Address classification (section 5.8.2): at least 60 addresses ------------------------------------------------------

$not_public = [
    // IPv4, every range, with its first and last address
    '0.0.0.0', '0.255.255.255', '10.0.0.0', '10.255.255.255', '100.64.0.0', '100.127.255.255',
    '127.0.0.1', '127.255.255.255', '169.254.0.0', '169.254.169.254', '169.254.255.255',
    '172.16.0.0', '172.31.255.255', '192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.0.1', '192.168.255.255',
    '198.18.0.1', '198.19.255.255', '198.51.100.7', '203.0.113.7', '224.0.0.1', '239.255.255.255',
    '240.0.0.1', '255.255.255.255',
    // IPv4 inside IPv6 forms
    '::', '::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:0:127.0.0.1',
    '::127.0.0.1', '64:ff9b::127.0.0.1', '64:ff9b::10.0.0.1', '64:ff9b:1::1',
    // IPv6 ranges
    '100::1', '100::ffff', '2001::1', '2001:db8::1', '2002::1', '2002:c000:0201::1',
    'fc00::1', 'fd12:3456::1', 'fe80::1', 'fec0::1', 'ff02::1', 'ff00::1',
];

$public = [
    '1.1.1.1', '8.8.8.8', '9.9.9.9', '23.0.0.1', '100.63.255.255', '100.128.0.1', '172.15.255.255',
    '172.32.0.1', '192.0.1.1', '192.167.255.255', '192.169.0.1', '198.17.255.255', '198.20.0.1',
    '203.0.114.1', '223.255.255.255', '104.18.0.1',
    '2606:4700::1111', '2001:4860:4860::8888', '2a00:1450:4001::200e', '2620:0:ccc::2',
    '::ffff:8.8.8.8', '64:ff9b::8.8.8.8', '2003::1',
];

foreach ($not_public as $address) {
    check(!AddressClass::isPublic($address), "{$address} is not public");
}

foreach ($public as $address) {
    check(AddressClass::isPublic($address), "{$address} is public");
}

check(!AddressClass::isPublic('not an address'), 'a string that is no address is not public');

// --- The fetcher: fake resolver and transport --------------------------------------------------------------------------

final class FakeResolver implements ExternalFileResolver
{
    /** @var array<string, list<list<string>>> host => the answers of each call, in order (the last one repeats) */
    public array $answers = [];

    /** @var string[] the names looked up, in order */
    public array $looked_up = [];

    public function addresses(string $host): array
    {
        $this->looked_up[] = $host;

        $answers = $this->answers[$host] ?? [];

        if ($answers === []) {
            return [];
        }

        $call = count(array_keys($this->looked_up, $host, true)) - 1;

        return $answers[min($call, count($answers) - 1)];
    }
}

final class FakeTransport implements ExternalFileTransport
{
    /** @var list<array{status: int, location: ?string, body: ?string, error: ?string}> the responses, in order */
    public array $responses = [];

    /** @var list<array{url: string, address: string, max: int}> the requests made */
    public array $requests = [];

    public function request(ExternalUrl $url, string $address, string $target_path, int $max_bytes, int $total_seconds): ExternalFileTransportResult
    {
        $this->requests[] = ['url' => (string)$url, 'address' => $address, 'max' => $max_bytes];

        $response = array_shift($this->responses) ?? ['status' => 500, 'location' => null, 'body' => null, 'error' => null];

        if ($response['error'] !== null) {
            return new ExternalFileTransportResult(0, null, 0, $response['error']);
        }

        $body = (string)$response['body'];

        if (strlen($body) > $max_bytes) {
            return new ExternalFileTransportResult(0, null, $max_bytes, 'size');
        }

        file_put_contents($target_path, $body);

        return new ExternalFileTransportResult($response['status'], $response['location'], strlen($body));
    }
}

final class Fetch
{
    public static function run(FakeResolver $resolver, FakeTransport $transport, string $url, ExternalFileTempArea $temp, int $max = 1000000): ?string
    {
        $fetcher = new ExternalFileFetcher($resolver, $transport, $temp);

        return reasonOf(fn() => $fetcher->download($url, $max, 30));
    }
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

$temp = new ExternalFileTempArea();

$public_host = ['cdn.example' => [['93.184.216.34']]];

// A plain download.
$resolver = new FakeResolver();
$resolver->answers = $public_host;
$transport = new FakeTransport();
$transport->responses = [['status' => 200, 'location' => null, 'body' => $png, 'error' => null]];
$fetcher = new ExternalFileFetcher($resolver, $transport, $temp);
$download = $fetcher->download('https://cdn.example/a.png', 1000000, 30);
check($download->bytes === strlen($png) && is_file($download->path) && $download->final_url === 'https://cdn.example/a.png', 'a public file is downloaded to the temp area');
check($transport->requests[0]['address'] === '93.184.216.34', 'the connection uses the validated address');
@unlink($download->path);

// Redirects to a private address are refused, and the transport is never asked for it.
$resolver = new FakeResolver();
$resolver->answers = ['start.example' => [['93.184.216.34']], 'inner.example' => [['10.0.0.5']]];
$transport = new FakeTransport();
$transport->responses = [['status' => 302, 'location' => 'https://inner.example/x.png', 'body' => null, 'error' => null]];
check(Fetch::run($resolver, $transport, 'https://start.example/a.png', $temp) === 'private_address', 'a redirect to a private address is refused');
check(count($transport->requests) === 1, 'the private address of a redirect is never contacted');

// A redirect loop.
$resolver = new FakeResolver();
$resolver->answers = ['loop.example' => [['93.184.216.34']]];
$transport = new FakeTransport();
$transport->responses = [
    ['status' => 302, 'location' => 'https://loop.example/b', 'body' => null, 'error' => null],
    ['status' => 302, 'location' => 'https://loop.example/a', 'body' => null, 'error' => null],
    ['status' => 302, 'location' => 'https://loop.example/b', 'body' => null, 'error' => null],
];
check(Fetch::run($resolver, $transport, 'https://loop.example/a', $temp) === 'redirects', 'a redirect loop fails');

// Three redirects are followed, four are not.
$chain = static function (int $redirects): array {
    $responses = [];

    for ($i = 1; $i <= $redirects; $i++) {
        $responses[] = ['status' => 301, 'location' => "https://chain.example/{$i}", 'body' => null, 'error' => null];
    }

    $responses[] = ['status' => 200, 'location' => null, 'body' => 'ok', 'error' => null];

    return $responses;
};

$resolver = new FakeResolver();
$resolver->answers = ['chain.example' => [['93.184.216.34']]];
$transport = new FakeTransport();
$transport->responses = $chain(3);
check(Fetch::run($resolver, $transport, 'https://chain.example/start', $temp) === null, 'three redirects are followed');

$transport = new FakeTransport();
$transport->responses = $chain(4);
check(Fetch::run($resolver, $transport, 'https://chain.example/start', $temp) === 'redirects', 'a fourth redirect is refused');

// A scheme change and a port change in a redirect.
$transport = new FakeTransport();
$transport->responses = [['status' => 302, 'location' => 'http://chain.example/x.png', 'body' => null, 'error' => null]];
check(Fetch::run($resolver, $transport, 'https://chain.example/start', $temp) === 'scheme', 'a redirect that changes the scheme is refused');

$transport = new FakeTransport();
$transport->responses = [['status' => 302, 'location' => 'https://chain.example:8443/x.png', 'body' => null, 'error' => null]];
check(Fetch::run($resolver, $transport, 'https://chain.example/start', $temp) === 'port', 'a redirect that changes the port is refused');

// A DNS answer that changes between two lookups: the first validated address is the one connected to, and a second
// lookup (a redirect) that gives a private address is refused.
$resolver = new FakeResolver();
$resolver->answers = ['rebind.example' => [['93.184.216.34'], ['127.0.0.1']]];
$transport = new FakeTransport();
$transport->responses = [['status' => 200, 'location' => null, 'body' => 'x', 'error' => null]];
$fetcher = new ExternalFileFetcher($resolver, $transport, $temp);
$download = $fetcher->download('https://rebind.example/a.png', 1000, 30);
check($transport->requests[0]['address'] === '93.184.216.34', 'a second DNS answer does not change the pinned address');
@unlink($download->path);

$resolver = new FakeResolver();
$resolver->answers = ['rebind.example' => [['93.184.216.34'], ['127.0.0.1']]];
$transport = new FakeTransport();
$transport->responses = [
    ['status' => 302, 'location' => 'https://rebind.example/b.png', 'body' => null, 'error' => null],
    ['status' => 200, 'location' => null, 'body' => 'x', 'error' => null],
];
check(Fetch::run($resolver, $transport, 'https://rebind.example/a.png', $temp) === 'private_address', 'a name that answers privately on a later lookup is refused');
check(count($transport->requests) === 1, 'the second answer is validated before any connection');

// Mixed answers: one private address refuses the whole name. No answer is a DNS failure.
$resolver = new FakeResolver();
$resolver->answers = ['mixed.example' => [['93.184.216.34', '192.168.1.1']], 'nothing.example' => [[]]];
$transport = new FakeTransport();
check(Fetch::run($resolver, $transport, 'https://mixed.example/a.png', $temp) === 'private_address', 'one private address among the answers refuses the name');
check(Fetch::run($resolver, $transport, 'https://nothing.example/a.png', $temp) === 'dns', 'a name with no answer fails with dns');
check(Fetch::run($resolver, $transport, 'https://localhost/a.png', $temp) === 'private_address', 'localhost is refused without a lookup');
check(Fetch::run($resolver, $transport, 'https://dev.localhost/a.png', $temp) === 'private_address', 'a name under .localhost is refused without a lookup');
check(Fetch::run($resolver, $transport, 'http://169.254.169.254/latest/meta-data/', $temp) === 'private_address', 'the metadata address is refused without a lookup');
check(Fetch::run($resolver, $transport, 'http://2130706433/x.png', $temp) === 'private_address', 'a decimal loopback literal is refused');
check(Fetch::run($resolver, $transport, 'http://[::1]/x.png', $temp) === 'private_address', 'an IPv6 loopback literal is refused');
check(count($transport->requests) === 0, 'no refused address is ever contacted');

// Failures of the transport and of the status.
$resolver = new FakeResolver();
$resolver->answers = $public_host;
$cases = [
    'a size limit stops the download' => [['status' => 200, 'location' => null, 'body' => str_repeat('a', 2000), 'error' => null], 'size', 1000],
    'a time limit stops the download' => [['status' => 0, 'location' => null, 'body' => null, 'error' => 'time'], 'time', 1000000],
    'a TLS failure is refused' => [['status' => 0, 'location' => null, 'body' => null, 'error' => 'tls'], 'tls', 1000000],
    'a 404 is refused' => [['status' => 404, 'location' => null, 'body' => 'nope', 'error' => null], 'status', 1000000],
    'a 500 is refused' => [['status' => 500, 'location' => null, 'body' => 'boom', 'error' => null], 'status', 1000000],
    'an empty body is refused' => [['status' => 200, 'location' => null, 'body' => '', 'error' => null], 'content', 1000000],
    'a redirect without a location is refused' => [['status' => 302, 'location' => null, 'body' => null, 'error' => null], 'status', 1000000],
];

foreach ($cases as $name => [$response, $reason, $max]) {
    $transport = new FakeTransport();
    $transport->responses = [$response];
    check(Fetch::run($resolver, $transport, 'https://cdn.example/a.png', $temp, $max) === $reason, $name);
}

// Cleanup: a failed download leaves no file behind.
$transport = new FakeTransport();
$transport->responses = [['status' => 404, 'location' => null, 'body' => 'nope', 'error' => null]];
$fetcher = new ExternalFileFetcher($resolver, $transport, $temp);
$before = $temp->directory() === null ? [] : glob($temp->directory() . '/*');
reasonOf(fn() => $fetcher->download('https://cdn.example/a.png', 1000, 30));
$after = $temp->directory() === null ? [] : glob($temp->directory() . '/*');
check(count($after) === count($before), 'a failed download removes its temporary file');

// --- Acceptance of a downloaded file (section 5.8.7) ---------------------------------------------------------------------

$types = [
    ['file_extension' => 'png', 'mime_type' => 'image/png', 'maximum_size' => 10240],
    ['file_extension' => 'zip', 'mime_type' => 'application/zip', 'maximum_size' => 0],
];

$accept = static function (string $content, string $url, int $field_max = 0) use ($types, $temp): ?string {
    $path = $temp->newFile('.part');
    file_put_contents($path, $content);

    return reasonOf(function () use ($content, $url, $field_max, $types, $path) {
        (new ExternalFileAcceptance($types))->accept(new ExternalFileDownload($path, strlen($content), $url), ExternalUrl::parse($url), $field_max);
    });
};

check($accept($png, 'https://placehold.co/600x450.png?text=Gallery') === null, 'a real PNG passes the acceptance checks');
check($accept($png, 'https://placehold.co/600x450') === null, 'a URL without an extension takes the extension of the detected type');
check($accept('hello, this is text', 'https://placehold.co/x.png') === 'type', 'a text file named .png is refused by its detected type');
check($accept($png, 'https://placehold.co/600x450?text=Gallery%20Entry') === null, 'the query is not part of the file name');
check($accept('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'https://placehold.co/600x450.svg') === 'type', 'an SVG file is refused: no SVG type is allowed');
check($accept('MZ' . str_repeat("\0", 60), 'https://github.example/tool.exe') === 'type', 'an executable is refused: no type allows it');
check($accept($png, 'https://placehold.co/600x450.png', 10) === 'size', 'a file over the field maximum is refused');
check($accept(str_repeat('a', 11 * 1024), 'https://placehold.co/x.png', 0) === 'type', 'a file of a type that is not PNG is refused before its size');
check($accept("PK\x05\x06" . str_repeat("\0", 18), 'https://github.example/x.zip') === null, 'an empty zip archive passes when the board allows zip');
check($accept("\x89PNG\r\n\x1a\nnot really", 'https://placehold.co/x.png') === 'type', 'a file with a PNG signature that is not a PNG is refused by its detected type');
check($accept($png, 'https://placehold.co/x.jpg') === 'type', 'a PNG named .jpg is refused: the board allows no jpg type');
check(ExternalFileAcceptance::nameOf(ExternalUrl::parse('https://x.example/a/..%2Fb%20c.PNG?q=1')) === '_b_c.PNG', 'a path separator and a space become underscores, and leading dots go');
check(ExternalFileAcceptance::nameOf(ExternalUrl::parse('https://x.example/a/%20%20b%20c.png')) === '_b_c.png', 'the name is the decoded last segment, sanitised');
check(ExternalFileAcceptance::nameOf(ExternalUrl::parse('https://x.example/')) === 'download', 'an empty name is download');
check(ExternalFileAcceptance::nameOf(ExternalUrl::parse('https://x.example/.hidden')) === 'hidden', 'a leading dot is removed from the name');

// --- The import budget (section 5.8.5) -----------------------------------------------------------------------------------

$budget = new MyShowcase\Service\ExternalFileBudget(60, 1000.0);
check($budget->secondsLeft(1000.0) === 30, 'the time left of a file is at most its 30 seconds');
check($budget->secondsLeft(1050.0) === 10, 'the time left shrinks as the import runs');
check(reasonOf(fn() => $budget->secondsLeft(1061.0)) === 'time', 'a spent time budget refuses the next file');
check(MyShowcase\Service\ExternalFileBudget::fits(25, 60) && !MyShowcase\Service\ExternalFileBudget::fits(26, 60), 'a file count of 25 fits and 26 does not');
check(!MyShowcase\Service\ExternalFileBudget::fits(1, 4), 'a time budget below 5 seconds does not fit');

$count = new MyShowcase\Service\ExternalFileBudget(60, 1000.0);
for ($i = 0; $i < 25; $i++) {
    $count->reserveFile();
}
check(reasonOf(fn() => $count->reserveFile()) === 'budget', 'the 26th file of an import is refused');

$data = new MyShowcase\Service\ExternalFileBudget(60, 1000.0);
$data->addBytes(ExternalFileLimits::BUDGET_BYTES - 10);
check(reasonOf(fn() => $data->addBytes(11)) === 'budget', 'a download over the 100 MB budget is refused');
check($data->bytes() === ExternalFileLimits::BUDGET_BYTES - 10, 'a refused download is not counted');

// --- Limits and the time budget ------------------------------------------------------------------------------------------

check(ExternalFileLimits::MAX_FILES === 25 && ExternalFileLimits::BUDGET_BYTES === 100 * 1024 * 1024 && ExternalFileLimits::CEILING_BYTES === 50 * 1024 * 1024, 'the documented limits are 25 files, 100 MB and 50 MB');
check(ExternalFileLimits::MAX_REDIRECTS === 3 && ExternalFileLimits::CONNECT_TIMEOUT === 5 && ExternalFileLimits::FILE_TIMEOUT === 30, 'the documented time limits and redirects');
check(str_starts_with(ExternalFileLimits::userAgent(), 'MyShowcase-Import/'), 'the user agent names the plugin');

// Cleanup of the temporary area removes every file and its folder.
$area = new ExternalFileTempArea();
$one = $area->newFile('.part');
file_put_contents($one, 'x');
$folder = $area->directory();
$area->cleanup();
check(!is_file($one) && ($folder === null || !is_dir($folder)), 'cleanup removes every temporary file and folder');
$area->cleanup();
check(true, 'cleanup can be called twice');

$temp->cleanup();

echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
