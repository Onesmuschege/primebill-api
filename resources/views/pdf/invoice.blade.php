<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', Arial, sans-serif; color: #1e293b; font-size: 13px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; }
        .company-name { font-size: 20px; font-weight: bold; color: #2563eb; margin: 0; }
        .company-meta { color: #64748b; font-size: 11px; margin-top: 4px; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { font-size: 22px; margin: 0; color: #0f172a; }
        .invoice-number { color: #64748b; font-size: 12px; }
        .status {
            display: inline-block; padding: 3px 10px; border-radius: 10px;
            font-size: 10px; font-weight: bold; text-transform: uppercase; margin-top: 6px;
        }
        .status-paid    { background: #dcfce7; color: #15803d; }
        .status-unpaid  { background: #fef9c3; color: #a16207; }
        .status-overdue { background: #fee2e2; color: #b91c1c; }
        .status-draft, .status-cancelled { background: #f1f5f9; color: #475569; }

        .bill-to { margin-bottom: 28px; }
        .bill-to .label { color: #94a3b8; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; }
        .bill-to .name { font-size: 14px; font-weight: bold; margin: 4px 0 2px; }
        .bill-to .detail { color: #475569; font-size: 12px; }

        table.line-items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.line-items th {
            text-align: left; font-size: 10px; text-transform: uppercase; color: #94a3b8;
            border-bottom: 2px solid #e2e8f0; padding: 8px 4px;
        }
        table.line-items td { padding: 10px 4px; border-bottom: 1px solid #f1f5f9; }
        table.line-items .amount-col { text-align: right; }

        .totals { width: 260px; margin-left: auto; }
        .totals .row { display: flex; justify-content: space-between; padding: 6px 4px; font-size: 12px; }
        .totals .row.total { font-size: 15px; font-weight: bold; border-top: 2px solid #0f172a; padding-top: 10px; margin-top: 4px; }

        .footer { margin-top: 48px; padding-top: 16px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 10px; text-align: center; }
        .notes { margin-top: 24px; padding: 12px 16px; background: #f8fafc; border-radius: 6px; font-size: 11px; color: #475569; }
    </style>
</head>
<body>

    <div class="header">
        <div>
            <p class="company-name">{{ $companyName }}</p>
            <p class="company-meta">
                @if($companyPhone) {{ $companyPhone }}<br>@endif
                @if($companyEmail) {{ $companyEmail }}<br>@endif
                @if($companyPaybill) Paybill: {{ $companyPaybill }} @endif
            </p>
        </div>
        <div class="invoice-title">
            <h1>INVOICE</h1>
            <p class="invoice-number">#{{ $invoice->invoice_number }}</p>
            <span class="status status-{{ $invoice->status }}">{{ $invoice->status }}</span>
        </div>
    </div>

    <div class="bill-to">
        <p class="label">Billed To</p>
        <p class="name">{{ $invoice->client->full_name ?? trim(($invoice->client->first_name ?? '') . ' ' . ($invoice->client->last_name ?? '')) }}</p>
        @if($invoice->client->phone)<p class="detail">{{ $invoice->client->phone }}</p>@endif
        @if($invoice->client->email)<p class="detail">{{ $invoice->client->email }}</p>@endif
        @if($invoice->client->address)<p class="detail">{{ $invoice->client->address }}</p>@endif
    </div>

    <table class="line-items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount-col">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Internet Service — Invoice #{{ $invoice->invoice_number }}</td>
                <td class="amount-col">Ksh {{ number_format($invoice->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <div class="row">
            <span>Subtotal</span>
            <span>Ksh {{ number_format($invoice->amount, 2) }}</span>
        </div>
        @if($invoice->tax > 0)
        <div class="row">
            <span>Tax</span>
            <span>Ksh {{ number_format($invoice->tax, 2) }}</span>
        </div>
        @endif
        <div class="row total">
            <span>Total Due</span>
            <span>Ksh {{ number_format($invoice->total, 2) }}</span>
        </div>
    </div>

    <table class="line-items" style="margin-top: 8px;">
        <tbody>
            <tr>
                <td style="border-bottom:none; color:#94a3b8; font-size:11px;">Due Date</td>
                <td class="amount-col" style="border-bottom:none; font-size:11px;">
                    {{ $invoice->due_date?->format('d M Y') ?? '—' }}
                </td>
            </tr>
            @if($invoice->paid_at)
            <tr>
                <td style="border-bottom:none; color:#94a3b8; font-size:11px;">Paid On</td>
                <td class="amount-col" style="border-bottom:none; font-size:11px;">
                    {{ $invoice->paid_at->format('d M Y H:i') }}
                </td>
            </tr>
            @endif
        </tbody>
    </table>

    @if($invoice->notes)
    <div class="notes">{{ $invoice->notes }}</div>
    @endif

    <div class="footer">
        Generated {{ now()->format('d M Y H:i') }} · {{ $companyName }}
    </div>

</body>
</html>