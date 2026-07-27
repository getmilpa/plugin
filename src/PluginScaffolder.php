<?php

/**
 * This file is part of Milpa Plugin — the GitHub-native plugin distribution
 * core of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin;

/**
 * Scaffolds a new plugin directory structure with milpa.json.
 *
 * Generates:
 *   plugins/{Name}/
 *   ├── milpa.json
 *   ├── {Name}.php
 *   ├── Controllers/
 *   ├── Services/
 *   ├── Entities/
 *   ├── Commands/
 *   ├── Interfaces/
 *   ├── Middleware/
 *   ├── Migrations/
 *   ├── Records/
 *   └── Resources/views/
 */
final class PluginScaffolder
{
    private string $pluginsPath;

    public function __construct(string $pluginsPath)
    {
        $this->pluginsPath = rtrim($pluginsPath, '/');
    }

    /**
     * Scaffold a new plugin.
     *
     * @param string $name   Plugin name in PascalCase (e.g., "MailPlugin")
     * @param string $type   Plugin type: Web, CLI, Mixed, Service
     * @param string $author Author name
     *
     * @return array{path: string, files: array<string>} Path to the created plugin and list of generated files
     *
     * @throws \RuntimeException If the plugin directory already exists
     */
    public function scaffold(string $name, string $type = 'Mixed', string $author = 'Milpa Team'): array
    {
        // Ensure name ends with "Plugin"
        if (!str_ends_with($name, 'Plugin')) {
            $name .= 'Plugin';
        }

        $pluginDir = $this->pluginsPath . '/' . $name;

        if (is_dir($pluginDir)) {
            throw new \RuntimeException("Plugin directory already exists: {$pluginDir}");
        }

        $namespace = "Milpa\\Plugins\\{$name}";
        $vendorName = $this->toKebabCase($name);
        $files = [];

        // Create directories
        $directories = [
            $pluginDir,
            $pluginDir . '/Controllers',
            $pluginDir . '/Services',
            $pluginDir . '/Entities',
            $pluginDir . '/Commands',
            $pluginDir . '/Interfaces',
            $pluginDir . '/Middleware',
            $pluginDir . '/Migrations',
            $pluginDir . '/Records',
            $pluginDir . '/Resources/views',
        ];

        foreach ($directories as $dir) {
            mkdir($dir, 0755, true);
        }

        // The canonical generator is the single manifest authority; the
        // scaffolder only feeds it the metadata the generated attribute will
        // carry. One deliberate override survives: the vendor name keeps the
        // scaffolder's kebab-case ("MailPlugin" → milpa/mail-plugin), where the
        // generator's own default is plain strtolower (pinned by its tests).
        $warnings = [];
        $manifest = PluginManifest::generateFromMetadata(
            [
                'name' => $name,
                'version' => '1.0.0',
                'author' => $author,
                'site' => 'https://milpa.dev',
                'type' => $type,
                'provides' => [],
                'requires' => [],
                'suggests' => [],
            ],
            $namespace,
            "{$name}.php",
            $warnings,
        );
        $manifest['name'] = "milpa/{$vendorName}";

        $manifestPath = $pluginDir . '/milpa.json';
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $files[] = 'milpa.json';

        // Generate main plugin class
        $pluginClass = $this->generatePluginClass($name, $namespace, $type, $author);
        file_put_contents($pluginDir . "/{$name}.php", $pluginClass);
        $files[] = "{$name}.php";

        return [
            'path' => $pluginDir,
            'files' => $files,
        ];
    }

    private function generatePluginClass(string $name, string $namespace, string $type, string $author): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Milpa\\Attributes\\PluginMetadata;
use Milpa\\Interfaces\\Di\\DIContainerInterface;
use Milpa\\Interfaces\\Plugin\\PluginInterface;
use Milpa\\Plugin\\PluginBase;

#[PluginMetadata(
    version: '1.0.0',
    author: '{$author}',
    site: 'https://milpa.dev',
    name: '{$name}',
    type: '{$type}',
    provides: [],
    requires: [],
    suggests: []
)]
class {$name} extends PluginBase implements PluginInterface
{
    public function __construct(DIContainerInterface \$container)
    {
        parent::__construct(\$container);
    }

    public function boot(): void
    {
        // Register services and load routes here
    }

    public function install(): void
    {
        \$this->log('[{$name}] Plugin installed');
    }

    public function uninstall(): void
    {
        \$this->log('[{$name}] Plugin uninstalled');
    }

    public function enable(): void
    {
        \$this->log('[{$name}] Plugin enabled');
    }

    public function disable(): void
    {
        \$this->log('[{$name}] Plugin disabled');
    }
}

PHP;
    }

    /**
     * Convert PascalCase to kebab-case.
     * "MailPlugin" → "mail-plugin"
     */
    private function toKebabCase(string $name): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name);
        return strtolower($result ?? $name);
    }
}
