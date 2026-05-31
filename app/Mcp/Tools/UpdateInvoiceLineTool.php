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

#[Description('Updates a single line of a DRAFT invoice (account.move.line). Identifies the line by its 0-based index within invoice_line_ids. Any combination of price_unit, account_id, name, tax_ids may be passed. Refuses if the invoice is not in draft. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class UpdateInvoiceLineTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $invoiceId = (int) $request->get('invoice_id');
            if ($invoiceId <= 0) {
                return Response::error('invoice_id (>0) is required.');
            }

            $lineIndex = (int) ($request->get('line_index') ?? 0);
            if ($lineIndex < 0) {
                return Response::error('line_index must be >= 0.');
            }

            $priceUnit = $request->get('price_unit');
            $accountId = $request->get('account_id');
            $name = $request->get('name');
            $taxIds = $request->get('tax_ids');

            $invoice = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['name', 'state', 'invoice_line_ids', 'move_type'],
            ]);
            if (empty($invoice)) {
                return Response::error("Invoice id={$invoiceId} not found.");
            }
            if ($invoice[0]['state'] !== 'draft') {
                return Response::error(
                    "Invoice id={$invoiceId} is in state '{$invoice[0]['state']}'. Only draft invoices can have lines edited."
                );
            }

            $lineIds = $invoice[0]['invoice_line_ids'] ?? [];
            if (! is_array($lineIds) || count($lineIds) === 0) {
                return Response::error("Invoice id={$invoiceId} has no invoice lines to edit.");
            }
            if ($lineIndex >= count($lineIds)) {
                return Response::error(sprintf(
                    'line_index=%d is out of bounds; invoice has %d lines (valid indices 0..%d).',
                    $lineIndex,
                    count($lineIds),
                    count($lineIds) - 1
                ));
            }

            $lineId = (int) $lineIds[$lineIndex];

            $vals = [];
            if ($priceUnit !== null && $priceUnit !== '') {
                $price = (float) $priceUnit;
                if ($price < 0) {
                    return Response::error('price_unit must be >= 0.');
                }
                $vals['price_unit'] = $price;
            }
            if ($accountId !== null && $accountId !== '') {
                $aid = (int) $accountId;
                if ($aid <= 0) {
                    return Response::error('account_id must be > 0.');
                }
                $vals['account_id'] = $aid;
            }
            if (is_string($name) && trim($name) !== '') {
                $vals['name'] = trim($name);
            }
            if ($taxIds !== null) {
                if (! is_array($taxIds)) {
                    return Response::error('tax_ids must be an array of integers.');
                }
                $clean = array_values(array_map('intval', $taxIds));
                $vals['tax_ids'] = [[6, 0, $clean]];
            }

            if (count($vals) === 0) {
                return Response::error('No fields provided to update. Pass at least one of: price_unit, account_id, name, tax_ids.');
            }

            $ok = $odoo->executeKw('account.move.line', 'write', [[$lineId], $vals]);
            if (! $ok) {
                return Response::error('write() returned false; no change applied.');
            }

            $after = $odoo->executeKw('account.move.line', 'read', [[$lineId]], [
                'fields' => ['name', 'price_unit', 'quantity', 'discount', 'price_subtotal', 'price_total', 'account_id', 'tax_ids'],
            ]);

            return Response::json([
                'status' => 'updated',
                'invoice_id' => $invoiceId,
                'line_id' => $lineId,
                'line_index' => $lineIndex,
                'updated_fields' => array_keys($vals),
                'line_after' => $after[0] ?? null,
            ]);
        } catch (Throwable $e) {
            return Response::error('update_invoice_line failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('ID of the draft invoice / vendor bill / journal entry (account.move).')
                ->required(),
            'line_index' => $schema->integer()
                ->description('0-based index of the line to update within invoice_line_ids. Defaults to 0 (first line).'),
            'price_unit' => $schema->number()
                ->description('Optional: new unit price for the line.'),
            'account_id' => $schema->integer()
                ->description('Optional: new chart-of-accounts id for the line (from list_accounts).'),
            'name' => $schema->string()
                ->description('Optional: new description text for the line.'),
            'tax_ids' => $schema->array()
                ->items($schema->integer())
                ->description('Optional: replaces tax_ids with this set of account.tax IDs. Pass [] to clear taxes.'),
        ];
    }
}
