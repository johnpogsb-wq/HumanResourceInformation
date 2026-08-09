<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Modules 2–5 have their schema in place but no UI yet. These keep the sidebar
 * navigable and document what each module will own.
 */
class ModulePlaceholderController extends Controller
{
    public function payroll(): Response
    {
        return $this->render(
            'Payroll & Compensation',
            'Salary computation that consumes Timekeeping and Leave data, with statutory contributions and payslips.',
            ['Salary computation', 'Government contributions & tax', 'Payslip generation (PDF)', 'Payroll run approval', 'Run history'],
        );
    }

    public function performance(): Response
    {
        return $this->render(
            'Performance Management',
            'KPI setting, review cycles, and rating history including 360-degree feedback.',
            ['KPI / goal setting', 'Review cycles', 'Evaluation forms & rating scale', 'Performance history', '360 / supervisor review'],
        );
    }

    private function render(string $title, string $description, array $features): Response
    {
        return Inertia::render('HR/ModulePlaceholder', [
            'module' => compact('title', 'description', 'features'),
        ]);
    }
}
