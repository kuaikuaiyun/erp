<?php

namespace App\Portals\Assistant;

use App\Models\AssistantDomainStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AssistantDomainCheckService
{
    /**
     * Check a user-entered domain without changing the original stored value.
     *
     * @return array{status: string, root_domain: ?string, http_status: ?int, message: string}
     */
    public function check(string $rawDomain): array
    {
        $host = $this->extractHost($rawDomain);
        $rootDomain = $host === null ? null : $this->registrableDomain($host);

        if ($rootDomain === null) {
            return $this->result(AssistantDomainStore::DOMAIN_CHECK_FAILED, null, null, 'invalid_domain');
        }

        $resolution = $this->resolvePublicAddresses($rootDomain);

        if ($resolution['records_found'] === false) {
            return $this->result(AssistantDomainStore::DOMAIN_CHECK_ABNORMAL, $rootDomain, null, 'dns_unresolved');
        }

        if ($resolution['addresses'] === []) {
            return $this->result(AssistantDomainStore::DOMAIN_CHECK_FAILED, $rootDomain, null, 'private_address');
        }

        $httpsResponse = $this->request($rootDomain, 'https', 443, $resolution['addresses'][0]);

        if ($httpsResponse !== null) {
            return $this->classifyResponse($httpsResponse, $rootDomain, 'https');
        }

        $httpResponse = $this->request($rootDomain, 'http', 80, $resolution['addresses'][0]);

        if ($httpResponse !== null) {
            return $this->classifyResponse($httpResponse, $rootDomain, 'http');
        }

        return $this->result(AssistantDomainStore::DOMAIN_CHECK_ABNORMAL, $rootDomain, null, 'http_request_failed');
    }

    private function extractHost(string $rawDomain): ?string
    {
        $rawDomain = trim($rawDomain);

        if ($rawDomain === '') {
            return null;
        }

        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $rawDomain) === 1
            ? $rawDomain
            : 'https://'.$rawDomain;
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        if (function_exists('idn_to_ascii')) {
            $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0;
            $asciiHost = idn_to_ascii($host, $flags, $variant);

            if (is_string($asciiHost) && $asciiHost !== '') {
                $host = strtolower($asciiHost);
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || strlen($host) > 253) {
            return null;
        }

        return $host;
    }

    private function registrableDomain(string $host): ?string
    {
        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return null;
        }

        $suffixLength = 1;

        foreach ($this->multiLabelPublicSuffixes() as $suffix) {
            $suffixLabels = explode('.', $suffix);

            if (count($labels) > count($suffixLabels)
                && array_slice($labels, -count($suffixLabels)) === $suffixLabels) {
                $suffixLength = max($suffixLength, count($suffixLabels));
            }
        }

        if (count($labels) <= $suffixLength) {
            return null;
        }

        return implode('.', array_slice($labels, -($suffixLength + 1)));
    }

    /** @return list<string> */
    private function multiLabelPublicSuffixes(): array
    {
        return [
            'ac.uk', 'co.uk', 'gov.uk', 'ltd.uk', 'me.uk', 'net.uk', 'nhs.uk', 'org.uk', 'plc.uk', 'sch.uk',
            'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
            'com.br', 'net.br', 'org.br', 'gov.br', 'edu.br',
            'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn', 'ac.cn',
            'com.hk', 'net.hk', 'org.hk', 'gov.hk', 'edu.hk', 'idv.hk',
            'co.in', 'firm.in', 'net.in', 'org.in', 'gen.in', 'ind.in',
            'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp', 'gr.jp', 'ed.jp',
            'co.kr', 'ne.kr', 'or.kr', 'go.kr', 'ac.kr', 'pe.kr',
            'com.mx', 'org.mx', 'gob.mx',
            'com.my', 'net.my', 'org.my', 'edu.my', 'gov.my',
            'co.nz', 'net.nz', 'org.nz', 'ac.nz', 'govt.nz',
            'com.sg', 'net.sg', 'org.sg', 'edu.sg', 'gov.sg',
            'com.tw', 'net.tw', 'org.tw', 'edu.tw', 'gov.tw', 'idv.tw',
            'co.za', 'net.za', 'org.za', 'gov.za',
        ];
    }

    /** @return array{records_found: bool, addresses: list<string>} */
    private function resolvePublicAddresses(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA);

        if (!is_array($records) || $records === []) {
            return ['records_found' => false, 'addresses' => []];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (!is_string($address)
                || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }

            if (!in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return ['records_found' => true, 'addresses' => $addresses];
    }

    private function request(string $domain, string $scheme, int $port, string $address): ?Response
    {
        $options = ['allow_redirects' => false];

        if (defined('CURLOPT_RESOLVE')) {
            $resolvedAddress = str_contains($address, ':') ? '['.$address.']' : $address;
            $options['curl'] = [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $domain, $port, $resolvedAddress)],
            ];
        }

        try {
            $client = Http::accept('*/*')
                ->withHeaders(['User-Agent' => 'KKY-ERP-Domain-Check/1.0'])
                ->withOptions($options)
                ->connectTimeout(2)
                ->timeout(4)
                ->withoutRedirecting();
            $response = $client->head($scheme.'://'.$domain);

            if (in_array($response->status(), [405, 501], true)) {
                $response = $client->get($scheme.'://'.$domain);
            }

            return $response;
        } catch (ConnectionException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{status: string, root_domain: string, http_status: int, message: string} */
    private function classifyResponse(Response $response, string $rootDomain, string $scheme): array
    {
        $status = $response->status();

        if ($status >= 200 && $status < 400) {
            return $this->result(AssistantDomainStore::DOMAIN_CHECK_NORMAL, $rootDomain, $status, $scheme.'_success');
        }

        if (in_array($status, [401, 403, 429], true)) {
            return $this->result(AssistantDomainStore::DOMAIN_CHECK_REVIEW, $rootDomain, $status, 'http_review_required');
        }

        return $this->result(
            AssistantDomainStore::DOMAIN_CHECK_ABNORMAL,
            $rootDomain,
            $status,
            $status >= 500 ? 'http_server_error' : 'http_client_error',
        );
    }

    /** @return array{status: string, root_domain: ?string, http_status: ?int, message: string} */
    private function result(string $status, ?string $rootDomain, ?int $httpStatus, string $message): array
    {
        return [
            'status' => $status,
            'root_domain' => $rootDomain,
            'http_status' => $httpStatus,
            'message' => $message,
        ];
    }
}
