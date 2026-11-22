<?php
declare(strict_types=1);

function h(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slug(string $title): string
{
    $title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $title) ?? '', '-');
}

function article(string $slug, string $tab = ''): string
{
    return url('articles/' . rawurlencode($slug) . ($tab === '' ? '' : '/' . $tab));
}

function url(string $path = ''): string
{
    global $base, $pretty;
    if ($pretty || $path === '' || str_starts_with($path, 'assets/')) {
        return $base . '/' . $path;
    }
    [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
    return $base . '/index.php?r=' . rawurlencode(rawurldecode($route)) . ($query !== '' ? '&' . $query : '');
}

function inline(string $text): string
{
    $pattern = '~(`[^`]+?`|\[\[[^\]\n]+\]\]|!?\[[^\]\n]+\]\([^\s)]+\)|\*\*[^*]+\*\*|\*[^*]+\*)~u';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return h($text);
    }
    foreach ($parts as $i => &$part) {
        if ($i % 2 === 0) {
            $part = h($part);
        } elseif ($part[0] === '`') {
            $part = '<code>' . h(substr($part, 1, -1)) . '</code>';
        } elseif (str_starts_with($part, '[[')) {
            [$title, $label] = array_pad(explode('|', substr($part, 2, -2), 2), 2, null);
            $part = '<a href="' . h(article(slug($title))) . '">' . h($label ?? $title) . '</a>';
        } elseif (preg_match('~^(!?)\[([^\]]+)\]\(([^)]+)\)$~u', $part, $match)) {
            [, $image, $label, $target] = $match;
            $safe = preg_match('~^(https?://[^\s]+|#[\w-]+)$~iD', $target)
                || preg_match('~^/(?![/\\\\])[^\\\\\s]*$~D', $target);
            if (!$safe) {
                $part = h($part);
            } elseif ($image) {
                $part = '<img loading="lazy" src="' . h($target) . '" alt="' . h($label) . '">';
            } else {
                $part = '<a href="' . h($target) . '" rel="nofollow ugc">' . h($label) . '</a>';
            }
        } elseif (str_starts_with($part, '**')) {
            $part = '<strong>' . h(substr($part, 2, -2)) . '</strong>';
        } else {
            $part = '<em>' . h(substr($part, 1, -1)) . '</em>';
        }
    }
    return implode('', $parts);
}

function renderMarkdown(string $text): array
{
    $html = '';
    $toc = [];
    $ids = [];
    $block = [];
    $type = '';
    $flush = static function () use (&$html, &$type, &$block): void {
        if ($block !== [] || $type === 'code') {
            $html .= match ($type) {
                'code' => '<pre><code>' . h(implode("\n", $block)) . '</code></pre>',
                'ul', 'ol' => '<' . $type . '><li>' . implode('</li><li>', array_map('inline', $block)) . '</li></' . $type . '>',
                'quote' => '<blockquote>' . inline(implode(' ', $block)) . '</blockquote>',
                default => '<p>' . inline(implode(' ', $block)) . '</p>',
            };
        }
        $type = '';
        $block = [];
    };

    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        if (str_starts_with($line, '```')) {
            $wasCode = $type === 'code';
            $flush();
            if (!$wasCode) {
                $type = 'code';
            }
        } elseif ($type === 'code') {
            $block[] = $line;
        } elseif (trim($line) === '') {
            $flush();
        } elseif (preg_match('/^(#{1,5})\s+(.+)$/u', $line, $m)) {
            $flush();
            $level = strlen($m[1]) + 1;
            $root = 'section-' . (slug($m[2]) ?: 'heading');
            $id = $root;
            for ($suffix = 2; isset($ids[$id]); $suffix++) {
                $id = $root . '-' . $suffix;
            }
            $ids[$id] = true;
            $toc[] = [$id, $m[2], $level];
            $html .= '<h' . $level . ' id="' . h($id) . '">' . inline($m[2]) . '</h' . $level . '>';
        } elseif (preg_match('/^(-{3,}|\*{3,})\s*$/', $line)) {
            $flush();
            $html .= '<hr>';
        } else {
            $newType = 'paragraph';
            foreach (['ul' => '/^[-*+]\s+(.+)$/u', 'ol' => '/^\d+\.\s+(.+)$/u', 'quote' => '/^>\s?(.*)$/u'] as $kind => $pattern) {
                if (preg_match($pattern, $line, $m)) {
                    $newType = $kind;
                    $line = $m[1];
                    break;
                }
            }
            if ($newType !== $type) {
                $flush();
                $type = $newType;
            }
            $block[] = $line;
        }
    }
    $flush();
    return [$html, $toc];
}

