<?php

namespace App\Mcp\Tools;

use App\Services\Odoo\OdooClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Description('Creates a custom field (x_ prefix, state manual) on account.move of type char, selection or boolean, and shows it on the vendor bill form (Other Info tab, only for in_invoice/in_refund) via an inherited view of account.view_move_form. Idempotent: reuses existing field/view. WRITES to Odoo.')]
#[IsOpenWorld(true)]
#[IsIdempotent(true)]
class CreateVendorBillCustomFieldTool extends Tool
{
    public function handle(Request $request, OdooClient $odoo): Response
    {
        try {
            $name = (string) $request->get('name');
            $label = (string) $request->get('label');
            $type = (string) $request->get('type');
            $options = $request->get('selection_options') ?? [];

            if (! preg_match('/^x_[a-z0-9_]+$/', $name) || $label === '') {
                return Response::error('name must match ^x_[a-z0-9_]+$ and label is required.');
            }
            if (! in_array($type, ['char', 'selection', 'boolean'], true)) {
                return Response::error('type must be char, selection or boolean.');
            }
            if ($type === 'selection' && empty($options)) {
                return Response::error('selection_options required for selection type.');
            }

            $model = $odoo->executeKw('ir.model', 'search_read', [[['model', '=', 'account.move']]], ['fields' => ['id'], 'limit' => 1]);
            if (empty($model)) {
                return Response::error('ir.model account.move not found.');
            }
            $modelId = $model[0]['id'];

            $existing = $odoo->executeKw('ir.model.fields', 'search_read', [[['model', '=', 'account.move'], ['name', '=', $name]]], ['fields' => ['id', 'ttype'], 'limit' => 1]);
            $fieldCreated = false;
            if (! empty($existing)) {
                $fieldId = $existing[0]['id'];
                if ($existing[0]['ttype'] !== $type) {
                    return Response::error("Field {$name} already exists with type {$existing[0]['ttype']}.");
                }
            } else {
                $vals = [
                    'name' => $name,
                    'field_description' => $label,
                    'model_id' => $modelId,
                    'ttype' => $type,
                    'state' => 'manual',
                    'copied' => false,
                ];
                if ($type === 'selection') {
                    $vals['selection_ids'] = array_map(fn ($o, $i) => [0, 0, ['value' => (string) $o, 'name' => (string) $o, 'sequence' => $i]], array_values($options), array_keys(array_values($options)));
                }
                $fieldId = $odoo->executeKw('ir.model.fields', 'create', [$vals]);
                $fieldCreated = true;
            }

            $viewName = "account.move.form.vendor.{$name}";
            $view = $odoo->executeKw('ir.ui.view', 'search_read', [[['name', '=', $viewName], ['model', '=', 'account.move']]], ['fields' => ['id'], 'limit' => 1]);
            $viewCreated = false;
            if (! empty($view)) {
                $viewId = $view[0]['id'];
            } else {
                $parent = $odoo->executeKw('ir.model.data', 'search_read', [[['module', '=', 'account'], ['name', '=', 'view_move_form'], ['model', '=', 'ir.ui.view']]], ['fields' => ['res_id'], 'limit' => 1]);
                if (empty($parent)) {
                    return Response::error("account.view_move_form not found; field id={$fieldId} ".($fieldCreated ? 'was created' : 'already existed').'.');
                }
                $arch = '<data><xpath expr="//group[@id=\'other_tab_group\']" position="inside">'
                    ."<group name=\"{$name}_group\" invisible=\"move_type not in ('in_invoice', 'in_refund')\">"
                    ."<field name=\"{$name}\"/></group>"
                    .'</xpath></data>';
                $viewId = $odoo->executeKw('ir.ui.view', 'create', [[
                    'name' => $viewName,
                    'model' => 'account.move',
                    'type' => 'form',
                    'inherit_id' => $parent[0]['res_id'],
                    'mode' => 'extension',
                    'priority' => 99,
                    'arch_base' => $arch,
                ]]);
                $viewCreated = true;
            }

            return Response::json([
                'field' => $name,
                'field_id' => $fieldId,
                'field_created' => $fieldCreated,
                'view_id' => $viewId,
                'view_name' => $viewName,
                'view_created' => $viewCreated,
                'status' => 'ok',
            ]);
        } catch (Throwable $e) {
            return Response::error('create_vendor_bill_custom_field failed: '.$e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Technical name, must start with x_ (e.g. x_my_field).')->required(),
            'label' => $schema->string()->description('Visible label (e.g. "My Field").')->required(),
            'type' => $schema->string()->enum(['char', 'selection', 'boolean'])->description('Field type.')->required(),
            'selection_options' => $schema->array()->items($schema->string())->description('Options for selection type.'),
        ];
    }
}
