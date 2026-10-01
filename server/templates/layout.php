<?php declare(strict_types=1);
/** @var \Closure $e @var string $title @var string $content */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?> - Maguari</title>
<link rel="stylesheet" href="/assets/maguari.css">
</head>
<body>
<main>
<?= $content ?>
</main>
</body>
</html>
