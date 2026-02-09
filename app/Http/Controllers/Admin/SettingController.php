<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameSetting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * List all game settings.
     */
    public function index()
    {
        $settings = GameSetting::orderBy('group')->orderBy('key')->get();
        $groups = $settings->groupBy('group');

        return view('admin.settings.index', compact('settings', 'groups'));
    }

    /**
     * Store a new setting.
     */
    public function store(Request $request)
    {
        $request->validate([
            'key' => 'required|string|max:255|unique:game_settings,key',
            'value' => 'nullable|string',
            'group' => 'required|string|max:255',
            'type' => 'required|in:string,integer,float,boolean,json',
            'description' => 'nullable|string|max:1000',
        ]);

        GameSetting::create($request->only('key', 'value', 'group', 'type', 'description'));

        return redirect()->route('admin.settings.index')->with('success', "Setting '{$request->key}' created.");
    }

    /**
     * Update a setting.
     */
    public function update(Request $request, GameSetting $gameSetting)
    {
        $request->validate([
            'value' => 'nullable|string',
            'description' => 'nullable|string|max:1000',
        ]);

        $gameSetting->update($request->only('value', 'description'));

        return redirect()->route('admin.settings.index')->with('success', "Setting '{$gameSetting->key}' updated.");
    }

    /**
     * Delete a setting.
     */
    public function destroy(GameSetting $gameSetting)
    {
        $key = $gameSetting->key;
        $gameSetting->delete();

        return redirect()->route('admin.settings.index')->with('success', "Setting '{$key}' deleted.");
    }
}
