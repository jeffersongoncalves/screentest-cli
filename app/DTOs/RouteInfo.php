<?php

namespace App\DTOs;

readonly class RouteInfo
{
    /**
     * @param  string[]  $params  Required route parameter names (e.g. "emailGroupMember" from
     *                            "confirm/{emailGroupMember}") — optional ("{param?}") ones excluded.
     * @param  array<string, string>  $paramModels  Subset of $params resolved to the Eloquent
     *                                              model class Laravel would route-model-bind
     *                                              them to, found by reading the controller
     *                                              action's own type-hinted parameter.
     */
    public function __construct(
        public string $name,
        public string $uri,
        public bool $signed = false,
        public bool $auth = false,
        public array $params = [],
        public array $paramModels = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            uri: $data['uri'],
            signed: $data['signed'] ?? false,
            auth: $data['auth'] ?? false,
            params: $data['params'] ?? [],
            paramModels: $data['paramModels'] ?? [],
        );
    }
}
