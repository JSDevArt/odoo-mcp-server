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

#[Description('Creates a vendor bill (account.move with move_type=in_invoice) in DRAFT state. Use this for expenses without a CFDI XML (e.g. international SaaS subscriptions, hosting). For Mexican expenses with XML use import_cfdi_xml instead. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class CreateVendorBillTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $partnerId = (int) $request->get('partner_id');
            $invoiceDate = (string) $request->get('invoice_date');
            $journalId = $request->get('journal_id');
            $ref = $request->get('ref');
            $lines = $request->get('lines');

            if ($partnerId <= 0) {
                return Response::error('partner_id (>0) is required.');
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
                return Response::error('invoice_date must be in YYYY-MM-DD format.');
            }
            if (! is_array($lines) || count($lines) === 0) {
                return Response::error('At least 1 line is required.');
            }

            if ($journalId === null || $journalId === '') {
                $purchase = $odoo->executeKw('account.journal', 'search_read', [[
                    ['type', '=', 'purchase'],
                    ['active', '=', true],
                ]], [
                    'fields' => ['id', 'name'],
                    'limit' => 1,
                ]);
                if (empty($purchase)) {
                    return Response::error('No active purchase journal found. Provide journal_id explicitly.');
                }
                $journalId = (int) $purchase[0]['id'];
            } else {
                $journalId = (int) $journalId;
            }

            $lineCommands = [];
            foreach ($lines as $i => $line) {
                if (! is_array($line)) {
                    return Response::error("Line #{$i}: must be an object with account_id, name, price_unit.");
                }
                $accountId = (int) ($line['account_id'] ?? 0);
                $lineName = (string) ($line['name'] ?? '');
                $priceUnit = (float) ($line['price_unit'] ?? 0);
                $quantity = (float) ($line['quantity'] ?? 1);
                $taxIds = $line['tax_ids'] ?? null;

                if ($accountId <= 0) {
                    return Response::error("Line #{$i}: account_id is required and must be > 0.");
                }
                if ($lineName === '') {
                    return Response::error("Line #{$i}: name is required.");
                }
                if ($priceUnit < 0) {
                    return Response::error("Line #{$i}: price_unit must be >= 0.");
                }
                if ($quantity <= 0) {
                    return Response::error("Line #{$i}: quantity must be > 0.");
                }

                $vals = [
                    'account_id' => $accountId,
                    'name' => $lineName,
                    'price_unit' => $priceUnit,
                    'quantity' => $quantity,
                ];

                if (is_array($taxIds) && count($taxIds) > 0) {
                    $clean = array_values(array_map('intval', $taxIds));
                    $vals['tax_ids'] = [[6, 0, $clean]];
                }

                $lineCommands[] = [0, 0, $vals];
            }

            $moveVals = [
                'move_type' => 'in_invoice',
                'partner_id' => $partnerId,
                'invoice_date' => $invoiceDate,
                'date' => $invoiceDate,
                'journal_id' => $journalId,
                'invoice_line_ids' => $lineCommands,
            ];
            if (is_string($ref) && trim($ref) !== '') {
                $moveVals['ref'] = trim($ref);
            }

            $invoiceId = $odoo->executeKw('account.move', 'create', [$moveVals]);
            if (! is_int($invoiceId)) {
                return Response::error('account.move.create did not return an integer id.');
            }

            $after = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['name', 'state', 'amount_untaxed', 'amount_tax', 'amount_total', 'partner_id', 'journal_id'],
            ]);

            return Response::json([
                'status' => 'created',
                'invoice_id' => $invoiceId,
                'invoice_name' => $after[0]['name'] ?? null,
                'state' => $after[0]['state'] ?? null,
                'partner' => isset($after[0]['partner_id']) && is_array($after[0]['partner_id']) ? $after[0]['partner_id'][1] : null,
                'journal' => isset($after[0]['journal_id']) && is_array($after[0]['journal_id']) ? $after[0]['journal_id'][1] : null,
                'amount_untaxed' => $after[0]['amount_untaxed'] ?? null,
                'amount_tax' => $after[0]['amount_tax'] ?? null,
                'amount_total' => $after[0]['amount_total'] ?? null,
                'num_lines' => count($lineCommands),
                'next_step' => 'Bill is in DRAFT. Review and post via post_move when ready.',
            ]);
        } catch (Throwable $e) {
            return Response::error('create_vendor_bill failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'partner_id' => $schema->integer()
                ->description('ID of the vendor partner (from list_partners or create_partner).')
                ->required(),
            'invoice_date' => $schema->string()
                ->description('Bill date in YYYY-MM-DD format.')
                ->required(),
            'journal_id' => $schema->integer()
                ->description('Optional purchase journal id. Defaults to the first active purchase journal.'),
            'ref' => $schema->string()
                ->description('Optional vendor reference / folio (e.g. "INV-2026-031" or the vendor invoice number).'),
            'lines' => $schema->array()
                ->items($schema->object([
                    'account_id' => $schema->integer()
                        ->description('Expense account id (from list_accounts).')
                        ->required(),
                    'name' => $schema->string()
                        ->description('Line description (e.g. "Hosting mensual mayo 2026").')
                        ->required(),
                    'price_unit' => $schema->number()
                        ->description('Unit price (before tax).')
                        ->required(),
                    'quantity' => $schema->number()
                        ->description('Quantity. Defaults to 1.'),
                    'tax_ids' => $schema->array()
                        ->items($schema->integer())
                        ->description('Optional list of account.tax ids to apply to this line.'),
                ]))
                ->min(1)
                ->description('At least 1 line with account_id, name, price_unit.')
                ->required(),
        ];
    }
}
