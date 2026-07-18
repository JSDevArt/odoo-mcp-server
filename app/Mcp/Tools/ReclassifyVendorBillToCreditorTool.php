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

#[Description('Reclassifies a posted vendor bill payable from its supplier to another creditor/bridge partner using a manual journal entry, then reconciles the vendor payable line. Use for reimbursements/funder bridge flows. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class ReclassifyVendorBillToCreditorTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $invoiceId = (int) $request->get('invoice_id');
            $creditorPartnerId = (int) $request->get('creditor_partner_id');
            $bridgeAccountId = (int) $request->get('bridge_account_id');
            $journalId = (int) $request->get('journal_id');
            $date = (string) $request->get('date');
            $amountParam = $request->get('amount');
            $ref = trim((string) ($request->get('ref') ?: ''));
            $narration = trim((string) ($request->get('narration') ?: ''));
            $post = $request->get('post');
            $post = $post === null ? true : (bool) $post;

            if ($invoiceId <= 0) {
                return Response::error('invoice_id (>0) is required.');
            }
            if ($creditorPartnerId <= 0) {
                return Response::error('creditor_partner_id (>0) is required.');
            }
            if ($bridgeAccountId <= 0) {
                return Response::error('bridge_account_id (>0) is required.');
            }
            if ($journalId <= 0) {
                return Response::error('journal_id (>0) is required.');
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return Response::error('date must be in YYYY-MM-DD format.');
            }

            $invoiceRows = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['id', 'name', 'state', 'move_type', 'partner_id', 'amount_total', 'amount_residual', 'payment_state', 'currency_id', 'invoice_date', 'ref'],
            ]);
            if (empty($invoiceRows)) {
                return Response::error("Vendor bill id={$invoiceId} not found.");
            }
            $invoice = $invoiceRows[0];
            if ($invoice['move_type'] !== 'in_invoice') {
                return Response::error("Refusing invoice id={$invoiceId}: move_type '{$invoice['move_type']}' is not vendor bill/in_invoice.");
            }
            if ($invoice['state'] !== 'posted') {
                return Response::error("Vendor bill id={$invoiceId} must be posted before reclassification. Current state: {$invoice['state']}.");
            }

            $residual = round((float) ($invoice['amount_residual'] ?? 0), 2);
            if ($residual <= 0) {
                return Response::error("Vendor bill id={$invoiceId} has no residual to reclassify.");
            }
            $amount = $amountParam === null ? $residual : round((float) $amountParam, 2);
            if ($amount <= 0) {
                return Response::error('amount must be > 0.');
            }
            if ($amount - $residual > 0.005) {
                return Response::error(sprintf('amount %.2f exceeds invoice residual %.2f.', $amount, $residual));
            }

            $vendorPartnerId = is_array($invoice['partner_id']) ? (int) $invoice['partner_id'][0] : 0;
            if ($vendorPartnerId <= 0) {
                return Response::error('Vendor bill has no partner_id.');
            }

            $payableLines = $odoo->executeKw('account.move.line', 'search_read', [[
                ['move_id', '=', $invoiceId],
                ['partner_id', '=', $vendorPartnerId],
                ['account_id.account_type', '=', 'liability_payable'],
                ['reconciled', '=', false],
            ]], [
                'fields' => ['id', 'name', 'account_id', 'partner_id', 'debit', 'credit', 'balance', 'amount_residual', 'reconciled'],
                'limit' => 10,
            ]);

            if (empty($payableLines)) {
                return Response::error("No open payable line found for vendor bill id={$invoiceId}.");
            }

            $payableLine = null;
            foreach ($payableLines as $line) {
                if (round(abs((float) ($line['amount_residual'] ?? 0)), 2) + 0.005 >= $amount) {
                    $payableLine = $line;
                    break;
                }
            }
            $payableLine = $payableLine ?: $payableLines[0];
            $payableAccountId = is_array($payableLine['account_id']) ? (int) $payableLine['account_id'][0] : 0;
            if ($payableAccountId <= 0) {
                return Response::error('Could not determine payable account_id from invoice payable line.');
            }

            $moveRef = $ref !== '' ? $ref : 'Bridge creditor reclass '.$invoice['name'];
            $moveVals = [
                'move_type' => 'entry',
                'journal_id' => $journalId,
                'date' => $date,
                'ref' => $moveRef,
                'line_ids' => [
                    [0, 0, [
                        'account_id' => $payableAccountId,
                        'partner_id' => $vendorPartnerId,
                        'name' => 'Bridge reclass debit '.$invoice['name'],
                        'debit' => $amount,
                        'credit' => 0,
                    ]],
                    [0, 0, [
                        'account_id' => $bridgeAccountId,
                        'partner_id' => $creditorPartnerId,
                        'name' => 'Bridge creditor credit '.$invoice['name'],
                        'debit' => 0,
                        'credit' => $amount,
                    ]],
                ],
            ];
            if ($narration !== '') {
                $moveVals['narration'] = $narration;
            }

            $moveId = $odoo->executeKw('account.move', 'create', [$moveVals]);
            if (! is_int($moveId)) {
                return Response::error('account.move.create did not return an integer id.');
            }

            if ($post) {
                $odoo->executeKw('account.move', 'action_post', [[$moveId]]);
            }

            $newDebitLines = $odoo->executeKw('account.move.line', 'search_read', [[
                ['move_id', '=', $moveId],
                ['partner_id', '=', $vendorPartnerId],
                ['account_id', '=', $payableAccountId],
                ['debit', '>', 0],
            ]], [
                'fields' => ['id', 'name', 'account_id', 'partner_id', 'debit', 'credit', 'balance', 'amount_residual', 'reconciled'],
                'limit' => 1,
            ]);

            $reconciled = false;
            if ($post && ! empty($newDebitLines)) {
                $odoo->executeKw('account.move.line', 'reconcile', [[(int) $payableLine['id'], (int) $newDebitLines[0]['id']]]);
                $reconciled = true;
            }

            $afterInvoiceRows = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['id', 'name', 'state', 'amount_total', 'amount_residual', 'payment_state', 'partner_id'],
            ]);
            $afterMoveRows = $odoo->executeKw('account.move', 'read', [[$moveId]], [
                'fields' => ['id', 'name', 'state', 'date', 'ref'],
            ]);

            return Response::json([
                'status' => 'created',
                'invoice_id' => $invoiceId,
                'invoice_name' => $invoice['name'] ?? null,
                'journal_entry_id' => $moveId,
                'journal_entry' => $afterMoveRows[0] ?? null,
                'amount' => $amount,
                'vendor_partner_id' => $vendorPartnerId,
                'creditor_partner_id' => $creditorPartnerId,
                'payable_account_id' => $payableAccountId,
                'bridge_account_id' => $bridgeAccountId,
                'invoice_payable_line_id' => $payableLine['id'] ?? null,
                'bridge_debit_line_id' => $newDebitLines[0]['id'] ?? null,
                'reconciled_vendor_payable' => $reconciled,
                'invoice_after' => $afterInvoiceRows[0] ?? null,
            ]);
        } catch (Throwable $e) {
            return Response::error('reclassify_vendor_bill_to_creditor failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()->description('Posted vendor bill id (account.move in_invoice) to reclassify.')->required(),
            'creditor_partner_id' => $schema->integer()->description('Partner id of creditor/funder/bridge party that JSDevArt now owes.')->required(),
            'bridge_account_id' => $schema->integer()->description('Payable/reconcilable account for the creditor, e.g. Various Creditors.')->required(),
            'journal_id' => $schema->integer()->description('Manual/general journal id, e.g. Miscellaneous Operations.')->required(),
            'date' => $schema->string()->description('Journal entry date YYYY-MM-DD.')->required(),
            'amount' => $schema->number()->description('Amount to reclassify. Defaults to full residual.'),
            'ref' => $schema->string()->description('Optional reference.'),
            'narration' => $schema->string()->description('Optional internal note/narration.'),
            'post' => $schema->boolean()->description('Whether to post and reconcile immediately. Defaults to true.'),
        ];
    }
}
