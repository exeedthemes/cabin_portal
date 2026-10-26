<?php
require_once __DIR__ . '/categories.php';

/** Normalized words, without empty/wildcard probes or common report filler. */
function af_match_words(string $text): array {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($words, fn($word) => strlen($word) >= 3 && !in_array($word,
        ['the', 'and', 'with', 'lost', 'found', 'item', 'left', 'have', 'this', 'that', 'mein', 'eine', 'einen', 'und'], true))));
}

function af_match_flight(string $text): string {
    preg_match_all('/(?<![A-Z0-9])([A-Z0-9]{2,3})[ -]*(\d{1,4}[A-Z]?)(?![A-Z0-9])/i', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        if (!preg_match('/[A-Z]/i', $m[1])) continue;
        return strtoupper($m[1]) . (string) ((int) $m[2]) . (preg_match('/[A-Z]$/i', $m[2], $suffix) ? strtoupper($suffix[0]) : '');
    }
    return '';
}

/** Uses only the current station connection; no closed items or automatic decisions. */
function af_match_candidates(PDO $pdo): array {
    $items = $pdo->query("SELECT tag_no, item_description, other_info, pax_email, status, created_at FROM items WHERE status IN ('Found', 'Lost', 'Claimed') ORDER BY created_at DESC, tag_no ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Normalize inventory once when ranking multiple pending reports in one request.
    foreach ($items as &$item) {
        $item['_match_words'] = af_match_words((string) $item['item_description']);
        $item['_match_category'] = af_detect_item_category((string) $item['item_description']);
        $item['_match_flight'] = af_match_flight((string) $item['other_info']);
    }
    unset($item);
    return $items;
}

function af_rank_matches(array $report, array $candidates, int $limit = 5): array {
    $words = af_match_words((string) ($report['item_description'] ?? ''));
    $category = af_detect_item_category((string) ($report['item_description'] ?? ''));
    $flight = af_match_flight((string) ($report['flight_number'] ?? ''));
    $email = strtolower(trim((string) ($report['pax_email'] ?? '')));
    $matches = [];
    foreach ($candidates as $item) {
        if (!in_array($item['status'] ?? '', ['Found', 'Lost', 'Claimed'], true) || ($report['tag_no'] ?? '') === $item['tag_no']) continue;
        $itemWords = $item['_match_words'] ?? af_match_words((string) $item['item_description']);
        $shared = array_intersect($words, $itemWords);
        $similarity = count($shared) / max(1, count(array_unique(array_merge($words, $itemWords))));
        $sameCategory = $category !== 'other' && $category === ($item['_match_category'] ?? af_detect_item_category((string) $item['item_description']));
        $sameFlight = $flight !== '' && $flight === ($item['_match_flight'] ?? af_match_flight((string) ($item['other_info'] ?? '')));
        $sameEmail = $email !== '' && $email === strtolower(trim((string) ($item['pax_email'] ?? '')));
        // A shared flight alone is insufficient; category alone is also too broad.
        if (!$sameEmail && !($similarity >= 0.35) && !($sameFlight && ($sameCategory || count($shared) > 0))) continue;
        $score = (int) round(60 * $similarity) + ($sameCategory ? 10 : 0) + ($sameFlight ? 30 : 0) + ($sameEmail ? 45 : 0);
        $reasons = [];
        if ($shared) $reasons[] = 'Description: ' . implode(', ', array_slice(array_values($shared), 0, 4));
        if ($sameCategory) $reasons[] = 'Same category';
        if ($sameFlight) $reasons[] = 'Same flight';
        if ($sameEmail) $reasons[] = 'Same passenger email';
        $item['match_score'] = $score;
        $item['match_reasons'] = $reasons;
        $matches[] = $item;
    }
    usort($matches, fn($a, $b) => ($b['match_score'] <=> $a['match_score']) ?: strcmp($b['created_at'] ?? '', $a['created_at'] ?? '') ?: strcmp($a['tag_no'], $b['tag_no']));
    return array_slice($matches, 0, max(0, min(5, $limit)));
}
