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
     */
    public function toggleAdmin(User $user)
    {
        $user->is_admin = ! $user->is_admin;
        $user->save();

        return redirect()->back()->with('success', "User '{$user->username}' admin status updated.");
    }

    /**
     * Toggle enabled status.
     */
    public function toggleEnabled(User $user)
    {
        $user->is_enabled = ! $user->is_enabled;
        $user->save();

        return redirect()->back()->with('success', "User '{$user->username}' " . ($user->is_enabled ? 'enabled' : 'disabled') . '.');
    }
}
