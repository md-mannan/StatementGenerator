<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function store(StoreBranchRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);
        $this->authorize('create', Branch::class);

        $client->branches()->create($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Branch created successfully.'),
        ]);

        return to_route('clients.show', $client);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $branch->update($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Branch updated successfully.'),
        ]);

        return to_route('clients.show', $branch->client);
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);

        $client = $branch->client;
        $branch->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Branch deleted successfully.'),
        ]);

        return to_route('clients.show', $client);
    }
}
