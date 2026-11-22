<?php
declare(strict_types=1);

function linkTo(string $label, string $href, string $class = ''): string
{
    return '<a' . ($class === '' ? '' : ' class="' . h($class) . '"') . ' href="' . h($href) . '">' . h($label) . '</a>';
}

function form(string $action, string $class = ''): string
{
    return '<form method="post" action="' . h($action) . '" class="' . h($class) . '">' . hidden('csrf', csrf());
}

function hidden(string $name, string|int $value): string
{
    return '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
}

function field(string $name, string $label, string $value = '', string $type = 'text', string $attributes = ''): string
{
    return '<label class="field">' . h($label) . '<input name="' . h($name) . '" type="' . h($type)
        . '" value="' . h($value) . '" ' . $attributes . '></label>';
}

function submit(string $label, string $class = 'primary'): string
{
    return '<button class="button ' . h($class) . '" type="submit">' . h($label) . '</button>';
}

function when(int $at): string
{
    return '<time datetime="' . gmdate(DATE_ATOM, $at) . '">' . gmdate('j M Y, H:i', $at) . ' UTC</time>';
}

function shell(string $title, string $content, array $state, ?array $user, string $section = '', string $outline = ''): void
{
    $settings = $state['settings'] ?? seed()['settings'];
    $name = $settings['name'];
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    $count = count($user['notifications'] ?? []);
    $sidebar = !$settings['private'] || ($user && $user['verified']);
    $showSetup = ($state['users'] ?? []) === [];
    $home = $settings['home'];
    $nav = [
        ['Overview', '', '⌂'],
        ['All articles', 'articles', '▤'],
        ['Topics', 'topics', '◇'],
        ['Recent changes', 'recent', '◷'],
    ];
    $query = $_GET['q'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($name) ?></title>
<link rel="stylesheet" href="<?= h(url('assets/wiki.css')) ?>">
<script defer src="<?= h(url('assets/wiki.js')) ?>"></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="top">
    <a class="brand" href="<?= h(url()) ?>"><span class="mark" aria-hidden="true">K</span><?= h($name) ?></a>
    <?php if ($sidebar): ?>
    <form class="search" action="<?= h(url('search')) ?>" method="get" role="search">
        <?php if (str_contains(url('search'), '?r=')) echo hidden('r', 'search'); ?>
        <span aria-hidden="true">⌕</span><input name="q" value="<?= h(is_string($query) ? $query : '') ?>" aria-label="Search the wiki" placeholder="Search the wiki…" maxlength="200">
        <button type="submit" aria-label="Search">↵</button>
    </form>
    <?php endif; ?>
    <div class="top-actions">
        <button class="button mode" type="button" id="theme" aria-label="Toggle colour theme" aria-pressed="false">◐</button>
        <?php if ($user): ?>
        <?= linkTo($user['name'], url('account'), 'button account-link') ?>
        <?php else: ?>
        <?= linkTo('Sign in', url($showSetup ? 'setup' : 'login'), 'button primary') ?>
        <?php endif; ?>
    </div>
</header>
<div class="layout<?= $outline === '' ? ' no-outline' : '' ?><?= !$sidebar ? ' restricted' : '' ?>">
    <?php if ($sidebar): ?>
    <aside class="sidebar">
        <nav aria-label="Wiki navigation">
            <h2>Workspace</h2>
            <?php foreach ($nav as [$label, $path, $glyph]): ?>
                <a class="nav-link<?= $section === $path ? ' selected' : '' ?>" href="<?= h(url($path)) ?>"<?= $section === $path ? ' aria-current="page"' : '' ?>><span aria-hidden="true"><?= h($glyph) ?></span><?= h($label) ?></a>
            <?php endforeach; ?>
            <?php if (editable($user, $state)): ?>
                <?= linkTo('+ New article', url('new'), 'button new-link') ?>
            <?php endif; ?>
            <div class="nav-group">
                <h2>Quick links</h2>
                <?php if (isset($state['pages'][$home])) echo linkTo(latest($state['pages'][$home])['title'], article($home), 'nav-link'); ?>
                <?php if ($home !== 'writing-guide' && isset($state['pages']['writing-guide']) && !$state['pages']['writing-guide']['deleted']) echo linkTo(latest($state['pages']['writing-guide'])['title'], article('writing-guide'), 'nav-link'); ?>
                <?php if ($user): ?>
                    <?= linkTo('Watched articles', url('watchlist'), 'nav-link') ?>
                    <?= linkTo('Notifications' . ($count ? " ($count)" : ''), url('notifications'), 'nav-link') ?>
                <?php endif; ?>
                <?php if (($user['role'] ?? '') === 'admin') echo linkTo('Administration', url('admin'), 'nav-link'); ?>
                <?php if ($showSetup) echo linkTo('Set up this wiki', url('setup'), 'nav-link'); ?>
            </div>
        </nav>
        <div class="side-footer"><span class="mark small" aria-hidden="true">K</span> Powered by KioWiki</div>
    </aside>
    <?php endif; ?>
    <main id="main">
        <?php if ($flash !== ''): ?><p class="notice" role="status"><?= h($flash) ?></p><?php endif; ?>
        <?= $content ?>
    </main>
    <?php if ($outline !== ''): ?><aside class="outline"><?= $outline ?></aside><?php endif; ?>
</div>
</body>
</html>
    <?php
}

function renderRoute(string $route, array $state, ?array $user, string $driver): void
{
    $settings = $state['settings'];
    if ($route === 'backup') {
        administrator($user);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="kiowiki-backup.json"');
        echo json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($route === '' || preg_match('~^articles/([^/]+)(?:/(edit|history|discussion|revision))?$~uD', $route, $m)) {
        $home = $route === '';
        $key = $home ? $settings['home'] : $m[1];
        $page = $state['pages'][$key] ?? null;
        if (!$page || $page['deleted']) {
            fail(404, 'Article not found.');
        }
        renderArticle((string) $key, $page, $home, $m[2] ?? '', $state, $user);
        return;
    }
    if ($route === 'new') {
        signedIn($user, true);
        if (!editable($user, $state)) {
            fail(403, 'Editor access is required.');
        }
        shell('New article', editor(null, url('new')), $state, $user, 'new');
        return;
    }
    if (in_array($route, ['articles', 'search', 'topics', 'recent', 'watchlist', 'notifications'], true)) {
        if (in_array($route, ['watchlist', 'notifications'], true)) {
            signedIn($user);
        }
        [$title, $content] = directoryView($route, $state, $user);
        shell($title, $content, $state, $user, $route);
        return;
    }
    if ($route === 'admin') {
        administrator($user);
        shell('Administration', adminView($state, $user, $driver), $state, $user, 'admin');
        return;
    }
    if (in_array($route, ['setup', 'login', 'signup', 'account', 'forgot', 'verify', 'reset'], true)) {
        [$title, $content] = accountView($route, $state, $user);
        shell($title, $content, $state, $user, $route);
        return;
    }
    fail(404, 'Page not found.');
}

function articleCards(array $pages, string $empty): string
{
    if ($pages === []) {
        return '<div class="empty"><p>' . h($empty) . '</p></div>';
    }
    $output = '<div class="cards">';
    foreach ($pages as $key => $revision) {
        $output .= '<a class="card" href="' . h(article((string) $key)) . '"><span class="card-icon" aria-hidden="true">▤</span><strong>'
            . h($revision['title']) . '</strong><span>' . h($revision['summary']) . '</span></a>';
    }
    return $output . '</div>';
}

function renderArticle(string $key, array $page, bool $home, string $tab, array $state, ?array $user): void
{
    $current = latest($page);
    $canEdit = editable($user, $state, $page);
    $version = count($page['revisions']);
    $title = $current['title'];
    if ($tab === 'edit') {
        signedIn($user, true);
        if (!$canEdit) {
            fail(403, 'You cannot edit this article.');
        }
        shell('Edit ' . $title, editor($current, article($key, 'edit'), $version, $key), $state, $user, 'articles');
        return;
    }
    $revision = $current;
    $number = $version;
    if ($tab === 'revision') {
        $number = filter_input(INPUT_GET, 'v', FILTER_VALIDATE_INT) ?: 0;
        $revision = $page['revisions'][$number - 1] ?? fail(404, 'Revision not found.');
    }
    [$body, $toc] = renderMarkdown($revision['body']);
    $outline = '';
    if (($tab === '' || $tab === 'revision') && $toc !== []) {
        $outline = '<nav aria-label="Article contents"><h2>On this page</h2><a href="#article-top" class="toc-link current" aria-current="location">Overview</a>';
        foreach ($toc as [$id, $label, $level]) {
            $outline .= linkTo($label, '#' . $id, 'toc-link level-' . $level);
        }
        $outline .= '</nav>';
    }
    ob_start();
    if ($home): ?>
        <header class="hero" id="article-top">
            <div class="hero-copy"><h1><?= h($state['settings']['name']) ?></h1>
            <p><?= h($state['settings']['description']) ?></p>
            <div class="actions"><?= linkTo('Browse articles', url('articles'), 'button white') ?><?= linkTo('Explore topics', url('topics'), 'button ghost') ?></div></div>
            <div class="hero-art" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        </header>
        <?php $featured = filterPages($state, ''); unset($featured[$key]); ?>
        <?php if ($featured !== []): ?><section class="discover"><h2>Explore the wiki</h2>
            <?= articleCards(array_slice($featured, 0, 3, true), '') ?>
        </section><?php endif; ?>
        <h2 class="welcome-title"><?= h($title) ?></h2>
    <?php else: ?>
        <div class="crumb"><?= linkTo('Articles', url('articles')) ?> <span>›</span> <?= h($title) ?></div>
        <header class="article-head" id="article-top">
            <div><h1><?= h($revision['title']) ?></h1><p><?= h($revision['summary']) ?></p></div>
            <?php if ($canEdit) echo linkTo('Edit article', article($key, 'edit'), 'button primary'); ?>
        </header>
    <?php endif; ?>
    <div class="tabs">
        <nav aria-label="Article sections">
            <?php foreach (['' => 'Read', 'discussion' => 'Discussion', 'history' => 'History'] as $mode => $label): ?>
                <a href="<?= h(article($key, $mode)) ?>"<?= ($tab === $mode || ($mode === 'history' && $tab === 'revision')) ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($user): ?><?= form(article($key, 'watch')) . submit(isset($user['watch'][$key]) ? 'Unwatch' : 'Watch', '') ?></form><?php endif; ?>
    </div>
    <?php if ($tab === 'history'): ?>
        <h2>Revision history</h2>
        <ol class="rows history">
        <?php foreach (array_reverse($page['revisions'], true) as $index => $item): ?>
            <li><div><?= linkTo('Revision ' . ($index + 1), article($key, 'revision') . (str_contains(article($key, 'revision'), '?') ? '&' : '?') . 'v=' . ($index + 1)) ?>
            <p><?= h($item['note'] ?: 'Edited article') ?></p></div><span><?= h($item['author']) ?> · <?= when($item['at']) ?></span></li>
        <?php endforeach; ?>
        </ol>
    <?php elseif ($tab === 'discussion'): ?>
        <h2>Discussion</h2>
        <?php if (!$page['discussion']): ?><p class="muted">No comments yet.</p><?php endif; ?>
        <?php foreach ($page['discussion'] as $comment): ?>
            <div class="comment"><strong><?= h($comment['author']) ?></strong> <span><?= when($comment['at']) ?></span><p><?= nl2br(h($comment['text'])) ?></p></div>
        <?php endforeach; ?>
        <?php if ($user && $user['verified'] && (!$page['locked'] || $user['role'] === 'admin')): ?>
            <?= form(article($key, 'discussion'), 'stack') ?>
            <label class="field">Add a comment<textarea name="comment" rows="4" maxlength="8000" required></textarea></label><?= submit('Post comment') ?></form>
        <?php elseif (!$user): ?><?= linkTo('Sign in to leave a comment', url('login')) ?>
        <?php else: ?><p class="muted"><?= $page['locked'] ? 'This discussion is locked.' : 'Verify your email to post a comment.' ?></p><?php endif; ?>
    <?php else: ?>
        <?php if ($tab === 'revision'): ?>
        <div class="notice revision"><p>Revision <?= h($number) ?> by <?= h($revision['author']) ?> · <?= when($revision['at']) ?></p>
        <?php if ($canEdit && $number !== $version): ?>
            <?= form(article($key, 'restore')) . hidden('revision', $number) . hidden('version', $version) . submit('Restore revision', '') ?></form>
        <?php endif; ?></div>
        <?php endif; ?>
        <article class="prose"><?= $body ?></article>
        <?php if ($revision['tags']): ?><div class="tags"><?php foreach ($revision['tags'] as $tag) echo linkTo($tag, url('articles?tag=' . rawurlencode($tag)), 'tag'); ?></div><?php endif; ?>
        <p class="page-meta">Last updated <?= when($revision['at']) ?> by <?= h($revision['author']) ?></p>
    <?php endif;
    shell($home ? 'Overview' : $title, ob_get_clean(), $state, $user, $home ? '' : 'articles', $outline);
}

function editor(?array $current, string $action, int $version = 0, string $key = '', array $draft = [], string $error = ''): string
{
    ob_start(); ?>
    <div class="crumb"><?= linkTo('Articles', url('articles')) ?></div>
    <h1><?= $current ? 'Edit article' : 'New article' ?></h1>
    <?php if ($error !== ''): ?><p class="notice" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= form($action, 'stack editor') . hidden('version', $version) ?>
    <?= field('title', 'Title', $draft['title'] ?? $current['title'] ?? '', 'text', 'required maxlength="160"') ?>
    <?= field('summary', 'Summary', $draft['summary'] ?? $current['summary'] ?? '', 'text', 'maxlength="500"') ?>
    <?= field('tags', 'Topics (comma separated)', $draft['tags'] ?? implode(', ', $current['tags'] ?? []), 'text', 'maxlength="400"') ?>
    <div class="compose" data-preview="<?= h(url('preview')) ?>">
        <label class="field">Article text<textarea name="body" rows="16" maxlength="200000" required><?= h($draft['body'] ?? $current['body'] ?? '') ?></textarea></label>
        <div class="preview-panel" hidden><strong>Preview</strong><div class="prose" data-preview-body aria-live="polite"></div></div>
    </div>
    <p class="muted typing-tip">Use Markdown for formatting and <code>[[Article title]]</code> for wiki links. <?= linkTo('Writing guide', article('writing-guide')) ?></p>
    <?= field('note', 'Edit summary', $draft['note'] ?? '', 'text', 'maxlength="500"') ?>
    <div class="actions"><?= submit($current ? 'Save changes' : 'Publish article') ?>
        <button class="button" type="button" data-preview-button aria-expanded="false" hidden>Preview</button>
        <?= linkTo('Cancel', $current ? article($key) : url('articles'), 'button') ?>
    </div></form>
    <?php return ob_get_clean();
}

function directoryView(string $route, array $state, ?array $user): array
{
    $query = $_GET['q'] ?? '';
    $tag = $_GET['tag'] ?? '';
    $query = is_string($query) && strlen($query) <= 200 && preg_match('//u', $query) ? trim($query) : '';
    $tag = is_string($tag) && strlen($tag) <= 400 ? $tag : '';
    $pages = filterPages($state, $route === 'search' ? $query : '', $route === 'articles' ? $tag : '');
    $title = match ($route) {
        'search' => 'Search', 'topics' => 'Topics', 'recent' => 'Recent changes', 'watchlist' => 'Watched articles',
        'notifications' => 'Notifications', default => $tag === '' ? 'All articles' : 'Topic: ' . $tag,
    };
    ob_start(); ?>
    <h1><?= h($title) ?></h1>
    <?php if ($route === 'topics'):
        $tags = [];
        foreach ($pages as $item) foreach ($item['tags'] as $label) $tags[$label] = ($tags[$label] ?? 0) + 1;
        ksort($tags);
        ?>
        <p class="muted">Browse articles grouped by topic.</p>
        <div class="tags topic-index"><?php foreach ($tags as $label => $count) echo linkTo($label . ' · ' . $count, url('articles?tag=' . rawurlencode((string) $label)), 'tag'); ?></div>
        <?php if (!$tags): ?><p class="muted">No topics yet.</p><?php endif; ?>
    <?php elseif ($route === 'recent'):
        $events = [];
        foreach ($pages as $key => $ignored) foreach (array_reverse(array_slice($state['pages'][$key]['revisions'], -50)) as $item) $events[] = [$key, $item];
        usort($events, static fn($a, $b) => $b[1]['at'] <=> $a[1]['at']);
        ?>
        <ol class="rows"><?php foreach (array_slice($events, 0, 50) as [$key, $item]): ?>
            <li><div><?= linkTo($pages[$key]['title'], article((string) $key)) ?><p><?= h($item['note'] ?: 'Edited article') ?></p></div><span><?= h($item['author']) ?> · <?= when($item['at']) ?></span></li>
        <?php endforeach; ?></ol>
    <?php elseif ($route === 'notifications'): ?>
        <?php if ($user['notifications']): ?>
            <?= form(url('notifications')) . submit('Clear notifications', '') ?></form>
            <ul class="rows"><?php foreach (array_reverse($user['notifications']) as $item): ?>
                <li><div><?= h($item['actor'] . ' ' . $item['event']) ?><p><?= isset($pages[$item['key']]) ? linkTo($pages[$item['key']]['title'], article((string) $item['key'])) : 'Archived article' ?></p></div><?= when($item['at']) ?></li>
            <?php endforeach; ?></ul>
        <?php else: ?><div class="empty"><p>No notifications yet.</p></div><?php endif; ?>
    <?php else:
        if ($route === 'watchlist') $pages = array_intersect_key($pages, $user['watch']);
        if ($route === 'search'): ?><p class="muted"><?= $query === '' ? 'Enter a phrase in the search bar.' : h(count($pages) . ' results for “' . $query . '”') ?></p><?php endif; ?>
        <?php if ($route !== 'search' || $query !== '') echo articleCards($pages, $route === 'watchlist' ? 'You are not watching any articles.' : 'No matching articles.'); ?>
    <?php endif;
    return [$title, ob_get_clean()];
}

function accountView(string $route, array $state, ?array $user): array
{
    $installed = $state['users'] !== [];
    if ($route === 'setup' && $installed) redirect(url('login'));
    if ($route !== 'setup' && !$installed) redirect(url('setup'));
    if ($route === 'signup' && !$state['settings']['registration']) fail(403, 'Registration is closed.');
    if ($route === 'account') signedIn($user);
    $title = match ($route) {
        'setup' => 'Set up your wiki', 'signup' => 'Create an account', 'forgot' => 'Reset your password',
        'reset' => 'Choose a new password', 'verify' => 'Verify your email', 'account' => 'Your account', default => 'Sign in',
    };
    ob_start(); ?>
    <section class="account">
    <h1><?= h($title) ?></h1>
    <?php if ($route === 'account'): ?>
        <p><?= h($user['name']) ?> · <?= h(ucfirst($user['role'])) ?></p>
        <p class="muted"><?= h($user['email']) ?> · <?= $user['verified'] ? 'Verified' : 'Verification pending' ?></p>
        <?php if (!$user['verified']): ?>
            <?= form(url('account')) . hidden('action', 'resend') . submit('Resend verification', '') ?></form>
        <?php endif; ?>
        <h2>Change password</h2>
        <?= form(url('account'), 'stack') . hidden('action', 'password') ?>
        <?= field('current_password', 'Current password', '', 'password', 'required autocomplete="current-password"') ?>
        <?= field('password', 'New password', '', 'password', 'required minlength="10" maxlength="72" autocomplete="new-password"') ?>
        <?= submit('Change password') ?></form>
        <?= form(url('logout'), 'signout') . submit('Sign out', '') ?></form>
    <?php else: ?>
        <?= form(url($route), 'stack') ?>
        <?php if ($route === 'setup'):
            $local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
            if (!$local): ?>
                <p class="muted">Read <code>setup.key</code> in the private data directory to claim administrator access.</p>
                <?= field('setup_key', 'Installation key', '', 'password', 'required autocomplete="off"') ?>
            <?php endif;
        endif;
        if (in_array($route, ['setup', 'signup'], true)) {
            echo field('name', 'Username', '', 'text', 'required maxlength="40" autocomplete="username"');
            echo field('email', 'Email', '', 'email', 'required maxlength="254" autocomplete="email"');
        }
        if (in_array($route, ['login', 'forgot'], true)) echo field('identity', 'Username or email', '', 'text', 'required autocomplete="username"');
        if (in_array($route, ['reset', 'verify'], true)) {
            $token = $_GET['token'] ?? null;
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) fail(422, 'Open the full link from your email.');
            echo hidden('token', $token);
        }
        if (in_array($route, ['setup', 'signup', 'reset'], true)) echo field('password', 'Password (at least 10 characters)', '', 'password', 'required minlength="10" maxlength="72" autocomplete="new-password"');
        if ($route === 'login') echo field('password', 'Password', '', 'password', 'required autocomplete="current-password"');
        echo submit($route === 'forgot' ? 'Send reset link' : $title); ?></form>
        <?php if ($route === 'login'): ?><div class="account-links"><?= linkTo('Forgot your password?', url('forgot')) ?>
            <?php if ($state['settings']['registration']) echo linkTo('Create an account', url('signup')); ?></div><?php endif; ?>
    <?php endif; ?></section>
    <?php return [$title, ob_get_clean()];
}

function adminView(array $state, array $user, string $driver): string
{
    $settings = $state['settings'];
    ob_start(); ?>
    <h1>Administration</h1><p class="muted">Storage driver <?= h(strtoupper($driver)) ?></p>
    <nav class="admin-links" aria-label="Administration sections">
        <?= linkTo('Settings', '#settings') . linkTo('Users', '#users') . linkTo('Articles', '#moderation') . linkTo('Audit log', '#audit') ?>
    </nav>
    <section class="admin-panel" id="settings">
        <h2>Wiki settings</h2>
        <?= form(url('admin'), 'stack') . hidden('action', 'settings') ?>
        <?= field('name', 'Wiki name', $settings['name'], 'text', 'required maxlength="70"') ?>
        <?= field('description', 'Description', $settings['description'], 'text', 'maxlength="300"') ?>
        <label class="field">Homepage<select name="home">
        <?php foreach (filterPages($state, '') as $key => $item): ?><option value="<?= h($key) ?>"<?= (string) $key === $settings['home'] ? ' selected' : '' ?>><?= h($item['title']) ?></option><?php endforeach; ?>
        </select></label>
        <?php foreach (['registration' => 'Allow public signups', 'editors_only' => 'Restrict editing to editors and administrators',
            'private' => 'Require sign-in to read articles', 'verification' => 'Require email verification for new members'] as $flag => $label): ?>
            <label class="check"><input type="checkbox" name="<?= h($flag) ?>"<?= $settings[$flag] ? ' checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
        <?= submit('Save settings') ?></form>
        <div class="actions admin-actions">
            <?= form(url('admin')) . hidden('action', 'mail') . submit('Send test email', '') ?></form>
            <?= linkTo('Download private backup', url('backup'), 'button') ?>
        </div>
    </section>
    <section class="admin-panel" id="users"><h2>Users</h2>
        <?php foreach ($state['users'] as $id => $member): ?>
            <details><summary><?= h($member['name']) ?> · <?= h($member['role']) ?><?= $member['disabled'] ? ' · disabled' : '' ?></summary>
            <p class="muted"><?= h($member['email']) ?> · <?= $member['verified'] ? 'verified' : 'pending verification' ?></p>
            <?php if ($id !== $user['id']): ?>
                <?= form(url('admin'), 'admin-row') . hidden('action', 'user') . hidden('id', $id) ?>
                <label class="field">Role<select name="role"><?php foreach (['member', 'editor', 'admin'] as $role): ?>
                    <option<?= $member['role'] === $role ? ' selected' : '' ?>><?= h($role) ?></option>
                <?php endforeach; ?></select></label>
                <label class="check"><input name="disabled" type="checkbox"<?= $member['disabled'] ? ' checked' : '' ?>> Disabled</label>
                <?= submit('Update access', '') ?></form>
            <?php endif; ?></details>
        <?php endforeach; ?>
    </section>
    <section class="admin-panel" id="moderation"><h2>Article moderation</h2>
        <?php foreach ($state['pages'] as $key => $page): ?>
            <details><summary><?= h(latest($page)['title']) ?><?= $page['deleted'] ? ' · archived' : '' ?></summary>
            <?= form(url('admin'), 'admin-row') . hidden('action', 'page') . hidden('key', $key) ?>
            <label class="check"><input name="locked" type="checkbox"<?= $page['locked'] ? ' checked' : '' ?>> Lock edits and discussion</label>
            <label class="check"><input name="deleted" type="checkbox"<?= $page['deleted'] ? ' checked' : '' ?>> Archived</label>
            <?= submit('Update article', '') ?></form></details>
        <?php endforeach; ?>
    </section>
    <section class="admin-panel" id="audit"><h2>Audit log</h2>
        <?php if (!$state['audit']): ?><p class="muted">No administrative changes yet.</p><?php endif; ?>
        <ul class="rows"><?php foreach (array_reverse($state['audit']) as $event): ?>
            <li><span><?= h($event['actor'] . ' · ' . $event['event']) ?></span><?= when($event['at']) ?></li>
        <?php endforeach; ?></ul>
    </section>
    <?php return ob_get_clean();
}
