<?php

namespace App\Console\Commands;

use App\Models\ChargerCommand;
use App\Services\Charging\CommandSecretFields;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SealCommandSecrets extends Command
{
    protected $signature = 'csms:seal-command-secrets {--apply} {--check} {--rekey} {--after=0} {--limit=100}';

    protected $description = 'Inspect or seal retained command secrets without dispatching or altering operational history';

    public function handle(): int
    {
        $after = (string) $this->option('after');
        $limit = (string) $this->option('limit');
        if (! ctype_digit($after) || strlen($after) > 18 || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ($this->option('check') && ($this->option('apply') || $this->option('rekey')))) {
            $this->error('Use a nonnegative after ID and limit1–1000; check cannot combine with apply/rekey.');

            return self::INVALID;
        }
        $ids = ChargerCommand::where('id', '>', (int) $after)->orderBy('id')->limit((int) $limit)->pluck('id');
        $changed = 0;
        foreach ($ids as $id) {
            try {
                $changed += DB::transaction(function () use ($id): int {
                    $command = ChargerCommand::whereKey($id)->lockForUpdate()->first();
                    if (! $command) {
                        return 0;
                    }
                    $attributes = $command->getAttributes();
                    $updates = [];
                    foreach (['payload', 'confirmation'] as $field) {
                        $raw = $attributes[$field] ?? null;
                        $sealed = CommandSecretFields::seal($raw, $attributes, $field, (bool) $this->option('rekey'));
                        if ($sealed !== $raw) {
                            $updates[$field] = $sealed;
                        }
                    }
                    if ($updates !== [] && $this->option('apply')) {
                        // No model events, timestamps, intent hashes or outcomes.
                        DB::table('charger_commands')->where('id', $id)->update($updates);
                    }

                    return (int) ($updates !== []);
                });
            } catch (Throwable) {
                $this->error('Command secret inspection failed at ID '.(int) $id.'; no command sent. Resume after repairing this row/key, not by skipping an unknown outcome.');

                return self::FAILURE;
            }
        }
        $this->line(json_encode(['examined' => $ids->count(), 'requires_sealing' => $changed, 'applied' => (bool) $this->option('apply'), 'rekey' => (bool) $this->option('rekey'), 'next_after_id' => $ids->count() === (int) $limit ? $ids->last() : 0, 'network_requests' => 0, 'commands_dispatched' => 0], JSON_THROW_ON_ERROR));

        return $this->option('check') && $changed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
