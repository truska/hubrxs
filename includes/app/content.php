<?php
require_once __DIR__ . '/auth.php';

function hub_content_page_keys(): array {
  return [
    'privacy_policy' => ['title' => 'Privacy Policy', 'path' => '/privacy-policy.php', 'slug' => 'privacy-policy'],
    'cookie_policy' => ['title' => 'Cookie Policy', 'path' => '/cookie-policy.php', 'slug' => 'cookie-policy'],
    'terms' => ['title' => 'Terms of Use', 'path' => '/terms.php', 'slug' => 'terms'],
    'accessibility' => ['title' => 'Accessibility', 'path' => '/accessibility.php', 'slug' => 'accessibility'],
  ];
}

function hub_content_page_defaults(string $pageKey): array {
  $pages = hub_content_page_keys();
  $pageDef = $pages[$pageKey] ?? [];
  $title = (string) ($pageDef['title'] ?? 'Content Page');
  $defaultBodies = [
    'privacy_policy' => 'How RxSource handles personal information for Hub users and website visitors.',
    'cookie_policy' => 'Information about cookies and similar technologies used by the Hub.',
    'terms' => 'General terms for accessing and using the RxSource Hub.',
    'accessibility' => 'Accessibility information and contact route for support.',
  ];
  $summary = (string) ($defaultBodies[$pageKey] ?? '');
  return [
    'page_key' => $pageKey,
    'slug' => (string) ($pageDef['slug'] ?? hub_content_slugify($title)),
    'title' => $title,
    'meta_title' => $title,
    'meta_description' => $summary,
    'canonical_url' => '',
    'robots' => 'index,follow',
    'body' => $summary,
    'published' => 1,
    'updated_at' => null,
  ];
}

function hub_content_slugify(string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
  $value = trim($value, '-');
  return $value !== '' ? $value : 'content-page';
}

function hub_content_page_table_ready(): bool {
  return hub_table_exists('hub_content_page');
}

function hub_content_page_get(string $pageKey): array {
  global $pdo, $DB_OK;

  $fallback = hub_content_page_defaults($pageKey);
  if (!$DB_OK || !($pdo instanceof PDO) || !hub_content_page_table_ready()) {
    return $fallback;
  }

  $stmt = $pdo->prepare('SELECT * FROM hub_content_page WHERE page_key = :page_key LIMIT 1');
  $stmt->execute([':page_key' => $pageKey]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$row) {
    return $fallback;
  }

  return array_merge($fallback, $row);
}

function hub_content_page_save(string $pageKey, array $data, ?int $modifiedBy = null): void {
  global $pdo, $DB_OK;

  if (!$DB_OK || !($pdo instanceof PDO) || !hub_content_page_table_ready()) {
    throw new RuntimeException('Content page table is not available.');
  }

  $fallback = hub_content_page_defaults($pageKey);
  $title = trim((string) ($data['title'] ?? ''));
  $slugInput = trim((string) ($data['slug'] ?? ''));
  $slug = $slugInput === '' ? (string) $fallback['slug'] : hub_content_slugify($slugInput);
  if ($title === '') {
    $title = (string) $fallback['title'];
  }
  if ($slug === '') {
    $slug = (string) $fallback['slug'];
  }
  $robots = trim((string) ($data['robots'] ?? 'index,follow'));
  if (!in_array($robots, ['index,follow', 'noindex,follow'], true)) {
    $robots = 'index,follow';
  }

  $stmt = $pdo->prepare(
    'INSERT INTO hub_content_page
      (page_key, slug, title, meta_title, meta_description, canonical_url, robots, body, published, modified_by, updated_at, created, modified)
     VALUES
      (:page_key, :slug, :title, :meta_title, :meta_description, :canonical_url, :robots, :body, :published, :modified_by, NOW(), NOW(), NOW())
     ON DUPLICATE KEY UPDATE
      slug = VALUES(slug),
      title = VALUES(title),
      meta_title = VALUES(meta_title),
      meta_description = VALUES(meta_description),
      canonical_url = VALUES(canonical_url),
      robots = VALUES(robots),
      body = VALUES(body),
      published = VALUES(published),
      modified_by = VALUES(modified_by),
      updated_at = NOW(),
      modified = NOW()'
  );
  $stmt->execute([
    ':page_key' => $pageKey,
    ':slug' => $slug,
    ':title' => $title,
    ':meta_title' => trim((string) ($data['meta_title'] ?? $title)),
    ':meta_description' => trim((string) ($data['meta_description'] ?? '')),
    ':canonical_url' => trim((string) ($data['canonical_url'] ?? '')),
    ':robots' => $robots,
    ':body' => trim((string) ($data['body'] ?? '')),
    ':published' => !empty($data['published']) ? 1 : 0,
    ':modified_by' => $modifiedBy,
  ]);
}

