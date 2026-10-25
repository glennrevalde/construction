<?php
declare(strict_types=1);

/** In-system notifications to particular users (approvals waiting, documents returned, ...). */
final class CsNotify
{
    /** @param int[] $userIds */
    public static function send(array $userIds, string $kind, string $title, string $body = '', ?string $link = null, ?int $projectId = null): void
    {
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
            DB::insert('cs_notifications', ['user_id' => $uid, 'project_id' => $projectId, 'kind' => mb_substr($kind, 0, 40),
                'title' => mb_substr($title, 0, 200), 'body' => $body !== '' ? mb_substr($body, 0, 500) : null, 'link' => $link ? mb_substr($link, 0, 255) : null]);
        }
    }

    public static function unread(int $userId): int
    {
        return (int)DB::value('SELECT COUNT(*) FROM cs_notifications WHERE user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    public static function forUser(int $userId, int $limit = 50): array
    {
        return DB::all('SELECT * FROM cs_notifications WHERE user_id = :u ORDER BY id DESC LIMIT ' . (int)$limit, ['u' => $userId]);
    }

    /** Users holding a role (by id) */
    public static function usersWithRole(int $roleId): array
    {
        return array_map('intval', DB::column("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id
                                                WHERE ur.role_id = :r AND u.status = 'active' AND u.approval_status = 'approved'", ['r' => $roleId]));
    }
}
