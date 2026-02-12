<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    /**
     * {@inheritdoc}
     */
    public function username()
    {
        return 'username_or_email';
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * Translates the "username_or_email" form field into the correct
     * database column (username OR email) for the auth guard.
     * This is a defense-in-depth override — AppUserProvider already
     * handles this, but having it here makes the flow explicit and
     * guards against provider misconfiguration.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function credentials(Request $request)
    {
        return [
            'username_or_email' => $request->input($this->username()),
            'password' => $request->input('password'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function redirectPath()
    {
        return route('home');
    }

    /**
     * The user has been authenticated.
     *
     * Ensures the redirect after login is always a clean 302→GET,
     * so that refreshing the resulting page does NOT prompt the
     * browser to "resend POST data" (which causes a 419 error).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function authenticated(Request $request, $user)
    {
        return redirect()->intended($this->redirectPath());
    }

    /**
     * {@inheritdoc}
     */
    public function logout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        flash()->success(
            trans('messages.success.logout')
        );

        return redirect()->route('login');
    }
}
