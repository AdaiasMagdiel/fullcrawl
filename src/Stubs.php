<?php

namespace AdaiasMagdiel\FullCrawl;

class Stubs
{
    public static function getMigrationTemplate(string $name): string
    {
        return <<<PHP
<?php

/**
 * FullCrawl Migration: {$name}
 */
return [
    'up' => function(PDO \$pdo) {
        // \$pdo->exec("...");
    },
    'down' => function(PDO \$pdo) {
        // \$pdo->exec("...");
    }
];
PHP;
    }
}
