<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Description('Deletes a DRAFT invoice (account.move). Refuses if the invoice is posted or cancelled. WRITES to Odoo (destructive, but only safe-state records).')]
#[IsOpenWorld(true)]
class DeleteInvoiceTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $invoiceId = (int) $request->get('invoice_id');

            if ($invoiceId <= 0) {
                return Response::error('invoice_id (>0) is required.');
            }

            $invoices = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['name', 'state', 'move_type', 'amount_total', 'partner_id'],
            ]);

            if (empty($invoices)) {
                return Response::error("Invoice id={$invoiceId} not found.");
            }
            $invoice = $invoices[0];

            if ($invoice['state'] !== 'draft') {
                return Response::error(
                    "Refusing to delete invoice id={$invoiceId}: state is '{$invoice['state']}'. Only draft invoices can be deleted. Posted invoices must be cancelled first, and even then are usually kept for auditing."
                );
            }

            $ok = $odoo->executeKw('account.move', 'unlink', [[$invoiceId]]);

            if (! $ok) {
                return Response::error('unlink() returned false; no deletion applied.');
            }

            return Response::json([
                'invoice_id' => $invoiceId,
                'previous_name' => $invoice['name'],
                'partner' => is_array($invoice['partner_id']) ? $invoice['partner_id'][1] : null,
                'move_type' => $invoice['move_type'],
                'status' => 'deleted',
            ]);
        } catch (Throwable $e) {
            return Response::error('delete_invoice failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('ID of the draft invoice to delete (from list_invoices).')
                ->required(),
        ];
    }
}
