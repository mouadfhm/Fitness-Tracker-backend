<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RecommendationController extends Controller
{
// GET /api/recommendations - suggest workouts based on user goals
public function index()
{
    // Dummy recommendation logic; replace with actual AI/model integration
    $recommendations = [
        ['id' => 1, 'name' => 'HIIT Training', 'description' => 'A high-intensity interval training session to boost metabolism and burn calories quickly.'],
        ['id' => 2, 'name' => 'Strength Training', 'description' => 'A weight lifting routine to build muscle and increase overall strength.'],
        ['id' => 3, 'name' => 'Running', 'description' => 'An outdoor running session focused on enhancing endurance and cardiovascular health.'],
        ['id' => 4, 'name' => 'Yoga', 'description' => 'A yoga session designed to improve flexibility, balance, and reduce stress.'],
        ['id' => 5, 'name' => 'Pilates', 'description' => 'A pilates workout emphasizing core strength and stability.'],
    ];
    return response()->json($recommendations);
}}