function hub_content_plain_text_to_html(string $body): string {
  $blocks = preg_split('/\R{2,}/', trim($body)) ?: [];
  $html = '';
  foreach ($blocks as $block) {
    $block = trim($block);
    if ($block === '') {
      continue;
    }
    $html .= '<p>' . nl2br(hub_h($block), false) . '</p>';
  }
  return $html;
}

function hub_content_sanitize_html(string $html): string {
  $blockedTags = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'];
  $allowedTags = [
    'p' => [],
    'br' => [],
    'h2' => [],
    'h3' => [],
    'h4' => [],
    'strong' => [],
    'b' => [],
    'em' => [],
    'i' => [],
    'ul' => [],
    'ol' => [],
    'li' => [],
    'blockquote' => [],
    'hr' => [],
    'a' => ['href', 'title', 'target', 'rel'],
  ];

  $doc = new DOMDocument('1.0', 'UTF-8');
  $previous = libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  libxml_use_internal_errors($previous);

  $cleanNode = function (DOMNode $node) use (&$cleanNode, $doc, $allowedTags, $blockedTags): ?DOMNode {
    if ($node instanceof DOMText) {
      return $doc->createTextNode($node->nodeValue ?? '');
    }
    if (!($node instanceof DOMElement)) {
      return null;
    }

    $tag = strtolower($node->tagName);
    if (in_array($tag, $blockedTags, true)) {
      return null;
    }

    if (!array_key_exists($tag, $allowedTags)) {
      $fragment = $doc->createDocumentFragment();
      foreach ($node->childNodes as $child) {
        $cleanChild = $cleanNode($child);
        if ($cleanChild) {
          $fragment->appendChild($cleanChild);
        }
      }
      return $fragment;
    }

    $clean = $doc->createElement($tag);
    foreach ($allowedTags[$tag] as $attr) {
      if (!$node->hasAttribute($attr)) {
        continue;
      }
      $value = trim($node->getAttribute($attr));
      if ($attr === 'href') {
        $lower = strtolower($value);
        if (!preg_match('/^(https?:|mailto:|tel:|\/|#)/', $lower)) {
          continue;
        }
      }
      if ($attr === 'target' && !in_array($value, ['_blank', '_self'], true)) {
        continue;
      }
      $clean->setAttribute($attr, $value);
    }
    if ($tag === 'a' && $clean->getAttribute('target') === '_blank') {
      $clean->setAttribute('rel', 'noopener noreferrer');
    }

    foreach ($node->childNodes as $child) {
      $cleanChild = $cleanNode($child);
      if ($cleanChild) {
        $clean->appendChild($cleanChild);
      }
    }
    return $clean;
  };

  $output = '';
  $container = $doc->getElementsByTagName('div')->item(0);
  if ($container) {
    foreach ($container->childNodes as $child) {
      $cleanChild = $cleanNode($child);
      if ($cleanChild) {
        $output .= $doc->saveHTML($cleanChild);
      }
    }
  }

  return trim($output);
}

function hub_content_render_text(string $body): string {
  $body = trim($body);
  if ($body === '') {
    return '<p>This page is awaiting approved content. For questions, contact <a href="mailto:solutions@rxsource.com">solutions@rxsource.com</a>.</p>';
  }

  if ($body === strip_tags($body)) {
    return hub_content_plain_text_to_html($body);
  }

  $html = hub_content_sanitize_html($body);
  return $html !== '' ? $html : hub_content_plain_text_to_html(strip_tags($body));
}
