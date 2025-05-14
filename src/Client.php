<?php declare(strict_types = 1);

namespace EcomailShoptet;

use EcomailShoptet\Exception\EcomailShoptetAnotherError;
use EcomailShoptet\Exception\EcomailShoptetInvalidAuthorization;
use EcomailShoptet\Exception\EcomailShoptetNoEvidenceResult;
use EcomailShoptet\Exception\EcomailShoptetRequestError;
use EcomailShoptet\Exception\EcomailShoptetSaveFailed;
use EcomailShoptet\Exception\EcomailShoptetNotFound;
use EcomailShoptet\Exception\EcomailShoptetPageNotFound;
use EcomailShoptet\Exception\EcomailShoptetRateLimitExceeded;
use Exception;

class Client
{

    /**
     * Shoptet access token
     *
     * @var string
     */
    private $access_token;

    /**
     * Shoptet ID
     *
     * @var string
     */
    private $shoptetId;

    public function __construct(string $access_token, string $shoptetId)
    {
        $this->access_token = $access_token;
        $this->shoptetId = $shoptetId;
    }

    public function getOauthAccessToken(string $clientId, string $redirectUri, ?string $clientSecret = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $data = [
            'code' => $this->access_token,
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'api',
        ];

        if ($clientSecret) {
            $data['client_secret'] = $clientSecret;
        }

        curl_setopt($ch, CURLOPT_URL, 'https://' . $this->shoptetId . '.myshoptet.com/action/ApiOAuthServer/token');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ecomail.cz Shoptet client (https://github.com/Ecomailcz/shoptet-client)');

        $output = curl_exec($ch);

        if($output === false) {
            throw new Exception(curl_error($ch), curl_errno($ch));
        }

        $result = json_decode($output, true);

        return $result;
    }

    public function getApiToken(string $oauthAccessToken)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_URL, 'https://' . $this->shoptetId . '.myshoptet.com/action/ApiOAuthServer/getAccessToken');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $oauthAccessToken]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ecomail.cz Shoptet client (https://github.com/Ecomailcz/shoptet-client)');

        $output = curl_exec($ch);

        if($output === false) {
            throw new Exception(curl_error($ch), curl_errno($ch));
        }
        
        $result = json_decode($output, true);

        return $result;
    }

    /**
     * @param string $httpMethod
     * @param string $url
     * @param mixed[] $postFields
     * @param string[] $queryParameters
     * @return mixed[]
     * @throws \EcomailShoptet\Exception\EcomailShoptetAnotherError
     * @throws \EcomailShoptet\Exception\EcomailShoptetNotFound
     * @throws \EcomailShoptet\Exception\EcomailShoptetInvalidAuthorization
     * @throws \EcomailShoptet\Exception\EcomailShoptetRequestError
     */
    public function makeRequest(string $httpMethod, string $url, array $postFields = [], array $queryParameters = []): array
    {
        /** @var resource $ch */
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);

        curl_setopt($ch, CURLOPT_HTTPAUTH, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Shoptet-Access-Token: ' . $this->access_token,
            'Content-Type: application/vnd.shoptet.v1.0',
        ]);

        if (count($queryParameters) !== 0) {
            $url .= '?' . http_build_query($queryParameters);
        }

        curl_setopt($ch, CURLOPT_URL, 'https://api.myshoptet.com/' . $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ecomail.cz Shoptet client (https://github.com/Ecomailcz/shoptet-client)');

        if (count($postFields) !== 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
        }

        $output = curl_exec($ch);

        if($output === false) {
            throw new Exception(curl_error($ch), curl_errno($ch));
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($output, 0, $headerSize);
        $body = substr($output, $headerSize);

        // Parse headers into associative array
        $headerLines = explode("\n", $headers);
        $parsedHeaders = [];
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $parsedHeaders[trim($key)] = trim($value);
            }
        }

        $result = json_decode($body, true);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 && curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 201) {
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 404) {
                throw new EcomailShoptetNotFound();
            }
            // Check authorization
            elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 401) {
                throw new EcomailShoptetInvalidAuthorization($this->access_token);
            } elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 429) {
                throw new EcomailShoptetRateLimitExceeded(json_encode($parsedHeaders));
            } elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 400) {
                if (isset($result['errors']) && sizeof($result['errors']) > 0) {
                    foreach ($result['errors'] as $error) {
                        if(strpos($error['message'], 'max page is') !== false) {
                            throw new EcomailShoptetPageNotFound();
                        }
                        throw new EcomailShoptetRequestError($error['message']);
                    }
                }
            }
        }

        if (!$result) {
            return ['data' => [], 'headers' => $parsedHeaders];
        }

        if (array_key_exists('success', $result) && !$result['success']) {
            throw new EcomailShoptetAnotherError($result);
        }

        return ['data' => $result, 'headers' => $parsedHeaders];
    }

}
