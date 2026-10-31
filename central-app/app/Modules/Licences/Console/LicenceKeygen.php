<?php

namespace App\Modules\Licences\Console;

use App\Modules\Licences\Support\LicenceSigner;
use Illuminate\Console\Command;

/**
 * A new signing key for activation codes: the seed for the server's
 * environment, and the public key to build into the desktop app. Codes
 * signed with an old key stop working in an app built with the new one.
 */
class LicenceKeygen extends Command
{
    protected $signature = 'licences:keygen {--show : Show the public key of the key configured now}';

    protected $description = 'Make a signing key for desktop activation codes (or show the current public key)';

    public function handle(): int
    {
        if ($this->option('show')) {
            $signer = LicenceSigner::fromConfig();
            if (! $signer->configured()) {
                $this->error('PLATFORM_LICENCE_SIGNING_KEY is not set.');

                return self::FAILURE;
            }
            $this->line('Public key (PUBLIC_KEY in pharmacy-electron-app src/main/licence/codes.ts):');
            $this->line($signer->publicKey());

            return self::SUCCESS;
        }

        $seed = LicenceSigner::newSeed();
        $this->line('Server environment (keep secret, never in git):');
        $this->line("PLATFORM_LICENCE_SIGNING_KEY={$seed}");
        $this->newLine();
        $this->line('Public key (PUBLIC_KEY in pharmacy-electron-app src/main/licence/codes.ts):');
        $this->line((new LicenceSigner($seed))->publicKey());

        return self::SUCCESS;
    }
}
