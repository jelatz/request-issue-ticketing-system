<?php

namespace App\Console\Commands;

use App\Models\TicketAttachment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('attachments:migrate-disk {from : Source filesystem disk name} {to : Target filesystem disk name} {--delete-source : Delete source files after verifying copy}')]
#[Description('Migrate ticket attachment files from one filesystem disk to another')]
class MigrateAttachmentsDiskCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');

        /** @var array<string, array<string, mixed>> $disks */
        $disks = config('filesystems.disks', []);

        if (! array_key_exists($from, $disks)) {
            $this->error("Source disk [{$from}] is not configured in filesystems.disks.");

            return self::FAILURE;
        }

        if (! array_key_exists($to, $disks)) {
            $this->error("Target disk [{$to}] is not configured in filesystems.disks.");

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('Source and target disks must be different.');

            return self::FAILURE;
        }

        $sourceDisk = Storage::disk($from);
        $targetDisk = Storage::disk($to);
        $deleteSource = (bool) $this->option('delete-source');

        $copied = 0;
        $skipped = 0;
        $missing = 0;

        foreach (TicketAttachment::query()->orderBy('id')->lazyById(200) as $attachment) {
            if ($targetDisk->exists($attachment->file_path)) {
                $skipped++;

                continue;
            }

            if (! $sourceDisk->exists($attachment->file_path)) {
                $this->warn("Missing source file for attachment #{$attachment->id}: {$attachment->file_path}");
                $missing++;

                continue;
            }

            $stream = $sourceDisk->readStream($attachment->file_path);

            if ($stream === null || $stream === false) {
                $this->warn("Missing source file for attachment #{$attachment->id}: {$attachment->file_path}");
                $missing++;

                continue;
            }

            try {
                $written = $targetDisk->writeStream($attachment->file_path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $written || ! $targetDisk->exists($attachment->file_path)) {
                $this->warn("Failed to copy attachment #{$attachment->id}: {$attachment->file_path}");
                $missing++;

                continue;
            }

            $copied++;

            if ($deleteSource) {
                $sourceDisk->delete($attachment->file_path);
            }
        }

        $this->line("Attachment migration complete. Copied: {$copied}, Skipped: {$skipped}, Missing: {$missing}.");

        return self::SUCCESS;
    }
}
