<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class ConfigureWebPush extends Command
{
    protected $signature = 'mediahub:configure-push';

    protected $description = 'Create private persistent browser push keys when not configured.';

    public function handle(): int
    {
        $path = storage_path('app/private/webpush/keys.json');
        if (is_file($path) || (config('webpush.public_key') && config('webpush.private_key'))) {
            $this->info('Existing push keys preserved.');

            return self::SUCCESS;
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error('Could not create private key directory.');

            return self::FAILURE;
        }
        $keys = VAPID::createVapidKeys();
        $handle = fopen($path, 'x');
        if (! $handle) {
            $this->error('Could not create private push keys.');

            return self::FAILURE;
        }
        chmod($path, 0600);
        $contents = json_encode($keys, JSON_THROW_ON_ERROR);
        $written = fwrite($handle, $contents);
        fclose($handle);
        if ($written !== strlen($contents)) {
            unlink($path);
            $this->error('Push key write failed.');

            return self::FAILURE;
        }
        $this->info('Private push keys created. Rebuild the configuration cache before serving requests.');

        return self::SUCCESS;
    }
}
