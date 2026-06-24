<?php

/**
 * Shared admin page for any registered entity type (Phase 1).
 *
 * Thin per-entity pages (networks.php, sources.php, ...) set $entityType and
 * include this file. The list, the create/edit modal and all field rendering
 * are produced generically from the declarative schema (entityschemas.php) by
 * js/entityadmin.js.
 */

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/entityschemas.php';
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../auth/Auth.php';

$entityType = $entityType ?? '';
$schema = entity_schema($entityType);
if ($schema === null) {
    http_response_code(404);
    exit('Unknown entity type');
}
auth_require("$entityType.view");
$calDs = Dates::get_calend_dates();
$jsPath = get_cloaker_path() . 'js';
$jsFsPath = __DIR__ . '/js';
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . "/head.php" ?>
<body>
    <?php include __DIR__ . "/header.php" ?>
    <div class="all-content-wrapper">
        <div class="buttons-block">
            <h3 style="margin:0;color:#eee;"><i class="bi <?= htmlspecialchars($schema['icon']) ?>"></i> <?= htmlspecialchars($schema['title']) ?></h3>
            <div class="buttons-right">
                <button id="entityNew" class="btn btn-primary"><i class="bi bi-plus-circle-fill"></i> New <?= htmlspecialchars($schema['singular']) ?></button>
            </div>
        </div>
        <div id="entityListMsg" class="text-muted" style="margin-bottom:10px;"></div>
        <table class="table table-dark table-hover" id="entityTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Group</th>
                    <th>Updated</th>
                    <th style="width:120px;text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div id="entityFormModal" class="modal">
        <h4 id="entityFormTitle" style="color:#eee;"></h4>
        <form id="entityForm" autocomplete="off">
            <input type="hidden" name="id" />
            <div id="entityFormFields"></div>
            <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" rel="modal:close">Cancel</button>
                <button type="submit" class="btn btn-success">Save</button>
            </div>
            <div id="entityFormError" class="text-danger" style="margin-top:8px;"></div>
        </form>
    </div>

    <style>
        #entityFormModal { max-width: 640px; width: 90%; background: #1e1e1e; border-radius: 8px; padding: 24px; }
        #entityFormFields .form-group { margin-bottom: 14px; }
        #entityFormFields label { color: #ccc; display: block; margin-bottom: 4px; font-size: 14px; }
        #entityFormFields .field-help { color: #888; font-size: 12px; margin-top: 3px; }
        #entityTable td, #entityTable th { vertical-align: middle; }
        .entity-actions a { cursor: pointer; margin-left: 10px; color: #aaa; }
        .entity-actions a:hover { color: #fff; }
    </style>

    <script id="entitySchema" type="application/json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <script>window.ENTITY_TYPE = <?= json_encode($entityType) ?>;</script>
    <script src="<?= $jsPath ?>/entityadmin.js?v=<?= filemtime($jsFsPath . '/entityadmin.js') ?>"></script>
</body>
</html>
