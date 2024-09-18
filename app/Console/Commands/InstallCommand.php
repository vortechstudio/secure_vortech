<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Process\Pipe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'app:install
                        {--db-host=localhost : Database Host}
                        {--db-port=3306 : Port for the database}
                        {--db-database= : Name for the database}
                        {--db-username=root : Username for accessing the database}
                        {--db-password= : Password for accessing the database}
                        {--github-repository=: Repository du projet}
                        {--github-token=: Token accès au repository}
                        ';

    protected $description = 'Installation Initial du système';

    public function handle()
    {
        if ($this->missingRequiredOptions()) {
            $this->error('Missing required options');
            $this->line('please run');
            $this->line('php artisan app:install --help');
            $this->line('to see the command usage.');
            return 0;
        }
        $this->alert('Application is installing...');
        $this->copyEnvExampleToEnv();
        $this->generateAppKey();
        $this->updateEnvVariablesFromOptions();
        $this->info('Env file created successfully.');
        $this->info('Runnning migrations and seeders...');
        if (!static::runMigrationsWithSeeders()) {
            $this->error('Your database credentials are wrong!');
            return 0;
        }
        $this->installCoreSystem();
        $this->installOptionnalSystem();
        if (!static::runMigrationsWithSeeders()) {
            $this->error('Your database credentials are wrong!');
            return 0;
        }
        if($this->confirm("Système visuel ?", true)) {
            $this->installFrontSystem();
        }
        $this->importGithubWorkflow();

        $this->alert('Application is installed successfully.');
        return 1;
    }

    public function missingRequiredOptions(): bool
    {
        return !$this->option('db-database');
    }

    private function updateEnv($data)
    {
        $env = file_get_contents(base_path('.env'));
        $env = explode("\n", $env);
        foreach ($data as $dataKey => $dataValue) {
            $alreadyExistInEnv = false;
            foreach ($env as $envKey => $envValue) {
                $entry = explode('=', $envValue, 2);
                // Check if exists or not in env file
                if ($entry[0] == $dataKey) {
                    $env[$envKey] = $dataKey . '=' . $dataValue;
                    $alreadyExistInEnv = true;
                } else {
                    $env[$envKey] = $envValue;
                }
            }
            // add the variable if not exists in env
            if (!$alreadyExistInEnv) {
                $env[] = $dataKey . '=' . $dataValue;
            }
        }
        $env = implode("\n", $env);
        file_put_contents(base_path('.env'), $env);
        return true;
    }

    public function copyEnvExampleToEnv()
    {
        if($this->option('env') == 'local') {
            if (!is_file(base_path('.env')) && is_file(base_path('.env.example'))) {
                File::copy(base_path('.env.example'), base_path('.env'));
            }
        } elseif ($this->option('env') == 'staging' || $this->option('env') == 'testing') {
            if (!is_file(base_path('.env')) && is_file(base_path('.env.staging'))) {
                File::copy(base_path('.env.staging'), base_path('.env'));
            }
        } else {
            if (!is_file(base_path('.env')) && is_file(base_path('.env.production'))) {
                File::copy(base_path('.env.production'), base_path('.env'));
            }
        }
    }

    public static function generateAppKey()
    {
        Artisan::call('key:generate');
    }

    public static function runMigrationsWithSeeders()
    {
        $a = confirm("Voulez-vous executer les migration");
        if ($a) {
            try {
                Artisan::call('migrate:fresh', ['--force' => true]);
                Artisan::call('db:seed', ['--force' => true]);
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return true;
    }

    public function updateEnvVariablesFromOptions()
    {
        $this->updateEnv([
            'DB_HOST' => $this->option('db-host'),
            'DB_PORT' => $this->option('db-port'),
            'DB_DATABASE' => $this->option('db-database'),
            'DB_USERNAME' => $this->option('db-username'),
            'DB_PASSWORD' => $this->option('db-password'),
            'GITHUB_REPOSITORY' => $this->option('github-repository'),
            'GITHUB_TOKEN' => $this->option('github-token')
        ]);
        $conn = config('database.default', 'mysql');
        $dbConfig = Config::get("database.connections.$conn");

        $dbConfig['host'] = $this->option('db-host');
        $dbConfig['port'] = $this->option('db-port');
        $dbConfig['database'] = $this->option('db-database');
        $dbConfig['username'] = $this->option('db-username');
        $dbConfig['password'] = $this->option('db-password');
        Config::set("database.connections.$conn", $dbConfig);
        DB::purge($conn);
        DB::reconnect($conn);
    }

    private function installCoreSystem()
    {
        $flow = confirm('Voulez-vous utiliser git flow ?');
        if ($flow) {
            Process::run('git flow init -f -d --feature feature/  --bugfix bugfix/ --release release/ --hotfix hotfix/ --support support/', function (string $type, string $output) {
                if($type == 'err') {
                    $this->error($output);
                } else {
                    $this->info("Git flow Initilialized !");
                }
            });
        }


        $this->info("Installation des dépendance principal obligatoire");

        $result = Process::pipe(function (Pipe $pipe) {
            $this->line("-- INSTALLATION DU LOG VIEWER --");
            $pipe->command('composer install arcanedev/log-viewer');
            $this->updateEnv([
                'LOG_CHANNEL' => "daily"
            ]);
            $pipe->command("php artisan log-viewer:publish");
            $pipe->command("php artisan log-viewer:clear");
            $this->line("");
            $this->line("-- INSTALLATION DE VIEWER/EXPORT PDF --");
            $pipe->command("composer require barryvdh/laravel-dompdf");
            $this->line("");
            $this->line("-- ");
        });
        if($result->successful()) {
            $this->info("Installation des dépendance principal obligatoire terminé");
        } else {
            $this->error("Erreur lors de l'installation des dépendance obligatoire");
        }
    }

    private function installOptionnalSystem()
    {
        $auth = confirm("Voulez-vous utiliser l'authentification ?", 'yes');
        if($auth) {
            $r = Process::pipe(function (Pipe $pipe) {
                $pipe->command("composer require laravel/fortify");
                Artisan::call("vendor:publish", ['--provider="Laravel\Fortify\FortifyServiceProvider"']);

                $pipe->command("composer require rappasoft/laravel-authentication-log");
                $pipe->command("composer require torann/geoip");

                Artisan::call('vendor:publish', ['--provider="Rappasoft\LaravelAuthenticationLog\LaravelAuthenticationLogServiceProvider"', '--tag="authentication-log-migrations"']);
                Artisan::call('vendor:publish', ['--provider="Torann\GeoIP\GeoIPServiceProvider"', '--tag=config']);
            });

            $r ? $this->alert("Installation de Laravel Fortify Terminer, n'oublier pas d'ajouter l'interface 'AuthenticationLoggable' au model 'User'") : $this->error("Erreur lors de l'installation de laravel Fortify");
        }


    }

    private function importGithubWorkflow()
    {
        $this->info("Importation du workflow github");
        $prAgent = file_put_contents(__DIR__ . '/../../.github/workflows/pr_agent.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/manager/master/.github/workflows/pr_agent.yml'), 'w');
        $prUpdate = file_put_contents(__DIR__ . '/../../.github/workflows/pr_update.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/manager/master/.github/workflows/pr_update.yml'), 'w');
        $d_staging = file_put_contents(__DIR__ . '/../../.github/workflows/deploy_staging.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/manager/master/.github/workflows/deploy_staging.yml'), 'w');
        $d_production = file_put_contents(__DIR__ . '/../../.github/workflows/deploy_production.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/manager/master/.github/workflows/deploy_production.yml'), 'w');
        $dependabot = file_put_contents(__DIR__ . '/../../.github/dependabot.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/api/master/.github/dependabot.yml'), 'w');
        $issue_bug = file_put_contents(__DIR__ . '/../../.github/ISSUE_TEMPLATE/bug.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/api/master/.github/ISSUE_TEMPLATE/bug.yml'), 'w');
        $issue_config = file_put_contents(__DIR__ . '/../../.github/ISSUE_TEMPLATE/config.yml', file_get_contents('https://raw.githubusercontent.com/vortechstudio/api/master/.github/ISSUE_TEMPLATE/config.yml'), 'w');

        $issue = file_put_contents(__DIR__ . '/../../.github/workflows/issue.yml', file_get_contents('https://raw.githubusercontent.com/laravel/laravel/11.x/.github/workflows/issues.yml'), 'w');
        $pr = file_put_contents(__DIR__ . '/../../.github/workflows/pull-requests.yml', file_get_contents('https://raw.githubusercontent.com/laravel/laravel/11.x/.github/workflows/pull-requests.yml'), 'w');
        $tests = file_put_contents(__DIR__ . '/../../.github/workflows/tests.yml', file_get_contents('https://raw.githubusercontent.com/laravel/laravel/11.x/.github/workflows/tests.yml'), 'w');
    }

    private function installFrontSystem()
    {
        $this->info("Installation de livewire");
        $result = Process::pipe(function (Pipe $pipe) {
            $pipe->command("composer require livewire/livewire");
            Artisan::call("livewire:publish", ["--config"]);
        });

        if ($result->successful()) {
            Process::run("npm install");
            Process::run("npm run build");
        }

        $result = Process::pipe(function (Pipe $pipe) {
            $pipe->command("composer require jantinnerezo/livewire-alert");
        });

    }
}
