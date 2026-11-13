<?php declare(strict_types = 1);

namespace EcomailShoptet;

final class ShoptetResponseError
{

    public function __construct(
        public readonly ?string $errorCode,
        public readonly string $message,
        public readonly ?string $instance,
    ) {
    }

    /**
     * @param mixed[] $error
     */
    public static function fromArray(array $error): self
    {
        return new self(
            is_string($error['errorCode'] ?? null) ? $error['errorCode'] : null,
            is_string($error['message'] ?? null) ? $error['message'] : '',
            is_string($error['instance'] ?? null) ? $error['instance'] : null,
        );
    }

}
