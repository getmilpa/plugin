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

namespace Milpa\Plugin\Runtime;

use Milpa\Attributes\PluginMetadata;
use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Ingest\AttributeLoader;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use Milpa\ValueObjects\Capability\CapabilityRequirement;

/**
 * Gate-and-order for plugin metadata arrays OUTSIDE the boot path: the same
 * one-resolution semantics {@see PluginsManager} boots with, packaged for CLI
 * consumers (deps/simulate) that used to lean on the deprecated
 * ContractResolver. A blocked graph throws with the report's first learnable
 * line; a resolvable graph returns the metadata resequenced to load order.
 */
final class MetadataGraphResolver
{
    /**
     * Gate `$metadataArrays` through one {@see GraphResolver} resolution and resequence them to
     * its `loadOrder`.
     *
     * @param list<array<string, mixed>> $metadataArrays name/version/type/provides/requires/suggests records
     *
     * @return list<array<string, mixed>> The same records, resequenced to the resolver's load order.
     *
     * @throws \RuntimeException         When the graph is blocked (message = learnable line).
     * @throws \InvalidArgumentException When a record is malformed and the resolver refuses to ingest
     *                                   it — e.g. a capability record with no contract version. It was
     *                                   not declared here until a caller caught it in the wild: the
     *                                   inspection operations were catching only `RuntimeException`,
     *                                   so a malformed manifest escaped as a trace instead of an
     *                                   answer. An undeclared throw is a contract that lies by
     *                                   omission.
     */
    public function order(array $metadataArrays): array
    {
        if ($metadataArrays === []) {
            return [];
        }

        $report = $this->resolveRecords($metadataArrays);

        if ($report->status === ResolutionStatus::Blocked) {
            throw new \RuntimeException(
                $report->firstLearnableLine()
                    ?? 'the architecture graph is blocked; the resolver reported no learnable error.'
            );
        }

        $byName = [];
        foreach ($metadataArrays as $record) {
            $byName[$record['name']] = $record;
        }

        $ordered = [];
        foreach ($report->loadOrder as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name) && isset($byName[$name])) {
                $ordered[] = $byName[$name];
            }
        }

        return count($ordered) === count($metadataArrays) ? $ordered : $metadataArrays;
    }

    /**
     * El MISMO análisis que {@see order()}, pero contestando en vez de lanzar.
     *
     * ── POR QUÉ HACÍA FALTA ─────────────────────────────────────────────────────────────────────
     *
     * `order()` corre en el arranque, y ahí lanzar es correcto: un grafo que no cierra no puede
     * producir un orden de carga, y seguir sería fingir. Lo que no era correcto es lo que se perdía en
     * el camino — el resolver produce un {@see ResolutionReport} COMPLETO (qué falta, qué choca, qué
     * se degrada, a qué lección lleva cada error) y de todo eso sólo sobrevivía la primera línea, como
     * mensaje de una excepción.
     *
     * Y se perdía justo cuando más se necesitaba: con el grafo roto la app no bootea, así que `coa` no
     * despacha, así que NINGUNA herramienta de diagnóstico corre. Medido en una app de ejemplo con una
     * capacidad sin proveedor: las quince herramientas del agente caídas, y una línea de error como
     * único dato. La diagnosis moría con el paciente.
     *
     * Esto es el mismo cálculo, disponible ANTES de bootear y sin bootear nada.
     *
     * @param list<array<string, mixed>> $metadataArrays name/version/type/provides/requires/suggests
     *
     * @throws \InvalidArgumentException cuando un registro está malformado y el resolver se niega a
     *                                   ingerirlo — eso no es un grafo que no cierra, es una entrada
     *                                   que no se puede leer, y confundirlos mandaría a alguien a
     *                                   buscar un proveedor que no era el problema
     */
    public function diagnose(array $metadataArrays): ResolutionReport
    {
        if ($metadataArrays === []) {
            // Una app sin plugins tiene un grafo que cierra por vacío, no uno roto. Contestar
            // `Blocked` aquí mandaría a alguien a buscar un proveedor faltante en una lista vacía.
            return new ResolutionReport(status: ResolutionStatus::Valid);
        }

        return $this->resolveRecords($metadataArrays);
    }

    /**
     * @param list<array<string, mixed>> $metadataArrays
     */
    private function resolveRecords(array $metadataArrays): ResolutionReport
    {
        $loader = new AttributeLoader();
        $manifests = [];
        $requirements = [];
        foreach ($metadataArrays as $record) {
            $metadata = new PluginMetadata(
                version: is_string($record['version'] ?? null) ? $record['version'] : '',
                author: is_string($record['author'] ?? null) ? $record['author'] : '',
                site: is_string($record['site'] ?? null) ? $record['site'] : '',
                name: is_string($record['name'] ?? null) ? $record['name'] : '',
                type: is_string($record['type'] ?? null) ? $record['type'] : '',
                provides: is_array($record['provides'] ?? null) ? array_values($record['provides']) : [],
                requires: is_array($record['requires'] ?? null) ? array_values($record['requires']) : [],
                suggests: is_array($record['suggests'] ?? null) ? array_values($record['suggests']) : [],
            );
            $manifests[] = $loader->fromMetadata($metadata);
            foreach ($metadata->requires as $entry) {
                $requirements[] = CapabilityRequirement::parse($entry);
            }
        }

        return (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: new HostProfile(name: 'host', version: '0.0.0', allowedLegacyContracts: ['*']),
            versionManifests: $manifests,
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: $requirements,
        ));
    }
}
