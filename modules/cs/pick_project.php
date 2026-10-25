<?php
declare(strict_types=1);

// The project chosen in the top bar: opens its workspace (or forgets the choice)
$id = (int)g('id');
if ($id && CsProject::canSee($id)) {
    $_SESSION['cs_project'] = $id;
    redirect(url('proj.view', ['id' => $id]));
}
unset($_SESSION['cs_project']);
redirect(url('proj.center'));
