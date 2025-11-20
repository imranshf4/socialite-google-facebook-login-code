<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class Ec extends Command
{
    protected $signature = 'code:ec
                            {--backup=backup : Backup directory name before encryption} 
                            {--only-critical : Encrypt only controllers, middleware, models, and providers}
                            {--no-backup : Skip creating backup}';

    protected $description = 'Encrypt PHP files in place (directly in app folder)';

    private $encryptionKey;
    private $criticalPaths = [
        'app',
        'routes',
    ];

    private $excludePaths = [
        'vendor',
        'node_modules',
        'config',
        'database',
        'resources',
        'storage',
        'bootstrap/cache',
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'artisan',
        'server.php',
        'public',
        '.git',
        'tests',
        '.env.example',
        'artisan',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'README.md',
        '.htaccess',
        'server.php',
        'vite.config.js',
        'tailwind.config.js',
        'jsconfig.json',
        'phpunit.xml',
        'readme.md',
        'vue.config.js',
        'debug.log',
        'env',
        'index.php',
        '.htaccess',
        'error_log',
        'webpack.mix.js',
        'backup',
        'encrypted',
    ];

    private $doNotEncryptFiles = [
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'artisan',
        'server.php',
    ];

    public function handle()
    {
        $this->info('🔐 Starting IN-PLACE code encryption process...');

        // Safety confirmation
        if (!$this->option('no-backup')) {
            $this->warn('⚠️  WARNING: This will encrypt files DIRECTLY in your app folder!');

            if (!$this->confirm('Do you want to continue? (Backup recommended)', false)) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $sourceDir = base_path();
        $backupDir = null;

        // Create backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $backupName = $this->option('backup');
            $backupDir = $this->isAbsolutePath($backupName)
                ? $backupName
                : base_path($backupName);

            $this->info('📦 Creating backup...');

            if (File::exists($backupDir)) {
                if (!$this->confirm("Backup directory exists. Overwrite?", false)) {
                    $this->error('Cannot proceed without backup.');
                    return 1;
                }
                File::deleteDirectory($backupDir);
            }

            try {
                $this->createBackup($sourceDir, $backupDir);
                $this->info("✅ Backup created at: {$backupDir}");
            } catch (\Exception $e) {
                $this->error('❌ Backup failed: ' . $e->getMessage());
                return 1;
            }
        }

        if ($this->option('only-critical')) {
            $this->warn('⚠️  CRITICAL MODE: Only encrypting controllers, middleware, models, providers, services and routes');
        }

        $this->info("📁 Encrypting files in: {$sourceDir}");

        $this->processDirectoryInPlace($sourceDir);

        $this->newLine();
        $this->info('✅ In-place encryption completed successfully!');

        if ($backupDir) {
            $this->info("📂 Original files backed up to: {$backupDir}");
            $this->warn('💡 Keep this backup safe - you cannot decrypt without it!');
        }

        return 0;
    }

    private function createBackup($source, $destination)
    {
        File::makeDirectory($destination, 0755, true, true);

        $files = File::allFiles($source);

        $this->info('Creating backup of ' . count($files) . ' files...');
        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            $relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip excluded paths
            if ($this->shouldExclude($relativePath)) {
                $bar->advance();
                continue;
            }

            $destPath = $destination . DIRECTORY_SEPARATOR . $relativePath;
            $destDir = dirname($destPath);

            if (!File::isDirectory($destDir)) {
                File::makeDirectory($destDir, 0755, true, true);
            }

            File::copy($file->getPathname(), $destPath);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function isAbsolutePath($path)
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:/i', $path);
    }

    private function processDirectoryInPlace($source)
    {
        $files = File::allFiles($source);

        $filesToProcess = [];
        foreach ($files as $file) {
            $relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            if (!$this->shouldExclude($relativePath)) {
                $filesToProcess[] = ['file' => $file, 'path' => $relativePath];
            }
        }

        $this->info('Found ' . count($filesToProcess) . ' files to process...');
        $bar = $this->output->createProgressBar(count($filesToProcess));
        $bar->start();

        $encrypted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($filesToProcess as $item) {
            try {
                $file = $item['file'];
                $relativePath = $item['path'];
                $filePath = $file->getPathname();

                // Only process PHP files that should be encrypted
                if ($file->getExtension() === 'php' && !$this->shouldNotEncrypt($relativePath)) {
                    if ($this->option('only-critical') && !$this->isCriticalPath($relativePath)) {
                        $skipped++;
                    } else {
                        $this->encryptionKey = $this->generateKey();

                        $this->encryptFileInPlace($filePath);
                        $encrypted++;
                    }
                } else {
                    $skipped++;
                }

                $bar->advance();
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error: " . $file->getFilename() . " - " . $e->getMessage());
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Encrypted: {$encrypted} PHP files");
        $this->info("⏭️  Skipped: {$skipped} files");
        if ($errors > 0) {
            $this->warn("⚠️  Errors: {$errors} files");
        }
    }

    private function encryptFileInPlace($filePath)
    {
        // Read original content
        $code = file_get_contents($filePath);

        // Simple base64 + XOR encryption
        $encrypted = base64_encode($this->xorEncrypt($code, $this->encryptionKey));

        // Create loader
        $loader = $this->createSimpleLoader($encrypted);

        // Overwrite the original file with encrypted version
        file_put_contents($filePath, $loader);
    }

    private function xorEncrypt($string, $key)
    {
        $result = '';
        $keyLength = strlen($key);

        for ($i = 0; $i < strlen($string); $i++) {
            $result .= $string[$i] ^ $key[$i % $keyLength];
        }

        return $result;
    }

    private function createSimpleLoader($encryptedData)
    {
        // Use random function names
        $decrypt = 'x_' . substr(md5(rand()), 0, 300);
        $key = 'x_' . substr(md5(rand()), 0, 300);
        $data = 'x_' . substr(md5(rand()), 0, 300);
        $result = 'x_' . substr(md5(rand()), 0, 300);

        $loader = "<?php\n";
        $loader .= "if(!function_exists('{$decrypt}')){\n";
        $loader .= "function {$decrypt}(\${$data},\${$key}){\n";
        $loader .= "\${$result}='';";
        $loader .= "\$len=strlen(\${$key});";
        $loader .= "for(\$i=0;\$i<strlen(\${$data});\$i++){\n";
        $loader .= "\${$result}.=\${$data}[\$i]^\${$key}[\$i%\$len];}\n";
        $loader .= "return \${$result};}}\n";
        $loader .= "\${$key}='" . $this->encryptionKey . "';\n";
        $loader .= "\${$data}='{$encryptedData}';\n";
        $loader .= "\${$result}={$decrypt}(base64_decode(\${$data}),\${$key});\n";
        $loader .= "\${$result}=preg_replace('/^<\\?php/i','',\${$result},1);\n";
        $loader .= "eval(\${$result});";

        return $loader;
    }

    private function shouldExclude($path)
    {
        foreach ($this->excludePaths as $exclude) {
            if (str_starts_with($path, $exclude)) {
                return true;
            }
        }
        return false;
    }

    private function shouldNotEncrypt($path)
    {
        foreach ($this->doNotEncryptFiles as $file) {
            if ($path === $file || str_ends_with($path, $file)) {
                return true;
            }
        }
        return false;
    }

    private function isCriticalPath($path)
    {
        foreach ($this->criticalPaths as $criticalPath) {
            if (str_starts_with($path, $criticalPath)) {
                return true;
            }
        }
        return false;
    }

    private function generateKey()
    {
        return bin2hex(random_bytes(256));
    }
}