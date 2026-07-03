<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class AssignmentController extends Controller
{
    public function update(int $assignment): RedirectResponse
    {
        DB::table('assignments')->where('id', $assignment)->update([
            'resource_id' => request('resource_id'),
            'source' => 'manual',
            'updated_at' => now(),
        ]);

        return back()->with('message', 'Zmieniono przypisanie.');
    }

    public function lock(int $assignment): RedirectResponse
    {
        DB::table('assignments')->where('id', $assignment)->update(['is_locked' => true, 'updated_at' => now()]);

        return back()->with('message', 'Zablokowano przypisanie.');
    }

    public function unlock(int $assignment): RedirectResponse
    {
        DB::table('assignments')->where('id', $assignment)->update(['is_locked' => false, 'updated_at' => now()]);

        return back()->with('message', 'Odblokowano przypisanie.');
    }

    public function destroy(int $assignment): RedirectResponse
    {
        DB::table('assignments')->where('id', $assignment)->update(['resource_id' => null, 'source' => 'manual', 'updated_at' => now()]);

        return back()->with('message', 'Usunięto obsadę.');
    }
}