function seed(): array
{
    $state = [
        'settings' => ['name' => 'KioWiki', 'description' => 'A shared space to collect, connect, and grow what you know.',
            'registration' => true, 'editors_only' => true, 'private' => false, 'verification' => false, 'home' => 'welcome'],
        'users' => [], 'pages' => [], 'tokens' => [], 'limits' => [], 'audit' => [],
    ];
    $examples = [
        ['Welcome', 'explore', 'Start here and learn how this wiki is organized.', <<<'MARKDOWN'
KioWiki is a collaborative knowledge base. Use it to document a project, build a community reference, or keep research in one searchable place.

# Get started
- Browse the **article directory** to see the pages in this wiki.
- Search for a title, phrase, or topic from anywhere.
- Open [[Writing guide]] to learn how to format and connect articles.

# Explore the wiki
Read [[Dinosaurs]] for an example article with sections, topics, and an edit history. Every article has space for a discussion, and signed-in members can watch pages to follow changes.
MARKDOWN],
        ['Writing guide', 'guides', 'Learn the Markdown and wiki-link syntax used by the editor.', <<<'MARKDOWN'
Write headings, lists, and links in plain text. Use Preview to see the same rendering readers will see.

# Formatting
- Wrap text with two asterisks for **bold** or one for *emphasis*.
- Start a line with a hash followed by a space to create a heading.
- Start a line with a dash followed by a space for a list item.
- Use backticks for inline code, or three backticks on their own lines for a code block.

# Connecting articles
Use double square brackets around an article title. For example, `[[Dinosaurs]]` creates a link to [[Dinosaurs]]. Add topics to group related articles.

# Edit history
Saving creates a new revision. The history shows who saved each revision and when, and editors can restore a previous revision.
MARKDOWN],
        ['Dinosaurs', 'science, paleontology', 'An introduction to the diverse vertebrates that include living birds.', <<<'MARKDOWN'
Dinosaurs are a group of vertebrates that first appeared during the Triassic Period. They occupied a wide range of ecological niches, from small feathered predators to large plant-eating sauropods.

# Diversity
Paleontologists study bones, eggs, footprints, and other fossils to understand how dinosaurs moved, fed, and reproduced. Some dinosaurs lived in herds, while others had adaptations for hunting or scavenging.

# Living dinosaurs
Modern birds descend from theropod dinosaurs. The other dinosaur lineages went extinct about 66 million years ago, near the end of the Cretaceous Period.

# Further reading
- [Natural History Museum dinosaurs](https://www.nhm.ac.uk/discover/dinosaurs.html)
MARKDOWN],
    ];
    foreach ($examples as [$title, $tags, $summary, $body]) {
        $key = slug($title);
        $state['pages'][$key] = newPage($title, $summary, explode(', ', $tags), $body, 'KioWiki', 'Initial article');
    }
    return $state;
}

function newPage(string $title, string $summary, array $tags, string $body, string $author, string $note): array
{
    return ['revisions' => [compact('title', 'summary', 'tags', 'body', 'author', 'note') + ['at' => time()]],
        'discussion' => [], 'deleted' => false, 'locked' => false];
}

function latest(array $page): array
{
    return $page['revisions'][count($page['revisions']) - 1];
}

function filterPages(array $state, string $query, string $tag = ''): array
{
    $found = [];
    $score = [];
    $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($state['pages'] as $key => $page) {
        if ($page['deleted']) {
            continue;
        }
        $revision = latest($page);
        if ($tag !== '' && !in_array($tag, $revision['tags'], true)) {
            continue;
        }
        $found[$key] = $revision;
        $score[$key] = 0;
        foreach ($terms as $term) {
            if (stripos($revision['title'], $term) !== false) {
                $score[$key] += 3;
            } elseif (stripos($revision['summary'] . ' ' . $revision['body'] . ' ' . implode(' ', $revision['tags']), $term) !== false) {
                $score[$key]++;
            } else {
                unset($found[$key]);
                break;
            }
        }
    }
    uksort($found, static fn($a, $b) => ($score[$b] <=> $score[$a]) ?: strcasecmp($found[$a]['title'], $found[$b]['title']));
    return $found;
}

function editable(?array $user, array $state, ?array $page = null): bool
{
    return $user !== null && $user['verified'] && ($user['role'] === 'admin'
        || (!($page['locked'] ?? false) && ($user['role'] === 'editor' || !$state['settings']['editors_only'])));
}
