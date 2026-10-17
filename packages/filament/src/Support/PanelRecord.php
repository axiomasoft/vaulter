<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;

/**
 * The model class the panel resources declare to Filament. Filament resources need a model
 * class for their labels, authorization and routing; the panel's rows are snapshots read
 * through authorized drive listings, so this class carries no table and nothing is ever
 * queried through it.
 */
#[Internal]
final class PanelRecord extends Model {}
