<?php

namespace App\Http\Controllers\admin;

use App\Models\PredefinedSector;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PredefinedSectorController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:predefined_sectors,name',
        ]);

        $sector = PredefinedSector::create($data);

        return response()->json([
            'message' => 'Sector added successfully.',
            'sector'  => $sector,
        ], 201);
    }
    public function destroy($id)
    {
        $sector = PredefinedSector::findOrFail($id);
        $sector->delete();

        return response()->json([
            'message' => 'Sector deleted successfully.'
        ], 200);
    }
}