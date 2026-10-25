<?php
declare(strict_types=1);

/**
 * Company Codes: what an employee types on the join page to say which company they belong to.
 *
 * A code only ever answers "which company?". It never logs anyone in and never opens any data: the
 * employee still needs their own username and password, and (unless the company switched it off) the
 * Company Owner's approval. Codes are unique across all companies, never reused, and say nothing about
 * the company's database. A regenerated code retires the old one at once; people who already joined stay.
 *
 * Format: the initials of the company name (up to three letters), then five random characters from an
 * alphabet without look-alikes (no 0/O, 1/I/L): ABD7K2QX, shown as ABD-7K2QX. It can be typed with or
 * without the dash or spaces, in capitals or not.
 */
final class CompanyCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** The same message for every code that cannot be used, so nothing is given away */
    public const INVALID = 'Invalid or inactive Company Code. Please verify the code with your Company Owner.';

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** ABD-7K2QX: easier to read out and copy */
    public static function display(?string $code): string
    {
        $code = (string)$code;
        return strlen($code) > 5 ? substr($code, 0, -5) . '-' . substr($code, -5) : $code;
    }

    /** A new code that no company has ever had */
    public static function generate(string $companyName): string
    {
        $prefix = '';
        foreach (preg_split('/[^A-Za-z]+/', strtoupper($companyName)) ?: [] as $word) {
            if ($word !== '' && !in_array($word, ['THE', 'AND', 'OF', 'INC', 'CORP', 'CO', 'LTD', 'LLC'], true)) {
                $prefix .= $word[0];
            }
        }
        $prefix = preg_replace('/[^A-Z]/', '', substr($prefix, 0, 3));
        if (strlen($prefix) < 2) {
            $prefix = 'CO';
        }
        $m = Master::sql();
        for ($try = 0; $try < 50; $try++) {
            $rand = '';
            for ($i = 0; $i < 5; $i++) {
                $rand .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = $prefix . $rand;
            if (!$m->value('SELECT id FROM company_codes WHERE code = :c', ['c' => $code])) {
                return $code;
            }
        }
        throw new RuntimeException('A unique Company Code could not be made.');
    }

    /** Gives a company a new code and retires the one it had */
    public static function regenerate(int $companyId, string $by): string
    {
        $c = Master::company($companyId, true);
        if (!$c) {
            throw new RuntimeException('Company not found.');
        }
        $code = self::generate((string)$c['name']);
        Master::sql()->tx(function (Sql $m) use ($companyId, $code, $by, $c) {
            $m->run("UPDATE company_codes SET status = 'retired', retired_at = NOW() WHERE company_id = :c AND status <> 'retired'", ['c' => $companyId]);
            $m->insert('company_codes', ['company_id' => $companyId, 'code' => $code, 'status' => 'active',
                'expires_at' => null, 'created_by' => mb_substr($by, 0, 80)]);
        });
        Master::forget($companyId);
        return $code;
    }

    /** Stops (disabled) or restarts (active) joining with the current code, and sets or clears its expiry */
    public static function setState(int $companyId, string $status, ?string $expiresAt): void
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new RuntimeException('Unknown code status.');
        }
        Master::sql()->run("UPDATE company_codes SET status = :s, expires_at = :e WHERE company_id = :c AND status <> 'retired'",
            ['s' => $status, 'e' => $expiresAt, 'c' => $companyId]);
        Master::forget($companyId);
    }

    /**
     * The company a code lets an employee join, or null. The code has to be the company's current one,
     * switched on and not expired, and the company has to be active.
     */
    public static function resolve(string $code): ?array
    {
        $code = self::normalize($code);
        if ($code === '' || strlen($code) > 20) {
            return null;
        }
        $row = Master::sql()->row(
            "SELECT k.company_id FROM company_codes k JOIN companies c ON c.id = k.company_id
              WHERE k.code = :code AND k.status = 'active' AND (k.expires_at IS NULL OR k.expires_at > NOW())
                AND c.status = 'active'",
            ['code' => $code]
        );
        return $row ? Master::company((int)$row['company_id'], true) : null;
    }
}
