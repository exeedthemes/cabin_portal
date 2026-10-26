<?php
/** Keep references sequential across inventory, passenger reports and removed records. */
function af_next_reference_tag(PDO $pdo, string $prefix = 'ID'): string
{
    if (!in_array($prefix, ['ID', 'PP'], true)) {
        throw new InvalidArgumentException('Unsupported reference prefix.');
    }
    $query = $pdo->prepare("SELECT tag_no FROM items WHERE tag_no LIKE ?
        UNION ALL SELECT tag_no FROM pending_reports WHERE tag_no LIKE ?
        UNION ALL SELECT tag_no FROM deleted_items WHERE tag_no LIKE ?");
    $pattern = $prefix . '-%';
    $query->execute([$pattern, $pattern, $pattern]);
    $highest = 0;
    $width = 4;
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $tag) {
        if (preg_match('/^' . $prefix . '-(\d+)/i', (string) $tag, $match)) {
            $highest = max($highest, (int) $match[1]);
            $width = max($width, strlen($match[1]));
        }
    }
    return $prefix . '-' . str_pad((string) ($highest + 1), $width, '0', STR_PAD_LEFT);
}
