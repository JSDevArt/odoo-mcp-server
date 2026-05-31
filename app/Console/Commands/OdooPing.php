<?php

namespace App\Console\Commands;

use App\Services\Odoo\OdooClient;
use Illuminate\Console\Command;
use Throwable;

class OdooPing extends Command
{
    protected $signature = 'odoo:ping';

    protected $description = 'Autentica contra Odoo y lee el usuario actual para validar conexion.';

    public function handle(OdooClient $odoo): int
    {
        try {
            $uid = $odoo->authenticate();
            $this->info("Authenticate OK -> uid={$uid}");

            $users = $odoo->executeKw('res.users', 'read', [[$uid]], [
                'fields' => ['name', 'login', 'company_id', 'lang', 'tz'],
            ]);

            $user = $users[0] ?? null;
            if (! $user) {
                $this->error('No se pudo leer el usuario.');
                return self::FAILURE;
            }

            $this->table(['field', 'value'], [
                ['uid', $user['id']],
                ['name', $user['name']],
                ['login', $user['login']],
                ['company', is_array($user['company_id']) ? $user['company_id'][1] : '-'],
                ['lang', $user['lang']],
                ['tz', $user['tz']],
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Fallo: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
