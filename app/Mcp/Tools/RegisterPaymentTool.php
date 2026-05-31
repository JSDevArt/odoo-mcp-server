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

#[Description('Registers a payment (cobro or pago) against an existing invoice. Uses Odoo account.payment.register wizard so it auto-reconciles the invoice. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class RegisterPaymentTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $invoiceId = (int) $request->get('invoice_id');
            $journalId = (int) $request->get('journal_id');
            $paymentDate = (string) $request->get('payment_date');
            $amount = $request->get('amount');
            $memo = $request->get('memo');

            if ($invoiceId <= 0 || $journalId <= 0 || $paymentDate === '') {
                return Response::error('invoice_id (>0), journal_id (>0), and payment_date (YYYY-MM-DD) are required.');
            }

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
                return Response::error('payment_date must be in YYYY-MM-DD format.');
            }

            $invoices = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['name', 'state', 'move_type', 'amount_total', 'amount_residual', 'partner_id', 'currency_id'],
            ]);

            if (empty($invoices)) {
                return Response::error("Invoice id={$invoiceId} not found.");
            }
            $invoice = $invoices[0];

            if ($invoice['state'] !== 'posted') {
                return Response::error("Invoice id={$invoiceId} is in state '{$invoice['state']}'. Only posted invoices can receive payments.");
            }

            if ((float) $invoice['amount_residual'] <= 0) {
                return Response::error("Invoice id={$invoiceId} already has zero residual amount.");
            }

            $vals = [
                'payment_date' => $paymentDate,
                'journal_id' => $journalId,
            ];
            if ($amount !== null && $amount !== '') {
                $vals['amount'] = (float) $amount;
            }
            if ($memo) {
                $vals['communication'] = (string) $memo;
            }

            $context = [
                'active_model' => 'account.move',
                'active_ids' => [$invoiceId],
                'active_id' => $invoiceId,
            ];

            $wizardId = $odoo->executeKw('account.payment.register', 'create', [$vals], [
                'context' => $context,
            ]);

            if (! is_int($wizardId)) {
                return Response::error('Could not create payment.register wizard.');
            }

            $odoo->executeKw('account.payment.register', 'action_create_payments', [[$wizardId]], [
                'context' => $context,
            ]);

            $after = $odoo->executeKw('account.move', 'read', [[$invoiceId]], [
                'fields' => ['name', 'state', 'amount_total', 'amount_residual', 'payment_state'],
            ]);

            return Response::json([
                'invoice_id' => $invoiceId,
                'invoice_name' => $invoice['name'],
                'partner' => is_array($invoice['partner_id']) ? $invoice['partner_id'][1] : null,
                'amount_total' => $invoice['amount_total'],
                'amount_residual_before' => $invoice['amount_residual'],
                'amount_residual_after' => $after[0]['amount_residual'] ?? null,
                'payment_state_after' => $after[0]['payment_state'] ?? null,
                'journal_id' => $journalId,
                'payment_date' => $paymentDate,
                'status' => 'payment_registered',
            ]);
        } catch (Throwable $e) {
            return Response::error('register_payment failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('ID of the posted invoice to pay (from list_invoices).')
                ->required(),
            'journal_id' => $schema->integer()
                ->description('Bank or cash journal id to receive/send the payment (from list_journals).')
                ->required(),
            'payment_date' => $schema->string()
                ->description('Date of the payment, format YYYY-MM-DD.')
                ->required(),
            'amount' => $schema->number()
                ->description('Optional: amount paid. Defaults to the full residual amount of the invoice.'),
            'memo' => $schema->string()
                ->description('Optional memo / communication for the payment.'),
        ];
    }
}
