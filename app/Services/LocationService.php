<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LocationRepository;
use InvalidArgumentException;
use RuntimeException;

class LocationService
{
    public function __construct(private LocationRepository $repository)
    {
    }

    public function getStates(?int $countryId = null, ?string $search = null): array
    {
        $search = $this->normalizeOptionalText($search);

        if ($countryId !== null) {
            $this->validatePositiveId($countryId, 'El país indicado no es válido.');

            if (!$this->repository->countryExists($countryId)) {
                throw new InvalidArgumentException('El país indicado no existe.');
            }
        }

        return $this->repository->findStates($countryId, $search);
    }

    public function getMunicipalities(?int $stateId = null, ?string $search = null): array
    {
        $search = $this->normalizeOptionalText($search);

        if ($stateId !== null) {
            $this->validatePositiveId($stateId, 'El estado indicado no es válido.');

            if (!$this->repository->stateExists($stateId)) {
                throw new InvalidArgumentException('El estado indicado no existe.');
            }
        }

        return $this->repository->findMunicipalities($stateId, $search);
    }

    public function getLocalities(?int $municipalityId = null, ?string $search = null): array
    {
        $search = $this->normalizeOptionalText($search);

        if ($municipalityId !== null) {
            $this->validatePositiveId($municipalityId, 'El municipio indicado no es válido.');

            if (!$this->repository->municipalityExists($municipalityId)) {
                throw new InvalidArgumentException('El municipio indicado no existe.');
            }
        }

        return $this->repository->findLocalities($municipalityId, $search);
    }

    public function getNeighborhoods(?int $localityId = null, ?string $search = null): array
    {
        $search = $this->normalizeOptionalText($search);

        if ($localityId !== null) {
            $this->validatePositiveId($localityId, 'La localidad indicada no es válida.');

            if (!$this->repository->localityExists($localityId)) {
                throw new InvalidArgumentException('La localidad indicada no existe.');
            }
        }

        return $this->repository->findNeighborhoods($localityId, $search);
    }

    public function createState(array $data): int
    {
        $countryId = isset($data['country_id']) ? (int) $data['country_id'] : 0;
        $code = $this->normalizeOptionalText($data['codigo'] ?? null);
        $name = $this->normalizeRequiredText($data['nombre'] ?? null, 'El nombre del estado es obligatorio.');

        $this->validatePositiveId($countryId, 'El país es obligatorio.');

        if (!$this->repository->countryExists($countryId)) {
            throw new InvalidArgumentException('El país indicado no existe.');
        }

        if ($this->repository->stateExistsByName($countryId, $name)) {
            throw new RuntimeException('Ya existe un estado con ese nombre para el país indicado.');
        }

        return $this->repository->insertState([
            'country_id' => $countryId,
            'codigo' => $code,
            'nombre' => $name
        ]);
    }

    public function createMunicipality(array $data): int
    {
        $stateId = isset($data['state_id']) ? (int) $data['state_id'] : 0;
        $code = $this->normalizeOptionalText($data['codigo'] ?? null);
        $name = $this->normalizeRequiredText($data['nombre'] ?? null, 'El nombre del municipio es obligatorio.');

        $this->validatePositiveId($stateId, 'El estado es obligatorio.');

        if (!$this->repository->stateExists($stateId)) {
            throw new InvalidArgumentException('El estado indicado no existe.');
        }

        if ($this->repository->municipalityExistsByName($stateId, $name)) {
            throw new RuntimeException('Ya existe un municipio con ese nombre para el estado indicado.');
        }

        return $this->repository->insertMunicipality([
            'state_id' => $stateId,
            'codigo' => $code,
            'nombre' => $name
        ]);
    }

    public function createLocality(array $data): int
    {
        $municipalityId = isset($data['municipality_id']) ? (int) $data['municipality_id'] : 0;
        $code = $this->normalizeOptionalText($data['codigo'] ?? null);
        $name = $this->normalizeRequiredText($data['nombre'] ?? null, 'El nombre de la colonia es obligatorio.');

        $this->validatePositiveId($municipalityId, 'El municipio es obligatorio.');

        if (!$this->repository->municipalityExists($municipalityId)) {
            throw new InvalidArgumentException('El municipio indicado no existe.');
        }

        if ($this->repository->localityExistsByName($municipalityId, $name)) {
            throw new RuntimeException('Ya existe una colonia con ese nombre para el municipio indicado.');
        }

        return $this->repository->insertLocality([
            'municipality_id' => $municipalityId,
            'codigo' => $code,
            'nombre' => $name
        ]);
    }

    private function normalizeRequiredText(mixed $value, string $message): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function validatePositiveId(int $id, string $message): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException($message);
        }
    }
}