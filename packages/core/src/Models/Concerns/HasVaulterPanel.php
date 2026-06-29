<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Panels\Panel;
use Vaulter\Panels\PanelRegistry;

/**
 * Makes a concrete per-panel model panel-aware (D13).
 *
 * A default-panel model (Vaulter\Models\*) resolves its table through the shared
 * v_* schema and its key strategy through config('vaulter.ids.default'). That is
 * correct for the shipped default panel, but a PREFIXED panel (own-tables mode,
 * D12) needs CONCRETE per-panel models so a queued job / broadcast / Scout
 * rehydrate to the right table — not a runtime $table footgun.
 *
 * A consumer model opts in by declaring the panel and entity it belongs to:
 *
 *     final class ProjectNode extends Vaulter\Models\Node
 *     {
 *         use HasVaulterPanel;
 *
 *         protected string $vaulterPanel = 'project';
 *         protected string $vaulterEntity = 'nodes';
 *     }
 *
 * Then getTable() resolves to app(PanelRegistry)->get('project')->table('nodes')
 * (e.g. project_drive_nodes) and the key strategy comes from the same panel when
 * it has own tables, overriding HasVaulterKeys's global default. When a panel is
 * not declared (empty string) the concern is inert and behaviour falls back to
 * the inherited default-panel resolution.
 *
 * @mixin Model
 *
 * @property string $vaulterPanel
 * @property string $vaulterEntity
 */
trait HasVaulterPanel
{
    /**
     * Resolve the physical table from the panel (D13) instead of the shared
     * default schema. Falls back to the parent resolution when no panel is
     * declared.
     */
    public function getTable(): string
    {
        if ($this->vaulterPanelName() === '') {
            return parent::getTable();
        }

        return $this->vaulterPanel()->table($this->vaulterEntityKey());
    }

    /**
     * Resolve the key strategy from the declared panel (D13) — but only in
     * own-tables mode, since a per-panel strategy is incoherent on the shared
     * schema (H.1). On the shared schema, or when no panel is declared, fall back
     * to the global strategy.
     */
    protected function vaulterIdStrategyKey(): string
    {
        if ($this->vaulterPanelName() === '') {
            return parent::vaulterIdStrategyKey();
        }

        $panel = $this->vaulterPanel();

        if (!$panel->hasOwnTables()) {
            return parent::vaulterIdStrategyKey();
        }

        return $panel->idStrategyKey();
    }

    /**
     * The resolved Panel value object for this model's declared panel.
     */
    protected function vaulterPanel(): Panel
    {
        return app(PanelRegistry::class)->get($this->vaulterPanelName());
    }

    /**
     * The declared panel name, or an empty string when none is set (concern is
     * inert).
     */
    protected function vaulterPanelName(): string
    {
        return property_exists($this, 'vaulterPanel') ? $this->vaulterPanel : '';
    }

    /**
     * The base entity key this model maps to (storages|nodes|files|…), used to
     * look up the panel table.
     */
    protected function vaulterEntityKey(): string
    {
        return property_exists($this, 'vaulterEntity') ? $this->vaulterEntity : '';
    }
}
