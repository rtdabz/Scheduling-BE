<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Terms;

class TermsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $terms = Terms::orderBy('academic_year', 'desc')
                      ->orderBy('semester', 'desc')
                      ->get();
        return response()->json($terms);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'semester' => 'required|in:1st,2nd,summer',
            'academic_year' => 'nullable|string|max:50',
        ]);

        $academicYear = $request->academic_year;
        if (!$academicYear) {
            $currentYear = now()->month >= 6 ? now()->year : now()->year - 1;
            $nextYear = $currentYear + 1;
            $academicYear = $currentYear . '-' . $nextYear;
        }

        // Check for duplicates
        $exists = Terms::where('academic_year', $academicYear)
                       ->where('semester', $request->semester)
                       ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This academic term already exists.'
            ], 422);
        }

        $term = Terms::create([
            'academic_year' => $academicYear,
            'semester'      => $request->semester,
            'is_active'     => false,
        ]);

        return response()->json([
            'message' => 'Term created successfully.',
            'term' => $term
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $term = Terms::findOrFail($id);
        return response()->json($term);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $term = Terms::findOrFail($id);

        if ($term->is_active) {
            return response()->json([
                'message' => 'Cannot delete the active academic term. Please activate another term first.'
            ], 400);
        }

        $term->delete();

        return response()->json([
            'message' => 'Term deleted successfully.'
        ]);
    }

    /**
     * Activate the specified term.
     */
    public function activate($id)
    {
        $term = Terms::findOrFail($id);
        $term->is_active = true;
        $term->save();

        return response()->json([
            'message' => 'Term activated successfully.',
            'term' => $term
        ]);
    }

    /**
     * Get the active term.
     */
    public function active()
    {
        $term = Terms::where('is_active', true)->first();
        return response()->json($term);
    }
}
