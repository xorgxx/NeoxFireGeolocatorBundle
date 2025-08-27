<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Provider;

use Neox\FireGeolocatorBundle\DTO\GeoApiContextDTO;
use Neox\FireGeolocatorBundle\DTO\ProviderResultDTO;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DsnHttpProvider extends AbstractProvider
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $dsn,
        private array $variables = [],
        private ?object $mapper = null // objet possédant map(array, string): GeoApiContextDTO
    ) {
    }

    public function fetch(string $ip): ProviderResultDTO
    {
        try {
            $url      = $this->interpolateUrl($this->dsn, $ip);
            $response = $this->httpClient->request('GET', $url, [
                'timeout'      => 5.0,        // inactivity timeout seconds
                'max_duration' => 8.0,   // hard cap for the whole request
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $data = $response->toArray(false);
                if ($this->mapper !== null && \is_object($this->mapper) && \method_exists($this->mapper, 'map')) {
                    $mapped = $this->mapper->map($data, $ip);
                    if ($mapped instanceof GeoApiContextDTO) {
                        return new ProviderResultDTO(true, $mapped, null);
                    }
                }

                return new ProviderResultDTO(false, null, 'invalid_mapper_or_mapping');
            }
            $group = ($status >= 400 && $status < 500) ? 'client' : (($status >= 500) ? 'server' : 'other');

            return new ProviderResultDTO(false, null, sprintf('HTTP_%s_%d', $group, $status));
        } catch (TimeoutExceptionInterface $e) {
            return new ProviderResultDTO(false, null, 'timeout');
        } catch (TransportExceptionInterface $e) {
            return new ProviderResultDTO(false, null, 'transport: ' . $e->getMessage());
        } catch (ClientExceptionInterface $e) {
            return new ProviderResultDTO(false, null, 'HTTP_CLIENT_EXCEPTION');
        } catch (ServerExceptionInterface $e) {
            return new ProviderResultDTO(false, null, 'HTTP_SERVER_EXCEPTION');
        } catch (\Throwable $e) {
            return new ProviderResultDTO(false, null, 'error: ' . $e->getMessage());
        }
    }

    private function interpolateUrl(string $dsn, string $ip): string
    {
        $url          = $dsn;
        $replacements = array_merge(['ip' => $ip], $this->variables);
        foreach ($replacements as $k => $v) {
            $url = str_replace('{' . $k . '}', (string) $v, $url);
        }
        // strip mapper+ prefix
        $pos = strpos($url, '+');
        if ($pos !== false) {
            $url = substr($url, $pos + 1);
        }

        return $url;
    }
}
