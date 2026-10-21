<?php

declare(strict_types=1);

/** The webservice's front controller under PHP's built-in web server, configured through YAPR_CONFIG. */
final class HttpServer
{
    /** @var resource */
    private $process;

    public readonly string $base;

    /** @param array<string, mixed> $config reporting-config.php values */
    public function __construct(array $config, string $configDir)
    {
        $file = $configDir . '/reporting-config.php';
        file_put_contents($file, '<?php return ' . var_export($config, true) . ';');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $env = ['YAPR_CONFIG' => $file, 'PATH' => (string) getenv('PATH')];
        $this->process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        $this->base = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 100; $i++) {
            if (@fsockopen('127.0.0.1', $port) !== false) {
                return;
            }
            usleep(50000);
        }
        throw new RuntimeException('The built-in web server did not start.');
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed}
     */
    public function request(string $method, string $path, array $headers = []): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'ignore_errors' => true, 'timeout' => 30]]);
        $body = (string) @file_get_contents($this->base . $path, false, $ctx);
        $status = 0;
        $out = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $out, 'body' => $body, 'json' => json_decode($body, true)];
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
