<?php

declare(strict_types=1);

namespace YapReporting\Setup;

use YapReporting\App;
use YapReporting\Auth\Client;
use YapReporting\Auth\ClientStore;
use YapReporting\Config;
use YapReporting\Http\Request;
use YapReporting\Security\IpMatcher;
use YapReporting\Security\RateLimiter;
use YapReporting\Support\FileCache;
use YapReporting\Yap\EventCatalog;
use YapReporting\Yap\YapConfigLoader;

/**
 * Browser-based replacement for bin/yapr, for hosts that only offer SFTP + HTTPS.
 *
 * Locked by default. To unlock, the operator creates config/ENABLE_SETUP over SFTP. The console then
 * writes a random one-time code to config/setup-code.txt; the operator downloads that file over SFTP
 * and pastes the code into the page. Proving SFTP access is the authentication. The code expires after
 * an hour, attempts are rate-limited, and "Finish and lock" deletes both files.
 */
final class SetupConsole
{
    private const COOKIE = 'yapr_setup';
    private const SESSION_TTL = 1800;
    private const CODE_TTL = 3600;

    private string $configDir;
    private string $flagFile;
    private string $codeFile;
    private ?string $code = null;
    private string $csrf = '';

    public function __construct(private readonly Config $config)
    {
        $this->configDir = $config->baseDir() . '/config';
        $this->flagFile = $this->configDir . '/ENABLE_SETUP';
        $this->codeFile = $this->configDir . '/setup-code.txt';
    }

