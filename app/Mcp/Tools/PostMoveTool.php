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

#[Description('Posts (publishes) a DRAFT account.move — invoice, vendor bill, or manual journal entry. Calls action_post in Odoo. Refuses if the move is already posted. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class PostMoveTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $moveId = (int) $request->get('move_id');
            if ($moveId <= 0) {
                return Response::error('move_id (>0) is required.');
            }

            $before = $odoo->executeKw('account.move', 'read', [[$moveId]], [
                'fields' => ['name', 'state', 'move_type'],
            ]);

            if (empty($before)) {
                return Response::error("Move id={$moveId} not found.");
            }

            $currentState = $before[0]['state'];
            if ($currentState === 'posted') {
                return Response::error(
                    "Move id={$moveId} is already posted (name: {$before[0]['name']}). No action taken."
                );
            }
            if ($currentState !== 'draft') {
                return Response::error(
                    "Move id={$moveId} is in state '{$currentState}'. Only draft moves can be posted."
                );
            }

            $odoo->executeKw('account.move', 'action_post', [[$moveId]]);

            $after = $odoo->executeKw('account.move', 'read', [[$moveId]], [
                'fields' => ['name', 'state', 'move_type'],
            ]);

            return Response::json([
                'move_id' => $moveId,
                'move_name' => $after[0]['name'] ?? null,
                'move_type' => $after[0]['move_type'] ?? null,
                'status' => 'posted',
            ]);
        } catch (Throwable $e) {
            return Response::error('post_move failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'move_id' => $schema->integer()
                ->description('ID of the draft account.move (invoice, vendor bill, or journal entry) to post.')
                ->required(),
        ];
    }
}
