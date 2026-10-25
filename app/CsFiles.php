<?php
declare(strict_types=1);

/**
 * Files attached to construction records (site photos, evidence, drawings, signed documents). They are kept under
 * the company's own storage folder, which the web server never serves; cs/file.php sends one only to a logged-in
 * user who may see the project it belongs to.
 */
final class CsFiles
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'dwg', 'dxf', 'zip', 'ppt', 'pptx'];

    public static function dir(): string
    {
        $d = Tenant::storage('construction');
        Master::protectDir($d);
        return $d;
    }

    /**
     * Saves the uploaded files of one form field (single or multiple) and records them as attachments.
     * Returns the ids of the attachments made.
     */
    public static function attach(string $field, string $entityType, int $entityId, ?int $projectId, string $caption = ''): array
    {
        $files = $_FILES[$field] ?? null;
        if (!$files || empty($files['name'])) {
            return [];
        }
        $list = [];
        if (is_array($files['name'])) {
            foreach ($files['name'] as $i => $n) {
                $list[] = ['name' => $n, 'tmp' => $files['tmp_name'][$i], 'err' => $files['error'][$i], 'size' => $files['size'][$i]];
            }
        } else {
            $list[] = ['name' => $files['name'], 'tmp' => $files['tmp_name'], 'err' => $files['error'], 'size' => $files['size']];
        }
        $ids = [];
        foreach ($list as $f) {
            if ($f['err'] === UPLOAD_ERR_NO_FILE || $f['name'] === '') {
                continue;
            }
            $saved = self::store($f['tmp'], (string)$f['name'], (int)$f['err'], (int)$f['size']);
            $ids[] = DB::insert('cs_attachments', ['entity_type' => $entityType, 'entity_id' => $entityId, 'project_id' => $projectId,
                'file_path' => $saved['path'], 'file_name' => $saved['name'], 'mime' => $saved['mime'], 'file_size' => $saved['size'],
                'caption' => $caption !== '' ? mb_substr($caption, 0, 255) : null, 'uploaded_by' => Auth::id()]);
        }
        return $ids;
    }

    /** Checks and moves one uploaded file into storage; returns [path (relative), name, mime, size] */
    public static function store(string $tmp, string $name, int $err = UPLOAD_ERR_OK, int $size = 0): array
    {
        if ($err !== UPLOAD_ERR_OK) {
            fail('The file ' . $name . ' was not received (upload error ' . $err . '). It may be larger than the server allows.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED, true)) {
            fail('Files of type .' . $ext . ' cannot be attached. Allowed: ' . implode(', ', self::ALLOWED) . '.');
        }
        $size = $size ?: (int)@filesize($tmp);
        if ($size > self::MAX_BYTES) {
            fail('The file ' . $name . ' is larger than 15 MB.');
        }
        $sub = date('Y/m');
        $dir = self::dir() . '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $stored = bin2hex(random_bytes(10)) . '.' . $ext;
        $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dir . '/' . $stored) : @copy($tmp, $dir . '/' . $stored);
        if (!$ok) {
            fail('The file could not be saved. Make the storage folder writable by the web server.');
        }
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : '';
        return ['path' => $sub . '/' . $stored, 'name' => mb_substr(preg_replace('/[^\w .()-]/u', '_', $name), 0, 190), 'mime' => $mime ?: 'application/octet-stream', 'size' => $size];
    }

    public static function list(string $entityType, int $entityId): array
    {
        return DB::all('SELECT a.*, u.full_name AS uploader FROM cs_attachments a LEFT JOIN users u ON u.id = a.uploaded_by
                         WHERE a.entity_type = :t AND a.entity_id = :e ORDER BY a.id', ['t' => $entityType, 'e' => $entityId]);
    }

    public static function url(int $attachmentId, bool $inline = true): string
    {
        return url('file.cs', ['id' => $attachmentId] + ($inline ? ['inline' => 1] : []));
    }

    public static function isImage(array $a): bool
    {
        return str_starts_with((string)$a['mime'], 'image/');
    }

    /** The attachments of a record as thumbnails and links, with an upload box when allowed */
    public static function panel(string $entityType, int $entityId, bool $canAdd, string $title = 'Attachments'): string
    {
        $files = self::list($entityType, $entityId);
        $out = '<div class="card"><div class="card-header">' . h($title) . ' <span class="cs-note">' . count($files) . '</span></div><div class="card-body">';
        if ($files) {
            $out .= '<div class="d-flex flex-wrap gap-2 mb-2">';
            foreach ($files as $f) {
                $out .= self::isImage($f)
                    ? '<a href="' . h(self::url((int)$f['id'])) . '" target="_blank" rel="noopener" title="' . h($f['caption'] ?: $f['file_name']) . '"><img src="' . h(self::url((int)$f['id'])) . '" alt="' . h($f['caption'] ?: $f['file_name']) . '" style="width:96px;height:72px;object-fit:cover;border-radius:6px;box-shadow:var(--gs-plate)"></a>'
                    : '<a class="btn btn-outline-secondary btn-sm" href="' . h(self::url((int)$f['id'], false)) . '"><i class="bi bi-paperclip"></i> ' . h($f['file_name']) . '</a>';
            }
            $out .= '</div>';
        } else {
            $out .= '<p class="cs-note mb-2">No files yet.</p>';
        }
        if ($canAdd) {
            $out .= '<form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">' . csrf_field()
                . '<input type="hidden" name="act" value="attach"><input class="form-control form-control-sm" style="max-width:280px" type="file" name="files[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.dwg,.dxf,.zip">'
                . '<input class="form-control form-control-sm" style="max-width:200px" name="caption" placeholder="Caption"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-upload"></i> Upload</button></form>';
        }
        return $out . '</div></div>';
    }
}