    public function handle(): void
    {
        $this->headers();
        if (!is_file($this->flagFile) && is_file($this->flagFile . '.txt')) {
            $this->flagFile .= '.txt';
        }
        if (!is_file($this->flagFile)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":{"code":"not_found","message":"Unknown endpoint."}}';
            return;
        }
        $request = Request::fromGlobals($this->config);
        if (!$request->secure && !((bool) $this->config->get('allow_http_from_loopback', false) && $request->isLoopback())) {
            $this->page('HTTPS required', '<p>Open this page with <strong>https://</strong>.</p>');
            return;
        }

        $this->code = $this->ensureCode();
        if ($this->code === null) {
            $this->page('Setup cannot start', '<p>PHP could not write <code>config/setup-code.txt</code>. Using your SFTP program, give the <code>config</code> folder write permission (755 or 775) and reload.</p>');
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $action = $method === 'POST' ? (string) ($_POST['action'] ?? '') : '';

        if ($action === 'login') {
            $this->login($request);
            return;
        }
        $expiry = $this->sessionExpiry();
        if ($expiry === null) {
            $this->loginPage(null);
            return;
        }
        $this->csrf = hash_hmac('sha256', 'csrf|' . $expiry, $this->code);

        if ($method === 'POST') {
            if (!hash_equals($this->csrf, (string) ($_POST['csrf'] ?? ''))) {
                $this->page('Session expired', '<p>Reload the page and try again.</p>');
                return;
            }
            $this->dispatch($action);
            return;
        }
        $this->dashboard();
    }

    // ---------------------------------------------------------------- authentication

    private function ensureCode(): ?string
    {
        if (is_file($this->codeFile) && time() - (int) filemtime($this->codeFile) < self::CODE_TTL) {
            $existing = trim((string) @file_get_contents($this->codeFile));
            if (preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){5}$/', $existing) === 1) {
                return $existing;
            }
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 6; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        $code = implode('-', $groups);
        $old = umask(0077);
        $ok = @file_put_contents($this->codeFile, $code . "\n", LOCK_EX);
        umask($old);
        if ($ok === false) {
            return null;
        }
        @chmod($this->codeFile, 0600);
        return $code;
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter(sys_get_temp_dir() . '/yapr-setup-' . substr(hash('sha256', $this->config->baseDir()), 0, 12));
    }

    private function login(Request $request): void
    {
        $limiter = $this->limiter();
        $key = 'setup:' . $request->clientIp;
        if ($limiter->exceeded($key, 5, 900) || $limiter->exceeded('setup:all', 20, 900)) {
            $this->loginPage('Too many attempts. Wait 15 minutes and try again.');
            return;
        }
        $given = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['code'] ?? '')) ?? '');
        $expected = str_replace('-', '', (string) $this->code);
        if ($given === '' || !hash_equals($expected, $given)) {
            $limiter->increment($key, 900);
            $count = $limiter->increment('setup:all', 900);
            if ($count >= 20) {
                @unlink($this->codeFile); // Force a fresh code after many failures.
            }
            $this->loginPage('That code is not correct.');
            return;
        }
        $expiry = time() + self::SESSION_TTL;
        $value = $expiry . '.' . hash_hmac('sha256', 'session|' . $expiry, (string) $this->code);
        setcookie(self::COOKIE, $value, [
            'expires' => $expiry,
            'path' => $this->cookiePath(),
            'secure' => $request->secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        header('Location: ' . $this->selfUrl(), true, 303);
    }

    private function sessionExpiry(): ?int
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $raw, $m) !== 1) {
            return null;
        }
        $expiry = (int) $m[1];
        if ($expiry < time() || !hash_equals(hash_hmac('sha256', 'session|' . $expiry, (string) $this->code), $m[2])) {
            return null;
        }
        return $expiry;
    }

    private function loginPage(?string $error): void
    {
        $html = '<p>This page sets up the reporting service without a command line.</p>'
            . '<ol><li>In your SFTP program, open the <code>reporting-api/config</code> folder.</li>'
            . '<li>Download <code>setup-code.txt</code> and open it in a text editor.</li>'
            . '<li>Type or paste the code below. It expires one hour after it was created.</li></ol>'
            . ($error !== null ? '<p class="bad">' . $this->e($error) . '</p>' : '')
            . '<form method="post" action="' . $this->e($this->selfUrl()) . '">'
            . '<input type="hidden" name="action" value="login">'
            . '<p><label for="code">Setup code</label><br><input id="code" name="code" autocomplete="one-time-code" size="34" required autofocus></p>'
            . '<p><button type="submit">Continue</button></p></form>';
        $this->page('Reporting service setup', $html);
    }

    // ---------------------------------------------------------------- actions

    private function dispatch(string $action): void
    {
        $store = new ClientStore($this->config->dataDir());
        try {
            switch ($action) {
                case 'init':
                    $store->initialise();
                    $this->dashboard('Initialised. The secret key and client list were created in the data folder.');
                    return;
                case 'create':
                    $this->createClient($store);
                    return;
                case 'rotate':
                    $id = $this->clientId();
                    $grace = max(0, min(168, (int) ($_POST['grace'] ?? 24)));
                    $token = $store->rotate($id, $grace * 3600);
                    $this->tokenPage($id, $token, $grace > 0 ? 'The old credential keeps working for ' . $grace . ' hour(s).' : 'The old credential stopped working immediately.');
                    return;
                case 'revoke':
                    $store->revoke($this->clientId());
                    $this->dashboard('Credential revoked. It stopped working immediately.');
                    return;
                case 'update':
                    $id = $this->clientId();
                    [$all, $areas] = $this->areasInput();
                    $store->update($id, ['all_service_bodies' => $all, 'service_body_ids' => $areas, 'scopes' => $this->scopesInput(), 'allow_unassigned' => !empty($_POST['allow_unassigned'])]);
                    (new FileCache($this->config->dataDir() . '/cache', 1))->clear();
                    $this->dashboard('Permissions saved. In WordPress, click Test connection on the Diagnostics page to refresh what it shows.');
                    return;
                case 'clear_cache':
                    $n = (new FileCache($this->config->dataDir() . '/cache', 1))->clear();
                    $this->dashboard('Removed ' . $n . ' cached report(s).');
                    return;
                case 'tests':
                    $this->testsPage();
                    return;
                case 'finish':
                    $this->finish();
                    return;
            }
        } catch (\InvalidArgumentException $e) {
            $this->dashboard(null, $e->getMessage());
            return;
        } catch (\Throwable $e) {
            $this->dashboard(null, 'That did not work: ' . get_class($e) . ' — ' . \YapReporting\Support\Logger::scrub($e->getMessage()));
            return;
        }
        $this->dashboard();
    }

    private function createClient(ClientStore $store): void
    {
        if (!$store->isInitialised()) {
            throw new \InvalidArgumentException('Initialise first.');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            throw new \InvalidArgumentException('Give the credential a name (for example, the website it is for).');
        }
        [$all, $areas] = $this->areasInput();
        $ips = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['ip'] ?? '')))));
        foreach ($ips as $ip) {
            if (!IpMatcher::isValidRule($ip)) {
                throw new \InvalidArgumentException('Not a valid IP address or range: ' . $ip);
            }
        }
        [$id, $token] = $store->create($name, $all, $areas, !empty($_POST['allow_unassigned']), $this->scopesInput(), $ips);
        $this->tokenPage($id, $token, 'Paste it into WordPress: YAP Reporting → Settings → API credential.');
    }

    /** @return array{0: bool, 1: int[]} */
    private function areasInput(): array
    {
        $all = !empty($_POST['all']);
        $raw = trim((string) ($_POST['areas'] ?? ''));
        if ($all) {
            return [true, []];
        }
        if (preg_match('/^\d+(\s*,\s*\d+)*$/', $raw) !== 1) {
            throw new \InvalidArgumentException('Enter service body ids separated by commas (for example 9, 13), or tick "All service bodies".');
        }
        return [false, array_values(array_unique(array_map('intval', explode(',', $raw))))];
    }

    /** @return string[] */
    private function scopesInput(): array
    {
        $scopes = array_values(array_intersect(Client::ALL_SCOPES, (array) ($_POST['scopes'] ?? [])));
        return $scopes === [] ? [Client::SCOPE_SUMMARY] : $scopes;
    }

    private function clientId(): string
    {
        $id = (string) ($_POST['client'] ?? '');
        if (preg_match('/^[0-9a-f]{12}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Unknown credential.');
        }
        return $id;
    }

    private function finish(): void
    {
        $removedFlag = @unlink($this->flagFile);
        @unlink($this->codeFile);
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => $this->cookiePath(), 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        $this->page('Setup locked', $removedFlag
            ? '<p class="good">Setup is locked. This page now returns "not found".</p><p>To use it again later, create <code>config/ENABLE_SETUP</code> over SFTP.</p>'
            : '<p class="bad">PHP could not delete <code>config/ENABLE_SETUP</code>. Delete it with your SFTP program now; until you do, the setup page stays available to anyone who can read <code>setup-code.txt</code>.</p>');
    }

    // ---------------------------------------------------------------- pages

    private function dashboard(?string $notice = null, ?string $error = null): void
    {
        $store = new ClientStore($this->config->dataDir());
        $h = '';
        if ($notice !== null) {
            $h .= '<p class="good">' . $this->e($notice) . '</p>';
        }
        if ($error !== null) {
            $h .= '<p class="bad">' . $this->e($error) . '</p>';
        }

        $h .= '<h2>1. Check the installation</h2>' . $this->checks($store);

        $h .= $this->volunteerCheck();

        $h .= '<h2>2. Initialise</h2>';
        if ($store->isInitialised()) {
            $h .= '<p class="good">Done. The data folder has its secret key and credential list.</p>';
        } else {
            $writable = is_dir($this->config->dataDir()) ? is_writable($this->config->dataDir()) : is_writable(dirname($this->config->dataDir()));
            $h .= $writable ? '' : '<p class="bad">The <code>data</code> folder is not writable. Set it to 755 or 775 in your SFTP program.</p>';
            $h .= $this->form('init', '<button type="submit">Initialise</button>');
        }

        $h .= '<h2>3. Credentials for WordPress sites</h2>';
        if ($store->isInitialised()) {
            $h .= $this->clientTable($store) . $this->createForm();
        } else {
            $h .= '<p>Initialise first.</p>';
        }

        $h .= '<h2>4. Self-test (optional)</h2><p>Runs the automated tests with made-up data in a temporary database. Your YAP data is not touched.</p>'
            . $this->form('tests', '<button type="submit">Run self-test</button>');

        $h .= '<h2>5. Finish</h2><p>Locks this page by deleting <code>config/ENABLE_SETUP</code> and the setup code.</p>'
            . $this->form('finish', '<button type="submit" class="primary">Finish and lock setup</button>')
            . $this->form('clear_cache', '<button type="submit">Clear report cache</button>');

        $this->page('Reporting service setup', $h);
    }

    private function checks(ClientStore $store): string
    {
        $rows = [];
        $ok = static fn (bool $b): string => $b ? '<span class="good">OK</span>' : '<span class="bad">Problem</span>';
        // This code needs PHP 8.1 to run at all, so reaching this line proves the version.
        $rows[] = ['PHP version', $ok(true) . ' ' . $this->e(PHP_VERSION)];
        foreach (['pdo_mysql', 'json', 'tokenizer'] as $ext) {
            $rows[] = ['PHP extension ' . $ext, $ok(extension_loaded($ext))];
        }
        $rows[] = ['YAP folder', $this->e($this->config->yapRoot())];

        $creds = null;
        try {
            $creds = (new YapConfigLoader($this->config))->databaseCredentials();
            $rows[] = ['YAP database settings', $ok(true) . ' found in ' . $this->e($creds['source']) . ', database <code>' . $this->e($creds['database']) . '</code> on <code>' . $this->e($creds['host']) . '</code>'];
        } catch (\Throwable $e) {
            $rows[] = ['YAP database settings', $ok(false) . ' ' . $this->e($e->getMessage())];
        }

        $eventFile = EventCatalog::locate($this->config);
        $catalog = EventCatalog::fromConfig($this->config);
        $rows[] = ['YAP event list', $ok(!$catalog->isEmpty()) . ' ' . ($eventFile !== null ? $this->e(basename(dirname($eventFile)) . '/' . basename($eventFile)) . ' — ' . count($catalog->all()) . ' events' : 'app/Constants/EventId.php not found')];

        $matrix = '';
        $areas = '';
        if ($creds !== null && !$catalog->isEmpty()) {
            try {
                $app = new App($this->config);
                $pdo = $app->db()->pdo();
                $probe = static function (\PDO $pdo, string $sql): string {
                    try {
                        return (string) $pdo->query($sql)->fetchColumn();
                    } catch (\Throwable) {
                        return '?';
                    }
                };
                $tz = $probe($pdo, 'SELECT @@session.time_zone');
                $ro = $probe($pdo, 'SELECT @@session.transaction_read_only');
                if ($ro === '?') {
                    $ro = $probe($pdo, 'SELECT @@session.tx_read_only');
                }
                $rows[] = ['Database connection', $ok(true) . ' connected; read-only session: ' . ($ro === '1' ? 'yes' : ($ro === '?' ? 'unknown' : 'NO')) . '; time zone: ' . $this->e($tz)];
                foreach ($app->schema()->describe() as $table => $info) {
                    $rows[] = ['Table ' . $table, $info['present'] ? $ok(true) . ' ' . $this->e(implode(', ', $info['columns'])) : '<span class="muted">not present</span>'];
                }
                $matrix = '<details><summary>Statistics this YAP version supports</summary><table>';
                foreach ($app->capabilities()->matrix() as $metric => $state) {
                    $matrix .= '<tr><th>' . $this->e(str_replace('_', ' ', $metric)) . '</th><td>' . ($state['available'] ? $ok(true) : '<span class="bad">Unavailable</span> ' . $this->e((string) $state['reason'])) . '</td></tr>';
                }
                $matrix .= '</table></details>';
                $areas = $this->activeServiceBodies($app);
            } catch (\Throwable $e) {
                $rows[] = ['Database connection', $ok(false) . ' ' . $this->e(\YapReporting\Support\Logger::scrub($e->getMessage()))];
            }
        }

        $rows[] = ['HTTPS required for the API', $ok((bool) $this->config->get('require_https', true))];
        $rows[] = ['Data folder', $this->e($this->config->dataDir()) . ' — ' . (is_dir($this->config->dataDir()) && is_writable($this->config->dataDir()) ? $ok(true) . ' writable' : '<span class="bad">not writable yet</span>')];
        $rows[] = ['Initialised', $ok($store->isInitialised())];

        $t = '<table>';
        foreach ($rows as [$k, $v]) {
            $t .= '<tr><th>' . $this->e($k) . '</th><td>' . $v . '</td></tr>';
        }
        return $t . '</table>' . $matrix . $areas;
    }

    /**
     * Shows how YAP stores volunteer lists (JSON key names and counts only) and how many recently dialed
     * numbers have a name. Unmatched numbers are shown as their last four digits so they can be found in YAP.
     */
    private function volunteerCheck(): string
    {
        try {
            $app = new App($this->config);
            $dir = $app->volunteerDirectory();
            if (!$dir->available()) {
                return '';
            }
            $rows = $dir->describe();
            $known = $dir->load();
            $dialedId = $app->catalog()->id('VOLUNTEER_DIALED');
            $dialed = [];
            if ($dialedId !== null) {
                foreach ($app->db()->select('SELECT meta FROM records_events WHERE event_id = ? AND meta IS NOT NULL ORDER BY id DESC LIMIT 5000', [$dialedId]) as $r) {
                    $n = \YapReporting\Reporting\DatasetLoader::volunteerFromMeta((string) $r['meta'], (array) $this->config->get('yap_schema.volunteer_meta_keys', []));
                    if ($n !== null) {
                        $dialed[$n] = ($dialed[$n] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable $e) {
            return '<details><summary>Volunteer names check</summary><p class="bad">' . $this->e(\YapReporting\Support\Logger::scrub($e->getMessage())) . '</p></details>';
        }
        $matched = 0;
        $missing = [];
        foreach ($dialed as $n => $count) {
            if (isset($known[(string) $n])) {
                $matched++;
            } else {
                $missing['…' . substr((string) $n, -4)] = $count;
            }
        }
        arsort($missing);
        $h = '<details' . ($missing ? ' open' : '') . '><summary>Volunteer names check</summary>';
        $h .= '<p>' . count($known) . ' volunteer numbers with names found in YAP\'s volunteer lists. Of the '
            . count($dialed) . ' numbers YAP dialed in its last 5,000 dial events, <strong>' . $matched . '</strong> have a name'
            . ($missing ? ' and <strong class="bad">' . count($missing) . '</strong> do not.' : '.') . '</p>';
        if ($missing) {
            $h .= '<p>Numbers without a name (last four digits, times dialed). Look these up in YAP → Volunteers / Groups:</p><p>';
            foreach (array_slice($missing, 0, 40, true) as $hint => $count) {
                $h .= '<code>' . $this->e((string) $hint) . '</code> ×' . (int) $count . ' &nbsp; ';
            }
            $h .= '</p>';
        }
        $h .= '<p>How the volunteer lists are stored (key names and counts only, no personal data). If names are missing, copy this table into a message to your developer:</p><table><tr><th>config id</th><td>type · service body · parent · status · entries / with name · JSON keys</td></tr>';
        foreach ($rows as $r) {
            $h .= '<tr><th>' . (int) $r['id'] . '</th><td>' . $this->e($r['data_type']) . ' · sb ' . $this->e((string) $r['service_body_id']) . ' · parent ' . $this->e((string) $r['parent_id']) . ' · status ' . $this->e((string) $r['status'])
                . ' · ' . ($r['valid_json'] ? (int) $r['volunteer_entries'] . ' / ' . (int) $r['entries_with_name'] : '<span class="bad">not valid JSON</span>')
                . '<br><small>' . $this->e(implode(', ', $r['json_keys'])) . '</small></td></tr>';
        }
        return $h . '</table></details>';
    }

    /** Service body ids seen in YAP's call records (uses the indexed service_body_id column). */
    private function activeServiceBodies(App $app): string
    {
        $rows = $app->db()->selectAll(
            'SELECT service_body_id AS sb, COUNT(DISTINCT callsid) AS calls, MAX(event_time) AS last_seen FROM records_events GROUP BY service_body_id ORDER BY calls DESC'
        );
        if ($rows === []) {
            return '<p>No call events recorded yet.</p>';
        }
        $h = '<details open><summary>Service bodies found in YAP\'s call records</summary><p>Use these ids when creating a credential. Names live in BMLT; 0 means "unassigned" (usually meeting searches).</p><table><tr><th>Service body id</th><th>Calls / messages</th><th>Last activity (UTC)</th></tr>';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . $this->e((string) ($r['sb'] ?? 'none')) . '</td><td>' . (int) $r['calls'] . '</td><td>' . $this->e((string) $r['last_seen']) . '</td></tr>';
        }
        return $h . '</table></details>';
    }

    private function clientTable(ClientStore $store): string
    {
        $clients = $store->all();
        if ($clients === []) {
            return '<p>No credentials yet.</p>';
        }
        $h = '<table><tr><th>Name</th><th>Status</th><th>Service bodies</th><th>Permissions</th><th></th></tr>';
        foreach ($clients as $id => $r) {
            $active = empty($r['revoked_at']);
            $h .= '<tr><td>' . $this->e((string) $r['name']) . '<br><small class="muted">' . $this->e((string) $id) . '</small></td>'
                . '<td>' . ($active ? 'Active' : 'Revoked') . '</td>'
                . '<td>' . (!empty($r['all_service_bodies']) ? 'All' : $this->e(implode(', ', (array) $r['service_body_ids']))) . '</td>'
                . '<td>' . $this->e(implode(', ', (array) $r['scopes'])) . '</td><td>';
            if ($active) {
                $h .= $this->permissionsForm((string) $id, $r);
                $h .= $this->form('rotate', '<input type="hidden" name="client" value="' . $this->e((string) $id) . '"><label>Old one works for <input name="grace" type="number" min="0" max="168" value="24" style="width:4em"> h</label> <button type="submit">Issue new credential</button>')
                    . $this->form('revoke', '<input type="hidden" name="client" value="' . $this->e((string) $id) . '"><button type="submit" onclick="return confirm(\'Revoke this credential? The website using it will stop receiving reports.\')">Revoke</button>');
            }
            $h .= '</td></tr>';
        }
        return $h . '</table>';
    }

    /** Edit a credential's permissions and service bodies in place (the token does not change). */
    private function permissionsForm(string $id, array $r): string
    {
        $boxes = '';
        foreach ($this->scopeLabels() as $value => $label) {
            $on = in_array($value, (array) ($r['scopes'] ?? []), true);
            $boxes .= '<label><input type="checkbox" name="scopes[]" value="' . $value . '"' . ($on ? ' checked' : '') . '> ' . $this->e($label) . '</label><br>';
        }
        $all = !empty($r['all_service_bodies']);
        $missingNames = !in_array(Client::SCOPE_VOLUNTEER_NAMES, (array) ($r['scopes'] ?? []), true);
        return '<details' . ($missingNames ? ' open' : '') . '><summary>Change permissions</summary>'
            . ($missingNames ? '<p class="bad">This credential cannot see volunteer names. Tick "Volunteer names" to show them in WordPress.</p>' : '')
            . $this->form('update',
                '<input type="hidden" name="client" value="' . $this->e($id) . '">'
                . '<p><label>Service body ids<br><input name="areas" value="' . $this->e(implode(', ', (array) ($r['service_body_ids'] ?? []))) . '"></label> '
                . '<label><input type="checkbox" name="all" value="1"' . ($all ? ' checked' : '') . '> All service bodies</label></p>'
                . '<p><label><input type="checkbox" name="allow_unassigned" value="1"' . (!empty($r['allow_unassigned']) ? ' checked' : '') . '> Include unassigned activity</label></p>'
                . '<fieldset><legend>What the site may see</legend>' . $boxes . '</fieldset>'
                . '<p><button type="submit" class="primary">Save permissions</button> <small class="muted">The credential itself does not change; WordPress keeps working.</small></p>')
            . '</details>';
    }

    /** @return array<string, string> */
    private function scopeLabels(): array
    {
        return [
            Client::SCOPE_SUMMARY => 'Totals and charts (needed for every site)',
            Client::SCOPE_CALL_DETAILS => 'Individual call list (never caller numbers)',
            Client::SCOPE_VOLUNTEER_STATS => 'Volunteer statistics',
            Client::SCOPE_VOLUNTEER_NAMES => 'Volunteer names (needed to show who answered)',
        ];
    }

    private function createForm(): string
    {
        $scopes = $this->scopeLabels();
        $boxes = '';
        foreach ($scopes as $value => $label) {
            $boxes .= '<label><input type="checkbox" name="scopes[]" value="' . $value . '"' . ($value === Client::SCOPE_SUMMARY ? ' checked' : '') . '> ' . $this->e($label) . '</label><br>';
        }
        return '<details' . ((new ClientStore($this->config->dataDir()))->all() === [] ? ' open' : '') . '><summary>Create a credential</summary>'
            . $this->form('create',
                '<p><label>Name<br><input name="name" required maxlength="100" placeholder="Example Area website"></label></p>'
                . '<p><label>Service body ids<br><input name="areas" placeholder="9, 13"></label> <label><input type="checkbox" name="all" value="1"> All service bodies</label></p>'
                . '<p><label><input type="checkbox" name="allow_unassigned" value="1"> Include unassigned activity (meeting searches without a service body)</label></p>'
                . '<fieldset><legend>What the site may see</legend>' . $boxes . '</fieldset>'
                . '<p><label>Only allow from these IP addresses (optional)<br><input name="ip" placeholder="203.0.113.10"></label><br><small class="muted">Your WordPress server\'s outgoing IP. Leave empty if unsure.</small></p>'
                . '<p><button type="submit" class="primary">Create credential</button></p>')
            . '</details>';
    }

    private function tokenPage(string $id, string $token, string $note): void
    {
        $h = '<p class="bad">Copy this now. It is shown only once and cannot be recovered.</p>'
            . '<p><textarea readonly rows="2" cols="80" onclick="this.select()">' . $this->e($token) . '</textarea></p>'
            . '<p>' . $this->e($note) . '</p>'
            . '<p>Credential id: <code>' . $this->e($id) . '</code></p>'
            . '<p><a href="' . $this->e($this->selfUrl()) . '">Back to setup</a></p>';
        $this->page('New API credential', $h);
    }

    private function testsPage(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->page('Self-test', '<p class="bad">The self-test needs the PHP <code>pdo_sqlite</code> extension, which this host does not have. This does not affect the service.</p><p><a href="' . $this->e($this->selfUrl()) . '">Back</a></p>');
            return;
        }
        $base = $this->config->baseDir() . '/tests';
        if (!class_exists('Assert', false)) {
            require_once $base . '/Assert.php';
        }
        if (!class_exists('Fixture', false)) {
            require_once $base . '/fixtures/Fixture.php';
        }
        $results = [];
        @set_time_limit(120);
        $load = static fn (string $f) => require $f; // isolates each test file's variables
        foreach (glob($base . '/unit/*Test.php') ?: [] as $file) {
            $suite = $load($file);
            foreach ((array) $suite as $name => $fn) {
                ob_start();
                try {
                    $fn();
                    $results[] = [basename($file, '.php') . ' — ' . $name, true, ''];
                } catch (\Throwable $e) {
                    $results[] = [basename($file, '.php') . ' — ' . $name, false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
                } finally {
                    ob_end_clean();
                }
            }
        }
        $failed = count(array_filter($results, static fn ($r) => !$r[1]));
        $h = '<p class="' . ($failed ? 'bad' : 'good') . '">' . (count($results) - $failed) . ' passed, ' . $failed . ' failed.</p><table>';
        foreach ($results as [$name, $pass, $msg]) {
            $h .= '<tr><td>' . ($pass ? '<span class="good">pass</span>' : '<span class="bad">FAIL</span>') . '</td><td>' . $this->e($name) . ($msg !== '' ? '<br><small>' . $this->e($msg) . '</small>' : '') . '</td></tr>';
        }
        $h .= '</table><p>If anything failed, copy this page into a message for your developer.</p><p><a href="' . $this->e($this->selfUrl()) . '">Back to setup</a></p>';
        $this->page('Self-test results', $h);
    }

    // ---------------------------------------------------------------- helpers

    private function form(string $action, string $inner): string
    {
        return '<form method="post" action="' . $this->e($this->selfUrl()) . '" class="inline">'
            . '<input type="hidden" name="action" value="' . $this->e($action) . '">'
            . '<input type="hidden" name="csrf" value="' . $this->e($this->csrf) . '">' . $inner . '</form>';
    }

    private function selfUrl(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/setup'), PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/setup';
    }

    private function cookiePath(): string
    {
        return $this->selfUrl();
    }

    private function headers(): void
    {
        header_remove('X-Powered-By');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
        header('X-Robots-Tag: noindex, nofollow');
    }

    private function page(string $title, string $body): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>' . $this->e($title) . '</title><style>'
            . 'body{font:15px/1.5 system-ui,sans-serif;color:#1f2a37;max-width:62rem;margin:2rem auto;padding:0 1rem}'
            . 'h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:2rem;border-top:1px solid #d9dee5;padding-top:1rem}'
            . 'table{border-collapse:collapse;width:100%;margin:.5rem 0}th,td{text-align:left;vertical-align:top;padding:.35rem .5rem;border-bottom:1px solid #e5e9ee}th{width:16rem;font-weight:600}'
            . '.good{color:#2d6a46;font-weight:600}.bad{color:#9b2c2c;font-weight:600}.muted{color:#5b6776}'
            . 'form.inline{display:inline-block;margin:.25rem .5rem .25rem 0}input,textarea,button{font:inherit}button{padding:.35rem .8rem;cursor:pointer}'
            . 'button.primary{background:#2f6f8f;color:#fff;border:1px solid #2f6f8f;border-radius:3px}fieldset{border:1px solid #d9dee5;margin:.5rem 0}'
            . 'code{background:#f2f4f7;padding:0 .25rem}details{margin:.75rem 0}summary{cursor:pointer;font-weight:600}'
            . '</style></head><body><h1>' . $this->e($title) . '</h1>' . $body
            . '<p class="muted" style="margin-top:3rem">YAP Reporting Webservice ' . $this->e(App::VERSION) . '</p></body></html>';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
