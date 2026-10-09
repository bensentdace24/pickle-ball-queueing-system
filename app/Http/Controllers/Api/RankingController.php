<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\RankingService;

class RankingController extends Controller
{
    use ApiResponse;

    public function __construct(private RankingService $rankings) {}

    public function index()
    {
        return $this->success($this->rankings->all());
    }
}
