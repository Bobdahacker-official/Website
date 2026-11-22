<?php
declare(strict_types=1);

final class HttpError extends RuntimeException {}

function fail(int $status, string $message): never
{
    throw new HttpError($message, $status);
}

function input(string $name, int $max = 255, bool $trim = true): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value)) {
        fail(422, 'One of the submitted fields is invalid or too long.');
    }
    return $trim ? trim($value) : $value;
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function redirect(string $to, ?string $message = null): never
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
    }
    header('Location: ' . $to, true, 303);
    exit;
}

function signedIn(?array $user, bool $verified = false): void
{
    if (!$user) {
        redirect(url('login'), 'Sign in to continue.');
    }
    if ($verified && !$user['verified']) {
        fail(403, 'Verify your email address to continue.');
    }
}

function administrator(?array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        fail(403, 'Administrator access is required.');
    }
}

function currentUser(array $state): ?array
{
    $user = $state['users'][$_SESSION['uid'] ?? ''] ?? null;
    return $user && !$user['disabled'] && ($_SESSION['version'] ?? -1) === $user['version'] ? $user : null;
}

function signIn(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = ['uid' => $user['id'], 'version' => $user['version']];
}

function passwordInput(): string
{
    $password = input('password', 72, false);
    if (strlen($password) < 10 || str_contains($password, "\0")) {
        fail(422, 'Choose a password between 10 and 72 bytes.');
    }
    return $password;
}

function findUser(array $state, string $identity): ?array
{
    $identity = strtolower($identity);
    foreach ($state['users'] as $user) {
        if (strtolower($user['name']) === $identity || $user['email'] === $identity) {
            return $user;
        }
    }
    return null;
}

function throttle(Store $store, string $scope, int $maximum, int $window): void
{
    $key = hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $store->change(static function (&$state) use ($key, $maximum, $window) {
        $now = time();
        $state['limits'] = array_filter($state['limits'], static fn($item) => $item[0] > $now);
        $state['limits'][$key] ??= [$now + $window, 0];
        if ($state['limits'][$key][1] >= $maximum) {
            fail(429, 'Too many attempts. Try again later.');
        }
        $state['limits'][$key][1]++;
    });
}

function detectedOrigin(): string
{
    global $base, $secure;
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scheme = $secure ? 'https' : 'http';
    if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD', $host)) {
        return '';
    }
    return $scheme . '://' . $host . $base;
}

function accountMail(Store $store, array $config, array $user, string $type): void
{
    $key = bin2hex(random_bytes(32));
    $digest = hash('sha256', $key);
    $origin = rtrim((string) ($config['base_url'] ?? $store->read()['settings']['base_url'] ?? ''), '/');
    if (!filter_var($origin, FILTER_VALIDATE_URL) || !preg_match('~^https?://[^?#]+$~iD', $origin)) {
        throw new RuntimeException('Configure an absolute base_url before enabling account email.');
    }
    $store->change(static function (&$state) use ($digest, $user, $type) {
        $now = time();
        $state['tokens'] = array_filter($state['tokens'], static fn($token) =>
            $token['expiry'] > $now && !($token['uid'] === $user['id'] && $token['type'] === $type));
        $state['tokens'][$digest] = ['uid' => $user['id'], 'version' => $user['version'],
            'type' => $type, 'expiry' => $now + ($type === 'reset' ? 3600 : 86400)];
    });
    global $base;
    $link = $origin . substr(url($type . '?token=' . $key), strlen($base));
    try {
        deliverMail($config, $user['email'], $type === 'reset' ? 'Reset your wiki password' : 'Verify your wiki email',
            "Open this link to continue:\n\n$link\n\nIf you did not request this, you can ignore this email.");
    } catch (Throwable $error) {
        $store->change(static function (&$state) use ($digest) {
            unset($state['tokens'][$digest]);
        });
        throw $error;
    }
}

function audit(array &$state, array $actor, string $event): void
{
    $state['audit'][] = ['actor' => $actor['name'], 'event' => $event, 'at' => time()];
    $state['audit'] = array_slice($state['audit'], -200);
}

function notifications(array &$state, string $key, array $actor, string $event): void
{
    foreach ($state['users'] as $id => &$watcher) {
        if ($id !== $actor['id'] && !$watcher['disabled'] && isset($watcher['watch'][$key])) {
            $watcher['notifications'][] = ['key' => $key, 'actor' => $actor['name'], 'event' => $event, 'at' => time()];
            $watcher['notifications'] = array_slice($watcher['notifications'], -100);
        }
    }
}

