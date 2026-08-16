<?php
namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;

class SettingsService
{
    public function __construct(private SettingsRepository $repository) {}

    private array $cache = [];

    public function getGlobal(string $id, mixed $default = null): mixed
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $setting = $this->repository->getGlobalById($id);

        if (!$setting) {
            return $default;
        }

        $value = $setting['valor'];

        return $this->cache[$id] = $this->castValue($value, $setting['tipo']);
    }

    public function get(string $id, int $organization, mixed $default = null): mixed
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $setting = $this->repository->getById($id, $organization);

        if (!$setting) {
            return $default;
        }

        $value = $setting['valor'];

        return $this->cache[$id] = $this->castValue($value, $setting['tipo']);
    }

    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int)$value,
            'float', 'money' => (float)$value,
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'si'], true),
            'json' => json_decode($value, true),
            'string' => $value,
            default => $value,
        };
    }
}