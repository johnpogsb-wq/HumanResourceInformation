<?php

namespace App\Console\Commands;

use App\Services\PhpMailerService;
use Illuminate\Console\Command;

class TestPhpMailerCommand extends Command
{
    protected $signature = 'mail:phpmailer {recipient? : The email address to send the test message to}';

    protected $description = 'Send a test OTP email directly using PHPMailer and report status';

    public function handle(PhpMailerService $phpMailer): int
    {
        $recipient = $this->argument('recipient') ?? config('mail.from.address') ?? config('mail.mailers.smtp.username');

        if (! $recipient) {
            $recipient = $this->ask('Enter the recipient email address');
        }

        $host = config('mail.mailers.smtp.host', env('MAIL_HOST', 'smtp.gmail.com'));
        $port = config('mail.mailers.smtp.port', env('MAIL_PORT', 587));
        $from = config('mail.from.address', env('MAIL_FROM_ADDRESS', 'primepowerhris@gmail.com'));
        $fromName = config('mail.from.name', env('MAIL_FROM_NAME', 'PrimePower Manpower HRIS'));

        $this->info("Mailer Engine: PHPMailer");
        $this->line("SMTP Host: {$host}:{$port}");
        $this->line("From: {$fromName} <{$from}>");
        $this->line("Sending test OTP email via PHPMailer to: {$recipient}...");

        $testOtp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $success = $phpMailer->sendLoginOtp($recipient, $testOtp, 120, 'PrimePower User');

        if ($success) {
            $this->newLine();
            $this->info("✓ Success! Test OTP ({$testOtp}) was sent via PHPMailer to {$recipient}.");
            $this->line("Please check your email inbox (and Spam folder).");

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->error("✗ Failed to send email via PHPMailer. Check storage/logs/laravel.log for connection details.");

        return Command::FAILURE;
    }
}
