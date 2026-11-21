<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Services\Psx\PsxPortfolioService;
use App\Services\Psx\TradeMatcher;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Main dashboard: a summary of every module for a period.
 * Each section is only worked out (and shown) when the user may see that module.
 * Figures use the same formulas as the Sales, Purchase, Inventory and Accounts reports.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user   = $request->user();
        $period = $request->input('period', 'month');
        [$from, $to] = DashboardPeriod::range($period, $request->input('from_date'), $request->input('to_date'));
        $today  = Carbon::today()->toDateString();

        $can = [
            'sales'     => $user->can('sale_invoices.index') || $user->can('reports.sales'),
            'purchases' => $user->can('purchase_invoices.index') || $user->can('reports.purchase'),
            'returns'   => $user->can('sale_return.index') || $user->can('purchase_return.index'),
            'inventory' => $user->can('products.index') || $user->can('reports.inventory'),
            'accounts'  => $user->can('reports.accounts') || $user->can('coa.index'),
            'vouchers'  => $user->can('vouchers.index'),
            'psx'       => $user->can('reports.psx_dashboard') && Schema::hasTable('psx_trades'),
        ];

        $data = [
            'filters' => ['period' => $period, 'from' => $from, 'to' => $to],
            'label'   => DashboardPeriod::label($period, $from, $to),
            'can'     => $can,
        ];

        if ($can['sales']) {
            $data['sales'] = $this->sales($from, $to);
        }
        if ($can['purchases']) {
            $data['purchases'] = $this->purchases($from, $to);
        }
        if ($can['sales'] || $can['purchases']) {
            $data['trend'] = $this->trend($from, $to, $can);
        }
        if ($can['returns']) {
            $data['returns'] = $this->returns($from, $to);
        }
        if ($can['inventory']) {
            $data['inventory'] = $this->inventory();
        }
        if ($can['accounts']) {
            $data['accounts'] = $this->accounts($from, $to, $today);
        }
        if ($can['vouchers']) {
            $data['vouchers'] = DB::table('vouchers')->whereNull('deleted_at')
                ->when($from, fn ($q) => $q->where('date', '>=', $from))
                ->when($to, fn ($q) => $q->where('date', '<=', $to))
                ->selectRaw('voucher_type, COUNT(*) as n, SUM(amount) as total')
                ->groupBy('voucher_type')->get()->keyBy('voucher_type');
        }
        if ($can['psx']) {
            $data['psx'] = $this->psx($from, $to);
        }

        return view('home', $data);
    }

    // ─── Sales ──────────────────────────────────────────────────

    private function salesBase(?string $from, ?string $to)
    {
        return DB::table('sale_invoices')->whereNull('sale_invoices.deleted_at')
            ->when($from, fn ($q) => $q->where('sale_invoices.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('sale_invoices.date', '<=', $to));
    }

    private function sales(?string $from, ?string $to): array
    {
        // Invoice total = items (price x qty) - discount, as in the Sales report
        $invoices = $this->salesBase($from, $to)
            ->leftJoin('sale_invoice_items as i', 'i.sale_invoice_id', '=', 'sale_invoices.id')
            ->groupBy('sale_invoices.id', 'sale_invoices.type', 'sale_invoices.discount', 'sale_invoices.account_id')
            ->selectRaw('sale_invoices.id, sale_invoices.type, sale_invoices.account_id, sale_invoices.discount,
                         COALESCE(SUM(i.sale_price * i.quantity), 0) as gross')
            ->get();

        $net = fn ($rows) => $rows->sum(fn ($r) => $r->gross - $r->discount);

        $customers = $invoices->groupBy('account_id')
            ->map(fn ($rows, $id) => ['id' => $id, 'total' => $net($rows), 'count' => $rows->count()])
            ->sortByDesc('total')->take(5)->values();
        $names = ChartOfAccounts::whereIn('id', $customers->pluck('id'))->pluck('name', 'id');

        $products = DB::table('sale_invoice_items as i')
            ->join('sale_invoices', 'sale_invoices.id', '=', 'i.sale_invoice_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereNull('sale_invoices.deleted_at')
            ->when($from, fn ($q) => $q->where('sale_invoices.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('sale_invoices.date', '<=', $to))
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.name, SUM(i.quantity) as qty, SUM(i.sale_price * i.quantity) as total')
            ->orderByDesc('total')->limit(6)->get();

        $recent = DB::table('sale_invoices')->whereNull('sale_invoices.deleted_at')
            ->leftJoin('chart_of_accounts as a', 'a.id', '=', 'sale_invoices.account_id')
            ->leftJoin('sale_invoice_items as i', 'i.sale_invoice_id', '=', 'sale_invoices.id')
            ->groupBy('sale_invoices.id', 'sale_invoices.invoice_no', 'sale_invoices.date', 'sale_invoices.type', 'sale_invoices.discount', 'a.name')
            ->selectRaw('sale_invoices.id, sale_invoices.invoice_no, sale_invoices.date, sale_invoices.type, a.name as party,
                         COALESCE(SUM(i.sale_price * i.quantity), 0) - sale_invoices.discount as total')
            ->orderByDesc('sale_invoices.date')->orderByDesc('sale_invoices.id')->limit(6)->get();

        return [
            'count'     => $invoices->count(),
            'gross'     => $invoices->sum('gross'),
            'discount'  => $invoices->sum('discount'),
            'net'       => $net($invoices),
            'cash'      => $net($invoices->where('type', 'cash')),
            'credit'    => $net($invoices->where('type', 'credit')),
            'customers' => $customers->map(fn ($c) => $c + ['name' => $names[$c['id']] ?? '—'])->all(),
            'products'  => $products->all(),
            'recent'    => $recent->all(),
        ];
    }

    // ─── Purchases ──────────────────────────────────────────────

    private function purchases(?string $from, ?string $to): array
    {
        $invoices = DB::table('purchase_invoices')->whereNull('purchase_invoices.deleted_at')
            ->when($from, fn ($q) => $q->where('purchase_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('purchase_invoices.invoice_date', '<=', $to))
            ->leftJoin('purchase_invoice_items as i', 'i.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->groupBy('purchase_invoices.id', 'purchase_invoices.vendor_id')
            ->selectRaw('purchase_invoices.id, purchase_invoices.vendor_id, COALESCE(SUM(i.price * i.quantity), 0) as total')
            ->get();

        $vendors = $invoices->groupBy('vendor_id')
            ->map(fn ($rows, $id) => ['id' => $id, 'total' => $rows->sum('total'), 'count' => $rows->count()])
            ->sortByDesc('total')->take(5)->values();
        $names = ChartOfAccounts::whereIn('id', $vendors->pluck('id'))->pluck('name', 'id');

        $recent = DB::table('purchase_invoices')->whereNull('purchase_invoices.deleted_at')
            ->leftJoin('chart_of_accounts as a', 'a.id', '=', 'purchase_invoices.vendor_id')
            ->leftJoin('purchase_invoice_items as i', 'i.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->groupBy('purchase_invoices.id', 'purchase_invoices.invoice_no', 'purchase_invoices.invoice_date', 'purchase_invoices.bill_no', 'a.name')
            ->selectRaw('purchase_invoices.id, purchase_invoices.invoice_no, purchase_invoices.invoice_date as date, purchase_invoices.bill_no,
                         a.name as party, COALESCE(SUM(i.price * i.quantity), 0) as total')
            ->orderByDesc('purchase_invoices.invoice_date')->orderByDesc('purchase_invoices.id')->limit(6)->get();

        return [
            'count'   => $invoices->count(),
            'total'   => $invoices->sum('total'),
            'vendors' => $vendors->map(fn ($v) => $v + ['name' => $names[$v['id']] ?? '—'])->all(),
            'recent'  => $recent->all(),
        ];
    }

    // ─── Sales vs purchases trend ───────────────────────────────

    private function trend(?string $from, ?string $to, array $can): array
    {
        $sales = $can['sales'] ? $this->salesBase($from, $to)
            ->leftJoin('sale_invoice_items as i', 'i.sale_invoice_id', '=', 'sale_invoices.id')
            ->groupBy('sale_invoices.id', 'sale_invoices.date', 'sale_invoices.discount')
            ->selectRaw('sale_invoices.date as d, COALESCE(SUM(i.sale_price * i.quantity), 0) - sale_invoices.discount as total')
            ->get() : collect();

        $purchases = $can['purchases'] ? DB::table('purchase_invoices')->whereNull('purchase_invoices.deleted_at')
            ->when($from, fn ($q) => $q->where('purchase_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('purchase_invoices.invoice_date', '<=', $to))
            ->join('purchase_invoice_items as i', 'i.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->groupBy('purchase_invoices.invoice_date')
            ->selectRaw('purchase_invoices.invoice_date as d, SUM(i.price * i.quantity) as total')
            ->get() : collect();

        $days    = $sales->pluck('d')->merge($purchases->pluck('d'))->map(fn ($d) => substr($d, 0, 10))->unique();
        $monthly = DashboardPeriod::monthly($from, $to, $days->count());
        $key     = fn ($d) => $monthly ? substr($d, 0, 7) : substr($d, 0, 10);

        $buckets = [];
        foreach ($sales as $r) {
            $buckets[$key($r->d)]['sales'] = ($buckets[$key($r->d)]['sales'] ?? 0) + $r->total;
        }
        foreach ($purchases as $r) {
            $buckets[$key($r->d)]['purchases'] = ($buckets[$key($r->d)]['purchases'] ?? 0) + $r->total;
        }
        ksort($buckets);

        $series = [];
        foreach ($buckets as $k => $v) {
            $series[] = [
                'label'     => $monthly ? Carbon::parse($k . '-01')->format('M Y') : Carbon::parse($k)->format('d M'),
                'sales'     => round($v['sales'] ?? 0, 2),
                'purchases' => round($v['purchases'] ?? 0, 2),
            ];
        }

        return ['series' => $series, 'monthly' => $monthly];
    }

    // ─── Returns ────────────────────────────────────────────────

    private function returns(?string $from, ?string $to): array
    {
        $sr = DB::table('sale_returns')
            ->when($from, fn ($q) => $q->where('return_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('return_date', '<=', $to))
            ->leftJoin('sale_return_items as i', 'i.sale_return_id', '=', 'sale_returns.id')
            ->selectRaw('COUNT(DISTINCT sale_returns.id) as n, COALESCE(SUM(i.qty * i.price), 0) as total')->first();

        $pr = DB::table('purchase_returns')
            ->when($from, fn ($q) => $q->where('return_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('return_date', '<=', $to))
            ->leftJoin('purchase_return_items as i', 'i.purchase_return_id', '=', 'purchase_returns.id')
            ->selectRaw('COUNT(DISTINCT purchase_returns.id) as n, COALESCE(SUM(i.quantity * i.price), 0) as total')->first();

        return [
            'sale'     => ['count' => (int) $sr->n, 'total' => (float) $sr->total],
            'purchase' => ['count' => (int) $pr->n, 'total' => (float) $pr->total],
        ];
    }

    // ─── Inventory (stock in hand, same formula as the Inventory report) ──

    private function inventory(): array
    {
        $sum = fn ($table, $parent, $fk, $col, $prodCol, $parentDate = null) => DB::table($table)
            ->when($parent, fn ($q) => $q->join($parent, "$parent.id", '=', "$table.$fk")->whereNull("$parent.deleted_at"))
            ->groupBy("$table.$prodCol")->selectRaw("$table.$prodCol as pid, SUM($table.$col) as q")->pluck('q', 'pid');

        $purchased = $sum('purchase_invoice_items', 'purchase_invoices', 'purchase_invoice_id', 'quantity', 'item_id');
        $sold      = $sum('sale_invoice_items', 'sale_invoices', 'sale_invoice_id', 'quantity', 'product_id');
        $pReturned = $sum('purchase_return_items', null, null, 'quantity', 'item_id');
        $sReturned = $sum('sale_return_items', null, null, 'qty', 'product_id');

        $products = DB::table('products')->whereNull('products.deleted_at')
            ->leftJoin('measurement_units as u', 'u.id', '=', 'products.measurement_unit')
            ->select('products.id', 'products.name', 'products.is_active', 'u.shortcode as unit')->get();

        $rows = $products->map(function ($p) use ($purchased, $sold, $pReturned, $sReturned) {
            $p->qty = (float) ($purchased[$p->id] ?? 0) + (float) ($sReturned[$p->id] ?? 0)
                    - (float) ($sold[$p->id] ?? 0) - (float) ($pReturned[$p->id] ?? 0);
            return $p;
        });

        $active = $rows->where('is_active', true);
        $stockAccount = ChartOfAccounts::where('account_code', '104001')->first();

        return [
            'products'     => $rows->count(),
            'active'       => $active->count(),
            'in_stock'     => $active->filter(fn ($p) => $p->qty > 0)->count(),
            'out_of_stock' => $active->filter(fn ($p) => $p->qty <= 0)->values(),
            'units'        => $active->filter(fn ($p) => $p->qty > 0)->sum('qty'),
            'value'        => $stockAccount ? $this->balances([$stockAccount->id], null)[$stockAccount->id]['dr'] ?? 0 : null,
            'top_stock'    => $active->filter(fn ($p) => $p->qty > 0)->sortByDesc('qty')->take(5)->values(),
        ];
    }

    // ─── Accounts (same rules as the Accounts reports) ──────────

    /**
     * Debit / credit totals per account, including the COA opening balance.
     * $to = null for no end date. Returns [id => ['dr' => net debit balance]].
     */
    private function balances(?array $ids, ?string $to): array
    {
        $dr = DB::table('vouchers')->whereNull('deleted_at')->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->when($ids, fn ($q) => $q->whereIn('ac_dr_sid', $ids))
            ->groupBy('ac_dr_sid')->selectRaw('ac_dr_sid as id, SUM(amount) as s')->pluck('s', 'id');
        $cr = DB::table('vouchers')->whereNull('deleted_at')->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->when($ids, fn ($q) => $q->whereIn('ac_cr_sid', $ids))
            ->groupBy('ac_cr_sid')->selectRaw('ac_cr_sid as id, SUM(amount) as s')->pluck('s', 'id');

        $accounts = ChartOfAccounts::when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->get(['id', 'name', 'account_type', 'receivables', 'payables']);

        $out = [];
        foreach ($accounts as $a) {
            $debit  = (float) $a->receivables + (float) ($dr[$a->id] ?? 0);
            $credit = (float) $a->payables + (float) ($cr[$a->id] ?? 0);
            $out[$a->id] = ['name' => $a->name, 'type' => $a->account_type, 'dr' => $debit - $credit];
        }
        return $out;
    }

    private function accounts(?string $from, ?string $to, string $today): array
    {
        $bal = collect($this->balances(null, $today));

        $receivables = $bal->filter(fn ($b) => in_array($b['type'], ['customer', 'receivable']) && $b['dr'] > 0.004)
            ->map(fn ($b) => ['name' => $b['name'], 'amount' => $b['dr']])->sortByDesc('amount');
        $payables = $bal->filter(fn ($b) => in_array($b['type'], ['vendor', 'payable']) && -$b['dr'] > 0.004)
            ->map(fn ($b) => ['name' => $b['name'], 'amount' => -$b['dr']])->sortByDesc('amount');

        // Profit for the period: vouchers in the period only (no opening balances), as the P&L report
        $periodSums = function ($types) use ($from, $to) {
            $ids = ChartOfAccounts::whereIn('account_type', $types)->pluck('id');
            if ($ids->isEmpty()) {
                return ['dr' => 0.0, 'cr' => 0.0];
            }
            $q = fn ($col) => (float) DB::table('vouchers')->whereNull('deleted_at')->whereIn($col, $ids)
                ->when($from, fn ($x) => $x->where('date', '>=', $from))
                ->when($to, fn ($x) => $x->where('date', '<=', $to))->sum('amount');
            return ['dr' => $q('ac_dr_sid'), 'cr' => $q('ac_cr_sid')];
        };
        $rev  = $periodSums(['revenue']);
        $cogs = $periodSums(['cogs', 'cost_of_sales']);
        $exp  = $periodSums(['expenses']);

        $revenue  = $rev['cr'] - $rev['dr'];
        $costs    = $cogs['dr'] - $cogs['cr'];
        $expenses = $exp['dr'] - $exp['cr'];

        return [
            'cash'         => $bal->where('type', 'cash')->sum('dr'),
            'bank'         => $bal->where('type', 'bank')->sum('dr'),
            'receivables'  => $receivables->sum('amount'),
            'receivable_n' => $receivables->count(),
            'top_receive'  => $receivables->take(5)->values()->all(),
            'payables'     => $payables->sum('amount'),
            'payable_n'    => $payables->count(),
            'top_pay'      => $payables->take(5)->values()->all(),
            'revenue'      => $revenue,
            'cogs'         => $costs,
            'gross'        => $revenue - $costs,
            'expenses'     => $expenses,
            'net'          => $revenue - $costs - $expenses,
            'coa'          => $bal->count(),
        ];
    }

    // ─── PSX Trading ────────────────────────────────────────────

    private function psx(?string $from, ?string $to): array
    {
        $result  = app(PsxPortfolioService::class)->result();
        $matches = array_filter($result['matches'], fn ($m) => (!$from || $m['sell_date'] >= $from) && (!$to || $m['sell_date'] <= $to));
        $summary = TradeMatcher::summary(array_values($matches));

        return [
            'net'      => $summary['net'],
            'closed'   => $summary['trades'],
            'winning'  => $summary['winning'],
            'invested' => array_sum(array_column($result['holdings'], 'cost_value')),
            'stocks'   => count(array_unique(array_column($result['holdings'], 'symbol'))),
        ];
    }
}