function pageInput(): array
{
    $title = input('title', 160);
    $summary = input('summary', 500);
    $body = input('body', 200000);
    $note = input('note', 500);
    $tags = array_values(array_unique(array_filter(array_map('slug', explode(',', input('tags', 400))))));
    if (slug($title) === '' || $body === '' || count($tags) > 12) {
        fail(422, 'Enter a title, article text, and up to 12 topics.');
    }
    return compact('title', 'summary', 'body', 'note', 'tags');
}

function handlePost(Store $store, array $config, array $state, ?array $user, string $route, string $setupFile): never
{
    if (!hash_equals(csrf(), input('csrf', 64))) {
        fail(403, 'Your session has changed. Reload the page and try again.');
    }

    if ($route === 'preview') {
        if (!editable($user, $state)) {
            fail(403, 'Editor access is required.');
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo renderMarkdown(input('body', 200000))[0];
        exit;
    }

    if ($route === 'login') {
        throttle($store, 'login', 12, 900);
        $candidate = findUser($state, input('identity'));
        $password = input('password', 72, false);
        $hash = $candidate['password'] ?? '$2y$10$pjXry.x2unUN5.FnOI2pV.G/dCZl.PUCs/4WFE1LmuIpPfMJL3fkG';
        if (!password_verify($password, $hash) || !$candidate || $candidate['disabled']) {
            fail(422, 'The username or password is incorrect.');
        }
        signIn($candidate);
        redirect(url());
    }
    if ($route === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect(url());
    }
    if (in_array($route, ['setup', 'signup'], true)) {
        $setup = $route === 'setup';
        throttle($store, $route, 12, 3600);
        $local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $provided = $setup && !$local ? input('setup_key', 64) : '';
        if ($setup && !$local && (!preg_match('/^[a-f0-9]{48}$/D', $provided)
            || !is_file($setupFile) || !hash_equals(trim((string) file_get_contents($setupFile)), $provided))) {
            fail(403, 'The installation key is incorrect.');
        }
        $name = input('name', 40);
        $email = strtolower(input('email', 254));
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,39}$/D', $name) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail(422, 'Use a valid email and a username of 3–40 letters, digits, dots, underscores, or hyphens.');
        }
        $password = password_hash(passwordInput(), PASSWORD_DEFAULT);
        $origin = $setup ? detectedOrigin() : '';
        $candidate = $store->change(static function (&$fresh) use ($name, $email, $password, $setup, $origin) {
            if ($setup ? $fresh['users'] !== [] : (!$fresh['settings']['registration'] || $fresh['users'] === [])) {
                fail(403, 'Account creation is unavailable.');
            }
            if (findUser($fresh, $name) || findUser($fresh, $email)) {
                fail(409, 'That username or email is already in use.');
            }
            if ($setup && $origin !== '') {
                $fresh['settings']['base_url'] = $origin;
            }
            $id = bin2hex(random_bytes(12));
            $role = $setup ? 'admin' : 'member';
            $verified = $setup || !$fresh['settings']['verification'];
            return $fresh['users'][$id] = compact('id', 'role', 'name', 'email', 'password', 'verified')
                + ['disabled' => false, 'version' => 0, 'watch' => [], 'notifications' => []];
        });
        signIn($candidate);
        if (!$candidate['verified']) {
            try {
                accountMail($store, $config, $candidate, 'verify');
                redirect(url('account'), 'Check your email for a verification link.');
            } catch (Throwable $error) {
                error_log('KioWiki verification mail: ' . $error->getMessage());
                redirect(url('account'), 'Your account was created. Email delivery failed. You can request another link here.');
            }
        }
        redirect($setup ? url('admin') : url(), $setup ? 'Your administrator account is ready.' : 'Your account is ready.');
    }

    if ($route === 'forgot') {
        throttle($store, 'forgot', 5, 3600);
        $candidate = findUser($state, input('identity'));
        if ($candidate && !$candidate['disabled']) {
            try {
                accountMail($store, $config, $candidate, 'reset');
            } catch (Throwable $error) {
                error_log('KioWiki reset mail: ' . $error->getMessage());
            }
        }
        redirect(url('forgot'), 'If an eligible account matches, a reset link will be sent.');
    }
    if (in_array($route, ['verify', 'reset'], true)) {
        $digest = hash('sha256', input('token', 64));
        $password = $route === 'reset' ? password_hash(passwordInput(), PASSWORD_DEFAULT) : null;
        $store->change(static function (&$fresh) use ($digest, $route, $password) {
            $token = $fresh['tokens'][$digest] ?? null;
            $candidate = $fresh['users'][$token['uid'] ?? ''] ?? null;
            if (!$token || !$candidate || $candidate['disabled'] || $token['expiry'] <= time()
                || $token['type'] !== $route || $token['version'] !== $candidate['version']) {
                fail(422, 'This link is invalid, expired, or already used.');
            }
            if ($password === null) {
                $candidate['verified'] = true;
            } else {
                $candidate['password'] = $password;
                $candidate['version']++;
            }
            $fresh['users'][$candidate['id']] = $candidate;
            unset($fresh['tokens'][$digest]);
        });
        redirect(url($user && $route === 'verify' ? 'account' : 'login'), $route === 'verify' ? 'Email verified.' : 'Password changed. Sign in again.');
    }

    signedIn($user);
    if ($route === 'account') {
        $action = input('action', 40);
        if ($action === 'resend') {
            if ($user['verified']) {
                fail(422, 'Your email is already verified.');
            }
            throttle($store, 'verify', 5, 3600);
            try {
                accountMail($store, $config, $user, 'verify');
            } catch (Throwable $error) {
                error_log('KioWiki verification mail: ' . $error->getMessage());
                fail(503, 'Email delivery failed. Contact a wiki administrator.');
            }
            redirect(url('account'), 'Verification email sent.');
        }
        if ($action !== 'password' || !password_verify(input('current_password', 72, false), $user['password'])) {
            fail(422, 'The current password is incorrect.');
        }
        $password = password_hash(passwordInput(), PASSWORD_DEFAULT);
        $updated = $store->change(static function (&$fresh) use ($password) {
            $candidate = currentUser($fresh) ?? fail(403, 'Sign in again.');
            $candidate['password'] = $password;
            $candidate['version']++;
            return $fresh['users'][$candidate['id']] = $candidate;
        });
        signIn($updated);
        redirect(url('account'), 'Password changed.');
    }
    if ($route === 'notifications') {
        $store->change(static function (&$fresh) {
            $actor = currentUser($fresh) ?? fail(403, 'Sign in again.');
            $fresh['users'][$actor['id']]['notifications'] = [];
        });
        redirect(url('notifications'), 'Notifications cleared.');
    }
    if ($route === 'admin') {
        administrator($user);
        adminPost($store, $config);
    }
    if ($route === 'new') {
        $fields = pageInput();
        $key = slug($fields['title']);
        $store->change(static function (&$fresh) use ($key, $fields) {
            $actor = currentUser($fresh);
            if (!editable($actor, $fresh)) {
                fail(403, 'Editor access is required.');
            }
            if (isset($fresh['pages'][$key])) {
                fail(409, 'An article already uses this address.');
            }
            $fresh['pages'][$key] = newPage($fields['title'], $fields['summary'], $fields['tags'],
                $fields['body'], $actor['name'], $fields['note'] ?: 'Created article');
        });
        redirect(article($key), 'Article created.');
    }
    if (preg_match('~^articles/([^/]+)/(edit|restore|discussion|watch)$~uD', $route, $match)) {
        [, $key, $action] = $match;
        $fields = $action === 'edit' ? pageInput() : [];
        $version = in_array($action, ['edit', 'restore'], true) ? input('version', 12) : '';
        $store->change(static function (&$fresh) use ($key, $action, $fields, $version) {
            $actor = currentUser($fresh);
            signedIn($actor, $action !== 'watch');
            $page = &$fresh['pages'][$key];
            if (!$page || $page['deleted']) {
                fail(404, 'Article not found.');
            }
            if ($action === 'watch') {
                if (isset($fresh['users'][$actor['id']]['watch'][$key])) {
                    unset($fresh['users'][$actor['id']]['watch'][$key]);
                } else {
                    $fresh['users'][$actor['id']]['watch'][$key] = true;
                }
                return;
            }
            if ($action === 'discussion') {
                if ($page['locked'] && $actor['role'] !== 'admin') {
                    fail(403, 'This discussion is locked.');
                }
                $comment = input('comment', 8000);
                if ($comment === '') {
                    fail(422, 'Write a comment first.');
                }
                $page['discussion'][] = ['text' => $comment, 'author' => $actor['name'], 'at' => time()];
                notifications($fresh, $key, $actor, 'posted in the discussion');
                return;
            }
            if (!editable($actor, $fresh, $page)) {
                fail(403, 'You cannot edit this article.');
            }
            if ($version !== (string) count($page['revisions'])) {
                fail(409, 'The article changed while you were editing. Copy your changes before reloading.');
            }
            $revision = $fields;
            if ($action === 'restore') {
                $index = input('revision', 12);
                if (!ctype_digit($index) || !isset($page['revisions'][(int) $index - 1])) {
                    fail(404, 'Revision not found.');
                }
                $revision = $page['revisions'][(int) $index - 1];
                $revision['note'] = 'Restored revision ' . $index;
            }
            $page['revisions'][] = array_replace($revision, ['at' => time(), 'author' => $actor['name']]);
            notifications($fresh, $key, $actor, $action === 'edit' ? 'edited the article' : 'restored a revision');
        });
        redirect(article($key, $action === 'discussion' ? 'discussion' : ''), match ($action) {
            'edit' => 'Article saved.', 'restore' => 'Revision restored.', 'discussion' => 'Comment added.', default => null,
        });
    }
    fail(404, 'Page not found.');
}

