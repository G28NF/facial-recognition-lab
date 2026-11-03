<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlIdentityRepository;
use InvalidArgumentException;

final class RegistrationService
{
    /** @param array<string,array{dimensions:int,threshold_squared:float,ambiguity_margin_squared:float}> $models */
    public function __construct(
        private readonly MysqlIdentityRepository $repository,
        private readonly array $models
    ) {}

    /** @param array<mixed> $faceapi @param array<mixed> $sface */
    public function register(string $name, bool $consent, array $faceapi, array $sface): int
    {
        $name = $this->validatedName($name);
        if (!$consent) {
            throw new InvalidArgumentException('Nome ou consentimento biometrico invalido.');
        }
        $vectors = [
            'faceapi-recognition' => VectorMath::validate($faceapi, $this->models['faceapi-recognition']['dimensions']),
            'opencv-sface' => VectorMath::validate($sface, $this->models['opencv-sface']['dimensions']),
        ];
        return $this->repository->register($name, $vectors, [
            'faceapi-recognition' => $this->models['faceapi-recognition']['threshold_squared'],
            'opencv-sface' => $this->models['opencv-sface']['threshold_squared'],
        ]);
    }

    public function rename(int $identityId, string $name): void
    {
        $this->repository->rename($identityId, $this->validatedName($name));
    }

    private function validatedName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('Nome invalido.');
        }
        return $name;
    }
}
