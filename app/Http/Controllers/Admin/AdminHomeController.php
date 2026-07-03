<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminPermissionRegistry;
use Illuminate\Http\RedirectResponse;

class AdminHomeController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $routeName = AdminPermissionRegistry::firstAllowedRoute(auth()->user()->permissionKeys()->all());

        abort_if(! $routeName, 403);

        return redirect()->route($routeName);
    }
}
