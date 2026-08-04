<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use App\Services\Settings\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    // GET /api/invoices
    public function index(Request $request)
    {
        $invoices = $this->invoiceService->getAllInvoices($request);

        return response()->json([
            'success' => true,
            'data'    => $invoices,
        ]);
    }

    // POST /api/invoices
    public function store(StoreInvoiceRequest $request)
    {
        $invoice = $this->invoiceService->createInvoice(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice created successfully',
            'data'    => $invoice,
        ], 201);
    }

    // GET /api/invoices/{id}
    public function show(Invoice $invoice)
    {
        $invoice->load('client', 'payments');

        return response()->json([
            'success' => true,
            'data'    => $invoice,
        ]);
    }

    // PUT /api/invoices/{id}
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = $this->invoiceService->updateInvoice(
            $invoice,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice updated successfully',
            'data'    => $invoice,
        ]);
    }

    // DELETE /api/invoices/{id}
    public function destroy(Request $request, Invoice $invoice)
    {
        $this->invoiceService->deleteInvoice($invoice, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Invoice deleted successfully',
        ]);
    }

    // POST /api/invoices/bulk-generate
    public function bulkGenerate(Request $request)
    {
        $request->validate([
            'client_ids' => 'required|array',
            'client_ids.*' => 'exists:clients,id',
        ]);

        $count = $this->invoiceService->bulkGenerate(
            $request->client_ids,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} invoices generated successfully",
            'data'    => ['count' => $count],
        ]);
    }

    // GET /api/invoices/{id}/pdf
    public function pdf(Invoice $invoice, SettingsService $settingsService)
    {
        $invoice->load('client');
        $company = $settingsService->getGroup('company');

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice'         => $invoice,
            'companyName'     => $company['company_name']    ?? 'PrimeBill ISP',
            'companyPhone'    => $company['company_phone']   ?? '',
            'companyEmail'    => $company['company_email']   ?? '',
            'companyPaybill'  => $company['company_paybill'] ?? '',
        ])->setPaper('a4');

        return $pdf->download("Invoice-{$invoice->invoice_number}.pdf");
    }
}
