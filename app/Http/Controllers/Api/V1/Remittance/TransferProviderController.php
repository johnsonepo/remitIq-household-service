<?php

namespace App\Http\Controllers\Api\V1\Remittance;

use App\Http\Controllers\Api\BaseController;
use App\Models\TransferProvider;
use Illuminate\Http\JsonResponse;

class TransferProviderController extends BaseController
{
    /**
     * List active transfer providers.
     */
    public function index(): JsonResponse
    {
        $providers = TransferProvider::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->success(
            $providers,
            'Transfer providers retrieved successfully.'
        );
    }
}
