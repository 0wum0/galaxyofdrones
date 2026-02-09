<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * List all users.
     */
    public function index(Request $request)
    {
        $query = User::query()->orderBy('created_at', 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(25)->appends($request->only('search'));

        return view('admin.users.index', compact('users', 'search'));
    }

    /**
     * Show a single user.
     */
    public function show(User $user)
    {
        $user->load(['planets', 'rank']);

        return view('admin.users.show', compact('user'));
    }

    /**
     * Toggle admin status.
     *
     * Prevents: self-lockout and removing the last admin.
     */
    public function toggleAdmin(Request $request, User $user)
    {
        // Prevent removing your own admin status
        if ($request->user()->id === $user->id && $user->is_admin) {
            return redirect()->back()->with('error', 'You cannot remove your own admin status.');
        }

        // Prevent removing the last admin
        if ($user->is_admin) {
            $adminCount = User::where('is_admin', true)->where('is_enabled', true)->count();
            if ($adminCount <= 1) {
                return redirect()->back()->with('error', 'Cannot remove admin status: at least one active admin must exist.');
            }
        }

        $user->is_admin = ! $user->is_admin;
        $user->save();

        return redirect()->back()->with('success', "User '{$user->username}' admin status updated.");
    }

    /**
     * Toggle enabled status.
     *
     * Prevents: self-disable and disabling the last admin.
     */
    public function toggleEnabled(Request $request, User $user)
    {
        // Prevent disabling yourself
        if ($request->user()->id === $user->id && $user->is_enabled) {
            return redirect()->back()->with('error', 'You cannot disable your own account.');
        }

        // Prevent disabling the last active admin
        if ($user->is_admin && $user->is_enabled) {
            $activeAdminCount = User::where('is_admin', true)->where('is_enabled', true)->count();
            if ($activeAdminCount <= 1) {
                return redirect()->back()->with('error', 'Cannot disable user: they are the last active admin.');
            }
        }

        $user->is_enabled = ! $user->is_enabled;
        $user->save();

        return redirect()->back()->with('success', "User '{$user->username}' " . ($user->is_enabled ? 'enabled' : 'disabled') . '.');
    }
}
