<?php

declare(strict_types=1);

namespace YapReporting;

use YapReporting\Auth\ClientStore;
use YapReporting\Auth\TokenAuthenticator;
use YapReporting\Controllers\ReportController;
use YapReporting\Database\Connection;
use YapReporting\Http\HttpException;
use YapReporting\Http\Request;
use YapReporting\Http\Response;
use YapReporting\Http\Router;
use YapReporting\Privacy\Pseudonymizer;
use YapReporting\Privacy\VolunteerDirectory;
use YapReporting\Reporting\Capabilities;
use YapReporting\Reporting\DatasetLoader;
use YapReporting\Security\RateLimiter;
use YapReporting\Support\FileCache;
use YapReporting\Support\Logger;
use YapReporting\Yap\EventCatalog;
use YapReporting\Yap\SchemaInspector;
use YapReporting\Yap\YapConfigLoader;

final class App
{
    public const VERSION = '1.7.0';
    public const API_VERSION = 'v1';

    private ?Connection $db;
    private ?ClientStore $clients = null;
    private ?RateLimiter $limiter = null;
    private ?Logger $logger = null;
    private ?SchemaInspector $schema = null;
    private ?EventCatalog $catalog = null;
    private ?Capabilities $caps = null;
    private ?Pseudonymizer $pseudo = null;
    private ?VolunteerDirectory $directory = null;
    private ?DatasetLoader $loader = null;
    private ?FileCache $cache = null;

    /** Services may be injected for testing. */
    public function __construct(private readonly Config $config, ?Connection $db = null, ?EventCatalog $catalog = null)
    {
        $this->db = $db;
        $this->catalog = $catalog;
    }

    public function handle(Request $request): Response
    {
        try {
            if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                throw new HttpException(405, 'method_not_allowed', 'This API is read-only; only GET is supported.', ['Allow' => 'GET, HEAD']);
            }
            if ((bool) $this->config->get('require_https', true) && !$request->secure
                && !((bool) $this->config->get('allow_http_from_loopback', false) && $request->isLoopback())) {
                throw new HttpException(403, 'https_required', 'HTTPS is required.');
            }
            foreach (['token', 'access_token', 'api_key', 'apikey', 'key', 'secret', 'auth'] as $forbidden) {
                if (array_key_exists($forbidden, $request->query)) {
                    throw new HttpException(400, 'credentials_in_query', 'Credentials must be sent in the Authorization header, never in the URL.');
                }
            }

            [$route, $params] = $this->router()->match($request->path);
            if ($route === null) {
                throw new HttpException(404, 'not_found', 'Unknown endpoint.');
            }
            if ($route['public']) {
                return ($route['handler'])($request, null, $params);
            }

            if (!$this->clients()->isInitialised()) {
                throw new HttpException(503, 'not_initialised', 'The reporting service has not been initialised.');
            }
            $client = (new TokenAuthenticator(
                $this->clients(),
                $this->limiter(),
                $this->logger(),
                $this->config->int('rate_limit.auth_failures_per_15_minutes', 20),
            ))->authenticate($request);

            $this->limiter()->hit('client:' . $client->id, $this->config->int('rate_limit.requests_per_minute', 120), 60);

            $response = ($route['handler'])($request, $client, $params);
            $this->logger()->info('request', [
                'client_id' => $client->id,
                'path' => $request->path,
                'status' => $response->status,
                'request_id' => $request->requestId,
            ]);
            return $response;
        } catch (HttpException $e) {
            return Response::json([
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'request_id' => $request->requestId],
            ], $e->status, $e->headers);
        } catch (\Throwable $e) {
            $this->logger()->error('unhandled_exception', [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
                'request_id' => $request->requestId,
            ]);
            $body = ['error' => ['code' => 'internal_error', 'message' => 'An internal error occurred.', 'request_id' => $request->requestId]];
            return Response::json($body, 500);
        }
    }

    private function router(): Router
    {
        $r = new Router();
        $c = new ReportController($this);
        $r->get('/api/v1/health', [$c, 'health'], true);
        $r->get('/api/v1/capabilities', [$c, 'capabilities']);
        $r->get('/api/v1/areas', [$c, 'areas']);
        $r->get('/api/v1/summary', [$c, 'summary']);
        $r->get('/api/v1/trends', [$c, 'trends']);
        $r->get('/api/v1/distributions', [$c, 'distributions']);
        $r->get('/api/v1/area-dashboard', [$c, 'areaDashboard']);
        $r->get('/api/v1/meeting-lookups', [$c, 'meetingLookups']);
        $r->get('/api/v1/meditations', [$c, 'meditations']);
        $r->get('/api/v1/sms-summary', [$c, 'smsSummary']);
        $r->get('/api/v1/calls', [$c, 'calls']);
        $r->get('/api/v1/calls/{id}', [$c, 'callDetail']);
        $r->get('/api/v1/volunteers', [$c, 'volunteers']);
        $r->get('/api/v1/volunteer-summary', [$c, 'volunteerSummary']);
        return $r;
    }

    // ---- Service accessors (lazy) ---------------------------------------------------------

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Connection
    {
        if ($this->db === null) {
            $creds = (new YapConfigLoader($this->config))->databaseCredentials();
            $this->db = new Connection($creds, $this->config);
        }
        return $this->db;
    }

    public function clients(): ClientStore
    {
        return $this->clients ??= new ClientStore($this->config->dataDir());
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->config->dataDir() . '/ratelimit');
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->config->dataDir() . '/logs');
    }

    public function schema(): SchemaInspector
    {
        return $this->schema ??= new SchemaInspector($this->db());
    }

    public function catalog(): EventCatalog
    {
        return $this->catalog ??= EventCatalog::fromConfig($this->config);
    }

    public function capabilities(): Capabilities
    {
        return $this->caps ??= new Capabilities($this->schema(), $this->catalog());
    }

    public function pseudonymizer(): Pseudonymizer
    {
        // Domain-separated key derived from the server secret.
        return $this->pseudo ??= new Pseudonymizer(hash_hmac('sha256', 'yapr-pseudonyms-v1', $this->clients()->pepper(), true));
    }

    public function volunteerDirectory(): VolunteerDirectory
    {
        return $this->directory ??= new VolunteerDirectory($this->db(), $this->schema());
    }

    public function loader(): DatasetLoader
    {
        return $this->loader ??= new DatasetLoader($this->db(), $this->schema(), $this->catalog(), $this->config);
    }

    public function cache(): FileCache
    {
        return $this->cache ??= new FileCache($this->config->dataDir() . '/cache', $this->config->int('cache_ttl_seconds', 60));
    }
}
