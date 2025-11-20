<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class Enc extends Command
{
    protected $signature = 'code:enc
                            {--output=encrypted : Output directory} 
                            {--skip-vendor : Skip copying vendor directory}
                            {--only-critical : Encrypt only controllers, middleware, models, and providers}';

    protected $description = 'Encrypt PHP files using simple compatible method';

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
        'error_log',
        'webpack.mix.js',
    ];

    private $doNotEncryptFiles = [
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'routes/merchant.php',
        'artisan',
        'server.php'
    ];

    public function handle()
    {
        $this->info('🔐 Starting SIMPLE code encryption process...');

        // Generate encryption key
        $this->encryptionKey =  $this->generateKey();

        if (!$this->encryptionKey) {
            $this->error('❌ No APP_KEY found!');
            return 1;
        }

        $outputDir = $this->option('output');
        $sourceDir = base_path();

        if (!$this->isAbsolutePath($outputDir)) {
            $outputDir = base_path($outputDir);
        }

        if (realpath($outputDir) === realpath($sourceDir)) {
            $this->error('❌ Output directory cannot be the same as source directory!');
            return 1;
        }

        if (File::exists($outputDir)) {
            if (!$this->confirm("Output directory exists. Delete and continue?")) {
                return 0;
            }
            try {
                File::deleteDirectory($outputDir);
            } catch (\Exception $e) {
                $this->error('❌ Could not delete existing directory: ' . $e->getMessage());
                return 1;
            }
        }

        try {
            File::makeDirectory($outputDir, 0755, true, true);
        } catch (\Exception $e) {
            $this->error('❌ Could not create output directory: ' . $e->getMessage());
            return 1;
        }

        $this->info("📁 Source: {$sourceDir}");
        $this->info("📁 Output: {$outputDir}");

        if ($this->option('only-critical')) {
            $this->warn('⚠️  CRITICAL MODE: Only encrypting controllers, middleware, models, providers , services  and routes');
        }

        $this->processDirectory($sourceDir, $outputDir);
        // $this->copyEssentialFiles($sourceDir, $outputDir);

        $this->newLine();
        $this->info('✅ Encryption completed successfully!');
        $this->info("📂 Encrypted files location: {$outputDir}");

        return 0;
    }

    private function isAbsolutePath($path)
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:/i', $path);
    }

    private function processDirectory($source, $destination)
    {
        $files = File::allFiles($source);

        $filesToProcess = [];
        foreach ($files as $file) {
            $relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);

            if (!$this->shouldExclude($relativePath)) {
                $filesToProcess[] = $file;
            }
        }

        $this->info('Found ' . count($filesToProcess) . ' files to process...');
        $bar = $this->output->createProgressBar(count($filesToProcess));
        $bar->start();

        $encrypted = 0;
        $copied = 0;
        $errors = 0;

        foreach ($filesToProcess as $file) {
            try {
                $relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);
                $destPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

                $destDir = dirname($destPath);
                if (!File::isDirectory($destDir)) {
                    File::makeDirectory($destDir, 0755, true, true);
                }

                if ($file->getExtension() === 'php' && !$this->shouldNotEncrypt($relativePath)) {
                    if ($this->option('only-critical') && !$this->isCriticalPath($relativePath)) {
                        File::copy($file->getPathname(), $destPath);
                        $copied++;
                    } else {
                        $this->encryptionKey =  $this->generateKey();

                        $this->encryptFile($file->getPathname(), $destPath);
                        $encrypted++;
                    }
                } else {
                    File::copy($file->getPathname(), $destPath);
                    $copied++;
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
        $this->info("📄 Copied: {$copied} other files");
        if ($errors > 0) {
            $this->warn("⚠️  Errors: {$errors} files");
        }
    }

    private function encryptFile($source, $destination)
    {
        $code = file_get_contents($source);

        // Simple base64 + XOR encryption (more compatible)
        $encrypted = base64_encode($this->xorEncrypt($code, $this->encryptionKey));

        // Create simple loader without eval issues
        $loader = $this->createSimpleLoader($encrypted);

        file_put_contents($destination, $loader);
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
        $decrypt = 'd_' . substr(md5(rand()), 0, 50);
        $key = 'm_' . substr(md5(rand()), 0, 50);
        $data = 'f_' . substr(md5(rand()), 0, 50);
        $result = 'r_' . substr(md5(rand()), 0, 50);

        $loader = "<?php\n";
        // $loader .= "/** Encrypted **/\n";
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
        // Strip PHP opening tag and eval
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

    private function copyEssentialFiles($source, $destination)
    {
        if ($this->option('skip-vendor')) {
            $this->warn('⚠️  Skipping vendor directory');
        } elseif (File::exists($source . '/vendor')) {
            $this->info('📦 Copying vendor directory...');
            try {
                File::copyDirectory($source . '/vendor', $destination . '/vendor');
            } catch (\Exception $e) {
                $this->warn('⚠️  Could not copy vendor.');
            }
        }

        $essentials = [
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
            'tailwind.config.js'
        ];

        foreach ($essentials as $file) {
            $sourcePath = $source . DIRECTORY_SEPARATOR . $file;
            $destPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (File::exists($sourcePath)) {
                try {
                    File::copy($sourcePath, $destPath);
                } catch (\Exception $e) {
                    //
                }
            }
        }

        if (File::exists($source . '/storage')) {
            $this->info('📦 Creating storage structure...');
            $storageDirs = [
                'storage/app/public',
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/framework/views',
                'storage/logs'
            ];

            foreach ($storageDirs as $dir) {
                $fullPath = $destination . DIRECTORY_SEPARATOR . $dir;
                if (!File::isDirectory($fullPath)) {
                    File::makeDirectory($fullPath, 0755, true, true);
                }
            }
        }

        if (File::exists($source . '/public')) {
            $this->info('📦 Copying public directory...');
            try {
                File::copyDirectory($source . '/public', $destination . '/public');
            } catch (\Exception $e) {
                //
            }
        }

        $bootstrapCache = $destination . '/bootstrap/cache';
        if (!File::isDirectory($bootstrapCache)) {
            File::makeDirectory($bootstrapCache, 0755, true, true);
        }
    }

    private function generateKey()
    {
        return bin2hex(random_bytes(256));
    }
}
