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

namespace Milpa\Plugin\Tests\Fixtures\MigFixtureBroken\Migrations;

/**
 * Test fixture migration shaped like the legacy migrations a host may carry: matches the
 * Version_*.php glob but does NOT implement PluginMigrationInterface. The
 * legacy runner discarded this in silence (the invisible-migration bug);
 * the v2 runner refuses it loudly instead. Lives in its own directory so it
 * only poisons the one test that targets it.
 */
class Version_Broken
{
    public bool $ran = false;

    public function up(object $c, object $l): void
    {
        $this->ran = true;
    }

    public function down(object $c, object $l): void
    {
    }
}
