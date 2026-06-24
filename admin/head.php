<?php
require_once __DIR__.'/../paths.php';
require_once __DIR__.'/embedmode.php';
?>
<head>
    <meta charset="utf-8" />
    <meta http-equiv="x-ua-compatible" content="ie=edge" />
    <title>YaAff</title>
    <meta name="description" content="Professional traffic routing, campaign analytics, and affiliate conversion management" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="shortcut icon" type="image/svg+xml" href="<?=get_cloaker_path()?>img/favicon.svg" />
    <?php include_once "css.php" ?>
    <?php include_once "scripts.php" ?>
    <?php if (yaaff_is_embed()): ?>
    <style>
        /* Chrome-free embed inside the modern SPA shell */
        body { background: #fff; }
        .all-content-wrapper { margin-top: 0 !important; padding-top: 16px !important; }
        .header-advance-area, .entity-nav-area, #updateOverlay { display: none !important; }
    </style>
    <?php endif; ?>
</head>
