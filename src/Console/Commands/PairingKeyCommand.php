<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Neocode\LaravelAppPairing\Support\EnvFileWriter;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;

/**
 * Generates, displays, or interactively sets the APP_PAIRING_TOKEN
 * bootstrap secret, and provisions PAIRING_APP_ID if it is missing.
 * The bootstrap token only ever authorizes the initial pairing handshake;
 * it is never used for normal signed communications. PAIRING_APP_ID is not
 * a secret — it is this installation's stable identity and only needs to
 * be generated once, ever, per installation.
 */
class PairingKeyCommand extends Command
{
    protected $signature = 'pairing:key
        {--show : Display the current APP_PAIRING_TOKEN in plain text}
        {--set : Interactively set APP_PAIRING_TOKEN via masked input}
        {--force : Overwrite an existing token without confirmation}';

    protected $description = 'Generate, display, or set the APP_PAIRING_TOKEN bootstrap secret, provisioning PAIRING_APP_ID if needed.';

    public function handle(): int
    {
        $writer = new EnvFileWriter(app()->environmentFilePath());

        $this->ensureAppId($writer);

        if ($this->option('show')) {
            return $this->showToken($writer);
        }

        if ($this->option('set')) {
            return $this->setToken($writer);
        }

        return $this->generateToken($writer);
    }

    /**
     * PAIRING_APP_ID identifies this installation and must stay stable for
     * its lifetime, but it is not a secret and there is no reason to make
     * the user generate it by hand. Provision it once, silently, the first
     * time this command runs, and never touch it again afterward.
     */
    private function ensureAppId(EnvFileWriter $writer): void
    {
        if ($writer->get('PAIRING_APP_ID') !== null) {
            return;
        }

        $appId = (string) Str::ulid();

        $writer->set('PAIRING_APP_ID', $appId);

        $this->components->info("A new PAIRING_APP_ID has been generated and saved to .env: {$appId}");
    }

    private function showToken(EnvFileWriter $writer): int
    {
        $current = $writer->get('APP_PAIRING_TOKEN');

        if ($current === null) {
            $this->components->error('No APP_PAIRING_TOKEN is currently configured.');

            return self::FAILURE;
        }

        $this->components->warn('The value below is a secret. Avoid pasting it anywhere it could be logged.');
        $this->line($current);

        return self::SUCCESS;
    }

    private function setToken(EnvFileWriter $writer): int
    {
        $decision = $this->confirmOverwrite($writer);

        if ($decision === null) {
            return self::FAILURE;
        }

        if ($decision === false) {
            return self::SUCCESS;
        }

        $token = password('Enter the new APP_PAIRING_TOKEN (input hidden)');

        if (trim($token) === '') {
            $this->components->error('The token cannot be empty.');

            return self::FAILURE;
        }

        $writer->set('APP_PAIRING_TOKEN', $token);
        $this->components->info('APP_PAIRING_TOKEN has been updated.');

        return self::SUCCESS;
    }

    private function generateToken(EnvFileWriter $writer): int
    {
        $decision = $this->confirmOverwrite($writer);

        if ($decision === null) {
            return self::FAILURE;
        }

        if ($decision === false) {
            return self::SUCCESS;
        }

        $writer->set('APP_PAIRING_TOKEN', base64_encode(random_bytes(32)));

        $this->components->info('A new APP_PAIRING_TOKEN has been generated and saved to .env.');
        $this->components->warn('Run `php artisan pairing:key --show` if you need to view it.');

        return self::SUCCESS;
    }

    /**
     * @return bool|null true = proceed, false = gracefully aborted, null = hard failure
     */
    private function confirmOverwrite(EnvFileWriter $writer): ?bool
    {
        if ($writer->get('APP_PAIRING_TOKEN') === null || $this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('An APP_PAIRING_TOKEN is already set. Pass --force to overwrite it non-interactively.');

            return null;
        }

        if (! confirm('An APP_PAIRING_TOKEN is already set. Overwrite it?', default: false)) {
            $this->components->warn('Aborted.');

            return false;
        }

        return true;
    }
}
