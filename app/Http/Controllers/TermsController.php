<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Terms;

class TermsController extends Controller
{
    public function store(Request $request)
        {
            $request->validate([
                'semester' => 'required|in:1st,2nd,summer',
            ]);

// auto-generate academic year
            $currentYear = now()->month >= 6 ? now()->year : now()->year - 1;
            $nextYear = $currentYear + 1;
            $academicYear = $currentYear . '-' . $nextYear;

            Terms::create([
                'academic_year' => $academicYear,
                'semester'      => $request->semester,
                'is_active'     => false,
            ]);
        }
}
