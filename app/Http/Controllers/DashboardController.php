<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('dashboard', $this->dashboardService->resolve(
            (int) auth()->id(),
        ));
    }
}
