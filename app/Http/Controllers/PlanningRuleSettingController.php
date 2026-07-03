<?php

namespace App\Http\Controllers;

use App\Planning\Infrastructure\PlanningRuleSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class PlanningRuleSettingController extends Controller
{
    public function update(string $code): RedirectResponse
    {
        PlanningRuleSettings::ensureDefaults();
        $rule = DB::table('planning_rule_settings')->where('code', $code)->first();
        if ($rule === null) {
            return back()->with('message', 'Nie znaleziono reguły planowania.');
        }
        $canToggle = (bool) ($rule->can_toggle ?? true);

        DB::table('planning_rule_settings')->where('code', $code)->update([
            'is_active' => $canToggle ? request()->boolean('is_active') : true,
            'weight' => max(0, (int) request('weight', 0)),
            'metadata' => json_encode(['source' => 'user']),
            'updated_at' => now(),
        ]);

        return back()->with('message', 'Zmieniono regułę planowania.');
    }
}
