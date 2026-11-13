<?php declare(strict_types = 1);

namespace EcomailShoptetTest;

use EcomailShoptet\ShoptetResponse;
use EcomailShoptet\ShoptetResponseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShoptetResponseTest extends TestCase
{

    private const RAW_HEADERS = "HTTP/2 429\r\nContent-Type: application/json\r\nRetry-After: Mon, 27 Oct 2026 13:00:05 GMT\r\nX-RateLimit-Bucket-Filling: 200;200\r\n\r\n";

    public function testReadsTheDataOfASuccessfulResponse(): void
    {
        $response = ShoptetResponse::fromRaw(201, "HTTP/2 201\r\n\r\n", '{"data":{"coupons":[{"code":"ABC"}]},"errors":null}');

        self::assertSame(201, $response->statusCode);
        self::assertSame(['coupons' => [['code' => 'ABC']]], $response->data);
        self::assertSame([], $response->errors);
        self::assertTrue($response->isJson);
        self::assertTrue($response->isSuccessful());
    }

    /**
     * @param list<array{errorCode: ?string, message: string, instance: ?string}> $expectedErrors
     */
    #[DataProvider('failedResponses')]
    public function testKeepsTheStatusAndEveryErrorOfAFailedResponse(int $statusCode, string $body, array $expectedErrors): void
    {
        $response = ShoptetResponse::fromRaw($statusCode, "HTTP/2 {$statusCode}\r\n\r\n", $body);

        self::assertSame($statusCode, $response->statusCode);
        self::assertNull($response->data);
        self::assertSame($expectedErrors, array_map(
            static fn (ShoptetResponseError $error): array => ['errorCode' => $error->errorCode, 'message' => $error->message, 'instance' => $error->instance],
            $response->errors,
        ));
        self::assertFalse($response->isSuccessful());
    }

    /**
     * @return iterable<string, array{int, string, list<array{errorCode: ?string, message: string, instance: ?string}>}>
     */
    public static function failedResponses(): iterable
    {
        yield 'validation errors on two fields' => [
            400,
            '{"data":null,"errors":[{"errorCode":"invalid-request-data","message":"Currency is invalid.","instance":"data.coupons[0].currency"},{"errorCode":"invalid-request-data","message":"Value is too high.","instance":"data.coupons[0].amount"}]}',
            [
                ['errorCode' => 'invalid-request-data', 'message' => 'Currency is invalid.', 'instance' => 'data.coupons[0].currency'],
                ['errorCode' => 'invalid-request-data', 'message' => 'Value is too high.', 'instance' => 'data.coupons[0].amount'],
            ],
        ];
        yield 'a missing permission' => [
            403,
            '{"data":null,"errors":[{"errorCode":"invalid-token-no-rights","message":"No rights.","instance":"url"}]}',
            [['errorCode' => 'invalid-token-no-rights', 'message' => 'No rights.', 'instance' => 'url']],
        ];
        yield 'a missing template' => [
            404,
            '{"data":null,"errors":[{"errorCode":"discount-coupon-template-not-found","message":"Template not found."}]}',
            [['errorCode' => 'discount-coupon-template-not-found', 'message' => 'Template not found.', 'instance' => null]],
        ];
        yield 'a write lock' => [
            423,
            '{"data":null,"errors":[{"errorCode":"locked","message":"Locked."}]}',
            [['errorCode' => 'locked', 'message' => 'Locked.', 'instance' => null]],
        ];
        yield 'an error item without fields' => [500, '{"data":null,"errors":[{},"not an error"]}', [['errorCode' => null, 'message' => '', 'instance' => null]]];
    }

    public function testTreatsErrorsInASuccessfulStatusAsAFailure(): void
    {
        $response = ShoptetResponse::fromRaw(201, "HTTP/2 201\r\n\r\n", '{"data":{"coupons":[]},"errors":[{"errorCode":"coupon-not-created","message":"Not created."}]}');

        self::assertSame(['coupons' => []], $response->data);
        self::assertCount(1, $response->errors);
        self::assertFalse($response->isSuccessful());
    }

    #[DataProvider('bodiesWithoutJson')]
    public function testMarksABodyWithoutJson(int $statusCode, string $body): void
    {
        $response = ShoptetResponse::fromRaw($statusCode, "HTTP/2 {$statusCode}\r\n\r\n", $body);

        self::assertFalse($response->isJson);
        self::assertNull($response->data);
        self::assertSame([], $response->errors);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function bodiesWithoutJson(): iterable
    {
        yield 'an html gateway error' => [502, '<html><body>Bad Gateway</body></html>'];
        yield 'an empty body' => [503, ''];
        yield 'a json scalar' => [200, '"ok"'];
    }

    public function testReadsTheHeadersCaseInsensitively(): void
    {
        $response = ShoptetResponse::fromRaw(429, self::RAW_HEADERS, '{"data":null,"errors":[]}');

        self::assertSame('Mon, 27 Oct 2026 13:00:05 GMT', $response->header('Retry-After'));
        self::assertSame('Mon, 27 Oct 2026 13:00:05 GMT', $response->header('retry-after'));
        self::assertSame('200;200', $response->headers['x-ratelimit-bucket-filling']);
        self::assertNull($response->header('X-Missing'));
    }

}
