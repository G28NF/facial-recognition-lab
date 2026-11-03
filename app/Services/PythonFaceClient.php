<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PythonFaceClient
{
    /** @param array{base_url:string,token:string,timeout_seconds:int} $config */
    public function __construct(private readonly array $config) {}

    /** @return array{embedding:list<float>,metrics:array<string,int|float>} */
    public function extract(string $image): array
    {
        if (!str_starts_with($image, 'data:image/jpeg;base64,') || strlen($image) > 7_200_000) {
            throw new \InvalidArgumentException('Imagem JPEG invalida ou maior que 5 MB.');
        }
        $started = hrtime(true);
        $response = $this->request('POST', '/extract', ['image' => $image]);
        $roundtrip = round((hrtime(true) - $started) / 1_000_000, 3);
        if (!is_array($response['embedding'] ?? null) || !is_array($response['metrics'] ?? null)) {
            throw new RuntimeException('Resposta invalida do servico facial.');
        }
        $embedding = VectorMath::validate($response['embedding'], 128);
        $metrics = [];
        foreach ($response['metrics'] as $name => $value) {
            if (is_string($name) && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $metrics[$name] = $value;
            }
        }
        $processing = (float) ($metrics['facial_processing_ms'] ?? 0.0);
        $metrics['php_python_roundtrip_ms'] = $roundtrip;
        $metrics['php_python_overhead_ms'] = round(max(0.0, $roundtrip - $processing), 3);
        return ['embedding' => $embedding, 'metrics' => $metrics];
    }

    /** @return array<string,mixed> */
    public function warmup(): array
    {
        return $this->request('GET', '/warmup');
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $handle = curl_init($this->config['base_url'].$path);
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel iniciar a comunicacao interna.');
        }
        $headers = ['Accept: application/json', 'X-Internal-Token: '.$this->config['token']];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT => $this->config['timeout_seconds'],
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body)) {
            throw new RuntimeException('Servico facial indisponivel. Inicie o servico Python e tente retomar a importacao.', 503);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status < 200 || $status >= 300) {
            $message = is_array($decoded) && is_string($decoded['detail'] ?? null) ? $decoded['detail'] : 'Falha no servico facial.';
            throw new RuntimeException($message, $status >= 400 && $status < 500 ? 422 : 503);
        }
        return $decoded;
    }
}
