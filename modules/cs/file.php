<?php
declare(strict_types=1);

// Sends an attached file to a logged-in user who may see its project (storage is never served directly).
$a = DB::row('SELECT * FROM cs_attachments WHERE id = :id', ['id' => (int)g('id')]);
$rev = null;
if (!$a && g('rev')) {
    $rev = DB::row('SELECT r.*, d.project_id FROM cs_document_revisions r JOIN cs_documents d ON d.id = r.document_id WHERE r.id = :id', ['id' => (int)g('rev')]);
    if ($rev) {
        $a = ['file_path' => $rev['file_path'], 'file_name' => $rev['file_name'], 'mime' => $rev['mime'], 'project_id' => $rev['project_id']];
    }
}
if (!$a || !$a['file_path'] || ($a['project_id'] && !CsProject::canSee((int)$a['project_id']))) {
    http_response_code(404);
    exit('File not found.');
}
$path = realpath(CsFiles::dir() . '/' . $a['file_path']);
if (!$path || !str_starts_with($path, realpath(CsFiles::dir())) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
$inline = g('inline') === '1' && preg_match('#^(image/|application/pdf)#', (string)$a['mime']);
header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string)$a['file_name']) . '"');
readfile($path);
exit;
