<?php

// Read-only live contract check. No tokens, customer data, or production writes.
$base = $argv[1] ?? 'https://hero-kid.com';
if (! in_array($base, ['https://hero-kid.com', 'http://127.0.0.1:8108'], true)) {
    throw new RuntimeException('Unsupported check target.');
}
$expected = ['id', 'type', 'sku', 'name', 'description', 'price', 'currency', 'stock', 'url', 'image', 'updated_at', 'rag_text'];
$report = [];
$articles = [];
foreach (['catalog', 'guide'] as $feed) {
    $ids = [];
    $counts = [];
    $pages = 0;
    $slowest = 0;
    $longest = 0;
    $url = $base.'/api/feeds/robodesk/'.$feed;
    do {
        if (++$pages > 1000) {
            throw new RuntimeException('Pagination did not terminate.');
        }
        $start = microtime(true);
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $mime = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $slowest = max($slowest, microtime(true) - $start);
        if ($body === false || $status !== 200 || ! str_contains((string) $mime, 'application/json')) {
            throw new RuntimeException($feed.' page '.$pages.' HTTP '.$status);
        }
        $page = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! isset($page['data']) || ! array_key_exists('next_page_url', $page) || $page['per_page'] !== 50) {
            throw new RuntimeException('Invalid pagination.');
        }
        foreach ($page['data'] as $item) {
            if (array_keys($item) !== $expected || isset($ids[$item['id']])) {
                throw new RuntimeException('Fields or duplicate ID: '.$item['id']);
            }
            $ids[$item['id']] = true;
            $articles[$item['id']] = $item;
            $counts[$item['type']] = ($counts[$item['type']] ?? 0) + 1;
            if (preg_match('/تنبيه|تعارض|لا تؤكد|المصدر|تاريخ التحقق|بتختار 1 قصص|قصص قصص|<[^>]+>/u', $item['rag_text'])) {
                throw new RuntimeException('Article requires content correction: '.$item['id']);
            }
            if (mb_strlen($item['description']) > 1000) {
                throw new RuntimeException('Description too long: '.$item['id']);
            }
            if ($feed === 'catalog') {
                if (! in_array($item['type'], ['story', 'product', 'package'], true) || ! is_numeric($item['price']) || ! in_array($item['stock'], ['in_stock', 'out_of_stock'], true)) {
                    throw new RuntimeException('Invalid catalog item.');
                }
            } else {
                if (! in_array($item['type'], ['guide', 'index'], true)) {
                    throw new RuntimeException('Invalid guide type.');
                }
                foreach (['sku', 'price', 'stock', 'image'] as $field) {
                    if ($item[$field] !== null) {
                        throw new RuntimeException('Invalid guide field.');
                    }
                }
            }
            $longest = max($longest, mb_strlen($item['rag_text']));
        }
        $url = $page['next_page_url'];
        if ($url !== null) {
            if (! preg_match('~^https://hero-kid\.com/api/feeds/robodesk/'.$feed.'\?page=\d+$~', $url)) {
                throw new RuntimeException('Unexpected next page URL.');
            }
            $url = str_replace('https://hero-kid.com', $base, $url);
        }
    } while ($url !== null);
    if (count($ids) !== $page['total']) {
        throw new RuntimeException('Total mismatch.');
    }
    $report[$feed] = ['counts' => $counts, 'total' => count($ids), 'pages' => $pages, 'last_next_page_url' => null,
        'slowest_page_seconds' => round($slowest, 3), 'longest_rag_characters' => $longest];
}
$gift = $articles['package:bak-hdy-hyrokyd'] ?? null;
if ($gift) {
    $price = (float) $gift['price'];
    if (floor($price) !== $price) {
        throw new RuntimeException('Gift package still has a fractional price.');
    }
    $mentions = 0;
    foreach ($articles as $item) {
        if ($item['type'] !== 'story' || ! str_contains($item['rag_text'], '«'.$gift['name'].'»')) {
            continue;
        }
        if (! preg_match('/«'.preg_quote($gift['name'], '/').'»[^\n]*? بـ ([0-9.]+) ج\.م/u', $item['rag_text'], $match) || (float) $match[1] !== $price) {
            throw new RuntimeException('Gift price differs in story: '.$item['id']);
        }
        $mentions++;
    }
    $saving = null;
    if (preg_match('/بدل ([0-9.]+) ج\.م لو اتشروا منفصلين — توفير ([0-9.]+) ج\.م/u', $gift['rag_text'], $match)) {
        $saving = (float) $match[2];
        if ((int) round(((float) $match[1] - $price) * 100) !== (int) round($saving * 100)) {
            throw new RuntimeException('Gift saving does not equal components minus price.');
        }
    }
    $report['gift_package'] = ['price' => $price, 'saving' => $saving, 'story_mentions_checked' => $mentions];
}
$report['activity_index_articles'] = count(array_filter(array_keys($articles), fn ($id) => str_starts_with($id, 'index:activity-books-')));
if (array_filter(array_keys($articles), fn ($id) => str_starts_with($id, 'index:activities-age-'))) {
    throw new RuntimeException('Old separate activity-age indexes are still served.');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
