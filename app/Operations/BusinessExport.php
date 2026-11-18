<?php

namespace App\Operations;

use App\Models\Business;
use App\Tenancy\BusinessSchema;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use ZipArchive;
final class BusinessExport
{
    public function create(Business $business): string
    {
        $root = storage_path('app/private/exports');
        if (!is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $work = $root . '/' . Str::uuid();
        mkdir($work, 0700);
        $path = $work . '.zip';
        $zip = new ZipArchive();
        $rows = 0;
        $bytes = 0;
        $manifest = ['format' => 'mkpos-business-export-v1', 'business' => $business->only('id', 'name', 'slug', 'status'), 'created_at' => now()->toIso8601String(), 'files' => [], 'notes' => 'Data portability export. Credentials, session tokens and private attachments are excluded. Restore only through a reviewed mapping into an isolated database.'];
        try {
            touch($path);
            chmod($path, 0600);
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create private export.');
            }
            chmod($path, 0600);
            DB::transaction(function () use ($business, $work, $zip, &$rows, &$bytes, &$manifest) {
                $tables = array_merge(BusinessSchema::TABLES, ['role_user', 'branch_user', 'product_tax', 'business_memberships', 'business_subscriptions', 'subscription_invoices', 'subscription_payments', 'subscription_periods', 'support_tickets', 'support_messages', 'accounts']);
                foreach ($tables as $table) {
                    if (!in_array($table, ['accounts', 'support_messages'], true) && !Schema::hasTable($table)) {
                        continue;
                    }
                    $file = $work . '/' . $table . '.jsonl';
                    $f = fopen($file, 'wb');
                    chmod($file, 0600);
                    $count = 0;
                    $query = match ($table) {
                        'accounts' => DB::table('users')->join('business_memberships', 'business_memberships.user_id', '=', 'users.id')->where('business_memberships.business_id', $business->id)->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.status', 'business_memberships.business_id')->orderBy('users.id'),
                        'support_messages' => DB::table('support_messages')->join('support_tickets', 'support_tickets.id', '=', 'support_messages.ticket_id')->where('support_tickets.business_id', $business->id)->select('support_messages.*', 'support_tickets.business_id')->orderBy('support_messages.id'),
                        default => DB::table($table)->where('business_id', $business->id)->orderBy(Schema::hasColumn($table, 'id') ? 'id' : 'business_id'),
                    };
                    try {
                        foreach ($query->lazy(500) as $record) {
                            $record = (array) $record;
                            if ($table === 'settings' && (!preg_match('/^(app\.|invoice\.|pos\.|inventory\.|appearance\.|localization\.|branch\.)/', $record['key']) || preg_match('/password|secret|token|credential|smtp/i', $record['key']))) {
                                continue;
                            }
                            if ($table === 'subscription_payments') {
                                unset($record['receipt_path'], $record['reference_hash']);
                            }
                            if ($table === 'activity_logs') {
                                unset($record['properties']);
                            }
                            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                            $rows++;
                            $count++;
                            $bytes += strlen($line);
                            if ($rows > config('operations.export_max_rows') || $bytes > config('operations.export_max_bytes')) {
                                throw new \RuntimeException('Export size limit exceeded. Contact platform support for an assisted export.');
                            }
                            fwrite($f, $line);
                        }
                    } finally {
                        fclose($f);
                    }
                    $name = 'data/' . $table . '.jsonl';
                    $zip->addFile($file, $name);
                    $manifest['files'][$name] = ['rows' => $count, 'sha256' => hash_file('sha256', $file)];
                }
            });
            $manifest['total_rows'] = $rows;
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (!$zip->close()) {
                throw new \RuntimeException('Export archive failed.');
            }
            chmod($path, 0600);
            return $path;
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\Throwable) {
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        } finally {
            foreach (glob($work . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($work);
        }
    }
}
