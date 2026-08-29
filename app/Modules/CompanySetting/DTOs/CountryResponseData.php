<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\DTOs;

readonly class CountryResponseData
{
    public function __construct(
        public string $code,
        public string $name,
    ) {}

    /**
     * @param  list<array{code: string, name: string}>  $countries
     * @return list<array{code: string, name: string}>
     */
    public static function collectionToArray(array $countries): array
    {
        return array_map(
            static fn (array $country): array => (new self($country['code'], $country['name']))->toArray(),
            $countries
        );
    }

    /**
     * @return array{code: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
        ];
    }
}
