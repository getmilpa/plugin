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

namespace Milpa\Plugin\Event;

use Milpa\Events\CapabilityResolvedEvent;
use Milpa\Events\InterceptionSlot;
use Milpa\Events\KernelBootedEvent;
use Milpa\Events\PluginBootedEvent;
use Milpa\Events\PluginBootingEvent;
use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Plugin\Runtime\PluginsManager;

/**
 * Every event this package dispatches, declared once and next to the code that dispatches it.
 *
 * The names below are the constants {@see PluginsManager} hands to `dispatch()`: the declaration
 * and the dispatch read the SAME constant, so neither can drift from the other. The manager
 * declares this list to whichever dispatcher it resolves, provided that dispatcher implements
 * {@see \Milpa\Interfaces\Event\DeclaredEvents}; a dispatcher that does not is asked nothing
 * (greenhouse decisions/0228: the emitter is the authority on what events exist).
 *
 * As a {@see DeclaresEvents} holder it also answers one step earlier: `composer.json` names this class
 * under `extra.milpa.events`, so a host reading the installed manifests can declare these events on
 * behalf of a {@see PluginsManager} the running process never constructs.
 */
final class PluginEvents implements DeclaresEvents
{
    /** POST: the dependency-ordered plugin graph is final for this boot, before any plugin boots. */
    public const CAPABILITY_RESOLVED = 'capability.resolved';

    /** PRE: a plugin is about to boot; the payload carries an {@see InterceptionSlot} a listener may stop. */
    public const PLUGIN_BOOTING = 'plugin.booting';

    /** POST: a plugin's boot() returned without throwing. */
    public const PLUGIN_BOOTED = 'plugin.booted';

    /** POST: the plugin boot cycle is complete for this process. */
    public const KERNEL_BOOTED = 'kernel.booted';

    /** The payload key every lifecycle event carries its value object under. */
    public const SUBJECT_KEY = 'event';

    /** The payload key the interceptable event carries its {@see InterceptionSlot} under. */
    public const SLOT_KEY = 'slot';

    /**
     * The declarations of the lifecycle events, in the order one boot dispatches them.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: self::CAPABILITY_RESOLVED,
                dispatchedBy: PluginsManager::class,
                when: 'The dependency-ordered plugin graph is final for this boot, before any plugin boots.',
                subjectKey: self::SUBJECT_KEY,
                subjectType: CapabilityResolvedEvent::class,
                mutable: false,
                interceptable: false,
            ),
            new EventDeclaration(
                name: self::PLUGIN_BOOTING,
                dispatchedBy: PluginsManager::class,
                when: 'Right before a plugin\'s boot() runs; stopping the slot vetoes that boot.',
                subjectKey: self::SUBJECT_KEY,
                subjectType: PluginBootingEvent::class,
                mutable: false,
                interceptable: true,
            ),
            new EventDeclaration(
                name: self::PLUGIN_BOOTED,
                dispatchedBy: PluginsManager::class,
                when: 'Right after a plugin\'s boot() returned without throwing.',
                subjectKey: self::SUBJECT_KEY,
                subjectType: PluginBootedEvent::class,
                mutable: false,
                interceptable: false,
            ),
            new EventDeclaration(
                name: self::KERNEL_BOOTED,
                dispatchedBy: PluginsManager::class,
                when: 'The plugin boot cycle is complete for this process, whichever path produced it.',
                subjectKey: self::SUBJECT_KEY,
                subjectType: KernelBootedEvent::class,
                mutable: false,
                interceptable: false,
            ),
        ];
    }
}
