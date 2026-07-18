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

#[Description('Attaches a local file from the MCP host filesystem to an Odoo record using ir.attachment. Checks for duplicate filename on the target record before creating. WRITES to Odoo.')]
#[IsOpenWorld(true)]
class AttachFileTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $resModel = trim((string) $request->get('res_model'));
            $resId = (int) $request->get('res_id');
            $path = (string) $request->get('path');
            $name = trim((string) ($request->get('name') ?: basename($path)));
            $mimetype = trim((string) ($request->get('mimetype') ?: 'application/octet-stream'));

            if ($resModel === '') {
                return Response::error('res_model is required.');
            }
            if ($resId <= 0) {
                return Response::error('res_id (>0) is required.');
            }
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                return Response::error("path must be a readable local file: {$path}");
            }
            if ($name === '') {
                return Response::error('name is required or derivable from path.');
            }

            $existing = $odoo->executeKw('ir.attachment', 'search_read', [[
                ['res_model', '=', $resModel],
                ['res_id', '=', $resId],
                ['name', '=', $name],
            ]], [
                'fields' => ['id', 'name', 'mimetype', 'file_size', 'res_model', 'res_id'],
                'limit' => 1,
            ]);

            if (! empty($existing)) {
                return Response::json([
                    'status' => 'exists',
                    'attachment_id' => $existing[0]['id'] ?? null,
                    'name' => $existing[0]['name'] ?? $name,
                    'mimetype' => $existing[0]['mimetype'] ?? null,
                    'file_size' => $existing[0]['file_size'] ?? null,
                    'res_model' => $resModel,
                    'res_id' => $resId,
                ]);
            }

            $data = base64_encode(file_get_contents($path));
            $attachmentId = $odoo->executeKw('ir.attachment', 'create', [[
                'name' => $name,
                'res_model' => $resModel,
                'res_id' => $resId,
                'type' => 'binary',
                'datas' => $data,
                'mimetype' => $mimetype,
            ]]);

            $after = $odoo->executeKw('ir.attachment', 'read', [[$attachmentId]], [
                'fields' => ['id', 'name', 'mimetype', 'file_size', 'res_model', 'res_id'],
            ]);

            return Response::json([
                'status' => 'created',
                'attachment_id' => $attachmentId,
                'name' => $after[0]['name'] ?? $name,
                'mimetype' => $after[0]['mimetype'] ?? $mimetype,
                'file_size' => $after[0]['file_size'] ?? filesize($path),
                'res_model' => $resModel,
                'res_id' => $resId,
            ]);
        } catch (Throwable $e) {
            return Response::error('attach_file failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'res_model' => $schema->string()->description('Odoo model, e.g. account.move.')->required(),
            'res_id' => $schema->integer()->description('Odoo record id.')->required(),
            'path' => $schema->string()->description('Readable local file path on the MCP host/container.')->required(),
            'name' => $schema->string()->description('Attachment filename in Odoo. Defaults to basename(path).'),
            'mimetype' => $schema->string()->description('MIME type. Defaults to application/octet-stream.'),
        ];
    }
}
