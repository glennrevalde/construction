<?php
declare(strict_types=1);

/**
 * Reads key details (vendor, date, amount) from a receipt.
 *
 * Works on text it can get without external services: text/e-mail files,
 * text-based PDFs, and the file name (e.g. "Petron_2026-09-01_1250.00.jpg").
 * For photos, plug an OCR service into ocrText() - the rest of the flow
 * (review queue, confirm, match or create) stays the same.
 */
final class ReceiptReader
{
    public static function read(string $path, string $originalName, string $mime): array
    {
        $text = $originalName . "\n" . self::fileText($path, $mime);
        return [
            'vendor_id'    => self::vendor($text),
            'receipt_date' => self::date($text),
            'amount'       => self::amount($text),
        ];
    }

    private static function fileText(string $path, string $mime): string
    {
        if (str_starts_with($mime, 'text/') || str_ends_with($path, '.eml') || str_ends_with($path, '.txt')) {
            return (string)file_get_contents($path, false, null, 0, 200000);
        }
        if ($mime === 'application/pdf') {
            $raw = (string)file_get_contents($path, false, null, 0, 2000000);
            $out = '';
            if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
                foreach ($m[1] as $stream) {
                    $data = @gzuncompress($stream);
                    if ($data === false) {
                        $data = $stream;
                    }
                    if (preg_match_all('/\((.*?)\)\s*Tj/s', $data, $tm)) {
                        $out .= implode(' ', $tm[1]) . "\n";
                    }
                }
            }
            return $out;
        }
        return self::ocrText($path);
    }

    /** Hook for an OCR service (not bundled). */
    private static function ocrText(string $path): string
    {
        return '';
    }

    private static function vendor(string $text): ?int
    {
        $norm = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $text));
        $best = null;
        $bestLen = 0;
        foreach (DB::all('SELECT id, name FROM vendors WHERE is_active = 1') as $v) {
            $first = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $v['name']));
            $first = trim(explode(' ', trim($first))[0] ?? '');
            if (strlen($first) >= 4 && str_contains($norm, $first) && strlen($first) > $bestLen) {
                $best = (int)$v['id'];
                $bestLen = strlen($first);
            }
        }
        return $best;
    }

    private static function date(string $text): ?string
    {
        if (preg_match('/(20\d{2})[-_.\/](\d{1,2})[-_.\/](\d{1,2})/', $text, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/(\d{1,2})[\/-](\d{1,2})[\/-](20\d{2})/', $text, $m) && checkdate((int)$m[1], (int)$m[2], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
        }
        return null;
    }

    private static function amount(string $text): ?float
    {
        // prefer a number after "total"; otherwise the largest money-looking number
        if (preg_match('/total[^0-9]{0,20}([0-9][0-9,]*\.\d{2})/i', $text, $m)) {
            return (float)str_replace(',', '', $m[1]);
        }
        if (preg_match_all('/(?<![\d-])([0-9]{1,3}(?:,[0-9]{3})*\.\d{2}|[0-9]+\.\d{2})(?!\d)/', $text, $m)) {
            $vals = array_map(fn($v) => (float)str_replace(',', '', $v), $m[1]);
            return $vals ? max($vals) : null;
        }
        return null;
    }
}
