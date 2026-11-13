<?php

namespace App\Http\Controllers;

use App\Models\PsxBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PsxBrokerController extends Controller
{
    public function index()
    {
        $brokers = PsxBroker::orderByDesc('is_default')->orderBy('name')->get();
        return view('psx.brokers', compact('brokers'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = Auth::id();

        DB::transaction(function () use ($data) {
            $broker = PsxBroker::create($data);
            $this->syncDefault($broker);
        });

        return redirect()->route('psx_brokers.index')->with('success', 'Broker added successfully.');
    }

    public function update(Request $request, $id)
    {
        $broker = PsxBroker::findOrFail($id);
        $data   = $this->validated($request);

        DB::transaction(function () use ($broker, $data) {
            $broker->update($data);
            $this->syncDefault($broker);
        });

        return redirect()->route('psx_brokers.index')->with('success', 'Broker updated successfully.');
    }

    public function destroy($id)
    {
        $broker = PsxBroker::findOrFail($id);

        if ($broker->is_default) {
            return back()->with('error', 'The default broker cannot be deleted. Make another broker default first.');
        }

        $broker->delete();

        return redirect()->route('psx_brokers.index')->with('success', 'Broker deleted successfully.');
    }

    /** Only one broker can be the default. */
    private function syncDefault(PsxBroker $broker): void
    {
        if ($broker->is_default) {
            PsxBroker::where('id', '!=', $broker->id)->update(['is_default' => false]);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:255',
            'code'                     => 'nullable|string|max:50',
            'client_code'              => 'nullable|string|max:50',
            'contact_person'           => 'nullable|string|max:255',
            'phone'                    => 'nullable|string|max:50',
            'email'                    => 'nullable|email|max:255',
            'min_commission_per_share' => 'required|numeric|min:0|max:1000',
            'commission_rate'          => 'required|numeric|min:0|max:100',
            'remarks'                  => 'nullable|string',
        ]);

        $data['intraday_buy_waiver'] = $request->boolean('intraday_buy_waiver');
        $data['is_default']          = $request->boolean('is_default');
        $data['is_active']           = $request->boolean('is_active');

        // A default broker must be active
        if ($data['is_default']) {
            $data['is_active'] = true;
        }

        return $data;
    }
}
