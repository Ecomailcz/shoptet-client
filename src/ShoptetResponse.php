<?php declare(strict_types = 1);

namespace EcomailShoptet;

final class ShoptetResponse
{

    /**
     * @param mixed[]|null $data
     * @param \EcomailShoptet\ShoptetResponseError[] $errors
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly ?array $data,
        public readonly array $errors,
        public readonly array $headers,
        public readonly bool $isJson,
    ) {
    }

    public static function fromRaw(int $statusCode, string $rawHeaders, string $body): self
    {
        $decoded = json_decode($body, true);
        $isJson = is_array($decoded);
        $data = $isJson && is_array($decoded['data'] ?? null) ? $decoded['data'] : null;
        $errors = $isJson && is_array($decoded['errors'] ?? null) ? self::parseErrors($decoded['errors']) : [];

        return new self($statusCode, $data, $errors, self::parseHeaders($rawHeaders), $isJson);
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300 && $this->errors === [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function parseHeaders(string $rawHeaders): array
    {
        $headers = [];

        foreach (explode("\n", $rawHeaders) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    /**
     * @param mixed[] $errors
     * @return \EcomailShoptet\ShoptetResponseError[]
     */
    private static function parseErrors(array $errors): array
    {
        $parsed = [];

        foreach ($errors as $error) {
            if (is_array($error)) {
                $parsed[] = ShoptetResponseError::fromArray($error);
            }
        }

        return $parsed;
    }

}