function adminPost(Store $store, array $config): never
{
    $action = input('action', 30);
    if ($action === 'mail') {
        throttle($store, 'mail-test', 4, 3600);
        try {
            $actor = currentUser($store->read());
            administrator($actor);
            deliverMail($config, $actor['email'], 'KioWiki mail test', 'KioWiki successfully submitted this test message.');
        } catch (Throwable $error) {
            error_log('KioWiki mail test: ' . $error->getMessage());
            fail(503, 'The mail transport rejected the test. Check the server log and mail configuration.');
        }
        redirect(url('admin'), 'Test email sent to your account address.');
    }
    $store->change(static function (&$state) use ($action, $config) {
        $actor = currentUser($state);
        administrator($actor);
        if ($action === 'settings') {
            $name = input('name', 70);
            $description = input('description', 300);
            $home = input('home', 160);
            if ($name === '' || !isset($state['pages'][$home]) || $state['pages'][$home]['deleted']) {
                fail(422, 'Enter a site name and choose a published homepage.');
            }
            if (isset($_POST['verification']) && (empty($config['base_url'] ?? $state['settings']['base_url'] ?? '') || empty($config['smtp']['from'] ?? $config['mail_from'] ?? ''))) {
                fail(422, 'Configure the wiki URL and mail sender before requiring verification.');
            }
            $settings = compact('name', 'description', 'home');
            foreach (['registration', 'editors_only', 'private', 'verification'] as $flag) {
                $settings[$flag] = isset($_POST[$flag]);
            }
            $state['settings'] = array_replace($state['settings'], $settings);
            audit($state, $actor, 'Updated wiki settings');
        } elseif ($action === 'user') {
            $id = input('id', 24);
            $role = input('role', 12);
            if (!isset($state['users'][$id]) || !in_array($role, ['member', 'editor', 'admin'], true)) {
                fail(422, 'Choose an existing user and a valid role.');
            }
            if ($id === $actor['id']) {
                fail(422, 'Another administrator must change your account.');
            }
            $target = &$state['users'][$id];
            $target['role'] = $role;
            $target['disabled'] = isset($_POST['disabled']);
            $target['version']++;
            audit($state, $actor, 'Updated access for ' . $target['name']);
        } elseif ($action === 'page') {
            $key = input('key', 160);
            if (!isset($state['pages'][$key])) {
                fail(404, 'Article not found.');
            }
            if ($key === $state['settings']['home'] && isset($_POST['deleted'])) {
                fail(422, 'Choose another homepage before archiving this article.');
            }
            $state['pages'][$key]['deleted'] = isset($_POST['deleted']);
            $state['pages'][$key]['locked'] = isset($_POST['locked']);
            audit($state, $actor, 'Updated moderation for ' . $key);
        } else {
            fail(422, 'Unknown administration action.');
        }
    });
    redirect(url('admin'), 'Changes saved.');
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https: http:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('KioWiki');
session_start(['use_strict_mode' => true, 'cookie_httponly' => true, 'cookie_secure' => $secure,
    'cookie_samesite' => 'Lax', 'cookie_path' => $base . '/']);
header('Cache-Control: private, no-store');
require __DIR__ . '/Views.php';

$outputLevel = ob_get_level();
$route = '';
$user = null;
$state = [];
try {
    $root = dirname(__DIR__);
    $config = is_file($root . '/config.php') ? require $root . '/config.php' : [];
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = trim(substr(is_string($path) ? $path : '', strlen($base)), '/');
    $fallback = $_GET['r'] ?? null;
    $pretty = $config['pretty_urls'] ?? ($fallback === null);
    $route = is_string($fallback) ? trim($fallback, '/') : ($path === 'index.php' ? '' : rawurldecode($path));
    if (strlen($route) > 350 || !preg_match('//u', $route)) {
        fail(404, 'Page not found.');
    }
    $data = $config['data_dir'] ?? $root . '/.kiowiki';
    if (!is_dir($data) && !@mkdir($data, 0700, true) && !is_dir($data)) {
        throw new RuntimeException('The PHP process cannot create its data directory.');
    }
    if (!is_file($data . '/.htaccess') && @file_put_contents($data . '/.htaccess', "Require all denied\n") === false) {
        throw new RuntimeException('The data directory is not writable.');
    }
    $store = new Store($config, $data);
    $state = $store->read();
    if ($state === []) {
        $state = $store->change(static function (&$fresh) {
            if ($fresh === []) {
                $fresh = seed();
            }
            return $fresh;
        });
    }
    $setupFile = $data . '/setup.key';
    if ($state['users'] === []) {
        $keyFile = @fopen($setupFile, 'x');
        if ($keyFile) {
            chmod($setupFile, 0600);
            fwrite($keyFile, bin2hex(random_bytes(24)) . "\n");
            fclose($keyFile);
        } elseif (!is_file($setupFile)) {
            throw new RuntimeException('The installation key could not be created.');
        }
    }
    $user = currentUser($state);
    if ($state['settings']['private'] && (!$user || !$user['verified'])
        && !in_array($route, ['login', 'logout', 'signup', 'forgot', 'reset', 'verify', 'account'], true)) {
        redirect(url($user ? 'account' : 'login'), $user ? 'Verify your email to read this wiki.' : 'Sign in to access this wiki.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handlePost($store, $config, $state, $user, $route, $setupFile);
    }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD, POST');
        fail(405, 'Method not allowed.');
    }
    if ($route === '' && $state['users'] === [] && !empty($config['setup_on_home'])) {
        redirect(url('setup'));
    }
    renderRoute($route, $state, $user, $store->driver());
} catch (HttpError $error) {
    while (ob_get_level() > $outputLevel) {
        ob_end_clean();
    }
    http_response_code($error->getCode());
    $draftRoute = $route === 'new' || preg_match('~^articles/([^/]+)/edit$~uD', $route, $draftMatch);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $draftRoute && in_array($error->getCode(), [409, 422], true)) {
        $draft = array_filter(array_intersect_key($_POST, array_flip(['title', 'summary', 'tags', 'body', 'note'])), 'is_string');
        $key = $draftMatch[1] ?? '';
        $current = isset($state['pages'][$key]) ? latest($state['pages'][$key]) : null;
        $version = is_string($_POST['version'] ?? null) ? (int) $_POST['version'] : 0;
        shell($current ? 'Edit article' : 'New article', editor($current, url($route), $version, $key, $draft, $error->getMessage()), $state, $user, 'articles');
    } else {
        shell('Request could not be completed', '<div class="empty"><h1>' . h($error->getCode()) . '</h1><p>'
            . h($error->getMessage()) . '</p><a class="button" href="' . h(url()) . '">Return home</a></div>', $state, $user);
    }
} catch (Throwable $error) {
    while (ob_get_level() > $outputLevel) {
        ob_end_clean();
    }
    error_log('KioWiki: ' . $error->getMessage());
    http_response_code(500);
    echo '<h1>KioWiki could not start</h1><p>Check the PHP error log, database permissions, and deployment instructions.</p>';
}
