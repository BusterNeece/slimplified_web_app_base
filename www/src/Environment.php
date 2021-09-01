<?php

declare(strict_types=1);

namespace App;

use App\Traits\AvailableStaticallyTrait;

class Environment
{
    use AvailableStaticallyTrait;

    protected array $data = [];

    // Environments
    public const ENV_DEVELOPMENT = 'development';
    public const ENV_TESTING = 'testing';
    public const ENV_PRODUCTION = 'production';

    // Core settings values
    public const APP_NAME = 'APP_NAME';
    public const APP_ENV = 'APPLICATION_ENV';

    public const BASE_DIR = 'BASE_DIR';
    public const TEMP_DIR = 'TEMP_DIR';
    public const CONFIG_DIR = 'CONFIG_DIR';
    public const VIEWS_DIR = 'VIEWS_DIR';
    public const UPLOADS_DIR = 'UPLOADS_DIR';

    public const IS_CLI = 'IS_CLI';

    public const ASSET_URL = 'ASSETS_URL';

    // Database and Cache Configuration Variables
    public const DB_HOST = 'MYSQL_HOST';
    public const DB_PORT = 'MYSQL_PORT';
    public const DB_NAME = 'MYSQL_DATABASE';
    public const DB_USER = 'MYSQL_USER';
    public const DB_PASSWORD = 'MYSQL_PASSWORD';

    public const REDIS_HOST = 'REDIS_HOST';
    public const REDIS_PORT = 'REDIS_PORT';
    public const REDIS_DB = 'REDIS_DB';

    // Default settings
    protected array $defaults = [
        self::APP_NAME => 'AzuraCast',
        self::APP_ENV => self::ENV_PRODUCTION,

        self::IS_CLI => ('cli' === PHP_SAPI),

        self::ASSET_URL => '/static',
    ];

    public function __construct(array $elements = [])
    {
        $this->data = array_merge($this->defaults, $elements);
    }

    /**
     * @return mixed[]
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function getAppEnvironment(): string
    {
        return $this->data[self::APP_ENV] ?? self::ENV_PRODUCTION;
    }

    public function isProduction(): bool
    {
        return self::ENV_PRODUCTION === $this->getAppEnvironment();
    }

    public function isTesting(): bool
    {
        return self::ENV_TESTING === $this->getAppEnvironment();
    }

    public function isDevelopment(): bool
    {
        return self::ENV_DEVELOPMENT === $this->getAppEnvironment();
    }

    public function isCli(): bool
    {
        return $this->data[self::IS_CLI] ?? ('cli' === PHP_SAPI);
    }

    public function getAppName(): string
    {
        return $this->data[self::APP_NAME] ?? 'Application';
    }

    public function getAssetUrl(): ?string
    {
        return $this->data[self::ASSET_URL] ?? '';
    }

    /**
     * @return string The base directory of the application, i.e. `/var/app/www` for Docker installations.
     */
    public function getBaseDirectory(): string
    {
        return $this->data[self::BASE_DIR];
    }

    /**
     * @return string The directory where temporary files are stored by the application, i.e. `/var/app/www_tmp`
     */
    public function getTempDirectory(): string
    {
        return $this->data[self::TEMP_DIR];
    }

    /**
     * @return string The directory where configuration files are stored by default.
     */
    public function getConfigDirectory(): string
    {
        return $this->data[self::CONFIG_DIR];
    }

    /**
     * @return string The directory where template/view files are stored.
     */
    public function getViewsDirectory(): string
    {
        return $this->data[self::VIEWS_DIR];
    }

    /**
     * @return string The directory where user system-level uploads are stored.
     */
    public function getUploadsDirectory(): string
    {
        return $this->data[self::UPLOADS_DIR];
    }

    /**
     * @return string The parent directory the application is within, i.e. `/var/azuracast`.
     */
    public function getParentDirectory(): string
    {
        return dirname($this->getBaseDirectory());
    }

    /**
     * @return mixed[]
     */
    public function getDatabaseSettings(): array
    {
        return [
            'host' => $this->data[self::DB_HOST] ?? 'mariadb',
            'port' => (int)($this->data[self::DB_PORT] ?? 3306),
            'dbname' => $this->data[self::DB_NAME] ?? 'azuracast',
            'user' => $this->data[self::DB_USER] ?? 'azuracast',
            'password' => $this->data[self::DB_PASSWORD] ?? 'azur4c457',
        ];
    }

    /**
     * @return mixed[]
     */
    public function getRedisSettings(): array
    {
        return [
            'host' => $this->data[self::REDIS_HOST] ?? 'redis',
            'port' => (int)($this->data[self::REDIS_PORT] ?? 6379),
            'db' => (int)($this->data[self::REDIS_DB] ?? 1),
        ];
    }
}
