<?php

namespace App\Http\Controllers;

use App\Models\{PurchaseRequisition, Rfq, Quotation, PurchaseOrder, GoodsReceipt, Invoice, User, Item};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementController extends Controller
{
    public function dashboard()
    {
        return view('dashboard.index', [
            'counts' => [
                'pr' => PurchaseRequisition::count(),
                'rfq' => Rfq::count(),
                'po' => PurchaseOrder::count(),
                'invoice' => Invoice::count()
            ]
        ]);
    }

    public function createPr()
    {
        return view('pr.create');
    }

    public function storePr(Request $r)
    {
        if ($r->has('estimated_total')) {
            $cleanTotal = str_replace(['.', ','], '', $r->input('estimated_total'));
            $r->merge(['estimated_total' => $cleanTotal]);
        }

        $d = $r->validate([
            'description' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'unit' => 'required|string|max:50',
            'estimated_total' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        $d['number'] = 'PR-' . date('Y') . '-' . str_pad((PurchaseRequisition::count() + 1), 4, '0', STR_PAD_LEFT);
        $d['user_id'] = $r->user()->id;
        $d['department'] = $r->input('department') ?? $r->user()->department ?? 'General / IT';
        $d['approval_level'] = $d['estimated_total'] > 50000000 ? 2 : 1;
        $d['status'] = 'pending_l1';

        PurchaseRequisition::create($d);

        return redirect()->route('pr.create')->with('success', 'Draft PR berhasil diajukan dan menunggu persetujuan.');
    }

    public function approvals(Request $r)
    {
        $level = (int) $r->route('level');
        $status = $level === 1 ? 'pending_l1' : 'pending_l2';

        return view('pr.approval', [
            'prs' => PurchaseRequisition::with('user')->where('status', $status)->latest()->get(),
            'level' => $level
        ]);
    }

    public function decidePr(Request $r, PurchaseRequisition $pr)
    {
        $r->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'nullable|string'
        ]);

        if ($r->decision === 'reject') {
            $pr->update(['status' => 'rejected', 'rejection_reason' => $r->reason]);
            return back()->with('success', 'PR ditolak dan perlu direvisi.');
        }

        if ($pr->status === 'pending_l1' && (int) $pr->approval_level === 2) {
            $pr->update(['status' => 'pending_l2']);
        } else {
            $pr->update(['status' => 'approved']);
        }

        return back()->with('success', 'PR berhasil diproses.');
    }

    public function createRfq()
    {
        return view('rfq.create', [
            'prs' => PurchaseRequisition::where('status', 'approved')->doesntHave('rfq')->latest()->get()
        ]);
    }

    public function storeRfq(Request $r)
    {
        $d = $r->validate([
            'pr_id' => 'required|exists:purchase_requisitions,id',
            'deadline' => 'required|date|after_or_equal:today',
            'method' => 'required|in:open,limited',
            'specifications' => 'nullable|string'
        ]);

        $d['number'] = 'RFQ-' . date('Y') . '-' . str_pad((Rfq::count() + 1), 4, '0', STR_PAD_LEFT);
        $d['created_by'] = $r->user()->id;
        $d['status'] = 'published';

        Rfq::create($d);

        return back()->with('success', 'RFQ berhasil dipublikasikan.');
    }

    public function recommendVendors(Request $r, PurchaseRequisition $pr)
    {
        $keywords = explode(' ', strtolower($pr->description));

        $vendors = User::where('role', 'vendor')
            ->where(function ($query) use ($keywords) {
                foreach ($keywords as $word) {
                    if (strlen($word) > 2) {
                        $query->orWhere('name', 'LIKE', "%{$word}%")
                              ->orWhere('department', 'LIKE', "%{$word}%");
                    }
                }
            })
            ->get();

        if ($vendors->isEmpty()) {
            $vendors = User::where('role', 'vendor')->get();
        }

        return response()->json([
            'pr' => $pr,
            'vendors' => $vendors
        ]);
    }

    public function storeRfqAi(Request $r)
    {
        $r->validate([
            'pr_id' => 'required|exists:purchase_requisitions,id',
            'deadline' => 'required|date',
            'vendor_ids' => 'required|array',
            'vendor_ids.*' => 'exists:users,id'
        ]);

        Rfq::create([
            'number' => 'RFQ-' . date('Y') . '-' . str_pad((Rfq::count() + 1), 4, '0', STR_PAD_LEFT),
            'pr_id' => $r->pr_id,
            'deadline' => $r->deadline,
            'method' => 'limited',
            'status' => 'published',
            'created_by' => $r->user()->id,
            'specifications' => 'Dipublikasikan otomatis via rekomendasi AI Agent.'
        ]);

        return back()->with('success', 'RFQ berhasil dibuat & dikirimkan ke ' . count($r->vendor_ids) . ' vendor terpilih!');
    }

    public function quotations()
    {
        return view('vendor.quotation', [
            'rfqs' => Rfq::with('pr')->where('status', 'published')->latest()->get()
        ]);
    }

    public function storeQuotation(Request $r)
    {
        if ($r->has('price')) {
            $cleanPrice = str_replace(['.', ','], '', $r->input('price'));
            $r->merge(['price' => $cleanPrice]);
        }

        $d = $r->validate([
            'rfq_id' => 'required|exists:rfqs,id',
            'price' => 'required|numeric|min:0',
            'document' => 'required|file|mimes:pdf|max:5120',
            'notes' => 'nullable|string'
        ]);

        $rfq = Rfq::findOrFail($d['rfq_id']);
        $d['vendor_id'] = $r->user()->id;
        $d['document_path'] = $r->file('document')->store('quotations', 'public');
        unset($d['document']);
        $d['status'] = 'submitted';

        Quotation::create($d);

        return back()->with('success', 'Penawaran berhasil dikirim.');
    }

    public function evaluation()
    {
        return view('procurement.evaluasi', [
            'rfqs' => Rfq::with('quotations.vendor')->whereHas('quotations')->get()
        ]);
    }

    public function chooseWinner(Request $r, Quotation $quotation)
    {
        $quotation->rfq->quotations()->where('id', '!=', $quotation->id)->update(['status' => 'rejected']);
        $quotation->update(['status' => 'winner']);

        return back()->with('success', 'Pemenang berhasil ditetapkan.');
    }

    public function createPo()
    {
        return view('po.create', [
            'quotations' => Quotation::with('rfq.pr', 'vendor')->where('status', 'winner')->doesntHave('rfq.purchaseOrder')->get()
        ]);
    }

    public function storePo(Request $r)
    {
        $d = $r->validate([
            'quotation_id' => 'required|exists:quotations,id',
            'delivery_date' => 'required|date',
            'payment_terms' => 'required|string'
        ]);

        $q = Quotation::with('rfq')->findOrFail($d['quotation_id']);
        $d = array_merge($d, [
            'number' => 'PO-' . date('Y') . '-' . str_pad((PurchaseOrder::count() + 1), 4, '0', STR_PAD_LEFT),
            'rfq_id' => $q->rfq_id,
            'vendor_id' => $q->vendor_id,
            'created_by' => $r->user()->id,
            'issued_at' => now(),
            'total' => $q->price,
            'status' => 'pending_authorization'
        ]);

        PurchaseOrder::create($d);

        return back()->with('success', 'Draft PO berhasil dibuat dan diajukan.');
    }

    public function poApprovals()
    {
        return view('po.approve', [
            'pos' => PurchaseOrder::with('vendor')->where('status', 'pending_authorization')->latest()->get()
        ]);
    }

    public function decidePo(Request $r, PurchaseOrder $po)
    {
        $r->validate([
            'decision' => 'required|in:approve,reject',
            'authorization_note' => 'nullable|string'
        ]);

        $po->update([
            'status' => $r->decision === 'approve' ? 'authorized' : 'rejected',
            'authorization_note' => $r->authorization_note
        ]);

        return back()->with('success', 'PO berhasil diproses.');
    }

    public function createGr()
    {
        return view('gr.create', [
            'pos' => PurchaseOrder::with('vendor')->where('status', 'authorized')->get()
        ]);
    }

    public function storeGr(Request $r)
    {
        $d = $r->validate([
            'po_id' => 'required|exists:purchase_orders,id',
            'delivery_note' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'condition' => 'required|in:good,damaged',
            'notes' => 'nullable|string'
        ]);

        $d['received_by'] = $r->user()->id;
        $d['status'] = 'verified';

        GoodsReceipt::create($d);

        return back()->with('success', 'GR berhasil dicatat.');
    }

    public function createInvoice()
    {
        return view('vendor.invoice', [
            'pos' => PurchaseOrder::with('vendor')->where('status', 'authorized')->get()
        ]);
    }

    public function storeInvoice(Request $r)
    {
        if ($r->has('amount')) {
            $cleanAmount = str_replace(['.', ','], '', $r->input('amount'));
            $r->merge(['amount' => $cleanAmount]);
        }

        $d = $r->validate([
            'po_id' => 'required|exists:purchase_orders,id',
            'number' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0',
            'document' => 'required|file|mimes:pdf|max:5120'
        ]);

        $d['vendor_id'] = $r->user()->id;
        $d['document_path'] = $r->file('document')->store('invoices', 'public');
        unset($d['document']);
        $d['status'] = 'pending_verification';

        Invoice::create($d);

        return back()->with('success', 'Invoice berhasil dikirim.');
    }

    public function verifyInvoices()
    {
        return view('invoice.verify', [
            'invoices' => Invoice::with('po.goodsReceipts', 'po.vendor')->where('status', 'pending_verification')->latest()->get()
        ]);
    }

    public function verifyInvoice(Request $r, Invoice $invoice)
    {
        $match = $invoice->po && $invoice->po->total == $invoice->amount && $invoice->po->goodsReceipts()->where('status', 'verified')->exists();

        $invoice->update([
            'status' => $match ? 'verified' : 'discrepancy',
            'verification_note' => $match ? 'PO, GR, dan invoice cocok.' : 'Terdapat perbedaan nilai atau GR belum verified.'
        ]);

        return back()->with('success', $match ? 'Invoice terverifikasi dan siap dibayar.' : 'Invoice ditandai discrepancy.');
    }

    public function items()
    {
        return view('master.items', [
            'items' => Item::latest()->get()
        ]);
    }

    public function departments()
    {
        return view('master.departments', [
            'departments' => DB::table('departments')->latest()->get()
        ]);
    }

    public function users()
    {
        return view('master.users', [
            'users' => User::latest()->get()
        ]);
    }
}