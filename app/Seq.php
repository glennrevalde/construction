<?php
declare(strict_types=1);

/** Sequential document numbers (invoice no., check no., asset no., ...). */
final class Seq
{
    public static function next(string $key): string
    {
        return DB::tx(function () use ($key) {
            $row = DB::row('SELECT prefix, next_no, pad FROM sequences WHERE seq_key = :k FOR UPDATE', ['k' => $key]);
            if (!$row) {
                DB::insert('sequences', ['seq_key' => $key, 'prefix' => '', 'next_no' => 1, 'pad' => 5]);
                $row = ['prefix' => '', 'next_no' => 1, 'pad' => 5];
            }
            DB::run('UPDATE sequences SET next_no = next_no + 1 WHERE seq_key = :k', ['k' => $key]);
            return $row['prefix'] . str_pad((string)$row['next_no'], (int)$row['pad'], '0', STR_PAD_LEFT);
        });
    }

    public static function peek(string $key): string
    {
        $row = DB::row('SELECT prefix, next_no, pad FROM sequences WHERE seq_key = :k', ['k' => $key]);
        if (!$row) {
            return '';
        }
        return $row['prefix'] . str_pad((string)$row['next_no'], (int)$row['pad'], '0', STR_PAD_LEFT);
    }
}
