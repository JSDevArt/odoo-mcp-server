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

#[Description('Force-deletes a single customer/vendor invoice (account.move) by id. If posted, attempts to reset to draft first, then unlink. WRITES to Odoo; destructive and intended only after explicit user authorization.')]
#[IsOpenWorld(true)]
class ForceDeleteInvoiceTool extends Tool
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
            $originalState = (string) $invoice['state'];
            if (! in_array($invoice['move_type'], ['in_invoice', 'in_refund', 'out_invoice', 'out_refund'], true)) {
                return Response::error("Refusing to delete account.move id={$invoiceId}: move_type '{$invoice['move_type']}' is not an invoice/refund.");
            }

            if ($originalState === 'posted') {
                try {
                    $odoo->executeKw('account.move', 'button_draft', [[$invoiceId]]);
                } catch (Throwable $e) {
                    return Response::error("Could not reset posted invoice id={$invoiceId} to draft: ".$e->getMessage());
                }
            } elseif ($originalState === 'cancel') {
                try {
                    $odoo->executeKw('account.move', 'button_draft', [[$invoiceId]]);
                } catch (Throwable $e) {
                    return Response::error("Could not reset cancelled invoice id={$invoiceId} to draft: ".$e->getMessage());
                }
            } elseif ($originalState !== 'draft') {
                return Response::error("Refusing to delete invoice id={$invoiceId}: unsupported state '{$originalState}'.");
            }

            $ok = $odoo->executeKw('account.move', 'unlink', [[$invoiceId]]);
            if (! $ok) {
                return Response::error('unlink() returned false; no deletion applied.');
            }

            return Response::json([
                'status' => 'deleted',
                'invoice_id' => $invoiceId,
                'previous_name' => $invoice['name'],
                'previous_state' => $originalState,
                'move_type' => $invoice['move_type'],
                'amount_total' => $invoice['amount_total'],
                'partner' => is_array($invoice['partner_id']) ? $invoice['partner_id'][1] : null,
            ]);
        } catch (Throwable $e) {
            return Response::error('force_delete_invoice failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('ID of the invoice to force-delete after explicit user authorization.')
                ->required(),
        ];
    }
}
