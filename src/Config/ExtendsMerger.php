<?php
declare(strict_types=1);

namespace Letts\Config;

use Letts\Exceptions\ConfigException;

/**
 * Applies extends merge: template fields fill in missing dugdale
 * fields; scalars use dugdale-wins; lanes/labels do union with dugdale-wins
 * on collision (lanes) and dedup (labels). Returns a new Config with merged
 * dugdales; original templates left intact.
 */
final class ExtendsMerger
{
    public static function merge(Config $c): Config
    {
        $mergedDugdales = [];
        foreach ($c->dugdales as $d) {
            $mergedDugdales[] = $d->extends === '' ? $d : self::mergeOne($d, $c);
        }
        return new Config(
            auth: $c->auth, defaults: $c->defaults, selector: $c->selector,
            routes: $c->routes, aliases: $c->aliases, templates: $c->templates,
            dugdales: $mergedDugdales,
        );
    }

    private static function mergeOne(Dugdale $d, Config $c): Dugdale
    {
        $t = $c->templates[$d->extends] ?? null;
        if ($t === null) {
            throw new ConfigException("dugdales[].id={$d->id}: unknown template: {$d->extends}");
        }

        // labels: REPLACE when the dugdale specifies its own, else inherit the
        // template's (Go extends.go — array fields replace, not
        // union). Note: a dugdale that explicitly sets `labels: []` cannot be
        // distinguished from "unset" at the DTO level and will inherit.
        $labels = $d->labels !== [] ? $d->labels : $t->labels;

        // lanes: start from template, drop nullified (null = delete),
        // then overlay dugdale lanes (dugdale wins on collision).
        $lanes = $t->lanes;
        foreach ($d->nullifiedLanes as $name) {
            unset($lanes[$name]);
        }
        foreach ($d->lanes as $name => $cfg) {
            $lanes[$name] = $cfg;
        }

        // runtime: deep-merge field-by-field so a partial dugdale override still
        // inherits the template's other fields (Go deep-merges; this is daemon-
        // side config the client doesn't consume, but we keep fidelity). The
        // bool validate_mission_file can't be told apart from unset at the DTO
        // level (Go uses a YAML AST pass) — inherit the template's only when the
        // dugdale supplies no runtime override at all.
        $dr = $d->runtime;
        $tr = $t->runtime;
        $runtime = new Runtime(
            missionPathTemplate: $dr->missionPathTemplate !== '' ? $dr->missionPathTemplate : $tr->missionPathTemplate,
            commandTemplate: $dr->commandTemplate !== [] ? $dr->commandTemplate : $tr->commandTemplate,
            validateMissionFile: $dr == new Runtime() ? $tr->validateMissionFile : $dr->validateMissionFile,
        );

        return new Dugdale(
            id: $d->id,
            host: $d->host,
            port: $d->port,
            url: $d->url,
            proxy: $d->proxy !== '' ? $d->proxy : $t->proxy,
            extends: $d->extends,
            missionDir: $d->missionDir !== '' ? $d->missionDir : $t->missionDir,
            runtime: $runtime,
            labels: $labels,
            token: $d->token !== '' ? $d->token : $t->token,
            adminToken: $d->adminToken !== '' ? $d->adminToken : $t->adminToken,
            execToken: $d->execToken !== '' ? $d->execToken : $t->execToken,
            lanes: $lanes,
            nullifiedLanes: $d->nullifiedLanes,
        );
    }
}
